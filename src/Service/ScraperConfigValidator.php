<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\scrape_to_field\DTO\ValidationResult;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Validates scraper configuration triples without making HTTP requests.
 */
class ScraperConfigValidator implements ScraperConfigValidatorInterface {

  /**
   * Constructs a ScraperConfigValidator object.
   *
   * @param \Drupal\scrape_to_field\Service\TargetUrlPolicy $targetUrlPolicy
   *   The target URL policy service.
   */
  public function __construct(
    protected TargetUrlPolicy $targetUrlPolicy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(
    string $url,
    string $selector,
    string $selector_type = 'css',
  ): ValidationResult {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return ValidationResult::invalid(
        new TranslatableMarkup('Invalid URL format.'),
        ValidationResult::REASON_INVALID_URL,
      );
    }

    try {
      $this->targetUrlPolicy->assertAllowed($url);
    }
    catch (\InvalidArgumentException $exception) {
      return ValidationResult::invalid(
        $exception->getMessage(),
        ValidationResult::REASON_POLICY_VIOLATION,
      );
    }

    if (empty(trim($selector))) {
      return ValidationResult::invalid(
        new TranslatableMarkup('Selector cannot be empty.'),
        ValidationResult::REASON_EMPTY_SELECTOR,
      );
    }

    if (!in_array($selector_type, ['css', 'xpath'], TRUE)) {
      return ValidationResult::invalid(
        new TranslatableMarkup('Selector type must be either CSS or XPath.'),
        ValidationResult::REASON_INVALID_SELECTOR_TYPE,
      );
    }

    if (!$this->hasValidSelectorSyntax($selector, $selector_type)) {
      return ValidationResult::invalid(
        new TranslatableMarkup('The selector syntax is invalid.'),
        ValidationResult::REASON_INVALID_SELECTOR_SYNTAX,
      );
    }

    return ValidationResult::valid(
      new TranslatableMarkup('Configuration syntax is valid.'),
    );
  }

  /**
   * Checks whether a CSS selector or XPath expression can be parsed.
   *
   * @param string $selector
   *   The selector to validate.
   * @param string $selector_type
   *   The selector type, either css or xpath.
   *
   * @return bool
   *   TRUE when the selector syntax is valid.
   */
  private function hasValidSelectorSyntax(
    string $selector,
    string $selector_type,
  ): bool {
    if ($selector_type === 'css') {
      try {
        (new CssSelectorConverter())->toXPath($selector);
        return TRUE;
      }
      catch (\InvalidArgumentException) {
        return FALSE;
      }
    }

    $document = new \DOMDocument();
    $document->loadHTML('<html><body></body></html>');
    $xpath = new \DOMXPath($document);
    set_error_handler(static fn(): bool => TRUE);

    try {
      return $xpath->query($selector) !== FALSE;
    }
    finally {
      restore_error_handler();
    }
  }

}
