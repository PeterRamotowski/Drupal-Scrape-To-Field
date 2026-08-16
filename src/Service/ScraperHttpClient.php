<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client that fetches HTML pages for web scraping.
 *
 * Handles retry logic, TLS enforcement, redirect safety, User-Agent
 * rotation, and response-body size limits.
 */
class ScraperHttpClient implements ScraperHttpClientInterface {

  /**
   * Maximum number of redirects followed for one scrape request.
   */
  private const MAX_REDIRECTS = 3;

  /**
   * Constructs a ScraperHttpClient object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The underlying Guzzle HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\scrape_to_field\Service\UserAgentService $userAgentService
   *   The user-agent rotation service.
   * @param \Drupal\scrape_to_field\Service\TargetUrlPolicy $targetUrlPolicy
   *   The URL policy used to validate redirect targets.
   * @param \Drupal\scrape_to_field\Service\ScrapeRateLimiter|null $rateLimiter
   *   The per-host request rate limiter. NULL supports stale compiled service
   *   definitions during a deployment until Drupal's cache is rebuilt.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected UserAgentService $userAgentService,
    protected TargetUrlPolicy $targetUrlPolicy,
    protected ?ScrapeRateLimiter $rateLimiter = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function fetchHtml(string $url, array $options = []): string {
    $config = $this->configFactory->get('scrape_to_field.settings');
    $user_agent = $this->userAgentService->getRandomUserAgent();
    $timeout = $this->getTimeout($config, $options);

    $response = $this->requestWithRetry($url, $user_agent, $timeout, $config, $options);
    return $this->readHtmlResponse($response, $config);
  }

  /**
   * Performs an HTTP GET with bounded retries and exponential back-off.
   *
   * @param string $url
   *   The URL to request.
   * @param string $user_agent
   *   The User-Agent header value.
   * @param int $timeout
   *   The per-request timeout in seconds.
   * @param mixed $config
   *   The module configuration object.
   * @param array $options
   *   Runtime options (max_retries, retry_delay, test_mode).
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The successful HTTP response.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   Re-thrown when retries are exhausted or the error is non-retryable.
   */
  protected function requestWithRetry(
    string $url,
    string $user_agent,
    int $timeout,
    mixed $config,
    array $options,
  ): ResponseInterface {
    $max_retries = $this->getPositiveInteger(
      $options['max_retries'] ?? $config->get('max_retries') ?? 2,
      2,
      0,
      5,
    );
    $retry_delay = $this->getPositiveInteger(
      $options['retry_delay'] ?? $config->get('retry_delay') ?? 1,
      1,
      0,
      30,
    );
    $is_test = !empty($options['test_mode']);
    $attempt = 0;

    while (TRUE) {
      try {
        return $this->requestRedirectChain($url, $user_agent, $timeout, $is_test);
      }
      catch (RequestException $exception) {
        if ($attempt >= $max_retries || !$this->isRetryableRequestException($exception)) {
          throw $exception;
        }

        $this->delayRetry($retry_delay, $attempt, $is_test);
        $attempt++;
      }
      catch (ConnectException $exception) {
        if ($attempt >= $max_retries) {
          throw $exception;
        }

        $this->delayRetry($retry_delay, $attempt, $is_test);
        $attempt++;
      }
    }
  }

  /**
   * Follows redirects while validating and pinning every target host.
   *
   * @param string $url
   *   The initial URL.
   * @param string $user_agent
   *   The User-Agent header value.
   * @param int $timeout
   *   The request timeout in seconds.
   * @param bool $is_test
   *   Whether per-host pacing should be skipped for an interactive test.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The final response.
   *
   * @throws \RuntimeException
   *   Thrown when the redirect limit is exceeded.
   */
  protected function requestRedirectChain(
    string $url,
    string $user_agent,
    int $timeout,
    bool $is_test,
  ): ResponseInterface {
    $current_url = $url;
    $claimed_hosts = [];

    for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
      $current_host = $this->targetUrlPolicy->getHost($current_url);
      if (!$is_test
        && $this->rateLimiter !== NULL
        && !isset($claimed_hosts[$current_host])
      ) {
        $this->rateLimiter->claim($current_host);
        $claimed_hosts[$current_host] = TRUE;
      }

      $request_options = $this->buildRequestOptions(
        $current_url,
        $user_agent,
        $timeout,
      );
      $response = $this->httpClient->request(
        'GET',
        $current_url,
        $request_options,
      );

      if (!$this->isRedirectResponse($response)) {
        return $response;
      }

      if ($redirects === self::MAX_REDIRECTS) {
        $response->getBody()->close();
        throw new \RuntimeException('Maximum redirect count exceeded.');
      }

      $location = $response->getHeaderLine('Location');
      $response->getBody()->close();
      $current_url = (string) UriResolver::resolve(
        Utils::uriFor($current_url),
        Utils::uriFor($location),
      );
    }

