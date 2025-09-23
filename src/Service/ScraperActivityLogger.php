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
  public function logConfigurationChange(NodeInterface $node, array $old_config, array $new_config): void {
    $this->logger->info('Scraper configuration changed for node @nid (@title) by user @uid', [
      '@nid' => $node->id() ?? 'unsaved',
      '@title' => $node->getTitle() ?? 'Untitled',
      '@uid' => $this->currentUser->id(),
    ]);
  }

  /**
   * Log manual scraping triggers.
   */
  public function logManualScrape(NodeInterface $node, int $jobs_queued): void {
    $this->logger->info('Manual scrape triggered for node @nid (@title) by user @uid - @count jobs queued', [
      '@nid' => $node->id() ?? 'unsaved',
      '@title' => $node->getTitle() ?? 'Untitled',
      '@uid' => $this->currentUser->id(),
      '@count' => $jobs_queued,
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
   * Log scraping job completion.
   */
  public function logScrapingJobComplete(NodeInterface $node, string $status, int $items_processed, string $details = ''): void {
    $this->logger->info('Scraping job completed for node @nid (@title): @status (@count items processed). @details', [
      '@nid' => $node->id() ?? 'unsaved',
      '@title' => $node->getTitle() ?? 'Untitled',
      '@status' => $status,
      '@count' => $items_processed,
      '@details' => $details ?: 'No additional details',
    ]);
  }

  /**
   * Log scraping errors.
   */
  public function logScrapingError(NodeInterface $node, string $error_message, array $context = []): void {
    $this->logger->error('Scraping error for node @nid (@title): @error', [
      '@nid' => $node->id() ?? 'unsaved',
      '@title' => $node->getTitle() ?? 'Untitled',
      '@error' => $error_message,
    ]);
  }

}