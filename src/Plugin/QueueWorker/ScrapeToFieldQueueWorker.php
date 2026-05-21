<?php

namespace Drupal\scrape_to_field\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\scrape_to_field\Service\ScrapeFieldManager;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes web scraping tasks in the background.
 */
#[QueueWorker(
  id: "scrape_to_field_queue",
  title: new TranslatableMarkup("Scrape to field queue worker"),
  cron: ["time" => 60]
)]
class ScrapeToFieldQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The scrape to field manager.
   */
  protected ScrapeFieldManager $scrapeFieldManager;

  /**
   * The scraper activity logger.
   */
  protected ScraperActivityLogger $scraperLogger;

  /**
   * Constructs a ScrapeToFieldQueueWorker object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ScrapeFieldManager $scraper_manager, ScraperActivityLogger $scraper_logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->scrapeFieldManager = $scraper_manager;
    $this->scraperLogger = $scraper_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('scrape_to_field.manager'),
      $container->get('scrape_to_field.activity_logger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || empty($data['node_id']) || !is_numeric($data['node_id'])) {
      $this->scraperLogger->logInvalidQueuePayload($data);
      return;
    }

    $field_name = isset($data['field_name']) ? (string) $data['field_name'] : NULL;
    $queued_timestamp = isset($data['timestamp']) && is_numeric($data['timestamp'])
      ? (int) $data['timestamp']
      : NULL;
    $processed = $this->scrapeFieldManager->processNodeScraping((int) $data['node_id'], $field_name, $queued_timestamp);
    if (!$processed) {
      throw new RequeueException('Scrape processing failed.');
    }
  }

}
