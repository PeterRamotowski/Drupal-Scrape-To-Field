<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;

/**
 * Manages web scraping operations for fields and nodes.
 */
class ScraperManager
{

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * The web scraper service.
   */
  protected WebScraperService $scraperService;

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a ScraperManager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, QueueFactory $queue_factory, WebScraperService $scraper_service, LoggerChannelInterface $logger)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->queueFactory = $queue_factory;
    $this->scraperService = $scraper_service;
    $this->logger = $logger;
  }

  /**
   * Queues scraping jobs for nodes with scraper configurations.
   */
  public function queueScrapingJobs(): int
  {
    $queued = 0;
    $queue = $this->queueFactory->get('scrape_to_field_queue');

    // Get all nodes with scraper configurations.
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->exists('field_scraper_config');

    $nids = $query->execute();

    foreach ($nids as $nid) {
      $node = $node_storage->load($nid);
      if ($node && $this->hasScraperConfig($node)) {
        $queue->createItem([
          'node_id' => $nid,
          'timestamp' => time(),
        ]);
        $queued++;
      }
    }

    $this->logger->info('Queued @count scraping jobs', ['@count' => $queued]);
    return $queued;
  }

  /**
   * Queues scraping jobs for fields respecting individual field frequencies.
   */
  public function queueScrapingJobsWithFrequency(): int
  {
    $queued = 0;
    $queue = $this->queueFactory->get('scrape_to_field_queue');
    $config = \Drupal::config('scrape_to_field.settings');
    $global_frequency = $config->get('cron_frequency') ?? 21600; // Default 6 hours
    $current_time = time();

    // Get all nodes with scraper configurations.
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->exists('field_scraper_config');

    $nids = $query->execute();

    foreach ($nids as $nid) {
      $node = $node_storage->load($nid);
      if (!$node || !$this->hasScraperConfig($node)) {
        continue;
      }

      $scraper_config = $this->getNodeScraperConfig($node);
      
      foreach ($scraper_config as $field_name => $field_config) {
        if (empty($field_config['enabled'])) {
          continue;
        }

        // Determine the frequency for this field
        $field_frequency = !empty($field_config['frequency']) ? (int) $field_config['frequency'] : $global_frequency;
        
        // Check if enough time has passed since last scrape for this field
        $last_scrape_key = "scrape_to_field.last_scrape.{$nid}.{$field_name}";
        $last_scrape = \Drupal::state()->get($last_scrape_key, 0);
        
        if (($current_time - $last_scrape) >= $field_frequency) {
          $queue->createItem([
            'node_id' => $nid,
            'field_name' => $field_name,
            'timestamp' => $current_time,
          ]);
          $queued++;
        }
      }
    }

    $this->logger->info('Queued @count field-specific scraping jobs', ['@count' => $queued]);
    return $queued;
  }

  /**
   * Cleans up old field scraping timestamps.
   * 
   * @param int $older_than
   *   Timestamp threshold - remove entries older than this (default: 30 days).
   */
  public function cleanupOldTimestamps(int $older_than = NULL): void
  {
    if ($older_than === NULL) {
      $older_than = time() - (30 * 24 * 60 * 60); // 30 days ago
    }
    
    $state = \Drupal::state();
    $all_state_keys = $state->getMultiple([]);
    
    foreach ($all_state_keys as $key => $value) {
      // Check if this is a scraper timestamp key and if it's old
      if (str_starts_with($key, 'scrape_to_field.last_scrape.') && $value < $older_than) {
        $state->delete($key);
      }
    }
  }

  /**
   * Processes scraping for a specific node.
   *
   * @param int $node_id
   *   The node ID to process.
   * @param string|null $field_name
   *   Optional field name to process only a specific field.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function processNodeScraping(int $node_id, ?string $field_name = NULL): bool
  {
    $node_storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\Core\Entity\ContentEntityInterface $node */
    $node = $node_storage->load($node_id);

    if (!$node) {
      $this->logger->error('Node @nid not found for scraping', ['@nid' => $node_id]);
      return FALSE;
    }

    $scraper_config = $this->getNodeScraperConfig($node);
    if (empty($scraper_config)) {
      // Nothing to scrape.
      return TRUE;
    }

    $updated = FALSE;

    // If field_name is specified, process only that field
    $fields_to_process = $field_name ? [$field_name => $scraper_config[$field_name] ?? []] : $scraper_config;

    foreach ($fields_to_process as $field_name_to_process => $config) {
      if (!$node->hasField($field_name_to_process) || empty($config['enabled'])) {
        continue;
      }

      $scraped_data = $this->scraperService->scrapeData(
        $config['url'],
        $config['selector'],
        $config['selector_type'] ?? 'css',
        [
          'extract_method' => $config['extract_method'] ?? 'text',
          'attribute' => $config['attribute'] ?? 'href',
        ]
      );

      if ($scraped_data !== NULL && !empty($scraped_data)) {
        $this->updateFieldWithScrapedData($node, $field_name_to_process, $scraped_data, $config);
        $updated = TRUE;
        
        // Update the timestamp for this specific field
        $last_scrape_key = "scrape_to_field.last_scrape.{$node_id}.{$field_name_to_process}";
        \Drupal::state()->set($last_scrape_key, time());
      }
    }

    if ($updated) {
      $node->save();
      $this->logger->info('Updated node @nid with scraped data', ['@nid' => $node_id]);
    }

    return TRUE;
  }

  /**
   * Checks if a node has scraper configuration.
   */
  protected function hasScraperConfig(NodeInterface $node): bool
  {
    if (!$node->hasField('field_scraper_config')) {
      return FALSE;
    }

    $config_field = $node->get('field_scraper_config');
    return !$config_field->isEmpty();
  }

  /**
   * Gets scraper configuration for a node.
   */
  protected function getNodeScraperConfig(NodeInterface $node): array
  {
    if (!$node->hasField('field_scraper_config')) {
      return [];
    }

    $config_field = $node->get('field_scraper_config');
    if ($config_field->isEmpty()) {
      return [];
    }

    $config_value = $config_field->first()->getValue();
    return json_decode($config_value['value'] ?? '[]', TRUE) ?: [];
  }

  /**
   * Updates a field with scraped data.
   */
  protected function updateFieldWithScrapedData(NodeInterface $node, string $field_name, array $data, array $config): void
  {
    $field = $node->get($field_name);
    $field_definition = $field->getFieldDefinition();
    $field_type = $field_definition->getType();
    $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();

    // Handle multiple_handling setting
    $multiple_handling = $config['multiple_handling'] ?? 'first';
    $processed_data = $this->processMultipleData($data, $multiple_handling, $config, $cardinality);

    switch ($field_type) {
      case 'string':
      case 'string_long':
        if (is_array($processed_data)) {
          // For multiple values, set each one
          $values = array_map(function ($item) {
            return ['value' => $item];
          }, $processed_data);
          $field->setValue($values);
        } else {
          $field->setValue(['value' => $processed_data]);
        }
        break;

      case 'text':
      case 'text_long':
        $text_format = $config['text_format'] ?? 'plain_text';
        if (is_array($processed_data)) {
          // For multiple values, set each one
          $values = array_map(function ($item) use ($text_format) {
            return ['value' => $item, 'format' => $text_format];
          }, $processed_data);
          $field->setValue($values);
        } else {
          $field->setValue(['value' => $processed_data, 'format' => $text_format]);
        }
        break;

      default:
        // For other field types, attempt to set the value(s).
        if (is_array($processed_data)) {
          $field->setValue($processed_data);
        } else {
          $field->setValue($processed_data);
        }
    }
  }

  /**
   * Process scraped data based on multiple_handling setting.
   */
  protected function processMultipleData(array $data, string $multiple_handling, array $config, int $cardinality): array|string
  {
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
        // Respect field cardinality
        if ($cardinality === 1) {
          // Single cardinality field, join the values
          $separator = $config['separator'] ?? ', ';
          return implode($separator, $data);
        } elseif ($cardinality === -1) {
          // Unlimited cardinality, return all values
          return $data;
        } else {
          // Limited cardinality, return up to the limit
          return array_slice($data, 0, $cardinality);
        }

      default:
        return $data[0] ?? '';
    }
  }
}
