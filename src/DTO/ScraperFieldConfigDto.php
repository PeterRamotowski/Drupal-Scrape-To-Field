<?php

namespace Drupal\scrape_to_field\DTO;

/**
 * Data Transfer Object for individual field scraper configuration.
 */
class ScraperFieldConfigDto {

  /**
   * Constructs a ScraperFieldConfigDto object.
   */
  public function __construct(
    public readonly bool $enabled,
    public readonly string $url,
    public readonly string $selector,
    public readonly string $selectorType,
    public readonly string $extractMethod,
    public readonly string $attribute = '',
    public readonly string $multipleHandling = 'first',
    public readonly string $separator = ', ',
    public readonly string $textFormat = 'plain_text',
    public readonly string $frequency = '',
    public readonly bool $enableCleaning = FALSE,
    public readonly array $cleaningOperations = [],
  ) {
  }

  /**
   * Creates a DTO from array configuration.
   */
  public static function fromArray(array $config): self {
    return new self(
      enabled: $config['enabled'] ?? FALSE,
      url: $config['url'] ?? '',
      selector: $config['selector'] ?? '',
      selectorType: $config['selector_type'] ?? 'css',
      extractMethod: $config['extract_method'] ?? 'text',
      attribute: $config['attribute'] ?? '',
      multipleHandling: $config['multiple_handling'] ?? 'first',
      separator: $config['separator'] ?? ', ',
      textFormat: $config['text_format'] ?? 'plain_text',
      frequency: $config['frequency'] ?? '',
      enableCleaning: $config['enable_cleaning'] ?? FALSE,
      cleaningOperations: $config['cleaning_operations'] ?? [],
    );
  }

  /**
   * Creates a DTO from form field values.
   */
  public static function fromFormValues(array $field_values, array $cleaning_operations = []): self {
    return new self(
      enabled: !empty($field_values['enabled']),
      url: $field_values['source_config']['url'] ?? '',
      selector: $field_values['source_config']['selector'] ?? '',
      selectorType: $field_values['source_config']['selector_type'] ?? 'css',
      extractMethod: $field_values['extraction_config']['extract_method'] ?? 'text',
      attribute: $field_values['extraction_config']['attribute'] ?? '',
      multipleHandling: $field_values['extraction_config']['multiple_handling'] ?? 'first',
      separator: $field_values['extraction_config']['separator'] ?? ', ',
      textFormat: $field_values['extraction_config']['text_format'] ?? 'plain_text',
      frequency: $field_values['advanced']['frequency'] ?? '',
      enableCleaning: !empty($field_values['extraction_config']['enable_cleaning']),
      cleaningOperations: $cleaning_operations,
    );
  }

  /**
   * Converts DTO to array format.
   */
  public function toArray(): array {
    return [
      'enabled' => $this->enabled,
      'url' => $this->url,
      'selector' => $this->selector,
      'selector_type' => $this->selectorType,
      'extract_method' => $this->extractMethod,
      'attribute' => $this->attribute,
      'multiple_handling' => $this->multipleHandling,
      'separator' => $this->separator,
      'text_format' => $this->textFormat,
      'frequency' => $this->frequency,
      'enable_cleaning' => $this->enableCleaning,
      'cleaning_operations' => $this->cleaningOperations,
    ];
  }

  /**
   * Validates the configuration.
   */
  public function validate(): array {
    $errors = [];

    if ($this->enabled) {
      if (empty($this->url)) {
        $errors[] = 'URL is required when scraping is enabled.';
      }
      elseif (!filter_var($this->url, FILTER_VALIDATE_URL)) {
        $errors[] = 'URL must be a valid URL.';
      }

      if (empty($this->selector)) {
        $errors[] = 'Selector is required when scraping is enabled.';
      }

      if ($this->extractMethod === 'attribute' && empty($this->attribute)) {
        $errors[] = 'Attribute name is required when extraction method is "attribute".';
      }

      if (!in_array($this->selectorType, ['css', 'xpath'])) {
        $errors[] = 'Selector type must be either "css" or "xpath".';
      }

      if (!in_array($this->extractMethod, ['text', 'html', 'attribute'])) {
        $errors[] = 'Extract method must be one of: text, html, attribute.';
      }

      if (!in_array($this->multipleHandling, ['first', 'all', 'join'])) {
        $errors[] = 'Multiple handling must be one of: first, all, join.';
      }
    }

    return $errors;
  }

  /**
   * Checks if the configuration is valid.
   */
  public function isValid(): bool {
    return empty($this->validate());
  }

  /**
   * Returns a disabled configuration.
   */
  public static function disabled(): self {
    return new self(enabled: FALSE, url: '', selector: '', selectorType: 'css', extractMethod: 'text');
  }

}
