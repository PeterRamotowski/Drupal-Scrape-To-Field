<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\scrape_to_field\Repository\NodeScraperConfigRepositoryInterface;
use Drupal\scrape_to_field\Repository\ScraperStateRepositoryInterface;

/**
 * Enqueues scraping jobs for enabled node fields.
 *
 * Determines which fields are due for re-scraping by comparing the last
 * scrape and last-queued timestamps against the configured frequency. State
 * management and config hydration are fully delegated to injected
 * repositories.
 */
class QueueManager {

  /**
   * Constructs a QueueManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\scrape_to_field\Service\ScraperActivityLogger $scraperLogger
   *   The activity logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\scrape_to_field\Repository\NodeScraperConfigRepositoryInterface $configRepository
   *   The node scraper config repository.
   * @param \Drupal\scrape_to_field\Repository\ScraperStateRepositoryInterface $stateRepository
   *   The scraper state repository.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected ScraperActivityLogger $scraperLogger,
    protected ConfigFactoryInterface $configFactory,
    protected NodeScraperConfigRepositoryInterface $configRepository,
    protected ScraperStateRepositoryInterface $stateRepository,
  ) {}

  /**
   * Queues scraping jobs for fields respecting individual field frequencies.
   *
   * @return int
   *   The number of queue items created.
   */
  public function queueScrapingJobsWithFrequency(): int {
    $queued = 0;
    $queue = $this->queueFactory->get('scrape_to_field_queue');
    $config = $this->configFactory->get('scrape_to_field.settings');
    // Default 6 hours.
    $global_frequency = $config->get('cron_frequency') ?? 21600;
    $current_time = time();

    $node_storage = $this->entityTypeManager->getStorage('node');
    $id_key = $node_storage->getEntityType()->getKey('id');
    $page_size = 250;
    $offset = 0;

    do {
      $nids = array_values($node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->exists('field_scraper_config')
        ->sort($id_key)
        ->range($offset, $page_size)
        ->execute());

      foreach (array_chunk($nids, 50) as $chunk) {
        $nodes = $node_storage->loadMultiple($chunk);

        foreach ($nodes as $node) {
          $config_dto = $this->configRepository->getConfig($node);
          if (!$config_dto->scrapingEnabled) {
            continue;
          }

          $scraper_config = $config_dto->toArray();

          foreach ($scraper_config as $field_name => $field_config) {
            if (empty($field_config['enabled'])) {
              continue;
            }

            $field_frequency = !empty($field_config['frequency'])
              ? (int) $field_config['frequency']
              : $global_frequency;

            $nid = (int) $node->id();
            $last_scrape = $this->stateRepository->getLastScrapeTime($nid, $field_name);
            $last_queued = $this->stateRepository->getLastQueuedTime($nid, $field_name);

            if (($current_time - max($last_scrape, $last_queued)) >= $field_frequency) {
              $queue->createItem([
                'node_id' => $nid,
                'field_name' => $field_name,
                'timestamp' => $current_time,
                'attempts' => 0,
              ]);
              $this->stateRepository->setLastQueuedTime($nid, $field_name, $current_time);
              $queued++;
            }
          }
        }
      }

      $offset += count($nids);
    } while (count($nids) === $page_size);

    $this->scraperLogger->logQueueActivity($queued);
    return $queued;
  }

}
