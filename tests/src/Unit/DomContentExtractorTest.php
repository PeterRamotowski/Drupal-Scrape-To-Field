<?php

namespace Drupal\Tests\scrape_to_field\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\scrape_to_field\Service\DomContentExtractor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DomContentExtractor class.
 */
class DomContentExtractorTest extends TestCase {

  /**
   * The extractor under test.
   */
  protected DomContentExtractor $extractor;

  /**
   * Mock config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface|MockObject $configFactory;

  /**
   * Mock config.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Config|MockObject $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(Config::class);
    $this->config->method('get')->willReturnMap([
      ['max_results', 50],
    ]);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->willReturn($this->config);

    $this->extractor = new DomContentExtractor($this->configFactory);
  }

  /**
   * Tests CSS selector extraction returns text content.
   */
  public function testCssSelectorExtractsText(): void {
    $html = '<html><body><h1>Test Title</h1></body></html>';

    $result = $this->extractor->extract($html, 'h1', 'css');

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertEquals('Test Title', $result[0]);
  }

  /**
   * Tests XPath selector extraction returns text content.
   */
  public function testXpathSelectorExtractsText(): void {
    $html = '<html><body><h1 class="title">XPath Title</h1></body></html>';

    $result = $this->extractor->extract($html, '//h1[@class="title"]', 'xpath');

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertEquals('XPath Title', $result[0]);
  }

  /**
   * Tests extraction of HTML content.
   */
  public function testHtmlExtractionMethod(): void {
    $html = '<html><body><div><span>inner</span></div></body></html>';

    $result = $this->extractor->extract($html, 'div', 'css', ['extract_method' => 'html']);

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertStringContainsString('<span>', $result[0]);
  }

  /**
   * Tests extraction of attribute values.
   */
  public function testAttributeExtractionMethod(): void {
    $html = '<html><body><a href="https://example.com">Link</a></body></html>';

    $result = $this->extractor->extract($html, 'a', 'css', [
      'extract_method' => 'attribute',
      'attribute' => 'href',
    ]);

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertEquals('https://example.com', $result[0]);
  }

  /**
   * Tests that no matching elements returns an empty array.
   */
  public function testNoMatchingElements(): void {
    $html = '<html><body><p>No heading here</p></body></html>';

    $result = $this->extractor->extract($html, 'h1', 'css');

    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests that max_results option limits the returned array.
   */
  public function testMaxResultsLimitsOutput(): void {
    $html = '<html><body><p>1</p><p>2</p><p>3</p><p>4</p><p>5</p></body></html>';

    $result = $this->extractor->extract($html, 'p', 'css', ['max_results' => 3]);

    $this->assertIsArray($result);
    $this->assertCount(3, $result);
  }

  /**
   * Tests that null attributes are not included in results.
   */
  public function testNullAttributeNotIncluded(): void {
    $html = '<html><body><a href="https://example.com">Link 1</a><a>Link 2</a></body></html>';

    $result = $this->extractor->extract($html, 'a', 'css', [
      'extract_method' => 'attribute',
      'attribute' => 'href',
    ]);

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertEquals('https://example.com', $result[0]);
  }

}
