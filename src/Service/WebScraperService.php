<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Web scraper service for extracting data from external websites.
 */
class WebScraperService {

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The user agent service.
   */
  protected UserAgentService $userAgentService;

  /**
   * The scraper activity logger.
   */
  protected ScraperActivityLogger $scraperLogger;

  /**
   * The data cleaning service.
   */
  protected DataCleaningService $dataCleaningService;

  /**
   * Constructs a WebScraperService object.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, UserAgentService $user_agent_service, ScraperActivityLogger $scraper_logger, DataCleaningService $data_cleaning_service) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->userAgentService = $user_agent_service;
    $this->scraperLogger = $scraper_logger;
    $this->dataCleaningService = $data_cleaning_service;
  }

  /**
   * Scrapes data from a given URL using CSS selector or XPath.
   *
   * @param string $url
   *   The URL to scrape.
   * @param string $selector
   *   CSS selector or XPath expression.
   * @param string $selector_type
   *   Type of selector: 'css' or 'xpath'.
   * @param array $options
   *   Additional scraping options.
   *
   * @return array|null
   *   Scraped data or NULL on failure.
   */
  public function scrapeData(string $url, string $selector, string $selector_type = 'css', array $options = []): ?array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $this->scraperLogger->logInvalidUrl($url);
      return NULL;
    }

    if (empty(trim($selector))) {
      $this->scraperLogger->logEmptySelector($url);
      return NULL;
    }

    if (!in_array($selector_type, ['css', 'xpath'])) {
      $this->scraperLogger->logInvalidSelectorType($selector_type, $url);
      return NULL;
    }

    try {
      // Get global scraper settings.
      $config = $this->configFactory->get('scrape_to_field.settings');
      $user_agent = $this->userAgentService->getRandomUserAgent();
      $timeout = $config->get('timeout') ?: 30;

      // Make HTTP request.
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => $timeout,
        'headers' => [
          'User-Agent' => $user_agent,
          'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
        'verify' => $config->get('verify_ssl') ?? TRUE,
      ]);

      $html = (string) $response->getBody();

      $crawler = new Crawler($html);

      // Extract data based on selector type.
      $data = [];
      if ($selector_type === 'xpath') {
        $nodes = $crawler->filterXPath($selector);
      }
      else {
        $nodes = $crawler->filter($selector);
      }

      // Process extraction method.
      $extract_method = $options['extract_method'] ?? 'text';
      $attribute = $options['attribute'] ?? 'href';

      $nodes->each(function (Crawler $node) use (&$data, $extract_method, $attribute) {
        switch ($extract_method) {
          case 'text':
            $data[] = $node->text();
            break;

          case 'html':
            $data[] = $node->html();
            break;

          case 'attribute':
            $data[] = $node->attr($attribute);
            break;

          default:
            $data[] = $node->text();
        }
      });

      // Apply cleaning operations.
      if (!empty($options['cleaning_operations'])) {
        $data = $this->dataCleaningService->applyCleaningOperations($data, $options['cleaning_operations']);
      }

      $is_test = $options['test_mode'] ?? FALSE;
      if (!$is_test) {
        $this->scraperLogger->logScrapingSuccess($url, count($data));
      }

      return $data;
    }
    catch (RequestException $e) {
      $this->scraperLogger->logRequestFailure($url, $e->getMessage());
      return NULL;
    }
    catch (\Exception $e) {
      $this->scraperLogger->logUnexpectedError($url, $e->getMessage());
      return NULL;
    }
  }

  /**
   * Validates a URL and selector combination.
   *
   * @param string $url
   *   The URL to validate.
   * @param string $selector
   *   The selector to validate.
   * @param string $selector_type
   *   The selector type.
   *
   * @return array
   *   Validation result with 'valid' boolean and 'message'.
   */
  public function validateScrapeConfig(string $url, string $selector, string $selector_type = 'css'): array {
    // Validate URL.
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return [
        'valid' => FALSE,
        'message' => 'Invalid URL format',
      ];
    }

    // Basic selector validation.
    if (empty(trim($selector))) {
      return [
        'valid' => FALSE,
        'message' => 'Selector cannot be empty',
      ];
    }

    // Try a test scrape with limited timeout.
    try {
      $test_data = $this->scrapeData($url, $selector, $selector_type, [
        'timeout' => 10,
        'test_mode' => TRUE,
      ]);
      if ($test_data === NULL) {
        return [
          'valid' => FALSE,
          'message' => 'Failed to connect to URL or selector returned no results',
        ];
      }
      return [
        'valid' => TRUE,
        'message' => 'Configuration is valid',
      ];
    }
    catch (\Exception $e) {
      return [
        'valid' => FALSE,
        'message' => 'Test scraping failed: ' . Html::escape($e->getMessage() ?? 'Unknown error'),
      ];
    }
  }

}
