<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\DTO\NodeScraperConfigDto;

/**
 * Manages web scraping operations for fields and nodes.
 */
class ScrapeFieldManager {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The web scraper service.
   */
  protected WebScraperService $scraperService;

  /**
   * The scraper activity logger.
   */
  protected ScraperActivityLogger $scraperLogger;

  /**
   * The content sanitization service.
   */
  protected ContentSanitizationService $sanitizationService;

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * The lock backend.
   */
  protected LockBackendInterface $lockBackend;

  /**
   * Constructs a ScrapeFieldManager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebScraperService $scraper_service, ScraperActivityLogger $scraper_logger, ContentSanitizationService $sanitization_service, StateInterface $state, LockBackendInterface $lock_backend) {
    $this->entityTypeManager = $entity_type_manager;
    $this->scraperService = $scraper_service;
    $this->scraperLogger = $scraper_logger;
    $this->sanitizationService = $sanitization_service;
    $this->state = $state;
    $this->lockBackend = $lock_backend;
  }

  /**
   * Processes scraping for a specific node.
   *
   * @param int $node_id
   *   The node ID to process.
   * @param string|null $field_name
   *   Optional field name to process only a specific field.
   * @param int|null $queued_timestamp
   *   Optional queue creation timestamp used to skip stale duplicate work.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function processNodeScraping(int $node_id, ?string $field_name = NULL, ?int $queued_timestamp = NULL): bool {
    $lock_name = "scrape_to_field.node.{$node_id}";
    if (!$this->lockBackend->acquire($lock_name, 300)) {
      return FALSE;
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
   */
  protected function doProcessNodeScraping(int $node_id, ?string $field_name = NULL, ?int $queued_timestamp = NULL): bool {
    $node_storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\Core\Entity\ContentEntityInterface $node */
    $node = $node_storage->load($node_id);

    if (!$node) {
      $this->scraperLogger->logNodeNotFound($node_id);
      $this->clearQueuedState($node_id, $field_name);
      return TRUE;
    }

    $scraper_config = $this->getNodeScraperConfig($node);
    if (empty($scraper_config)) {
      // Nothing to scrape.
      return TRUE;
    }

    $updated = FALSE;
    $processed_fields = [];
    $failed = FALSE;

    // If field_name is specified, process only that field.
    $fields_to_process = $field_name ? [$field_name => $scraper_config[$field_name] ?? []] : $scraper_config;

    foreach ($fields_to_process as $field_name_to_process => $config) {
      if (!$node->hasField($field_name_to_process) || empty($config['enabled'])) {
        $this->clearQueuedState($node_id, $field_name_to_process);
        continue;
      }

      if ($queued_timestamp !== NULL && $this->isStaleQueueItem($node_id, $field_name_to_process, $queued_timestamp)) {
        $this->clearQueuedState($node_id, $field_name_to_process);
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
        ]
      );

      if ($scraped_data === NULL) {
        $failed = TRUE;
        continue;
      }

      $processed_fields[] = $field_name_to_process;
      if (!empty($scraped_data)) {
        $sanitized_data = $this->sanitizationService->sanitizeScrapedData($scraped_data, $config);

        $this->updateFieldWithScrapedData($node, $field_name_to_process, $sanitized_data, $config);
        $updated = TRUE;
      }
    }

    if ($updated) {
      $validation_error = $this->validateProcessedFields($node, $processed_fields);
      if ($validation_error !== NULL) {
        $this->scraperLogger->logValidationFailure($node, $validation_error);
        foreach ($processed_fields as $processed_field) {
          $this->clearQueuedState($node_id, $processed_field);
        }
        return TRUE;
      }

      if (method_exists($node, 'setNewRevision')) {
        $node->setNewRevision(FALSE);
      }

      $node->save();
      $this->scraperLogger->logNodeUpdated($node_id);
    }

    foreach ($processed_fields as $processed_field) {
      $last_scrape_key = "scrape_to_field.last_scrape.{$node_id}.{$processed_field}";
      $this->state->set($last_scrape_key, time());
      $this->clearQueuedState($node_id, $processed_field);
    }

