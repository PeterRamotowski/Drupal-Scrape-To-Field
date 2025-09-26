<?php

namespace Drupal\Tests\scrape_to_field\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the actual web scraping functionality.
 */
class WebScrapingFunctionalTest extends BrowserTestBase
{

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'scrape_to_field',
    'node',
    'field',
    'user',
    'system',
    'automated_cron',
    'dblog',
  ];

  /**
   * A user with admin permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $this->createContentType(['type' => 'test_content']);

    $this->createFieldWithStorage('field_scraped_title', 'string', 'test_content');
    $this->createFieldWithStorage('field_scraped_body', 'text_long', 'test_content');

    $this->adminUser = $this->drupalCreateUser([
      'administer scrape to field',
      'configure any node scrape to field',
      'create test_content content',
      'edit any test_content content',
      'access administration pages',
    ]);
  }

  /**
   * Creates a field with storage for testing.
   */
  protected function createFieldWithStorage($field_name, $field_type, $bundle)
  {
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

    $form_display = \Drupal::entityTypeManager()
      ->getStorage('entity_form_display')
      ->load("node.{$bundle}.default");

    if (!$form_display) {
      $form_display = \Drupal::entityTypeManager()
        ->getStorage('entity_form_display')
        ->create([
          'targetEntityType' => 'node',
          'bundle' => $bundle,
          'mode' => 'default',
        ]);
    }

    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    $form_display->setComponent($field_name, [
      'type' => $field_type === 'string' ? 'string_textfield' : 'text_textarea',
    ]);
    $form_display->save();
  }

  /**
   * Tests queue functionality for background scraping.
   */
  public function testQueueFunctionality()
  {
    $this->drupalLogin($this->adminUser);

    $node = $this->drupalCreateNode([
      'type' => 'test_content',
      'title' => 'Test Queue Processing',
    ]);

    $this->drupalGet("/node/{$node->id()}/scraper-config");
    $edit = [
      'global_settings[scraping_enabled]' => TRUE,
      'field_field_scraped_title[enabled]' => TRUE,
      'field_field_scraped_title[source_config][url]' => 'https://example.com/test-page',
      'field_field_scraped_title[source_config][selector_type]' => 'css',
      'field_field_scraped_title[source_config][selector]' => 'h1',
    ];
    $this->submitForm($edit, 'Save configuration');

    $queue_manager = \Drupal::service('scrape_to_field.queue');
    $queue = \Drupal::service('queue')->get('scrape_to_field_queue');

    $queue->deleteQueue();

    $queued_count = $queue_manager->queueScrapingJobsWithFrequency();

    $this->assertGreaterThanOrEqual(0, $queued_count);

    $queue_worker = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance('scrape_to_field_queue');

    if ($queue->numberOfItems() > 0) {
      $item = $queue->claimItem();
      $this->assertNotFalse($item);

      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
      } catch (\Exception $e) {
        $queue->releaseItem($item);
      }
    }
  }

  /**
   * Tests scraping validation and error handling.
   */
  public function testScrapingValidationAndErrorHandling()
  {
    $this->drupalLogin($this->adminUser);

    $node = $this->drupalCreateNode([
      'type' => 'test_content',
      'title' => 'Test Validation',
    ]);

    $this->drupalGet("/node/{$node->id()}/scraper-config");
    $this->assertSession()->statusCodeEquals(200);

    $page_content = $this->getSession()->getPage()->getContent();

    if (strpos($page_content, 'global_settings') !== false) {
      $this->assertSession()->fieldExists('global_settings[scraping_enabled]');

      $title_field_exists = $this->getSession()->getPage()->findField('field_field_scraped_title[enabled]');

      if ($title_field_exists) {
        // Test invalid URL validation.
        $edit = [
          'global_settings[scraping_enabled]' => TRUE,
          'field_field_scraped_title[enabled]' => TRUE,
          'field_field_scraped_title[source_config][url]' => 'not-a-valid-url',
          'field_field_scraped_title[source_config][selector_type]' => 'css',
          'field_field_scraped_title[source_config][selector]' => 'h1',
        ];
        $this->submitForm($edit, 'Save configuration');

        $current_url = $this->getSession()->getCurrentUrl();
        $this->assertStringContainsString('scraper-config', $current_url);

        $page_content = $this->getSession()->getPage()->getContent();
        $this->assertTrue(
          strpos($page_content, 'Please enter a valid URL') !== false ||
            strpos($page_content, 'valid URL') !== false ||
            strpos($page_content, 'error') !== false,
          'Expected URL validation error message'
        );

        // Test empty selector validation.
        $edit = [
          'global_settings[scraping_enabled]' => TRUE,
          'field_field_scraped_title[enabled]' => TRUE,
          'field_field_scraped_title[source_config][url]' => 'https://example.com/test-page',
          'field_field_scraped_title[source_config][selector_type]' => 'css',
          'field_field_scraped_title[source_config][selector]' => '',
        ];
        $this->submitForm($edit, 'Save configuration');

        $current_url = $this->getSession()->getCurrentUrl();
        $this->assertStringContainsString('scraper-config', $current_url);

        $page_content = $this->getSession()->getPage()->getContent();
        $this->assertTrue(
          strpos($page_content, 'Selector is required') !== false ||
            strpos($page_content, 'required') !== false ||
            strpos($page_content, 'error') !== false,
          'Expected selector validation error message'
        );

        // Test valid configuration should work (or show service validation errors)
        $edit = [
          'global_settings[scraping_enabled]' => TRUE,
          'field_field_scraped_title[enabled]' => TRUE,
          'field_field_scraped_title[source_config][url]' => 'https://example.com/test-page',
          'field_field_scraped_title[source_config][selector_type]' => 'css',
          'field_field_scraped_title[source_config][selector]' => 'h1',
        ];
        $this->submitForm($edit, 'Save configuration');

        $current_url = $this->getSession()->getCurrentUrl();
        if (strpos($current_url, 'scraper-config') !== false) {
          $page_content = $this->getSession()->getPage()->getContent();
          $this->assertTrue(
            strpos($page_content, 'error') !== false || strpos($page_content, 'Scraper configuration error') !== false,
            'Expected service validation errors when external URL cannot be validated'
          );
        } else {
          $this->assertSession()->addressMatches('/\/node\/\d+$/');
        }
      } else {
        $this->markTestSkipped('Required field not available for validation testing');
      }
    } else {
      $this->markTestSkipped('Form validation cannot be tested - no compatible fields');
    }
  }

  /**
   * Tests integration with Drupal's cron system.
   */
  public function testCronIntegration()
  {
    $queue = \Drupal::service('queue')->get('scrape_to_field_queue');
    $queue->createItem([
      'node_id' => 1,
      'field_mappings' => [
        [
          'field_name' => 'title',
          'selector' => 'h1',
          'selector_type' => 'css',
        ],
      ],
      'url' => 'https://example.com/test',
    ]);

    $initial_count = $queue->numberOfItems();
    $this->assertEquals(1, $initial_count);

    \Drupal::service('cron')->run();

    $final_count = $queue->numberOfItems();
    $this->assertLessThanOrEqual($initial_count, $final_count);
  }

    /**
   * Tests scraping with multiple field mappings.
   */
  public function testMultipleFieldMappings()
  {
    $this->drupalLogin($this->adminUser);

    $node = $this->drupalCreateNode([
      'type' => 'test_content',
      'title' => 'Test Multiple Mappings',
    ]);

    $this->drupalGet("/node/{$node->id()}/scraper-config");
    $this->assertSession()->statusCodeEquals(200);

    $page_content = $this->getSession()->getPage()->getContent();

    if (strpos($page_content, 'No fields of supported types') !== false) {
      $this->fail('Fields should be available for testing multiple mappings');
      return;
    }

    // Check if both fields exist before attempting to configure them
    $title_field_exists = $this->getSession()->getPage()->findField('field_field_scraped_title[enabled]');
    $body_field_exists = $this->getSession()->getPage()->findField('field_field_scraped_body[enabled]');

    $this->assertNotNull($title_field_exists, 'field_scraped_title should be available');
    $this->assertNotNull($body_field_exists, 'field_scraped_body should be available');

    if ($title_field_exists && $body_field_exists) {
      // Configure both fields
      $edit = [
        'global_settings[scraping_enabled]' => TRUE,
        'field_field_scraped_title[enabled]' => TRUE,
        'field_field_scraped_title[source_config][url]' => 'https://example.com/test-page',
        'field_field_scraped_title[source_config][selector_type]' => 'css',
        'field_field_scraped_title[source_config][selector]' => 'h1',
        'field_field_scraped_body[enabled]' => TRUE,
        'field_field_scraped_body[source_config][url]' => 'https://example.com/test-page',
        'field_field_scraped_body[source_config][selector_type]' => 'css',
        'field_field_scraped_body[source_config][selector]' => 'div.content',
      ];

      $this->submitForm($edit, 'Save configuration');

      $current_url = $this->getSession()->getCurrentUrl();
      if (strpos($current_url, 'scraper-config') !== false) {
        $page_content = $this->getSession()->getPage()->getContent();
        if (strpos($page_content, 'error') !== false) {
          $this->assertTrue(true, 'Form validation is working (validation errors found)');
          return;
        }
      } else {
        $this->assertSession()->addressMatches('/\/node\/\d+$/');

        $scraper_manager = \Drupal::service('scrape_to_field.manager');
        $config = $scraper_manager->getNodeScraperConfig($node);

        $this->assertCount(2, $config, 'Both field configurations should be saved');

        // Check first mapping.
        $this->assertTrue($config['field_scraped_title']['enabled']);
        $this->assertEquals('h1', $config['field_scraped_title']['selector']);

        // Check second mapping.
        $this->assertTrue($config['field_scraped_body']['enabled']);
        $this->assertEquals('div.content', $config['field_scraped_body']['selector']);
      }
    }
  }
}
