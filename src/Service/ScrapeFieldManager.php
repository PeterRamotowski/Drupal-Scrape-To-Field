<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Drupal\scrape_to_field\Service\WebScraperService;

/**
 * Manages web scraping operations for fields and nodes.
 */
class ScrapeFieldManager
{

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
   * Constructs a ScrapeFieldManager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebScraperService $scraper_service, ScraperActivityLogger $scraper_logger)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->scraperService = $scraper_service;
    $this->scraperLogger = $scraper_logger;
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
      $this->scraperLogger->logNodeNotFound($node_id);
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
          'enable_cleaning' => $config['enable_cleaning'] ?? FALSE,
          'cleaning_operations' => $config['cleaning_operations'] ?? [],
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
      $this->scraperLogger->logNodeUpdated($node_id);
    }

    return TRUE;
  }

  /**
   * Checks if a node has scraper configuration.
   */
  public function hasScraperConfig(NodeInterface $node): bool
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
  public function getNodeScraperConfig(NodeInterface $node): array
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
        $this->setFieldValue($field, $processed_data);
        break;

      case 'text':
      case 'text_long':
        $text_format = $config['text_format'] ?? 'plain_text';
        $this->setFieldValue($field, $processed_data, ['format' => $text_format]);
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

  /**
   * Helper method to set field values with consistent structure.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field to set values on.
   * @param array|string $processed_data
   *   The processed data to set.
   * @param array $additional_properties
   *   Additional properties to set on each field item (e.g., 'format' for text fields).
   * @param string|null $cast_type
   *   Optional type casting: 'int', 'float', 'string', or null for no casting.
   */
  protected function setFieldValue($field, $processed_data, array $additional_properties = [], ?string $cast_type = null): void
  {
    if (is_array($processed_data)) {
      // For multiple values, set each one
      $values = array_map(function ($item) use ($additional_properties, $cast_type) {
        $value = $this->castValue($item, $cast_type);
        return array_merge(['value' => $value], $additional_properties);
      }, $processed_data);
      $field->setValue($values);
    } else {
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
  protected function castValue($value, ?string $cast_type)
  {
    if ($cast_type === null) {
      return $value;
    }

    return match ($cast_type) {
      'int' => (int) $value,
      'float' => (float) $value,
      'string' => (string) $value,
      default => $value,
    };
  }
}
