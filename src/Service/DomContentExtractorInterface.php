<?php

namespace Drupal\scrape_to_field\Service;

/**
 * Interface for DOM content extractors used by the scraper.
 */
interface DomContentExtractorInterface {

  /**
   * Extracts values from an HTML string using a CSS or XPath selector.
   *
   * @param string $html
   *   The raw HTML to parse.
   * @param string $selector
   *   The CSS selector or XPath expression.
   * @param string $selector_type
   *   Either 'css' or 'xpath'.
   * @param array $options
   *   Extraction options:
   *   - extract_method (string): 'text', 'html', or 'attribute'.
   *   - attribute (string): Attribute name when method is 'attribute'.
   *   - max_results (int): Maximum number of nodes to process.
   *
   * @return string[]
   *   Ordered list of extracted string values.
   */
  public function extract(
    string $html,
    string $selector,
    string $selector_type,
    array $options = [],
  ): array;

}
