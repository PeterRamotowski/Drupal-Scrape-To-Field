<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Service for logging scraper activities with detailed context.
 *
 * All log methods ensure that placeholders (@variables) are never null
 * to prevent Html::escape() errors when views render the log messages.
 */
class ScraperActivityLogger {

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a ScraperActivityLogger object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(LoggerChannelInterface $logger, AccountProxyInterface $current_user) {
    $this->logger = $logger;
    $this->currentUser = $current_user;
  }

  /**
   * Log scraper configuration changes.
   */
  public function logConfigurationChange(NodeInterface $node): void {
    $this->logger->info('Scraper configuration changed for node @nid (@title) by user @uid', [
      '@nid' => $node->id() ?? 'unsaved',
      '@title' => $node->getTitle() ?? 'Untitled',
      '@uid' => $this->currentUser->id(),
    ]);
  }

  /**
   * Log configuration testing attempts.
   */
  public function logConfigurationTest(NodeInterface $node, string $result, string $details = ''): void {
    $this->logger->info('Configuration test for node @nid (@title): @result. @details', [
      '@nid' => $node->id() ?? 'unsaved',
      '@title' => $node->getTitle() ?? 'Untitled',
      '@result' => $result,
      '@details' => $details ?: 'No additional details',
    ]);
  }

  /**
   * Log invalid URL errors.
   */
  public function logInvalidUrl(string $url): void {
    $this->logger->error('Invalid URL provided to scrapeData: @url', [
      '@url' => $this->redactUrl($url),
    ]);
  }

  /**
   * Log empty selector errors.
   */
  public function logEmptySelector(string $url): void {
    $this->logger->error('Empty selector provided to scrapeData for URL: @url', [
      '@url' => $this->redactUrl($url),
    ]);
  }

  /**
   * Log invalid selector type errors.
   */
  public function logInvalidSelectorType(string $selector_type, string $url): void {
    $this->logger->error('Invalid selector type "@type" provided to scrapeData for URL: @url', [
      '@type' => $selector_type ?: 'empty',
      '@url' => $this->redactUrl($url),
    ]);
  }

  /**
   * Log successful scraping results.
   */
  public function logScrapingSuccess(string $url, int $count): void {
    $this->logger->info('Successfully scraped @count items from @url', [
      '@count' => $count,
      '@url' => $this->redactUrl($url),
    ]);
  }

  /**
   * Log HTTP request failures during scraping.
   */
  public function logRequestFailure(string $url, ?string $error_message = NULL): void {
    $this->logger->error('Failed to scrape @url: @error', [
      '@url' => $this->redactUrl($url),
      '@error' => $error_message ?: 'Unknown request error',
    ]);
  }

  /**
   * Log unexpected errors during scraping.
   */
  public function logUnexpectedError(string $url, ?string $error_message = NULL): void {
    $this->logger->error('Unexpected error while scraping @url: @error', [
      '@url' => $this->redactUrl($url),
      '@error' => $error_message ?: 'Unknown error',
    ]);
  }

  /**
   * Log when a node is not found for scraping.
   */
  public function logNodeNotFound(int $node_id): void {
    $this->logger->error('Node @nid not found for scraping', [
      '@nid' => $node_id,
    ]);
  }

  /**
   * Log when a node is successfully updated with scraped data.
   */
  public function logNodeUpdated(int $node_id): void {
    $this->logger->info('Updated node @nid with scraped data', [
      '@nid' => $node_id,
    ]);
  }

  /**
   * Log queue activity for scraping jobs.
   */
  public function logQueueActivity(int $queued_count): void {
    $this->logger->info('Queued @count scraping jobs', [
      '@count' => $queued_count,
    ]);
  }

  /**
   * Log invalid scraper configuration stored on a node.
   */
  public function logInvalidConfiguration(int $node_id, string $reason): void {
    $this->logger->error('Invalid scraper configuration for node @nid: @reason', [
      '@nid' => $node_id,
      '@reason' => $reason,
    ]);
  }

  /**
   * Log invalid queue payloads.
   */
  public function logInvalidQueuePayload(mixed $payload): void {
    $this->logger->warning('Invalid scrape queue payload: @payload', [
      '@payload' => $this->summarizePayload($payload),
    ]);
  }

  /**
   * Logs a queue item that exhausted its processing retry budget.
   *
   * @param int $node_id
   *   The node ID.
   * @param string|null $field_name
   *   The field machine name, or NULL for a node-wide job.
   * @param int $attempts
   *   The number of failed attempts.
   */
  public function logQueueRetriesExhausted(
    int $node_id,
    ?string $field_name,
    int $attempts,
  ): void {
    $this->logger->error('Scrape queue retries exhausted for node @nid, field @field after @attempts attempts.', [
      '@nid' => $node_id,
      '@field' => $field_name ?: 'all',
      '@attempts' => $attempts,
    ]);
  }

  /**
   * Logs an exception raised while processing a scrape queue item.
   *
   * @param int $node_id
   *   The node ID being processed.
   * @param string|null $field_name
   *   The field machine name, or NULL for a node-wide job.
   * @param string $reason
   *   The exception message.
   */
  public function logQueueProcessingFailure(
    int $node_id,
    ?string $field_name,
    string $reason,
  ): void {
    $this->logger->error('Scrape queue processing failed for node @nid, field @field: @reason', [
      '@nid' => $node_id,
      '@field' => $field_name ?: 'all',
      '@reason' => $reason ?: 'Unknown processing error',
    ]);
  }

  /**
   * Log entity validation failures before scraped data is saved.
   */
  public function logValidationFailure(NodeInterface $node, string $reason): void {
    $this->logger->error('Scraped data failed validation for node @nid: @reason', [
      '@nid' => $node->id() ?? 'unsaved',
      '@reason' => $reason,
    ]);
  }

  /**
   * Redacts query strings, fragments, and credentials from URLs.
   */
  protected function redactUrl(string $url): string {
    if ($url === '') {
      return 'empty';
    }

    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host'])) {
      return 'invalid-url';
    }

    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '';

    return $scheme . '://' . $host . $port . $path;
  }

  /**
   * Summarizes a queue payload without risking very large log entries.
   */
  protected function summarizePayload(mixed $payload): string {
    try {
      $summary = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $summary = get_debug_type($payload);
    }

    return substr($summary, 0, 500);
  }

}
