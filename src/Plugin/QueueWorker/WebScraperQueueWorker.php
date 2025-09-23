<?php

namespace Drupal\scrape_to_field\Plugin\QueueWorker;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\scrape_to_field\Service\ScraperManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Processes web scraping tasks in the background.
 */
#[QueueWorker(
  id: "scrape_to_field_queue",
  title: new TranslatableMarkup("Web Scraper Queue Worker"),
  cron: ["time" => 60]
)]
class WebScraperQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface
{

  /**
   * The scraper manager service.
   */
  protected ScraperManager $scraperManager;

  /**
   * Constructs a WebScraperQueueWorker object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ScraperManager $scraper_manager)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->scraperManager = $scraper_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('scrape_to_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data)
  {
    if (!isset($data['node_id'])) {
      return;
    }

    $field_name = $data['field_name'] ?? NULL;
    $this->scraperManager->processNodeScraping($data['node_id'], $field_name);
  }
}
