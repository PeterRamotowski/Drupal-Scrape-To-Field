<?php

namespace Drupal\scrape_to_field\DTO;

/**
 * Data Transfer Object for complete node scraper configuration.
 */
class NodeScraperConfigDto
{

  /**
   * Constructs a NodeScraperConfigDto object.
   *
   * @param ScraperFieldConfigDto[] $fieldConfigs
   *   Array of field configurations indexed by field name.
   */
  public function __construct(
    public readonly bool $scrapingEnabled,
    public readonly array $fieldConfigs = [],
  ) {
  }

  /**
   * Creates a DTO from array configuration.
   */
  public static function fromArray(array $config, bool $scrapingEnabled = true): self
  {
    $fieldConfigs = [];
    
    foreach ($config as $fieldName => $fieldConfig) {
      if (is_array($fieldConfig)) {
        $fieldConfigs[$fieldName] = ScraperFieldConfigDto::fromArray($fieldConfig);
      }
    }

    return new self($scrapingEnabled, $fieldConfigs);
  }

  /**
   * Creates a DTO from JSON string.
   */
  public static function fromJson(string $json, bool $scrapingEnabled = true): self
  {
    $config = json_decode($json, true) ?: [];
    return self::fromArray($config, $scrapingEnabled);
  }

  /**
   * Converts DTO to array format.
   */
  public function toArray(): array
  {
    $result = [];
    
    foreach ($this->fieldConfigs as $fieldName => $fieldConfig) {
      $result[$fieldName] = $fieldConfig->toArray();
    }

    return $result;
  }

  /**
   * Converts DTO to JSON string.
   */
  public function toJson(): string
  {
    return json_encode($this->toArray());
  }

  /**
   * Gets configuration for a specific field.
   */
  public function getFieldConfig(string $fieldName): ?ScraperFieldConfigDto
  {
    return $this->fieldConfigs[$fieldName] ?? null;
  }

  /**
   * Gets all enabled field configurations.
   */
  public function getEnabledFieldConfigs(): array
  {
    return array_filter($this->fieldConfigs, fn($config) => $config->enabled);
  }

  /**
   * Checks if any fields are enabled for scraping.
   */
  public function hasEnabledFields(): bool
  {
    return !empty($this->getEnabledFieldConfigs());
  }

  /**
   * Validates all field configurations.
   */
  public function validate(): array
  {
    $errors = [];
    
    foreach ($this->fieldConfigs as $fieldName => $fieldConfig) {
      $fieldErrors = $fieldConfig->validate();
      if (!empty($fieldErrors)) {
        $errors[$fieldName] = $fieldErrors;
      }
    }

    return $errors;
  }

  /**
   * Checks if all configurations are valid.
   */
  public function isValid(): bool
  {
    return empty($this->validate());
  }

  /**
   * Creates a new configuration with updated field config.
   */
  public function withFieldConfig(string $fieldName, ScraperFieldConfigDto $fieldConfig): self
  {
    $fieldConfigs = $this->fieldConfigs;
    $fieldConfigs[$fieldName] = $fieldConfig;
    
    return new self($this->scrapingEnabled, $fieldConfigs);
  }

  /**
   * Creates a new configuration with field removed.
   */
  public function withoutField(string $fieldName): self
  {
    $fieldConfigs = $this->fieldConfigs;
    unset($fieldConfigs[$fieldName]);
    
    return new self($this->scrapingEnabled, $fieldConfigs);
  }

  /**
   * Creates a disabled configuration.
   */
  public static function disabled(): self
  {
    return new self(false, []);
  }

}