    throw new \RuntimeException('Maximum redirect count exceeded.');
  }

  /**
   * Checks whether a response contains a redirect target.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The HTTP response.
   *
   * @return bool
   *   TRUE when the response is a supported redirect with a Location header.
   */
  protected function isRedirectResponse(ResponseInterface $response): bool {
    return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], TRUE)
      && $response->getHeaderLine('Location') !== '';
  }

  /**
   * Builds secure Guzzle request options for one validated target.
   *
   * @param string $url
   *   The target URL.
   * @param string $user_agent
   *   The User-Agent header value.
   * @param int $timeout
   *   The request timeout in seconds.
   *
   * @return array
   *   Guzzle request options array.
   */
  protected function buildRequestOptions(
    string $url,
    string $user_agent,
    int $timeout,
  ): array {
    $ip_addresses = $this->targetUrlPolicy->getAllowedIpAddresses($url);
    $host = $this->targetUrlPolicy->getHost($url);
    $request_options = [
      'timeout' => $timeout,
      'connect_timeout' => min(5, $timeout),
      'stream' => TRUE,
      'allow_redirects' => FALSE,
      'headers' => [
        'User-Agent' => $user_agent,
        'Accept' => 'text/html,application/xhtml+xml',
      ],
      'verify' => TRUE,
    ];

    $curl_options = $this->getCurlOptions($url, $host, $ip_addresses[0]);
    if ($curl_options !== []) {
      $request_options['curl'] = $curl_options;
    }

    return $request_options;
  }

  /**
   * Returns cURL options that enforce TLS and pin the validated address.
   *
   * @param string $url
   *   The validated URL.
   * @param string $host
   *   The normalized URL host.
   * @param string $ip_address
   *   The validated public address to pin.
   *
   * @return array
   *   cURL options for the request.
   *
   * @throws \RuntimeException
   *   Thrown when a hostname cannot be pinned securely.
   */
  protected function getCurlOptions(
    string $url,
    string $host,
    string $ip_address,
  ): array {
    if (!defined('CURLOPT_SSLVERSION') || !defined('CURL_SSLVERSION_TLSv1_2')) {
      throw new \RuntimeException('The cURL extension is required for secure scraping.');
    }

    $curl_options = [
      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
    ];

    if (filter_var($host, FILTER_VALIDATE_IP) === FALSE) {
      if (!defined('CURLOPT_RESOLVE')) {
        throw new \RuntimeException('DNS pinning is unavailable for scraping.');
      }

      $port = parse_url($url, PHP_URL_PORT) ?: 443;
      $resolved_address = str_contains($ip_address, ':')
        ? '[' . $ip_address . ']'
        : $ip_address;
      $curl_options[CURLOPT_RESOLVE] = [
        "{$host}:{$port}:{$resolved_address}",
      ];
    }

    return $curl_options;
  }

  /**
   * Reads and validates an HTML response body within configured limits.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The HTTP response.
   * @param mixed $config
   *   The module configuration object.
   *
   * @return string
   *   The response body.
   *
   * @throws \RuntimeException
   *   Thrown for unsupported content types or oversized bodies.
   */
  protected function readHtmlResponse(ResponseInterface $response, mixed $config): string {
    $content_type = strtolower($response->getHeaderLine('Content-Type'));
    if ($content_type !== ''
      && !str_contains($content_type, 'text/html')
      && !str_contains($content_type, 'application/xhtml+xml')
    ) {
      throw new \RuntimeException('Unsupported response content type.');
    }

    $max_bytes = $this->getPositiveInteger(
      $config->get('max_response_bytes') ?? 1048576,
      1048576,
      1024,
      10485760,
    );
    $content_length = $response->getHeaderLine('Content-Length');
    if ($content_length !== '' && (int) $content_length > $max_bytes) {
      throw new \RuntimeException('Response body exceeds the configured limit.');
    }

    $body = $response->getBody();
    $html = '';
    $bytes_read = 0;

    try {
      while (!$body->eof()) {
        $chunk = $body->read(min(8192, ($max_bytes + 1) - $bytes_read));
        if ($chunk === '' && !$body->eof()) {
          throw new \RuntimeException('Unable to read the response body.');
        }

        $bytes_read += strlen($chunk);
        if ($bytes_read > $max_bytes) {
          throw new \RuntimeException('Response body exceeds the configured limit.');
        }
        $html .= $chunk;
      }
    }
    finally {
      $body->close();
    }

    return $html;
  }

  /**
   * Gets the request timeout from options or configuration.
   *
   * @param mixed $config
   *   The module configuration object.
   * @param array $options
   *   Runtime options that may contain a 'timeout' key.
   *
   * @return int
   *   Timeout in seconds, clamped to 1–120.
   */
  protected function getTimeout(mixed $config, array $options): int {
    return $this->getPositiveInteger(
      $options['timeout'] ?? $config->get('timeout') ?? 30,
      30,
      1,
      120,
    );
  }

  /**
   * Checks whether a request exception is safe to retry.
   *
   * @param \GuzzleHttp\Exception\RequestException $exception
   *   The exception to inspect.
   *
   * @return bool
   *   TRUE when the exception corresponds to a transient server condition.
   */
  protected function isRetryableRequestException(RequestException $exception): bool {
    $response = $exception->getResponse();
    if ($response === NULL) {
      return TRUE;
    }

    return in_array($response->getStatusCode(), [408, 429, 500, 502, 503, 504], TRUE);
  }

  /**
   * Delays a retry using exponential back-off.
   *
   * @param int $retry_delay
   *   Base delay in seconds.
   * @param int $attempt
   *   Zero-based attempt index used to compute the exponent.
   * @param bool $is_test
   *   When TRUE the delay is skipped entirely.
   */
  protected function delayRetry(int $retry_delay, int $attempt, bool $is_test): void {
    if ($retry_delay <= 0 || $is_test) {
      return;
    }

    $delay = min($retry_delay * (2 ** $attempt), 30);
    usleep($delay * 1000000);
  }

  /**
   * Normalises a value to a positive integer within bounds.
   *
   * @param mixed $value
   *   The raw value to normalise.
   * @param int $default
   *   Fallback when the value is non-numeric.
   * @param int $min
   *   Lower bound (inclusive).
   * @param int $max
   *   Upper bound (inclusive).
   *
   * @return int
   *   The clamped integer.
   */
  protected function getPositiveInteger(mixed $value, int $default, int $min, int $max): int {
    $integer = is_numeric($value) ? (int) $value : $default;
    return max($min, min($max, $integer));
  }

}
