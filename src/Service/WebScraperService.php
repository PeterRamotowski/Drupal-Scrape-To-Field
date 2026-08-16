<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\scrape_to_field\DTO\ValidationResult;
use Drupal\scrape_to_field\Exception\ScrapeRateLimitException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin orchestrator that coordinates scraping of a single URL.
 *
 * Delegates HTTP transport to ScraperHttpClientInterface, DOM extraction to
 * DomContentExtractorInterface, and config validation to
 * ScraperConfigValidatorInterface. This class is responsible only for
 * coordinating those delegates and applying the rate limiter.
 */
class WebScraperService {

  /**
   * Constructs a WebScraperService object.
   *
   * @param \Drupal\scrape_to_field\Service\ScraperHttpClientInterface $httpClient
   *   The HTTP client for fetching scrape targets.
   * @param \Drupal\scrape_to_field\Service\DomContentExtractorInterface $domExtractor
   *   The DOM content extractor.
   * @param \Drupal\scrape_to_field\Service\ScraperConfigValidatorInterface $configValidator
   *   The configuration validator.
   * @param \Drupal\scrape_to_field\Service\ScraperActivityLogger $scraperLogger
   *   The activity logger.
   * @param \Drupal\scrape_to_field\Service\DataCleaningService $dataCleaningService
   *   The data cleaning service.
   */
  public function __construct(
    protected ScraperHttpClientInterface $httpClient,
    protected DomContentExtractorInterface $domExtractor,
    protected ScraperConfigValidatorInterface $configValidator,
    protected ScraperActivityLogger $scraperLogger,
    protected DataCleaningService $dataCleaningService,
  ) {}

  /**
   * Scrapes data from a given URL using a CSS selector or XPath expression.
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
   *
   * @throws \Drupal\scrape_to_field\Exception\ScrapeRateLimitException
   *   Thrown when a background scrape must be delayed for host pacing.
   */
  public function scrapeData(string $url, string $selector, string $selector_type = 'css', array $options = []): ?array {
    $result = $this->configValidator->validate($url, $selector, $selector_type);
    if (!$result->isValid()) {
      match ($result->getReason()) {
        ValidationResult::REASON_EMPTY_SELECTOR => $this->scraperLogger->logEmptySelector($url),
        ValidationResult::REASON_INVALID_SELECTOR_TYPE => $this->scraperLogger->logInvalidSelectorType($selector_type, $url),
        default => $this->scraperLogger->logInvalidUrl($url),
      };
      return NULL;
    }

    try {
      $is_test = !empty($options['test_mode']);
      $html = $this->httpClient->fetchHtml($url, $options);

      if ($html === '') {
        return [];
      }

      $data = $this->domExtractor->extract($html, $selector, $selector_type, $options);

      if (!empty($options['cleaning_operations'])) {
        $data = $this->dataCleaningService->applyCleaningOperations(
          $data,
          $options['cleaning_operations'],
        );
      }

      if (!$is_test) {
        $this->scraperLogger->logScrapingSuccess($url, count($data));
      }

      return $data;
    }
    catch (ScrapeRateLimitException $exception) {
      throw $exception;
    }
    catch (GuzzleException $e) {
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
   * Returns an array with keys 'valid' (bool) and 'message' (string) for
   * backward compatibility with callers that use the array return format.
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
   * Delegates to ScraperConfigValidatorInterface and returns an array for
   * backward compatibility. Prefer injecting ScraperConfigValidatorInterface
   * directly in new code.
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
    $result = $this->configValidator->validate($url, $selector, $selector_type);
    return [
      'valid' => $result->isValid(),
      'message' => $result->getMessage(),
    ];
  }

}
