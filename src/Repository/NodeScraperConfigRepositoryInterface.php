<?php

namespace Drupal\scrape_to_field\Repository;

use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\DTO\NodeScraperConfigDto;

/**
 * Interface for the node scraper configuration repository.
 *
 * Isolates all reads and writes of the field_scraper_config node field,
 * removing duplicated JSON parsing and DTO hydration from services and forms.
 */
interface NodeScraperConfigRepositoryInterface {

  /**
   * Checks whether a node has any scraper configuration stored.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to inspect.
   *
   * @return bool
   *   TRUE when the field exists and is non-empty.
   */
  public function hasConfig(NodeInterface $node): bool;

  /**
   * Loads and returns the scraper configuration for a node.
   *
   * Returns a disabled (empty) DTO when no configuration is stored or when
   * the stored JSON is malformed.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to load configuration from.
   *
   * @return \Drupal\scrape_to_field\DTO\NodeScraperConfigDto
   *   The configuration DTO.
   */
  public function getConfig(NodeInterface $node): NodeScraperConfigDto;

  /**
   * Persists a scraper configuration DTO to a node field.
   *
   * Encodes the DTO as JSON, sets the node field, and saves the node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to update.
   * @param \Drupal\scrape_to_field\DTO\NodeScraperConfigDto $config
   *   The configuration DTO to persist.
   *
   * @throws \JsonException
   *   Thrown when the DTO cannot be encoded as JSON.
   */
  public function saveConfig(NodeInterface $node, NodeScraperConfigDto $config): void;

  /**
   * Clears the scraper configuration stored on a node.
   *
   * Sets the field to empty and saves the node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose configuration will be cleared.
   */
  public function clearConfig(NodeInterface $node): void;

}
