<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\node\NodeInterface;

/**
 * Discovers scraper-eligible fields on a given node.
 *
 * Provides a single authoritative list of the Drupal field types the
 * scraper supports, eliminating per-class duplication.
 */
class ScraperFieldDiscovery implements ScraperFieldDiscoveryInterface {

  /**
   * Field types the scraper can write values into.
   */
  private const SUPPORTED_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'integer',
    'decimal',
    'float',
  ];

  /**
   * {@inheritdoc}
   */
  public function getSupportedFields(NodeInterface $node): array {
    $fields = [];
    $supported = self::SUPPORTED_FIELD_TYPES;

    foreach ($node->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      if (in_array($field_definition->getType(), $supported, TRUE)) {
        $fields[$field_name] = $field_definition;
      }
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFieldTypes(): array {
    return self::SUPPORTED_FIELD_TYPES;
  }

}
