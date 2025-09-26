<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

/**
 * Manages queues.
 */
class QueueManager {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * The scrape to field service.
   */
  protected ScrapeFieldManager $scrapeFieldManager;

  /**
   * The scraper activity logger.
   */
  protected ScraperActivityLogger $scraperLogger;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * Constructs a QueueManager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, QueueFactory $queue_factory, ScrapeFieldManager $scrape_field_manager, ScraperActivityLogger $scraper_logger, ConfigFactoryInterface $config_factory, StateInterface $state) {
    $this->entityTypeManager = $entity_type_manager;
    $this->queueFactory = $queue_factory;
    $this->scrapeFieldManager = $scrape_field_manager;
    $this->scraperLogger = $scraper_logger;
    $this->configFactory = $config_factory;
    $this->state = $state;
  }

  /**
   * Queues scraping jobs for fields respecting individual field frequencies.
   */
  public function queueScrapingJobsWithFrequency(): int {
    $queued = 0;
    $queue = $this->queueFactory->get('scrape_to_field_queue');
    $config = $this->configFactory->get('scrape_to_field.settings');
    // Default 6 hours.
    $global_frequency = $config->get('cron_frequency') ?? 21600;
    $current_time = time();

    // Get all nodes with scraper configurations.
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->exists('field_scraper_config');

    $nids = $query->execute();

    foreach ($nids as $nid) {
      $node = $node_storage->load($nid);
      if (!$node || !$this->scrapeFieldManager->hasScraperConfig($node)) {
        continue;
      }

      $scraper_config = $this->scrapeFieldManager->getNodeScraperConfig($node);

      foreach ($scraper_config as $field_name => $field_config) {
        if (empty($field_config['enabled'])) {
          continue;
        }

        // Determine the frequency for this field.
        $field_frequency = !empty($field_config['frequency']) ? (int) $field_config['frequency'] : $global_frequency;

        // Check if enough time has passed since last scrape for this field.
        $last_scrape_key = "scrape_to_field.last_scrape.{$nid}.{$field_name}";
        $last_scrape = $this->state->get($last_scrape_key, 0);

        if (($current_time - $last_scrape) >= $field_frequency) {
          $queue->createItem([
            'node_id' => $nid,
            'field_name' => $field_name,
            'timestamp' => $current_time,
          ]);
          $queued++;
        }
      }
    }

    $this->scraperLogger->logQueueActivity($queued);
    return $queued;
  }

}
