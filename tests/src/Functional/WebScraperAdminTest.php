<?php

namespace Drupal\Tests\scrape_to_field\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the web scraper administration interface.
 */
class WebScraperAdminTest extends BrowserTestBase
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
   * A regular user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $this->createContentType(['type' => 'article']);

    // Add a test field for scraping.
    $field_storage = \Drupal::entityTypeManager()->getStorage('field_storage_config')->create([
      'field_name' => 'field_test_scraper',
      'entity_type' => 'node',
      'type' => 'string',
    ]);
    $field_storage->save();

    $field_config = \Drupal::entityTypeManager()->getStorage('field_config')->create([
      'field_name' => 'field_test_scraper',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Test Scraper Field',
    ]);
    $field_config->save();

    $this->adminUser = $this->drupalCreateUser([
      'administer scrape to field',
      'configure any node scrape to field',
      'create article content',
      'edit any article content',
      'access administration pages',
    ]);

    $this->regularUser = $this->drupalCreateUser([
      'create article content',
      'edit own article content',
    ]);
  }

  /**
   * Tests access to scraper configuration pages.
   */
  public function testScraperConfigurationAccess()
  {
    // Test anonymous user cannot access admin pages.
    $this->drupalGet('/admin/config/content/web-scraper');
    $this->assertSession()->statusCodeEquals(403);

    // Test regular user cannot access admin pages.
    $this->drupalLogin($this->regularUser);
    $this->drupalGet('/admin/config/content/web-scraper');
    $this->assertSession()->statusCodeEquals(403);

    // Test admin user can access admin pages.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/content/web-scraper');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Scrape to field Settings');
  }

  /**
   * Tests the global scraper settings form.
   */
  public function testGlobalScraperSettings()
  {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/content/web-scraper');

    $this->assertSession()->fieldExists('timeout');
    $this->assertSession()->fieldExists('verify_ssl');
    $this->assertSession()->fieldExists('enable_cron');
    $this->assertSession()->fieldExists('cron_frequency');
  }

  /**
   * Tests the node-specific scraper configuration.
   */
  public function testNodeScraperConfiguration()
  {
    $this->drupalLogin($this->adminUser);

    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Test Article']);

    // Go to node scraper config page.
    $this->drupalGet("/node/{$node->id()}/scraper-config");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Configure scrape to field');

    // Test global settings are present.
    $this->assertSession()->fieldExists('global_settings[scraping_enabled]');
    $this->assertSession()->buttonExists('Save configuration');

    $edit = [
      'global_settings[scraping_enabled]' => TRUE,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    // Verify configuration was saved.
    $this->drupalGet("/node/{$node->id()}/scraper-config");
    $this->assertSession()->checkboxChecked('global_settings[scraping_enabled]');
  }

  /**
   * Tests permissions and access control.
   */
  public function testPermissionsAndAccess()
  {
    // Create a user with limited permissions.
    $limited_user = $this->drupalCreateUser([
      'configure any node scrape to field',
      'create article content',
      'edit any article content',
    ]);

    $this->drupalLogin($limited_user);

    // Test user cannot access global admin settings.
    $this->drupalGet('/admin/config/content/web-scraper');
    $this->assertSession()->statusCodeEquals(403);

    // Test user can access node-specific scraper configuration.
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Test Article']);
    $this->drupalGet("/node/{$node->id()}/scraper-config");
    $this->assertSession()->statusCodeEquals(200);

    // Create a user with no scraper permissions.
    $no_scraper_user = $this->drupalCreateUser([
      'create article content',
      'edit any article content',
    ]);

    $this->drupalLogin($no_scraper_user);

    // Test user cannot access node-specific scraper configuration.
    $this->drupalGet("/node/{$node->id()}/scraper-config");
    $this->assertSession()->statusCodeEquals(403);
  }


}
