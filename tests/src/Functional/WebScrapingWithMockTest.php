<?php

namespace Drupal\Tests\scrape_to_field\Functional;

use GuzzleHttp\Client;
use Drupal\scrape_to_field\DTO\NodeScraperConfigDto;
use Drupal\scrape_to_field\DTO\ScraperFieldConfigDto;
use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests scraping functionality with mocked HTTP responses using fixture data.
 */
class WebScrapingWithMockTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * {@inheritdoc}
   */
  protected static $modules = [
    'scrape_to_field',
    'scrape_to_field_test',
    'node',
    'field',
    'user',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createContentType(['type' => 'test_article']);

    $this->createFieldWithStorage('field_scraped_title', 'string', 'test_article');
    $this->createFieldWithStorage('field_scraped_content', 'text_long', 'test_article');
    $this->createFieldWithStorage('field_scraped_author', 'string', 'test_article');
    $this->createFieldWithStorage('field_scraped_date', 'string', 'test_article');
  }

  /**
   * Creates a field with storage for testing.
   */
  protected function createFieldWithStorage($field_name, $field_type, $bundle) {
    $field_storage = \Drupal::entityTypeManager()
      ->getStorage('field_storage_config')
      ->create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $field_type,
      ]);
    $field_storage->save();

    $field = \Drupal::entityTypeManager()
      ->getStorage('field_config')
      ->create([
        'field_storage' => $field_storage,
        'bundle' => $bundle,
        'label' => ucfirst(str_replace('_', ' ', $field_name)),
      ]);
    $field->save();
  }

  /**
   * Gets HTML fixture content.
   */
  protected function getFixtureContent($filename) {
    $fixture_path = \Drupal::service('extension.list.module')
      ->getPath('scrape_to_field_test') . '/fixtures/' . $filename;
    return file_get_contents($fixture_path);
  }

  /**
   * Tests scraping simple HTML fixture data with CSS selectors.
   */
  public function testScrapingSimpleHtmlFixtureWithCssSelectors() {
    $html_content = $this->getFixtureContent('test_page.html');

    $node = $this->createTestNodeWithConfig([
      'field_scraped_title' => [
        'url' => 'https://example.com/test-page',
        'selector' => 'h1.main-title',
        'selector_type' => 'css',
      ],
      'field_scraped_author' => [
        'url' => 'https://example.com/test-page',
        'selector' => '.author',
        'selector_type' => 'css',
      ],
    ]);

    $this->mockHttpClient($html_content);
    $this->processScrapingQueue($node);

    $updated_node = $this->reloadNode($node);

    $this->assertEquals('Main Heading', $updated_node->get('field_scraped_title')->value);
    $this->assertEquals('John Doe', $updated_node->get('field_scraped_author')->value);
  }

  /**
   * Tests scraping complex HTML fixture data with multiple field mappings.
   */
  public function testScrapingComplexHtmlFixtureWithMultipleFields() {
    $html_content = $this->getFixtureContent('complex_test_page.html');

    $node = $this->createTestNodeWithConfig([
      'field_scraped_title' => [
        'url' => 'https://example.com/complex-page',
        'selector' => 'h1',
        'selector_type' => 'css',
      ],
      'field_scraped_content' => [
        'url' => 'https://example.com/complex-page',
        'selector' => '.summary',
        'selector_type' => 'css',
      ],
      'field_scraped_author' => [
        'url' => 'https://example.com/complex-page',
        'selector' => '.category',
        'selector_type' => 'css',
      ],
    ]);

    $this->mockHttpClient($html_content);
    $this->processScrapingQueue($node);

    $updated_node = $this->reloadNode($node);

    $this->assertEquals('Multiple Headlines Test', $updated_node->get('field_scraped_title')->value);
    $this->assertStringContainsString('Summary for news item', $updated_node->get('field_scraped_content')->value);
    $this->assertEquals('Technology', $updated_node->get('field_scraped_author')->value);
  }

  /**
   * Tests XPath selector functionality with fixture data.
   */
  public function testXpathSelectorFunctionalityWithFixture() {
    $html_content = $this->getFixtureContent('test_page.html');

    $node = $this->createTestNodeWithConfig([
      'field_scraped_title' => [
        'url' => 'https://example.com/xpath-test',
        'selector' => '//h1[@class="main-title"]',
        'selector_type' => 'xpath',
      ],
      'field_scraped_author' => [
        'url' => 'https://example.com/xpath-test',
        'selector' => '//span[@class="author"]',
        'selector_type' => 'xpath',
      ],
      'field_scraped_date' => [
        'url' => 'https://example.com/xpath-test',
        'selector' => '//time[@class="published"]',
        'selector_type' => 'xpath',
      ],
    ]);

    $this->mockHttpClient($html_content);
    $this->processScrapingQueue($node);

    $updated_node = $this->reloadNode($node);

    $this->assertEquals('Main Heading', $updated_node->get('field_scraped_title')->value);
    $this->assertEquals('John Doe', $updated_node->get('field_scraped_author')->value);
    $this->assertEquals('January 15, 2025', $updated_node->get('field_scraped_date')->value);
  }

  /**
   * Tests handling of malformed HTML content from fixture.
   */
  public function testMalformedHtmlHandlingWithFixture() {
    $html_content = $this->getFixtureContent('malformed_html.html');

    $node = $this->createTestNodeWithConfig([
      'field_scraped_title' => [
        'url' => 'https://example.com/malformed',
        'selector' => 'h1',
        'selector_type' => 'css',
      ],
    ]);

    $this->mockHttpClient($html_content);
    $this->processScrapingQueue($node);

    $updated_node = $this->reloadNode($node);

    $scraped_title = $updated_node->get('field_scraped_title')->value;
    $this->assertNotEmpty($scraped_title, 'Scraped content should not be empty');
    $this->assertStringContainsString('Unclosed heading', $scraped_title, 'Should extract text even from malformed HTML');
  }

  /**
   * Tests scraping with different CSS selector patterns from complex fixture.
   */
  public function testDifferentCssSelectorPatternsWithFixture() {
    $html_content = $this->getFixtureContent('complex_test_page.html');

    $test_cases = [
      [
        'selector' => 'h1',
        'expected' => 'Multiple Headlines Test',
        'description' => 'Simple element selector',
      ],
      [
        'selector' => '.news-item h2',
        'expected' => 'News Item 1',
        'description' => 'Descendant selector',
      ],
      [
        'selector' => '[data-id="2"] h2',
        'expected' => 'News Item 2',
        'description' => 'Attribute selector with descendant',
      ],
      [
        'selector' => '.stat-value',
        'expected' => '1,234',
        'description' => 'Class selector',
      ],
    ];

    foreach ($test_cases as $index => $test_case) {
      $node = $this->createTestNodeWithConfig([
        'field_scraped_title' => [
          'url' => 'https://example.com/css-test-' . $index,
          'selector' => $test_case['selector'],
          'selector_type' => 'css',
        ],
      ]);

      $this->mockHttpClient($html_content);
      $this->processScrapingQueue($node);

      $updated_node = $this->reloadNode($node);
      $scraped_value = $updated_node->get('field_scraped_title')->value;

      $this->assertEquals(
        $test_case['expected'],
        $scraped_value,
        $test_case['description'] . ' failed. Selector: ' . $test_case['selector']
      );
    }
  }

  /**
   * Creates a test node with scraping configuration.
   *
   * @param array $field_configs
   *   Array of field configurations.
   *
   * @return \Drupal\node\Entity\Node
   *   The created node.
   */
  protected function createTestNodeWithConfig(array $field_configs) {
    $node = Node::create([
      'type' => 'test_article',
      'title' => 'Test Scraping Node ' . rand(1000, 9999),
    ]);
    $node->save();

    $this->ensureScraperConfigField($node);

    $scraper_field_configs = [];
    foreach ($field_configs as $field_name => $field_config) {
      $scraper_field_configs[$field_name] = new ScraperFieldConfigDto(
        enabled: TRUE,
        url: $field_config['url'],
        selector: $field_config['selector'],
        selectorType: $field_config['selector_type'],
        extractMethod: 'text',
      );
    }

    $node_scraper_config = new NodeScraperConfigDto(TRUE, $scraper_field_configs);

    if ($node->hasField('field_scraper_config')) {
      $node->set('field_scraper_config', $node_scraper_config->toJson());
      $node->save();
    }

    return $node;
  }

  /**
   * Ensures the scraper config field exists on the node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to check.
   */
  protected function ensureScraperConfigField($node) {
    if (!$node->hasField('field_scraper_config')) {
      $field_storage = \Drupal::entityTypeManager()
        ->getStorage('field_storage_config')
        ->create([
          'field_name' => 'field_scraper_config',
          'entity_type' => 'node',
          'type' => 'text_long',
        ]);
      $field_storage->save();

      $field = \Drupal::entityTypeManager()
        ->getStorage('field_config')
        ->create([
          'field_storage' => $field_storage,
          'bundle' => 'test_article',
          'label' => 'Scraper Configuration',
        ]);
      $field->save();

      \Drupal::entityTypeManager()->clearCachedDefinitions();
    }
  }

  /**
   * Processes the scraping queue for a specific node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to process scraping for.
   */
  protected function processScrapingQueue($node) {
    $queue = \Drupal::service('queue')->get('scrape_to_field_queue');

    $scraper_manager = \Drupal::service('scrape_to_field.manager');
    $config = $scraper_manager->getNodeScraperConfig($node);

    foreach ($config as $field_name => $field_config) {
      if (!empty($field_config['enabled'])) {
        $queue->createItem([
          'node_id' => $node->id(),
          'field_name' => $field_name,
          'timestamp' => time(),
        ]);
      }
    }

    $queue_worker = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance('scrape_to_field_queue');

    while (($item = $queue->claimItem()) && is_object($item) && property_exists($item, 'data')) {
      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
      }
    }
  }

  /**
   * Reloads a node to get fresh data.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to reload.
   *
   * @return \Drupal\node\Entity\Node
   *   The reloaded node.
   */
  protected function reloadNode($node) {
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
    return \Drupal::entityTypeManager()->getStorage('node')->load($node->id());
  }

  /**
   * Mocks the HTTP client to return specific content.
   */
  protected function mockHttpClient($html_content) {
    $mock = new MockHandler([
      new Response(200, [], $html_content),
      new Response(200, [], $html_content),
      new Response(200, [], $html_content),
      new Response(200, [], $html_content),
      new Response(200, [], $html_content),
      new Response(200, [], $html_content),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    // Reset the container services to ensure our mock is used
    $this->container->set('http_client', $client);
    
    // Also rebuild the scraper service with the new client
    $scraper_service = new \Drupal\scrape_to_field\Service\WebScraperService(
      $client,
      $this->container->get('config.factory'),
      $this->container->get('scrape_to_field.user_agent'),
      $this->container->get('scrape_to_field.activity_logger'),
      $this->container->get('scrape_to_field.data_cleaning')
    );
    $this->container->set('scrape_to_field.scraper', $scraper_service);
    
    // Rebuild the manager service with the new scraper
    $manager = new \Drupal\scrape_to_field\Service\ScrapeFieldManager(
      $this->container->get('entity_type.manager'),
      $scraper_service,
      $this->container->get('scrape_to_field.activity_logger'),
      $this->container->get('scrape_to_field.content_sanitization'),
      $this->container->get('state')
    );
    $this->container->set('scrape_to_field.manager', $manager);
  }

}
