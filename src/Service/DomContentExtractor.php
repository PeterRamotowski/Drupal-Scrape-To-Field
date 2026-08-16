<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts values from an HTML document using CSS selectors or XPath.
 */
class DomContentExtractor implements DomContentExtractorInterface {

  /**
   * Constructs a DomContentExtractor object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, used to read the default max_results limit.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function extract(
    string $html,
    string $selector,
    string $selector_type,
    array $options = [],
  ): array {
    $config = $this->configFactory->get('scrape_to_field.settings');
    $extract_method = $options['extract_method'] ?? 'text';
    $attribute = $options['attribute'] ?? 'href';
    $max_results = $this->getPositiveInteger(
      $options['max_results'] ?? $config->get('max_results') ?? 50,
      50,
      1,
      500,
    );

    $crawler = new Crawler($html);

    if ($selector_type === 'xpath') {
      $nodes = $crawler->filterXPath($selector);
    }
    else {
      $nodes = $crawler->filter($selector);
    }

    $nodes = $nodes->slice(0, $max_results);

    $data = [];
    $nodes->each(function (Crawler $node) use (&$data, $extract_method, $attribute): void {
      switch ($extract_method) {
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

    return $data;
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
