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
      '@url' => $url ?: 'empty',
    ]);
  }

  /**
   * Log empty selector errors.
   */
  public function logEmptySelector(string $url): void {
    $this->logger->error('Empty selector provided to scrapeData for URL: @url', [
      '@url' => $url ?: 'empty',
    ]);
  }

  /**
   * Log invalid selector type errors.
   */
  public function logInvalidSelectorType(string $selector_type, string $url): void {
    $this->logger->error('Invalid selector type "@type" provided to scrapeData for URL: @url', [
      '@type' => $selector_type ?: 'empty',
      '@url' => $url ?: 'empty',
    ]);
  }

  /**
   * Log successful scraping results.
   */
  public function logScrapingSuccess(string $url, int $count): void {
    $this->logger->info('Successfully scraped @count items from @url', [
      '@count' => $count,
      '@url' => $url ?: 'empty',
    ]);
  }

  /**
   * Log HTTP request failures during scraping.
   */
  public function logRequestFailure(string $url, ?string $error_message = NULL): void {
    $this->logger->error('Failed to scrape @url: @error', [
      '@url' => $url ?: 'empty',
      '@error' => $error_message ?: 'Unknown request error',
    ]);
  }

  /**
   * Log unexpected errors during scraping.
   */
  public function logUnexpectedError(string $url, ?string $error_message = NULL): void {
    $this->logger->error('Unexpected error while scraping @url: @error', [
      '@url' => $url ?: 'empty',
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

}