    return !$failed;
  }

  /**
   * Checks if a node has scraper configuration.
   */
  public function hasScraperConfig(NodeInterface $node): bool {
    if (!$node->hasField('field_scraper_config')) {
      return FALSE;
    }

    $config_field = $node->get('field_scraper_config');
    return !$config_field->isEmpty();
  }

  /**
   * Gets scraper configuration for a node.
   */
  public function getNodeScraperConfig(NodeInterface $node): array {
    if (!$node->hasField('field_scraper_config')) {
      return [];
    }

    $config_field = $node->get('field_scraper_config');
    if ($config_field->isEmpty()) {
      return [];
    }

    $config_value = $config_field->first()->getValue();
    try {
      return NodeScraperConfigDto::fromJson($config_value['value'] ?? '[]')->toArray();
    }
    catch (\InvalidArgumentException $exception) {
      $this->scraperLogger->logInvalidConfiguration((int) $node->id(), $exception->getMessage());
      return [];
    }
  }

  /**
   * Updates a field with scraped data.
   */
  protected function updateFieldWithScrapedData(NodeInterface $node, string $field_name, array $data, array $config): void {
    $field = $node->get($field_name);
    $field_definition = $field->getFieldDefinition();
    $field_type = $field_definition->getType();
    $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();

    // Handle multiple_handling setting.
    $multiple_handling = $config['multiple_handling'] ?? 'first';
    $processed_data = $this->processMultipleData($data, $multiple_handling, $config, $cardinality);

    switch ($field_type) {
      case 'string':
      case 'string_long':
        $this->setFieldValue($field, $processed_data);
        break;

      case 'text':
      case 'text_long':
        $text_format = $this->getValidTextFormat($field_definition, $config['text_format'] ?? '');
        $properties = $text_format !== NULL ? ['format' => $text_format] : [];
        $this->setFieldValue($field, $processed_data, $properties);
        break;

      case 'integer':
        $this->setFieldValue($field, $processed_data, [], 'int');
        break;

      case 'decimal':
        $this->setFieldValue($field, $processed_data, [], 'string');
        break;

      case 'float':
        $this->setFieldValue($field, $processed_data, [], 'float');
        break;

      default:
        // For other field types, attempt to set the value(s) directly.
        $field->setValue($processed_data);
    }
  }

  /**
   * Process scraped data based on multiple_handling setting.
   */
  protected function processMultipleData(array $data, string $multiple_handling, array $config, int $cardinality): array|string {
    if (empty($data)) {
      return '';
    }

    switch ($multiple_handling) {
      case 'first':
        return $data[0] ?? '';

      case 'join':
        $separator = $config['separator'] ?? ', ';
        return implode($separator, $data);

      case 'all':
        // Respect field cardinality.
        if ($cardinality === 1) {
          // Single cardinality field, join the values.
          $separator = $config['separator'] ?? ', ';
          return implode($separator, $data);
        }
        elseif ($cardinality === -1) {
          // Unlimited cardinality, return all values.
          return $data;
        }
        else {
          // Limited cardinality, return up to the limit.
          return array_slice($data, 0, $cardinality);
        }

      default:
        return $data[0] ?? '';
    }
  }

  /**
   * Helper method to set field values with consistent structure.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field to set values on.
   * @param array|string $processed_data
   *   The processed data to set.
   * @param array $additional_properties
   *   Additional properties to set on each field item.
   * @param string|null $cast_type
   *   Optional type casting: 'int', 'float', 'string', or null for no casting.
   */
  protected function setFieldValue($field, $processed_data, array $additional_properties = [], ?string $cast_type = NULL): void {
    if (is_array($processed_data)) {
      // For multiple values, set each one.
      $values = array_map(function ($item) use ($additional_properties, $cast_type) {
        $value = $this->castValue($item, $cast_type);
        return array_merge(['value' => $value], $additional_properties);
      }, $processed_data);
      $field->setValue($values);
    }
    else {
      $value = $this->castValue($processed_data, $cast_type);
      $field->setValue(array_merge(['value' => $value], $additional_properties));
    }
  }

  /**
   * Helper method to cast values to the appropriate type.
   *
   * @param mixed $value
   *   The value to cast.
   * @param string|null $cast_type
   *   The type to cast to: 'int', 'float', 'string', or null for no casting.
   *
   * @return mixed
   *   The cast value.
   */
  protected function castValue($value, ?string $cast_type) {
    if ($cast_type === NULL) {
      return $value;
    }

    return match ($cast_type) {
      'int' => (int) $value,
      'float' => (float) $value,
      'string' => (string) $value,
      default => $value,
    };
  }

  /**
   * Gets a text format that is enabled and valid for the field.
   */
  protected function getValidTextFormat(FieldDefinitionInterface $field_definition, string $preferred_format): ?string {
    $format_storage = $this->entityTypeManager->getStorage('filter_format');
    $formats = $format_storage->loadByProperties(['status' => TRUE]);
    if (empty($formats)) {
      return NULL;
    }

    $valid_format_ids = array_keys($formats);
    $allowed_formats = $field_definition->getSetting('allowed_formats') ?: [];
    if (!empty($allowed_formats)) {
      $valid_format_ids = array_values(array_intersect($valid_format_ids, $allowed_formats));
    }

    if ($valid_format_ids === []) {
      return NULL;
    }

    if ($preferred_format !== '' && in_array($preferred_format, $valid_format_ids, TRUE)) {
      return $preferred_format;
    }

    $fallback_format = function_exists('filter_fallback_format') ? filter_fallback_format() : NULL;
    if ($fallback_format !== NULL && in_array($fallback_format, $valid_format_ids, TRUE)) {
      return $fallback_format;
    }

    return reset($valid_format_ids) ?: NULL;
  }

  /**
   * Validates only fields changed by the scraper.
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

  /**
   * Clears the duplicate queue guard for a node field.
   */
  protected function clearQueuedState(int $node_id, ?string $field_name): void {
    if ($field_name === NULL || $field_name === '') {
      return;
    }

    $this->state->delete("scrape_to_field.queued.{$node_id}.{$field_name}");
  }

  /**
   * Checks whether a queued field scrape predates the latest success.
   */
  protected function isStaleQueueItem(int $node_id, string $field_name, int $queued_timestamp): bool {
    $last_scrape = (int) $this->state->get("scrape_to_field.last_scrape.{$node_id}.{$field_name}", 0);
    return $last_scrape >= $queued_timestamp;
  }

}
