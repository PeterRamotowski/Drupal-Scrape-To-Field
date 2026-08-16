<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\scrape_to_field\DTO\ValidationResult;

/**
 * Interface for scraper configuration validators.
 */
interface ScraperConfigValidatorInterface {

  /**
   * Validates a URL and selector triple without making an HTTP request.
   *
   * @param string $url
   *   The URL to validate.
   * @param string $selector
   *   The CSS or XPath selector to validate.
   * @param string $selector_type
   *   The selector type: 'css' or 'xpath'.
   *
   * @return \Drupal\scrape_to_field\DTO\ValidationResult
   *   Immutable result containing isValid(), getMessage(), and getReason().
   */
  public function validate(
    string $url,
    string $selector,
    string $selector_type = 'css',
  ): ValidationResult;

}
