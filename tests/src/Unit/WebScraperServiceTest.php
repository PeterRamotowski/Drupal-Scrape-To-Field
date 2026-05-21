<?php

namespace Drupal\Tests\scrape_to_field\Unit;

use Psr\Http\Message\RequestInterface;
use Drupal\scrape_to_field\Service\WebScraperService;
use Drupal\scrape_to_field\Service\UserAgentService;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Drupal\scrape_to_field\Service\DataCleaningService;
use Drupal\scrape_to_field\Service\ScrapeRateLimiter;
use Drupal\scrape_to_field\Service\TargetUrlPolicy;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the WebScraperService class.
 */
class WebScraperServiceTest extends TestCase {

  /**
   * The web scraper service under test.
   *
   * @var \Drupal\scrape_to_field\Service\WebScraperService
   */
  protected WebScraperService $scraperService;

  /**
   * Mock HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ClientInterface|MockObject $httpClient;

  /**
   * Mock config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface|MockObject $configFactory;

  /**
   * Mock config object.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Config|MockObject $config;

  /**
   * Mock user agent service.
   *
   * @var \Drupal\scrape_to_field\Service\UserAgentService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected UserAgentService|MockObject $userAgentService;

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
   * Mock target URL policy.
   *
   * @var \Drupal\scrape_to_field\Service\TargetUrlPolicy|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TargetUrlPolicy|MockObject $targetUrlPolicy;

  /**
   * Mock scrape rate limiter.
   *
   * @var \Drupal\scrape_to_field\Service\ScrapeRateLimiter|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ScrapeRateLimiter|MockObject $rateLimiter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(Config::class);
    $this->userAgentService = $this->createMock(UserAgentService::class);
    $this->scraperLogger = $this->createMock(ScraperActivityLogger::class);
    $this->dataCleaningService = $this->createMock(DataCleaningService::class);
    $this->targetUrlPolicy = $this->createMock(TargetUrlPolicy::class);
    $this->rateLimiter = $this->createMock(ScrapeRateLimiter::class);

    $this->configFactory
      ->method('get')
      ->with('scrape_to_field.settings')
      ->willReturn($this->config);

    $this->config
      ->method('get')
      ->willReturnMap([
        ['timeout', 30],
        ['max_retries', 0],
        ['retry_delay', 0],
        ['user_agent_rotation', TRUE],
        ['max_response_bytes', 1048576],
        ['max_results', 50],
      ]);

    $this->userAgentService
      ->method('getRandomUserAgent')
      ->willReturn('Test User Agent 1.0');

    $this->dataCleaningService
      ->method('applyCleaningOperations')
      ->willReturnArgument(0);

    $this->targetUrlPolicy
      ->method('assertAllowed')
      ->willReturnCallback(function (string $url): void {
        if (!str_starts_with($url, 'https://')) {
          throw new \InvalidArgumentException('Only https:// URLs are permitted.');
        }
      });

    $this->targetUrlPolicy
      ->method('getHost')
      ->willReturn('example.com');

    $this->rateLimiter
      ->method('claim')
      ->willReturn(TRUE);

    $this->scraperService = new WebScraperService(
      $this->httpClient,
      $this->configFactory,
      $this->userAgentService,
      $this->scraperLogger,
      $this->dataCleaningService,
      $this->targetUrlPolicy,
      $this->rateLimiter
    );
  }

  /**
   * Tests successful CSS selector scraping.
   */
  public function testSuccessfulCssSelectorScraping() {
    $html = '<html><body><h1>Test Title</h1><p>Test content</p></body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->method('request')
      ->willReturn($response);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE]
    );

    $this->assertIsArray($result);
    $this->assertNotEmpty($result);
    $this->assertEquals('Test Title', $result[0]);
  }

  /**
   * Tests successful XPath selector scraping.
   */
  public function testSuccessfulXpathSelectorScraping() {
    $html = '<html><body><h1 class="title">XPath Title</h1></body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->method('request')
      ->willReturn($response);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      '//h1[@class="title"]',
      'xpath',
      ['test_mode' => TRUE]
    );

    $this->assertIsArray($result);
    $this->assertNotEmpty($result);
    $this->assertEquals('XPath Title', $result[0]);
  }

  /**
   * Tests scraping with invalid URL.
   */
  public function testScrapingWithInvalidUrl() {
    $this->scraperLogger
      ->expects($this->once())
      ->method('logInvalidUrl')
      ->with('not-a-valid-url');

    $result = $this->scraperService->scrapeData(
      'not-a-valid-url',
      'h1',
      'css'
    );

    $this->assertNull($result);
  }

  /**
   * Tests scraping with empty selector.
   */
  public function testScrapingWithEmptySelector() {
    $this->scraperLogger
      ->expects($this->once())
      ->method('logEmptySelector')
      ->with('https://example.com/test');

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      '',
      'css'
    );

    $this->assertNull($result);
  }

  /**
   * Tests scraping with invalid selector type.
   */
  public function testScrapingWithInvalidSelectorType() {
    $this->scraperLogger
      ->expects($this->once())
      ->method('logInvalidSelectorType')
      ->with('invalid', 'https://example.com/test');

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'invalid'
    );

    $this->assertNull($result);
  }

  /**
   * Tests handling of HTTP request exceptions.
   */
  public function testHttpRequestException() {
    $exception = new RequestException(
      'Connection timeout',
      $this->createMock(RequestInterface::class)
    );

    $this->httpClient
      ->method('request')
      ->willThrowException($exception);

    $this->scraperLogger
      ->expects($this->once())
      ->method('logRequestFailure')
      ->with('https://example.com/test', 'Connection timeout');

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css'
    );

    $this->assertNull($result);
  }

  /**
   * Tests scraping with no matching elements.
   */
  public function testScrapingWithNoMatchingElements() {
    $html = '<html><body><p>No heading here</p></body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->method('request')
      ->willReturn($response);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE]
    );

    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests scraping with multiple matching elements.
   */
  public function testScrapingWithMultipleMatchingElements() {
    $html = '<html><body><h1>Title 1</h1><h1>Title 2</h1><h1>Title 3</h1></body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->method('request')
      ->willReturn($response);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE]
    );

    $this->assertIsArray($result);
    $this->assertCount(3, $result);
    $this->assertEquals('Title 1', $result[0]);
    $this->assertEquals('Title 2', $result[1]);
    $this->assertEquals('Title 3', $result[2]);
  }

  /**
   * Tests scraping with custom cleaning operations.
   */
  public function testScrapingWithCustomCleaningOperations() {
    $html = '<html><body><h1>Original Title</h1></body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->method('request')
      ->willReturn($response);

    $cleaning_operations = [
      ['search' => 'Original', 'replace' => 'Modified'],
    ];

    // Override the default mock behavior for this specific test.
    $this->dataCleaningService = $this->createMock(DataCleaningService::class);
    $this->dataCleaningService
      ->expects($this->once())
      ->method('applyCleaningOperations')
      ->with(['Original Title'], $cleaning_operations)
      ->willReturn(['Modified Title']);

    // Create a new service instance for this test with the overridden mock.
    $scraperService = new WebScraperService(
      $this->httpClient,
      $this->configFactory,
      $this->userAgentService,
      $this->scraperLogger,
      $this->dataCleaningService,
      $this->targetUrlPolicy,
      $this->rateLimiter
    );

    $result = $scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['cleaning_operations' => $cleaning_operations, 'test_mode' => TRUE]
    );

    $this->assertIsArray($result);
    $this->assertEquals('Modified Title', $result[0]);
  }

  /**
   * Tests HTTP client request configuration.
   */
  public function testHttpClientRequestConfiguration() {
    $html = '<html><body><h1>Test</h1></body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://example.com/test',
        $this->callback(function ($options) {
          return $options['timeout'] === 30 &&
                 $options['connect_timeout'] === 5 &&
                 $options['verify'] === TRUE &&
                 isset($options['allow_redirects']['on_redirect']) &&
                 $options['headers']['User-Agent'] === 'Test User Agent 1.0' &&
                 isset($options['headers']['Accept']);
        })
      )
      ->willReturn($response);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE]
    );

    $this->assertIsArray($result);
  }

  /**
   * Tests malformed HTML handling.
   */
  public function testMalformedHtmlHandling() {
    $malformed_html = '<html><body><h1>Unclosed title<p>Content</body>';
    $response = new Response(200, [], $malformed_html);

    $this->httpClient
      ->method('request')
      ->willReturn($response);

    $result = $this->scraperService->scrapeData(
      'https://example.com/test',
      'h1',
      'css',
      ['test_mode' => TRUE]
    );

    $this->assertIsArray($result);
    $this->assertEquals('Unclosed title', $result[0]);
  }

  /**
   * Tests handling of non-HTML responses.
   */
  public function testNonHtmlResponseHandling() {
    $json_response = '{"title": "JSON Title"}';
    $response = new Response(200, ['Content-Type' => 'application/json'], $json_response);

    $this->httpClient
      ->method('request')
      ->willReturn($response);

    $result = $this->scraperService->scrapeData(
      'https://example.com/api/data',
      'h1',
      'css',
      ['test_mode' => TRUE]
    );

    $this->assertNull($result);
  }

}
