<?php

namespace Drupal\scrape_to_field\Service;

/**
 * Service for cleaning and transforming scraped data.
 */
class DataCleaningService {

  /**
   * Maximum cleaning operations processed for one field.
   */
  private const MAX_OPERATIONS = 25;

  /**
   * Maximum length for a single cleaning operation line.
   */
  private const MAX_OPERATION_LENGTH = 256;

  /**
   * Maximum length for the raw cleaning operations text.
   */
  private const MAX_OPERATIONS_TEXT_LENGTH = 4096;

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
  public function applyCleaningOperations(array $data, array $cleaning_operations): array {
    $cleaned_data = [];

    foreach ($data as $item) {
      $cleaned_item = (string) $item;

      // Apply each cleaning operation in order.
      foreach (array_slice($cleaning_operations, 0, self::MAX_OPERATIONS) as $operation) {
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
  public function parseCleaningOperations(string $operations_text): array {
    $operations_text = substr($operations_text, 0, self::MAX_OPERATIONS_TEXT_LENGTH);
    $lines = explode("\n", $operations_text);
    $cleaning_operations = [];

    foreach (array_slice($lines, 0, self::MAX_OPERATIONS) as $line) {
      $line = trim($line);
      if (!empty($line) && strlen($line) <= self::MAX_OPERATION_LENGTH) {
        $parts = explode('|', $line, 2);
        $search = $parts[0] ?? '';
        $replace = $parts[1] ?? '';

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
