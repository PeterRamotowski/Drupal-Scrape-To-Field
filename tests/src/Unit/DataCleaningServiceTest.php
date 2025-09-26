<?php

namespace Drupal\Tests\scrape_to_field\Unit;

use Drupal\scrape_to_field\Service\DataCleaningService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DataCleaningService class.
 */
class DataCleaningServiceTest extends TestCase
{

  /**
   * The data cleaning service under test.
   *
   * @var \Drupal\scrape_to_field\Service\DataCleaningService
   */
  protected DataCleaningService $dataCleaningService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();
    $this->dataCleaningService = new DataCleaningService();
  }

  /**
   * Tests basic cleaning operations.
   */
  public function testBasicCleaningOperations()
  {
    $data = ['Hello World!', 'Test content'];
    $cleaning_operations = [
      ['search' => 'Hello', 'replace' => 'Hi'],
      ['search' => '!', 'replace' => ''],
    ];

    $result = $this->dataCleaningService->applyCleaningOperations($data, $cleaning_operations);

    $this->assertIsArray($result);
    $this->assertEquals('Hi World', $result[0]);
    $this->assertEquals('Test content', $result[1]);
  }

  /**
   * Tests cleaning operations with empty search.
   */
  public function testCleaningOperationsWithEmptySearch()
  {
    $data = ['Original content'];
    $cleaning_operations = [
      ['search' => '', 'replace' => 'replacement'],
      ['search' => 'Original', 'replace' => 'Modified'],
    ];

    $result = $this->dataCleaningService->applyCleaningOperations($data, $cleaning_operations);

    $this->assertEquals('Modified content', $result[0]);
  }

  /**
   * Tests cleaning operations with missing replace key.
   */
  public function testCleaningOperationsWithMissingReplace()
  {
    $data = ['Remove this!'];
    $cleaning_operations = [
      ['search' => ' this!'],
    ];

    $result = $this->dataCleaningService->applyCleaningOperations($data, $cleaning_operations);

    $this->assertEquals('Remove', $result[0]);
  }

  /**
   * Tests parsing cleaning operations from text.
   */
  public function testParseCleaningOperations()
  {
    $operations_text = "Hello|Hi\nWorld|Universe\n!|\nempty line|replacement\nspaces   |trimmed";

    $result = $this->dataCleaningService->parseCleaningOperations($operations_text);

    $expected = [
      ['search' => 'Hello', 'replace' => 'Hi'],
      ['search' => 'World', 'replace' => 'Universe'],
      ['search' => '!', 'replace' => ''],
      ['search' => 'empty line', 'replace' => 'replacement'],
      ['search' => 'spaces   ', 'replace' => 'trimmed'],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Tests parsing empty operations text.
   */
  public function testParseEmptyOperationsText()
  {
    $operations_text = '';

    $result = $this->dataCleaningService->parseCleaningOperations($operations_text);

    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests multiple cleaning operations in sequence.
   */
  public function testMultipleCleaningOperations()
  {
    $data = ['<p>Hello World!</p>'];
    $cleaning_operations = [
      ['search' => '<p>', 'replace' => ''],
      ['search' => '</p>', 'replace' => ''],
      ['search' => 'Hello', 'replace' => 'Hi'],
      ['search' => '!', 'replace' => '.'],
    ];

    $result = $this->dataCleaningService->applyCleaningOperations($data, $cleaning_operations);

    $this->assertEquals('Hi World.', $result[0]);
  }

  /**
   * Tests cleaning operations with empty data array.
   */
  public function testCleaningOperationsWithEmptyData()
  {
    $data = [];
    $cleaning_operations = [
      ['search' => 'test', 'replace' => 'replacement'],
    ];

    $result = $this->dataCleaningService->applyCleaningOperations($data, $cleaning_operations);

    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests cleaning operations with non-string data items.
   */
  public function testCleaningOperationsWithNonStringData()
  {
    $data = [123, null, true, 'string'];
    $cleaning_operations = [
      ['search' => '12', 'replace' => 'twelve'],
      ['search' => 'string', 'replace' => 'text'],
    ];

    $result = $this->dataCleaningService->applyCleaningOperations($data, $cleaning_operations);

    $this->assertEquals('twelve3', $result[0]);
    $this->assertEquals('', $result[1]);
    $this->assertEquals('1', $result[2]);
    $this->assertEquals('text', $result[3]);
  }

  /**
   * Tests data cleaning and sanitization.
   */
  public function testDataCleaningAndSanitization()
  {
    $test_data = ['<script>alert("xss")</script><p>Clean content</p>', '  Trimmed content  '];
    $cleaning_operations = [
      ['search' => '<script>', 'replace' => ''],
      ['search' => '</script>', 'replace' => ''],
      ['search' => '  ', 'replace' => ' '],
    ];

    $cleaned_data = $this->dataCleaningService->applyCleaningOperations($test_data, $cleaning_operations);

    $this->assertStringNotContainsString('<script>', $cleaned_data[0]);
    $this->assertStringContainsString('Clean content', $cleaned_data[0]);

    $operations_text = "<script>|\n</script>|\n  | ";
    $parsed_operations = $this->dataCleaningService->parseCleaningOperations($operations_text);

    $this->assertCount(2, $parsed_operations);
    $this->assertEquals('<script>', $parsed_operations[0]['search']);
    $this->assertEquals('', $parsed_operations[0]['replace']);
  }
}
