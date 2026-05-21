<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
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
   * The target URL policy.
   */
  protected TargetUrlPolicy $targetUrlPolicy;

  /**
   * The scrape rate limiter.
   */
  protected ScrapeRateLimiter $rateLimiter;

  /**
   * Constructs a WebScraperService object.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, UserAgentService $user_agent_service, ScraperActivityLogger $scraper_logger, DataCleaningService $data_cleaning_service, TargetUrlPolicy $target_url_policy, ScrapeRateLimiter $rate_limiter) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->userAgentService = $user_agent_service;
    $this->scraperLogger = $scraper_logger;
    $this->dataCleaningService = $data_cleaning_service;
    $this->targetUrlPolicy = $target_url_policy;
    $this->rateLimiter = $rate_limiter;
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
    $validation = $this->validateScrapeConfigSyntax($url, $selector, $selector_type);
    if (!$validation['valid']) {
      if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $this->scraperLogger->logInvalidUrl($url);
      }
      elseif (empty(trim($selector))) {
        $this->scraperLogger->logEmptySelector($url);
      }
      elseif (!in_array($selector_type, ['css', 'xpath'], TRUE)) {
        $this->scraperLogger->logInvalidSelectorType($selector_type, $url);
      }
      else {
        $this->scraperLogger->logInvalidUrl($url);
      }
      return NULL;
    }

    try {
      // Get global scraper settings.
      $config = $this->configFactory->get('scrape_to_field.settings');
      $user_agent = $this->userAgentService->getRandomUserAgent();
      $timeout = $this->getTimeout($config, $options);
      $is_test = !empty($options['test_mode']);
      $host = $this->targetUrlPolicy->getHost($url);

      if (!$is_test && !$this->rateLimiter->claim($host)) {
        throw new \RuntimeException('Per-host scrape rate limit exceeded.');
      }

      $response = $this->requestWithRetry($url, $user_agent, $timeout, $config, $options);
      $html = $this->readHtmlResponse($response, $config);

      if ($html === '') {
        return [];
      }

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
      $max_results = $this->getPositiveInteger($options['max_results'] ?? $config->get('max_results') ?? 50, 50, 1, 500);
      $nodes = $nodes->slice(0, $max_results);

      $nodes->each(function (Crawler $node) use (&$data, $extract_method, $attribute) {
        switch ($extract_method) {
          case 'text':
            $data[] = $node->text();
            break;

          case 'html':
            $data[] = $node->html();
            break;

          case 'attribute':
            $attribute_value = $node->attr($attribute);
            if ($attribute_value !== NULL) {
              $data[] = $attribute_value;
            }
            break;

          default:
            $data[] = $node->text();
        }
      });

      // Apply cleaning operations.
      if (!empty($options['cleaning_operations'])) {
        $data = $this->dataCleaningService->applyCleaningOperations($data, $options['cleaning_operations']);
      }

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
    return $this->validateScrapeConfigSyntax($url, $selector, $selector_type);
  }

  /**
   * Validates URL and selector syntax without making an HTTP request.
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
  public function validateScrapeConfigSyntax(string $url, string $selector, string $selector_type = 'css'): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return [
        'valid' => FALSE,
        'message' => new TranslatableMarkup('Invalid URL format.'),
      ];
    }

    try {
      $this->targetUrlPolicy->assertAllowed($url);
    }
    catch (\InvalidArgumentException $exception) {
      return [
        'valid' => FALSE,
        'message' => $exception->getMessage(),
      ];
    }

    if (empty(trim($selector))) {
      return [
        'valid' => FALSE,
        'message' => new TranslatableMarkup('Selector cannot be empty.'),
      ];
    }

    if (!in_array($selector_type, ['css', 'xpath'], TRUE)) {
      return [
        'valid' => FALSE,
        'message' => new TranslatableMarkup('Selector type must be either CSS or XPath.'),
      ];
    }

    return [
      'valid' => TRUE,
      'message' => new TranslatableMarkup('Configuration syntax is valid.'),
    ];
  }

  /**
   * Performs an HTTP request with bounded retries.
   */
  protected function requestWithRetry(string $url, string $user_agent, int $timeout, $config, array $options): ResponseInterface {
    $max_retries = $this->getPositiveInteger($options['max_retries'] ?? $config->get('max_retries') ?? 2, 2, 0, 5);
    $retry_delay = $this->getPositiveInteger($options['retry_delay'] ?? $config->get('retry_delay') ?? 1, 1, 0, 30);
    $request_options = $this->buildRequestOptions($user_agent, $timeout);
    $is_test = !empty($options['test_mode']);
    $attempt = 0;

    while (TRUE) {
      try {
        return $this->httpClient->request('GET', $url, $request_options);
      }
      catch (RequestException $exception) {
        if ($attempt >= $max_retries || !$this->isRetryableRequestException($exception)) {
          throw $exception;
        }

        $this->delayRetry($retry_delay, $attempt, $is_test);
        $attempt++;
      }
    }
  }

  /**
   * Builds secure Guzzle request options.
   */
  protected function buildRequestOptions(string $user_agent, int $timeout): array {
    $request_options = [
      'timeout' => $timeout,
      'connect_timeout' => min(5, $timeout),
      'allow_redirects' => [
        'max' => 3,
        'protocols' => ['https'],
        'strict' => TRUE,
        'referer' => FALSE,
        'on_redirect' => function ($request, $response, $uri): void {
          $this->targetUrlPolicy->assertAllowed((string) $uri);
        },
      ],
      'headers' => [
        'User-Agent' => $user_agent,
        'Accept' => 'text/html,application/xhtml+xml',
      ],
      'verify' => TRUE,
    ];

    $curl_options = $this->getCurlTlsOptions();
    if ($curl_options !== []) {
      $request_options['curl'] = $curl_options;
    }

    return $request_options;
  }

  /**
   * Gets cURL options enforcing TLS 1.2 or newer when cURL is available.
   */
  protected function getCurlTlsOptions(): array {
    if (!defined('CURLOPT_SSLVERSION') || !defined('CURL_SSLVERSION_TLSv1_2')) {
      return [];
    }

    return [
      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
    ];
  }

  /**
   * Reads and validates an HTML response body within configured limits.
   */
  protected function readHtmlResponse(ResponseInterface $response, $config): string {
    $content_type = strtolower($response->getHeaderLine('Content-Type'));
    if ($content_type !== ''
      && !str_contains($content_type, 'text/html')
      && !str_contains($content_type, 'application/xhtml+xml')
    ) {
      throw new \RuntimeException('Unsupported response content type.');
    }

    $max_bytes = $this->getPositiveInteger($config->get('max_response_bytes') ?? 1048576, 1048576, 1024, 10485760);
    $content_length = $response->getHeaderLine('Content-Length');
    if ($content_length !== '' && (int) $content_length > $max_bytes) {
      throw new \RuntimeException('Response body exceeds the configured limit.');
    }

    $body = $response->getBody();
    $html = $body->read($max_bytes + 1);
    if (strlen($html) > $max_bytes) {
      throw new \RuntimeException('Response body exceeds the configured limit.');
    }

    return $html;
  }

  /**
   * Gets the request timeout from options or configuration.
   */
  protected function getTimeout($config, array $options): int {
    return $this->getPositiveInteger($options['timeout'] ?? $config->get('timeout') ?? 30, 30, 1, 120);
  }

  /**
   * Checks whether a request exception is safe to retry.
   */
  protected function isRetryableRequestException(RequestException $exception): bool {
    $response = $exception->getResponse();
    if ($response === NULL) {
      return TRUE;
    }

    return in_array($response->getStatusCode(), [408, 429, 500, 502, 503, 504], TRUE);
  }

  /**
   * Delays a retry using exponential backoff.
   */
  protected function delayRetry(int $retry_delay, int $attempt, bool $is_test): void {
    if ($retry_delay <= 0 || $is_test) {
      return;
    }

    $delay = min($retry_delay * (2 ** $attempt), 30);
    usleep($delay * 1000000);
  }

  /**
   * Normalizes a positive integer within bounds.
   */
  protected function getPositiveInteger(mixed $value, int $default, int $min, int $max): int {
    $integer = is_numeric($value) ? (int) $value : $default;
    return max($min, min($max, $integer));
  }

}
