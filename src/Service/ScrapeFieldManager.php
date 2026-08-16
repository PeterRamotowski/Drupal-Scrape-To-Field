<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\Exception\ScrapeLockUnavailableException;
use Drupal\scrape_to_field\Repository\NodeScraperConfigRepositoryInterface;
use Drupal\scrape_to_field\Repository\ScraperStateRepositoryInterface;

/**
 * Orchestrates web scraping operations for node fields.
 *
 * Acquires a distributed lock per node, resolves which fields to process,
 * delegates HTTP fetching and field writing to injected collaborators, then
 * persists updated state.  Business logic for HTTP transport, field-value
 * mapping, config persistence, and state key management is fully delegated.
 */
class ScrapeFieldManager {

  /**
   * Constructs a ScrapeFieldManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load nodes.
   * @param \Drupal\scrape_to_field\Service\WebScraperService $scraperService
   *   The web scraper service.
   * @param \Drupal\scrape_to_field\Service\ScraperActivityLogger $scraperLogger
   *   The activity logger.
   * @param \Drupal\scrape_to_field\Service\ContentSanitizationService $sanitizationService
   *   The content sanitization service.
   * @param \Drupal\scrape_to_field\Repository\ScraperStateRepositoryInterface $stateRepository
   *   The scraper state repository.
   * @param \Drupal\scrape_to_field\Repository\NodeScraperConfigRepositoryInterface $configRepository
   *   The node scraper configuration repository.
   * @param \Drupal\scrape_to_field\Service\FieldValueWriterInterface $fieldValueWriter
   *   The field value writer.
   * @param \Drupal\Core\Lock\LockBackendInterface $lockBackend
   *   The lock backend.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected WebScraperService $scraperService,
    protected ScraperActivityLogger $scraperLogger,
    protected ContentSanitizationService $sanitizationService,
    protected ScraperStateRepositoryInterface $stateRepository,
    protected NodeScraperConfigRepositoryInterface $configRepository,
    protected FieldValueWriterInterface $fieldValueWriter,
    protected LockBackendInterface $lockBackend,
  ) {}

  /**
   * Processes scraping for a specific node.
   *
   * @param int $node_id
   *   The node ID to process.
   * @param string|null $field_name
   *   Optional field name to limit processing to a single field.
   * @param int|null $queued_timestamp
   *   Optional queue creation timestamp used to skip stale duplicate work.
   *
   * @return bool
   *   TRUE when processing completed without errors.
   *
   * @throws \Drupal\scrape_to_field\Exception\ScrapeLockUnavailableException
   *   Thrown when another worker is already processing the node.
   */
  public function processNodeScraping(int $node_id, ?string $field_name = NULL, ?int $queued_timestamp = NULL): bool {
    $lock_name = "scrape_to_field.node.{$node_id}";
    if (!$this->lockBackend->acquire($lock_name, 300)) {
      throw new ScrapeLockUnavailableException();
    }

    try {
      return $this->doProcessNodeScraping($node_id, $field_name, $queued_timestamp);
    }
    finally {
      $this->lockBackend->release($lock_name);
    }
  }

