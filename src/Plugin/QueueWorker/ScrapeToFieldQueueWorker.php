<?php

namespace Drupal\scrape_to_field\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\scrape_to_field\Service\ScrapeFieldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes web scraping tasks in the background.
 */
#[QueueWorker(
  id: "scrape_to_field_queue",
  title: new TranslatableMarkup("Scrape to field queue worker"),
  cron: ["time" => 60]
)]
class ScrapeToFieldQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface
{

  /**
   * The scrape to field manager.
   */
  protected ScrapeFieldManager $scrapeFieldManager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ScrapeFieldManager $scraper_manager)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->scrapeFieldManager = $scraper_manager;
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
    $this->scrapeFieldManager->processNodeScraping($data['node_id'], $field_name);
  }
}
