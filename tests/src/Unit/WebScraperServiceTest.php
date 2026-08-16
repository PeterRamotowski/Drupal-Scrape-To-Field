<?php

namespace Drupal\Tests\scrape_to_field\Unit;

use Drupal\scrape_to_field\DTO\ValidationResult;
use Drupal\scrape_to_field\Service\DataCleaningService;
use Drupal\scrape_to_field\Service\DomContentExtractorInterface;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Drupal\scrape_to_field\Service\ScraperConfigValidatorInterface;
use Drupal\scrape_to_field\Service\ScraperHttpClientInterface;
use Drupal\scrape_to_field\Service\WebScraperService;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Tests the WebScraperService orchestration logic.
 */
class WebScraperServiceTest extends TestCase {

  /**
   * The web scraper service under test.
   */
  protected WebScraperService $scraperService;

  /**
   * Mock HTTP client.
   *
   * @var \Drupal\scrape_to_field\Service\ScraperHttpClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ScraperHttpClientInterface|MockObject $httpClient;

  /**
   * Mock DOM extractor.
   *
   * @var \Drupal\scrape_to_field\Service\DomContentExtractorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected DomContentExtractorInterface|MockObject $domExtractor;

  /**
   * Mock config validator.
   *
   * @var \Drupal\scrape_to_field\Service\ScraperConfigValidatorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ScraperConfigValidatorInterface|MockObject $configValidator;

  /**
   * Mock scraper activity logger.
   *
   * @var \Drupal\scrape_to_field\Service\ScraperActivityLogger|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ScraperActivityLogger|MockObject $scraperLogger;

  /**
   * Mock data cleaning service.
   *
   * @var \Drupal\scrape_to_field\Service\DataCleaningService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected DataCleaningService|MockObject $dataCleaningService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ScraperHttpClientInterface::class);
    $this->domExtractor = $this->createMock(DomContentExtractorInterface::class);
    $this->configValidator = $this->createMock(ScraperConfigValidatorInterface::class);
    $this->scraperLogger = $this->createMock(ScraperActivityLogger::class);
    $this->dataCleaningService = $this->createMock(DataCleaningService::class);
    $this->dataCleaningService
      ->method('applyCleaningOperations')
      ->willReturnArgument(0);

    $this->scraperService = new WebScraperService(
      $this->httpClient,
      $this->domExtractor,
      $this->configValidator,
      $this->scraperLogger,
      $this->dataCleaningService,
    );
  }

  /**
   * Tests successful CSS selector scraping.
   */
  public function testSuccessfulCssSelectorScraping() {
    $html = '<html><body><h1>Test Title</h1></body></html>';

    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $this->httpClient
      ->method('fetchHtml')
      ->willReturn($html);

    $this->domExtractor
      ->method('extract')
      ->willReturn(['Test Title']);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE],
    );

    $this->assertIsArray($result);
    $this->assertNotEmpty($result);
    $this->assertEquals('Test Title', $result[0]);
  }

  /**
   * Tests successful XPath selector scraping.
   */
  public function testSuccessfulXpathSelectorScraping() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $this->httpClient
      ->method('fetchHtml')
      ->willReturn('<html><body><h1>XPath Title</h1></body></html>');

    $this->domExtractor
      ->method('extract')
      ->willReturn(['XPath Title']);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      '//h1[@class="title"]',
      'xpath',
      ['test_mode' => TRUE],
    );

    $this->assertIsArray($result);
    $this->assertNotEmpty($result);
    $this->assertEquals('XPath Title', $result[0]);
  }

  /**
   * Tests scraping with invalid URL.
   */
  public function testScrapingWithInvalidUrl() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::invalid('Invalid URL format.', ValidationResult::REASON_INVALID_URL));

    $this->scraperLogger
      ->expects($this->once())
      ->method('logInvalidUrl')
      ->with('not-a-valid-url');

    $result = $this->scraperService->scrapeData('not-a-valid-url', 'h1', 'css');

    $this->assertNull($result);
  }

  /**
   * Tests scraping with empty selector.
   */
  public function testScrapingWithEmptySelector() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::invalid('Selector cannot be empty.', ValidationResult::REASON_EMPTY_SELECTOR));

    $this->scraperLogger
      ->expects($this->once())
      ->method('logEmptySelector')
      ->with('https://example.com/test');

    $result = $this->scraperService->scrapeData('https://example.com/test', '', 'css');

    $this->assertNull($result);
  }

  /**
   * Tests scraping with invalid selector type.
   */
  public function testScrapingWithInvalidSelectorType() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::invalid('Selector type must be CSS or XPath.', ValidationResult::REASON_INVALID_SELECTOR_TYPE));

    $this->scraperLogger
      ->expects($this->once())
      ->method('logInvalidSelectorType')
      ->with('invalid', 'https://example.com/test');

    $result = $this->scraperService->scrapeData('https://example.com/test', 'h1', 'invalid');

    $this->assertNull($result);
  }

  /**
   * Tests handling of HTTP request exceptions.
   */
  public function testHttpRequestException() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $exception = new RequestException(
      'Connection timeout',
      $this->createMock(RequestInterface::class),
    );

    $this->httpClient
      ->method('fetchHtml')
      ->willThrowException($exception);

    $this->scraperLogger
      ->expects($this->once())
      ->method('logRequestFailure')
      ->with('https://example.com/test', 'Connection timeout');

    $result = $this->scraperService->scrapeData('https://example.com/test', 'h1', 'css');

    $this->assertNull($result);
  }

  /**
   * Tests scraping with no matching elements returns empty array.
   */
  public function testScrapingWithNoMatchingElements() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $this->httpClient
      ->method('fetchHtml')
      ->willReturn('<html><body><p>No heading here</p></body></html>');

    $this->domExtractor
      ->method('extract')
      ->willReturn([]);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE],
    );

    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests scraping with multiple matching elements.
   */
  public function testScrapingWithMultipleMatchingElements() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $this->httpClient
      ->method('fetchHtml')
      ->willReturn('<html><body></body></html>');

    $this->domExtractor
      ->method('extract')
      ->willReturn(['Title 1', 'Title 2', 'Title 3']);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE],
    );

    $this->assertIsArray($result);
    $this->assertCount(3, $result);
    $this->assertEquals('Title 1', $result[0]);
    $this->assertEquals('Title 2', $result[1]);
    $this->assertEquals('Title 3', $result[2]);
  }

  /**
   * Tests scraping with cleaning operations applied.
   */
  public function testScrapingWithCustomCleaningOperations() {
    $cleaning_operations = [['search' => 'Original', 'replace' => 'Modified']];

    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $this->httpClient
      ->method('fetchHtml')
      ->willReturn('<html><body></body></html>');

    $this->domExtractor
      ->method('extract')
      ->willReturn(['Original Title']);

    $this->dataCleaningService = $this->createMock(DataCleaningService::class);
    $this->dataCleaningService
      ->expects($this->once())
      ->method('applyCleaningOperations')
      ->with(['Original Title'], $cleaning_operations)
      ->willReturn(['Modified Title']);

    $scraperService = new WebScraperService(
      $this->httpClient,
      $this->domExtractor,
      $this->configValidator,
      $this->scraperLogger,
      $this->dataCleaningService,
    );

    $result = $scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['cleaning_operations' => $cleaning_operations, 'test_mode' => TRUE],
    );

    $this->assertIsArray($result);
    $this->assertEquals('Modified Title', $result[0]);
  }

  /**
   * Tests that empty HTML returns an empty array.
   */
  public function testEmptyHtmlReturnsEmptyArray() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $this->httpClient
      ->method('fetchHtml')
      ->willReturn('');

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE],
    );

    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests validateScrapeConfigSyntax backward compatibility.
   */
  public function testValidateScrapeConfigSyntaxDelegates() {
    $this->configValidator
      ->method('validate')
      ->willReturn(ValidationResult::valid());

    $result = $this->scraperService->validateScrapeConfigSyntax(
      'https://example.com',
      'h1',
      'css',
    );

    $this->assertIsBool($result['valid']);
    $this->assertTrue($result['valid']);
    $this->assertArrayHasKey('message', $result);
  }

}
