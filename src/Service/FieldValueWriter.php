<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\filter\FilterFormatRepositoryInterface;
use Drupal\node\NodeInterface;

/**
 * Writes scraped values into Drupal node fields.
 *
 * Handles field-type mapping, cardinality enforcement, value casting,
 * and text-format resolution.
 */
class FieldValueWriter implements FieldValueWriterInterface {

  /**
   * Field types supported by this writer.
   */
  private const SUPPORTED_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'integer',
    'decimal',
    'float',
  ];

  /**
   * Constructs a FieldValueWriter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load filter_format entities.
   * @param \Drupal\filter\FilterFormatRepositoryInterface|null $filterFormatRepository
   *   The filter format repository, when the Filter module is enabled.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?FilterFormatRepositoryInterface $filterFormatRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function write(
    NodeInterface $node,
    string $field_name,
    array $data,
    array $config,
  ): void {
    $field = $node->get($field_name);
    $field_definition = $field->getFieldDefinition();
    $field_type = $field_definition->getType();
    $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();

    $multiple_handling = $config['multiple_handling'] ?? 'first';
    $processed_data = $this->processMultipleData(
      $data,
      $multiple_handling,
      $config,
      $cardinality,
    );

    switch ($field_type) {
      case 'string':
      case 'string_long':
        $this->setFieldValue($field, $processed_data);
        break;

      case 'text':
      case 'text_long':
        $text_format = $this->getValidTextFormat(
          $field_definition,
          $config['text_format'] ?? '',
        );
        $properties = $text_format !== NULL ? ['format' => $text_format] : [];
        $this->setFieldValue($field, $processed_data, $properties);
        break;

      case 'integer':
        $this->setFieldValue($field, $processed_data, [], 'int');
        break;

      case 'decimal':
        $this->setFieldValue($field, $processed_data, [], 'decimal');
        break;

      case 'float':
        $this->setFieldValue($field, $processed_data, [], 'float');
        break;

      default:
        $field->setValue($processed_data);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFieldTypes(): array {
    return self::SUPPORTED_FIELD_TYPES;
  }

  /**
   * {@inheritdoc}
   */
  public function getValidTextFormat(
    FieldDefinitionInterface $field_definition,
    string $preferred_format,
  ): ?string {
    $format_storage = $this->entityTypeManager->getStorage('filter_format');
    $formats = $format_storage->loadByProperties(['status' => TRUE]);
    if (empty($formats)) {
      return NULL;
    }

    $valid_format_ids = array_keys($formats);
    $allowed_formats = $field_definition->getSetting('allowed_formats') ?: [];
    if (!empty($allowed_formats)) {
      $valid_format_ids = array_values(
        array_intersect($valid_format_ids, $allowed_formats),
      );
    }

    if ($valid_format_ids === []) {
      return NULL;
    }

    if ($preferred_format !== '' && in_array($preferred_format, $valid_format_ids, TRUE)) {
      return $preferred_format;
    }

    $fallback_format = $this->filterFormatRepository?->getFallbackFormatId();

    if ($fallback_format !== NULL && in_array($fallback_format, $valid_format_ids, TRUE)) {
      return $fallback_format;
    }

    return reset($valid_format_ids) ?: NULL;
  }

  /**
   * Processes scraped data according to the multiple_handling configuration.
   *
   * @param string[] $data
   *   The raw scraped values.
   * @param string $multiple_handling
   *   One of 'first', 'join', or 'all'.
   * @param array $config
   *   Field scraper configuration (separator).
   * @param int $cardinality
   *   The field's storage cardinality (-1 for unlimited, 1 for single).
   *
   * @return array|string
   *   A single string or an array of strings ready for field assignment.
   */
  protected function processMultipleData(
    array $data,
    string $multiple_handling,
    array $config,
    int $cardinality,
  ): array|string {
    if (empty($data)) {
      return '';
    }

    switch ($multiple_handling) {
      case 'join':
        $separator = $config['separator'] ?? ', ';
        return implode($separator, $data);

      case 'all':
        if ($cardinality === 1) {
          $separator = $config['separator'] ?? ', ';
          return implode($separator, $data);
        }
        if ($cardinality === -1) {
          return $data;
        }
        return array_slice($data, 0, $cardinality);

      default:
        return $data[0] ?? '';
    }
  }

  /**
   * Assigns a value to a field item list with optional properties and casting.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field item list to set values on.
   * @param array|string $processed_data
   *   The processed value(s) to assign.
   * @param array $additional_properties
   *   Extra properties to merge into each field item structure.
   * @param string|null $cast_type
   *   Optional type cast: 'int', 'float', 'decimal', or NULL for none.
   */
  protected function setFieldValue(
    $field,
    array|string $processed_data,
    array $additional_properties = [],
    ?string $cast_type = NULL,
  ): void {
    if (is_array($processed_data)) {
      $values = array_map(function ($item) use ($additional_properties, $cast_type) {
        $value = $this->castValue($item, $cast_type);
        return array_merge(['value' => $value], $additional_properties);
      }, $processed_data);
      $field->setValue($values);
    }
    else {
      $value = $this->castValue($processed_data, $cast_type);
      $field->setValue(array_merge(['value' => $value], $additional_properties));
    }
  }

  /**
   * Casts a value to the specified PHP type.
   *
   * @param mixed $value
   *   The value to cast.
   * @param string|null $cast_type
   *   Target type: 'int', 'float', 'string', or NULL for no casting.
   *
   * @return mixed
   *   The cast value.
   */
  protected function castValue(mixed $value, ?string $cast_type): mixed {
    if ($cast_type === NULL) {
      return $value;
    }

    return match ($cast_type) {
      'int' => $this->castInteger($value),
      'float' => $this->castFloat($value),
      'decimal' => $this->castDecimal($value),
      default => $value,
    };
  }

  /**
   * Casts a validated integer value.
   *
   * @param mixed $value
   *   The scraped value.
   *
   * @return int
   *   The validated integer.
   *
   * @throws \UnexpectedValueException
   *   Thrown when the value is not an integer.
   */
  private function castInteger(mixed $value): int {
    $normalized_value = trim((string) $value);
    if (!preg_match('/^[+-]?\d+$/D', $normalized_value)) {
      throw new \UnexpectedValueException('Scraped data is not a valid integer.');
    }

    $is_negative = str_starts_with($normalized_value, '-');
    $digits = ltrim(ltrim($normalized_value, '+-'), '0');
    if ($digits === '') {
      return 0;
    }

    $limit = $is_negative
      ? ltrim((string) PHP_INT_MIN, '-')
      : (string) PHP_INT_MAX;
    if (strlen($digits) > strlen($limit)
      || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
    ) {
      throw new \UnexpectedValueException('Scraped integer data is outside the supported range.');
    }

    return (int) $normalized_value;
  }

  /**
   * Casts a validated finite floating-point value.
   *
   * @param mixed $value
   *   The scraped value.
   *
   * @return float
   *   The validated float.
   *
   * @throws \UnexpectedValueException
   *   Thrown when the value is not a finite number.
   */
  private function castFloat(mixed $value): float {
    $normalized_value = trim((string) $value);
    if ($normalized_value === '' || !is_numeric($normalized_value)) {
      throw new \UnexpectedValueException('Scraped data is not a valid floating-point number.');
    }

    $float_value = (float) $normalized_value;
    if (!is_finite($float_value)) {
      throw new \UnexpectedValueException('Scraped floating-point data is outside the supported range.');
    }

    return $float_value;
  }

  /**
   * Normalizes a validated decimal value.
   *
   * @param mixed $value
   *   The scraped value.
   *
   * @return string
   *   The validated decimal string.
   *
   * @throws \UnexpectedValueException
   *   Thrown when the value is not a decimal number.
   */
  private function castDecimal(mixed $value): string {
    $normalized_value = trim((string) $value);
    if (!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $normalized_value)) {
      throw new \UnexpectedValueException('Scraped data is not a valid decimal number.');
    }

    return $normalized_value;
  }

}
