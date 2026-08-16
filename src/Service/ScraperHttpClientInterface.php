<?php

namespace Drupal\scrape_to_field\Service;

/**
 * Interface for the HTTP client used to fetch scraping targets.
 */
interface ScraperHttpClientInterface {

  /**
   * Fetches the HTML body at the given URL.
   *
   * Retries on transient failures using exponential back-off. Enforces
   * TLS 1.2+, DNS pinning, redirect-safety, a configurable byte limit on the
   * response body, and a Content-Type guard that rejects non-HTML responses.
   *
   * @param string $url
   *   The URL to fetch.
   * @param array $options
   *   Runtime overrides: timeout, max_retries, retry_delay, test_mode.
   *
   * @return string
   *   The response HTML body. Empty string when the body is empty.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   *   Thrown when the request fails after all retry attempts.
   * @throws \RuntimeException
   *   Thrown for non-HTTP errors (TLS, unsupported content type,
   *   body size exceeded).
   */
  public function fetchHtml(string $url, array $options = []): string;

}
