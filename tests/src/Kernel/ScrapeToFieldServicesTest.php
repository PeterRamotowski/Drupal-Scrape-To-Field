<?php

namespace Drupal\Tests\scrape_to_field\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the scrape_to_field services in a kernel environment.
 */
class ScrapeToFieldServicesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'scrape_to_field',
    'system',
    'user',
    'node',
    'field',
  ];

  /**
   * Tests that all scrape_to_field services can be instantiated.
   */
  public function testServicesCanBeInstantiated() {
    $scraper = $this->container->get('scrape_to_field.scraper');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\WebScraperService', $scraper);

    $manager = $this->container->get('scrape_to_field.manager');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\ScrapeFieldManager', $manager);

    $queue_manager = $this->container->get('scrape_to_field.queue');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\QueueManager', $queue_manager);

    $activity_logger = $this->container->get('scrape_to_field.activity_logger');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\ScraperActivityLogger', $activity_logger);

    $user_agent = $this->container->get('scrape_to_field.user_agent');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\UserAgentService', $user_agent);

    $data_cleaning = $this->container->get('scrape_to_field.data_cleaning');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\DataCleaningService', $data_cleaning);

    $target_url_policy = $this->container->get('scrape_to_field.target_url_policy');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\TargetUrlPolicy', $target_url_policy);

    $rate_limiter = $this->container->get('scrape_to_field.rate_limiter');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\ScrapeRateLimiter', $rate_limiter);

    $http_client = $this->container->get('scrape_to_field.http_client');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\ScraperHttpClientInterface', $http_client);

    $dom_extractor = $this->container->get('scrape_to_field.dom_extractor');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\DomContentExtractorInterface', $dom_extractor);

    $config_validator = $this->container->get('scrape_to_field.config_validator');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\ScraperConfigValidatorInterface', $config_validator);

    $field_value_writer = $this->container->get('scrape_to_field.field_value_writer');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\FieldValueWriterInterface', $field_value_writer);

    $field_discovery = $this->container->get('scrape_to_field.field_discovery');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\ScraperFieldDiscoveryInterface', $field_discovery);

    $state_repository = $this->container->get('scrape_to_field.state_repository');
    $this->assertInstanceOf('Drupal\scrape_to_field\Repository\ScraperStateRepositoryInterface', $state_repository);

    $node_config_repository = $this->container->get('scrape_to_field.node_config_repository');
    $this->assertInstanceOf('Drupal\scrape_to_field\Repository\NodeScraperConfigRepositoryInterface', $node_config_repository);

    $uninstall_handler = $this->container->get('scrape_to_field.uninstall_handler');
    $this->assertInstanceOf('Drupal\scrape_to_field\Service\ModuleUninstallHandler', $uninstall_handler);
  }

  /**
   * Tests the UserAgentService.
   */
  public function testUserAgentService() {
    $user_agent_service = $this->container->get('scrape_to_field.user_agent');

    $user_agent = $user_agent_service->getRandomUserAgent();
    $this->assertIsString($user_agent);
    $this->assertNotEmpty($user_agent);

    $user_agents = [];
    for ($i = 0; $i < 10; $i++) {
      $user_agents[] = $user_agent_service->getRandomUserAgent();
    }

    $unique_agents = array_unique($user_agents);
    $this->assertGreaterThan(1, count($unique_agents));
  }

  /**
   * Tests the WebScraperService with invalid inputs.
   */
  public function testWebScraperServiceValidation() {
    $scraper_service = $this->container->get('scrape_to_field.scraper');

    // Test with invalid URL.
    $result = $scraper_service->scrapeData('not-a-url', 'h1', 'css');
    $this->assertNull($result);

    // Test with empty selector.
    $result = $scraper_service->scrapeData('https://example.com', '', 'css');
    $this->assertNull($result);

    // Test with invalid selector type.
    $result = $scraper_service->scrapeData('https://example.com', 'h1', 'invalid');
    $this->assertNull($result);
  }

  /**
   * Tests the queue functionality.
   */
  public function testQueueFunctionality() {
    $queue = $this->container->get('queue')->get('scrape_to_field_queue');

    $queue->deleteQueue();
    $this->assertEquals(0, $queue->numberOfItems());

    $queue->createItem([
      'node_id' => 1,
      'field_name' => 'title',
      'timestamp' => time(),
    ]);

    $this->assertEquals(1, $queue->numberOfItems());

    // Test queue item structure.
    $item = $queue->claimItem();
    $this->assertNotFalse($item);

    if ($item && is_object($item) && property_exists($item, 'data')) {
      $this->assertIsArray($item->data);
      $this->assertArrayHasKey('node_id', $item->data);
      $this->assertArrayHasKey('field_name', $item->data);
      $this->assertArrayHasKey('timestamp', $item->data);

      $queue->releaseItem($item);
    }
  }

  /**
   * Tests the scraper activity logger.
   */
  public function testScraperActivityLogger() {
    /** @var \Drupal\scrape_to_field\Service\ScraperActivityLogger $activity_logger */
    $activity_logger = $this->container->get('scrape_to_field.activity_logger');

    // Test that logging methods exist and don't crash.
    $activity_logger->logScrapingSuccess('https://example.com', 5);
    $activity_logger->logRequestFailure('https://example.com', 'Timeout error');
    $activity_logger->logInvalidUrl('bad-url');
    $activity_logger->logEmptySelector('https://example.com');
    $activity_logger->logInvalidSelectorType('invalid', 'https://example.com');

    $this->assertTrue(TRUE);
  }

  /**
   * Tests configuration integration.
   */
  public function testConfigurationIntegration() {
    $config = $this->config('scrape_to_field.settings');

    $timeout = $config->get('timeout');
    $max_retries = $config->get('max_retries');
    $retry_delay = $config->get('retry_delay');
    $max_response_bytes = $config->get('max_response_bytes');

    $this->assertTrue($timeout === NULL || is_numeric($timeout));
    $this->assertTrue($max_retries === NULL || is_numeric($max_retries));
    $this->assertTrue($retry_delay === NULL || is_numeric($retry_delay));
    $this->assertTrue($max_response_bytes === NULL || is_numeric($max_response_bytes));
  }

  /**
   * Tests queue worker plugin.
   */
  public function testQueueWorkerPlugin() {
    $plugin_manager = $this->container->get('plugin.manager.queue_worker');

    $plugins = $plugin_manager->getDefinitions();
    $this->assertArrayHasKey('scrape_to_field_queue', $plugins);

    // Test that we can create an instance.
    $queue_worker = $plugin_manager->createInstance('scrape_to_field_queue');
    $this->assertInstanceOf('Drupal\scrape_to_field\Plugin\QueueWorker\ScrapeToFieldQueueWorker', $queue_worker);
  }

}
