<?php

namespace Drupal\scrape_to_field\Service;

/**
 * Service for cleaning and transforming scraped data.
 */
class DataCleaningService
{

  /**
   * Applies cleaning operations to scraped data.
   *
   * @param array $data
   *   The scraped data array.
   * @param array $cleaning_operations
   *   Array of cleaning operations with 'search' and 'replace' keys.
   *
   * @return array
   *   The cleaned data array.
   */
  public function applyCleaningOperations(array $data, array $cleaning_operations): array
  {
    $cleaned_data = [];

    foreach ($data as $item) {
      $cleaned_item = (string) $item;

      // Apply each cleaning operation in order
      foreach ($cleaning_operations as $operation) {
        $search = $operation['search'] ?? '';
        $replace = $operation['replace'] ?? '';

        if (!empty($search)) {
          $cleaned_item = str_replace($search, $replace, $cleaned_item);
        }
      }

      $cleaned_data[] = $cleaned_item;
    }

    return $cleaned_data;
  }

  /**
   * Parses cleaning operations text into array format.
   *
   * @param string $operations_text
   *   The operations text with format "search|replace" per line.
   *
   * @return array
   *   Array of cleaning operations with 'search' and 'replace' keys.
   */
  public function parseCleaningOperations(string $operations_text): array
  {
    $lines = explode("\n", $operations_text);
    $cleaning_operations = [];

    foreach ($lines as $line) {
      $line = trim($line);
      if (!empty($line)) {
        $parts = explode('|', $line, 2);
        $search = $parts[0] ?? '';
        $replace = isset($parts[1]) ? $parts[1] : '';

        if (!empty($search)) {
          $cleaning_operations[] = [
            'search' => $search,
            'replace' => $replace,
          ];
        }
      }
    }

    return $cleaning_operations;
  }

}