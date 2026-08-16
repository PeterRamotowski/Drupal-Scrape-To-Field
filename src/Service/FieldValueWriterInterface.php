<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\node\NodeInterface;

/**
 * Interface for writing scraped values into Drupal node fields.
 */
interface FieldValueWriterInterface {

  /**
   * Writes scraped data into a node field, respecting type and cardinality.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose field will be updated.
   * @param string $field_name
   *   The machine name of the field to write.
   * @param string[] $data
   *   Sanitized scraped values.
   * @param array $config
   *   Field scraper configuration (multiple_handling, separator, text_format).
   */
  public function write(
    NodeInterface $node,
    string $field_name,
    array $data,
    array $config,
  ): void;

  /**
   * Returns the field types supported by this writer.
   *
   * @return string[]
   *   An array of Drupal field type machine names.
   */
  public function getSupportedFieldTypes(): array;

  /**
   * Returns a valid, enabled text format for the given field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param string $preferred_format
   *   Preferred text format machine name; honoured when available.
   *
   * @return string|null
   *   A valid text-format machine name, or NULL when none could be resolved.
   */
  public function getValidTextFormat(
    FieldDefinitionInterface $field_definition,
    string $preferred_format,
  ): ?string;

}
