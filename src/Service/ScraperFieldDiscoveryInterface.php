<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\node\NodeInterface;

/**
 * Interface for discovering scraper-eligible fields on a node.
 */
interface ScraperFieldDiscoveryInterface {

  /**
   * Returns all non-base fields of scraper-supported types on a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to inspect.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   Field definitions keyed by field machine name.
   */
  public function getSupportedFields(NodeInterface $node): array;

  /**
   * Returns the field type machine names supported by the scraper.
   *
   * @return string[]
   *   Array of Drupal field type machine names.
   */
  public function getSupportedFieldTypes(): array;

}