  /**
   * Processes scraping while the node lock is held.
   *
   * @param int $node_id
   *   The node ID to process.
   * @param string|null $field_name
   *   Optional field name to limit processing.
   * @param int|null $queued_timestamp
   *   Optional queue item timestamp for staleness checks.
   *
   * @return bool
   *   TRUE unless a scraping error occurred for at least one field.
   */
  protected function doProcessNodeScraping(int $node_id, ?string $field_name = NULL, ?int $queued_timestamp = NULL): bool {
    $node_storage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $node_storage->load($node_id);

    if (!$node) {
      $this->scraperLogger->logNodeNotFound($node_id);
      if ($field_name !== NULL && $field_name !== '') {
        $this->stateRepository->clearQueuedState($node_id, $field_name);
      }
      return TRUE;
    }

    $config_dto = $this->configRepository->getConfig($node);
    $scraper_config = $config_dto->toArray();
    if (!$config_dto->scrapingEnabled || empty($scraper_config)) {
      if ($field_name !== NULL && $field_name !== '') {
        $this->stateRepository->clearQueuedState($node_id, $field_name);
      }
      return TRUE;
    }

    $fields_to_process = $field_name
      ? [$field_name => $scraper_config[$field_name] ?? []]
      : $scraper_config;

    $field_updates = [];
    $processed_fields = [];
    $failed = FALSE;

    foreach ($fields_to_process as $field_name_to_process => $config) {
      if (!$node->hasField($field_name_to_process) || empty($config['enabled'])) {
        $this->stateRepository->clearQueuedState($node_id, $field_name_to_process);
        continue;
      }

      if ($queued_timestamp !== NULL && $this->stateRepository->isStale($node_id, $field_name_to_process, $queued_timestamp)) {
        $this->stateRepository->clearQueuedState($node_id, $field_name_to_process);
        continue;
      }

      $scraped_data = $this->scraperService->scrapeData(
        $config['url'],
        $config['selector'],
        $config['selector_type'] ?? 'css',
        [
          'extract_method' => $config['extract_method'] ?? 'text',
          'attribute' => $config['attribute'] ?? 'href',
          'enable_cleaning' => $config['enable_cleaning'] ?? FALSE,
          'cleaning_operations' => $config['cleaning_operations'] ?? [],
        ],
      );

      if ($scraped_data === NULL) {
        $failed = TRUE;
        continue;
      }

      $processed_fields[] = $field_name_to_process;
      if (!empty($scraped_data)) {
        $field_updates[$field_name_to_process] = [
          'config' => $config,
          'data' => $this->sanitizationService->sanitizeScrapedData(
            $scraped_data,
            $config,
          ),
        ];
      }
    }

    if ($field_updates !== []) {
      $node_storage->resetCache([$node_id]);
      /** @var \Drupal\node\NodeInterface|null $node */
      $node = $node_storage->load($node_id);
      if (!$node) {
        $this->scraperLogger->logNodeNotFound($node_id);
        foreach ($processed_fields as $processed_field) {
          $this->stateRepository->clearQueuedState($node_id, $processed_field);
        }
        return TRUE;
      }

      $latest_config = $this->configRepository->getConfig($node);
      foreach ($field_updates as $updated_field_name => $field_update) {
        $latest_field_config = $latest_config
          ->getFieldConfig($updated_field_name)?->toArray();
        if (!$latest_config->scrapingEnabled
          || $latest_field_config !== $field_update['config']
          || !$node->hasField($updated_field_name)
        ) {
          $processed_fields = array_values(array_diff(
            $processed_fields,
            [$updated_field_name],
          ));
          $this->stateRepository->clearQueuedState($node_id, $updated_field_name);
          continue;
        }

        $this->fieldValueWriter->write(
          $node,
          $updated_field_name,
          $field_update['data'],
          $field_update['config'],
        );
      }

      $validation_error = $this->validateProcessedFields($node, $processed_fields);
      if ($validation_error !== NULL) {
        $this->scraperLogger->logValidationFailure($node, $validation_error);
        foreach ($processed_fields as $processed_field) {
          $this->stateRepository->clearQueuedState($node_id, $processed_field);
        }
        return TRUE;
      }

      if ($processed_fields !== [] && method_exists($node, 'setNewRevision')) {
        $node->setNewRevision(FALSE);
      }

      if ($processed_fields !== []) {
        $node->save();
        $this->scraperLogger->logNodeUpdated($node_id);
      }
    }

    foreach ($processed_fields as $processed_field) {
      $this->stateRepository->setLastScrapeTime($node_id, $processed_field, time());
      $this->stateRepository->clearQueuedState($node_id, $processed_field);
    }

    return !$failed;
  }

  /**
   * Validates only the fields changed by the scraper.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to validate.
   * @param string[] $processed_fields
   *   Machine names of fields that were updated.
   *
   * @return string|null
   *   Concatenated violation messages, or NULL when all fields are valid.
   */
  protected function validateProcessedFields(NodeInterface $node, array $processed_fields): ?string {
    $messages = [];

    foreach (array_unique($processed_fields) as $field_name) {
      if (!$node->hasField($field_name)) {
        continue;
      }

      $violations = $node->get($field_name)->validate();
      foreach ($violations as $violation) {
        $messages[] = $field_name . '.' . $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
    }

    return $messages === [] ? NULL : implode(' ', $messages);
  }

}
