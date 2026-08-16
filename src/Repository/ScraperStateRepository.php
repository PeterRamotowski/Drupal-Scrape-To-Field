<?php

namespace Drupal\scrape_to_field\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Manages the module's persistent scrape-timing state.
 *
 * Encapsulates all State API access for scrape timestamps and queue markers,
 * providing a single source of truth for key naming.
 */
class ScraperStateRepository implements ScraperStateRepositoryInterface {

  /**
   * State key prefix for last-scrape timestamps.
   */
  private const KEY_LAST_SCRAPE = 'scrape_to_field.last_scrape';

  /**
   * State key prefix for last-queued timestamps.
   */
  private const KEY_QUEUED = 'scrape_to_field.queued';

  /**
   * State key prefix for consecutive processing failure counts.
   */
  private const KEY_PROCESSING_FAILURES = 'scrape_to_field.failures';

  /**
   * Constructs a ScraperStateRepository object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection, used only for bulk deletion on uninstall.
   */
  public function __construct(
    protected StateInterface $state,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getLastScrapeTime(int $node_id, string $field_name): int {
    return (int) $this->state->get(
      $this->lastScrapeKey($node_id, $field_name),
      0,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setLastScrapeTime(
    int $node_id,
    string $field_name,
    int $timestamp,
  ): void {
    $this->state->set($this->lastScrapeKey($node_id, $field_name), $timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function getLastQueuedTime(int $node_id, string $field_name): int {
    return (int) $this->state->get(
      $this->queuedKey($node_id, $field_name),
      0,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setLastQueuedTime(
    int $node_id,
    string $field_name,
    int $timestamp,
  ): void {
    $this->state->set($this->queuedKey($node_id, $field_name), $timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function clearQueuedState(int $node_id, string $field_name): void {
    $this->state->delete($this->queuedKey($node_id, $field_name));
  }

  /**
   * {@inheritdoc}
   */
  public function recordProcessingFailure(int $node_id, string $field_name): int {
    $key = $this->processingFailuresKey($node_id, $field_name);
    $failures = (int) $this->state->get($key, 0) + 1;
    $this->state->set($key, $failures);
    return $failures;
  }

  /**
   * {@inheritdoc}
   */
  public function clearProcessingFailures(int $node_id, string $field_name): void {
    $this->state->delete($this->processingFailuresKey($node_id, $field_name));
  }

  /**
   * {@inheritdoc}
   */
  public function isStale(
    int $node_id,
    string $field_name,
    int $queued_timestamp,
  ): bool {
    return $this->getLastScrapeTime($node_id, $field_name) >= $queued_timestamp;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllForModule(): void {
    $this->database->delete('key_value')
      ->condition('collection', 'state')
      ->condition('name', 'scrape_to_field.%', 'LIKE')
      ->execute();
  }

  /**
   * Builds the state key for a last-scrape timestamp.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return string
   *   The state key string.
   */
  private function lastScrapeKey(int $node_id, string $field_name): string {
    return self::KEY_LAST_SCRAPE . ".{$node_id}.{$field_name}";
  }

  /**
   * Builds the state key for a queued timestamp.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return string
   *   The state key string.
   */
  private function queuedKey(int $node_id, string $field_name): string {
    return self::KEY_QUEUED . ".{$node_id}.{$field_name}";
  }

  /**
   * Builds the state key for a consecutive processing failure count.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return string
   *   The state key string.
   */
  private function processingFailuresKey(int $node_id, string $field_name): string {
    return self::KEY_PROCESSING_FAILURES . ".{$node_id}.{$field_name}";
  }

}
