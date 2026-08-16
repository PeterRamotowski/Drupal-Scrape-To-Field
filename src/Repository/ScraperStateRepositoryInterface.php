<?php

namespace Drupal\scrape_to_field\Repository;

/**
 * Interface for the scraper state repository.
 *
 * Encapsulates all read/write operations on Drupal State that track scraping
 * timestamps and queue markers. Provides a single source of truth for state
 * key naming, eliminating raw string interpolation in multiple services.
 */
interface ScraperStateRepositoryInterface {

  /**
   * Returns the Unix timestamp of the last successful scrape.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return int
   *   Timestamp, or 0 when never scraped.
   */
  public function getLastScrapeTime(int $node_id, string $field_name): int;

  /**
   * Persists the last successful scrape timestamp.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   * @param int $timestamp
   *   Unix timestamp to store.
   */
  public function setLastScrapeTime(
    int $node_id,
    string $field_name,
    int $timestamp,
  ): void;

  /**
   * Returns the Unix timestamp of the last queue insertion.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return int
   *   Timestamp, or 0 when never queued.
   */
  public function getLastQueuedTime(int $node_id, string $field_name): int;

  /**
   * Persists the queue insertion timestamp.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   * @param int $timestamp
   *   Unix timestamp to store.
   */
  public function setLastQueuedTime(
    int $node_id,
    string $field_name,
    int $timestamp,
  ): void;

  /**
   * Removes the queued marker for a node/field pair.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   */
  public function clearQueuedState(int $node_id, string $field_name): void;

  /**
   * Records a failed processing attempt for a node/field pair.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   *
   * @return int
   *   The updated number of consecutive failures.
   */
  public function recordProcessingFailure(int $node_id, string $field_name): int;

  /**
   * Clears failed processing attempts for a node/field pair.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   */
  public function clearProcessingFailures(int $node_id, string $field_name): void;

  /**
   * Determines whether a queued item predates the last successful scrape.
   *
   * @param int $node_id
   *   The node ID.
   * @param string $field_name
   *   The field machine name.
   * @param int $queued_timestamp
   *   The timestamp from the queue item payload.
   *
   * @return bool
   *   TRUE when the last scrape happened at or after the queued timestamp.
   */
  public function isStale(
    int $node_id,
    string $field_name,
    int $queued_timestamp,
  ): bool;

  /**
   * Removes all module-specific state keys from persistent storage.
   *
   * Intended for use during module uninstall.
   */
  public function deleteAllForModule(): void;

}
