<?php

namespace Drupal\scrape_to_field\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\scrape_to_field\Exception\ScrapeLockUnavailableException;
use Drupal\scrape_to_field\Exception\ScrapeRateLimitException;
use Drupal\scrape_to_field\Repository\ScraperStateRepositoryInterface;
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
final class ScrapeToFieldQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum number of consecutive attempts for one queue item.
   */
  private const MAX_PROCESSING_ATTEMPTS = 3;

  /**
   * Delay between failed processing attempts, in seconds.
   */
  private const RETRY_DELAY = 60;

  /**
   * The scrape to field manager.
   */
  protected ScrapeFieldManager $scrapeFieldManager;

  /**
   * The scraper activity logger.
   */
  protected ScraperActivityLogger $scraperLogger;

  /**
   * The scraper state repository.
   */
  protected ScraperStateRepositoryInterface $stateRepository;

  /**
   * Constructs a ScrapeToFieldQueueWorker object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\scrape_to_field\Service\ScrapeFieldManager $scraper_manager
   *   The scrape field manager.
   * @param \Drupal\scrape_to_field\Service\ScraperActivityLogger $scraper_logger
   *   The scraper activity logger.
   * @param \Drupal\scrape_to_field\Repository\ScraperStateRepositoryInterface $state_repository
   *   The scraper state repository.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ScrapeFieldManager $scraper_manager,
    ScraperActivityLogger $scraper_logger,
    ScraperStateRepositoryInterface $state_repository,
    protected QueueFactory $queueFactory,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->scrapeFieldManager = $scraper_manager;
    $this->scraperLogger = $scraper_logger;
    $this->stateRepository = $state_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('scrape_to_field.manager'),
      $container->get('scrape_to_field.activity_logger'),
      $container->get('scrape_to_field.state_repository'),
      $container->get('queue'),
      $container->get('datetime.time'),
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
    $node_id = (int) $data['node_id'];
    $retry_after = isset($data['retry_after']) && is_numeric($data['retry_after'])
      ? (int) $data['retry_after']
      : 0;
    $retry_delay = $retry_after - $this->time->getCurrentTime();
    if ($retry_delay > 0) {
      throw new DelayedRequeueException($retry_delay, 'Scrape retry is delayed.');
    }

    try {
      $processed = $this->scrapeFieldManager->processNodeScraping(
        $node_id,
        $field_name,
        $queued_timestamp,
      );
    }
    catch (ScrapeRateLimitException $exception) {
      throw new DelayedRequeueException(
        $exception->getRetryDelay(),
        $exception->getMessage(),
      );
    }
    catch (ScrapeLockUnavailableException $exception) {
      throw new DelayedRequeueException(
        self::RETRY_DELAY,
        $exception->getMessage(),
      );
    }
    catch (\Throwable $exception) {
      $this->scraperLogger->logQueueProcessingFailure(
        $node_id,
        $field_name,
        $exception->getMessage(),
      );
      $this->handleProcessingFailure($data, $node_id, $field_name);
      return;
    }

    if ($processed) {
      return;
    }

    $this->handleProcessingFailure($data, $node_id, $field_name);
  }

  /**
   * Records a processing failure and schedules another bounded attempt.
   *
   * @param array $data
   *   The current queue item payload.
   * @param int $node_id
   *   The node ID being processed.
   * @param string|null $field_name
   *   The field machine name, or NULL for a node-wide job.
   */
  private function handleProcessingFailure(
    array $data,
    int $node_id,
    ?string $field_name,
  ): void {
    $attempts = max(0, (int) ($data['attempts'] ?? 0)) + 1;
    if ($attempts >= self::MAX_PROCESSING_ATTEMPTS) {
      if ($field_name !== NULL && $field_name !== '') {
        $this->stateRepository->clearQueuedState($node_id, $field_name);
      }
      $this->scraperLogger->logQueueRetriesExhausted(
        $node_id,
        $field_name,
        $attempts,
      );
      return;
    }

    $data['attempts'] = $attempts;
    $data['retry_after'] = $this->time->getCurrentTime() + self::RETRY_DELAY;
    $this->queueFactory
      ->get('scrape_to_field_queue')
      ->createItem($data);
  }

}
