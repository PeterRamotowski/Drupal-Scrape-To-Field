<?php

namespace Drupal\Tests\scrape_to_field\Unit;

use Drupal\scrape_to_field\DTO\ValidationResult;
use Drupal\scrape_to_field\Service\ScraperConfigValidator;
use Drupal\scrape_to_field\Service\TargetUrlPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ScraperConfigValidator class.
 */
class ScraperConfigValidatorTest extends TestCase {

  /**
   * The validator under test.
   */
  protected ScraperConfigValidator $validator;

  /**
   * Mock target URL policy.
   *
   * @var \Drupal\scrape_to_field\Service\TargetUrlPolicy|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TargetUrlPolicy|MockObject $targetUrlPolicy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->targetUrlPolicy = $this->createMock(TargetUrlPolicy::class);
    $this->validator = new ScraperConfigValidator($this->targetUrlPolicy);
  }

  /**
   * Tests that a valid URL and selector returns a valid result.
   */
  public function testValidConfiguration(): void {
    $result = $this->validator->validate('https://example.com', 'h1', 'css');

    $this->assertTrue($result->isValid());
    $this->assertEquals(ValidationResult::REASON_VALID, $result->getReason());
  }

  /**
   * Tests that an invalid URL returns an invalid result.
   */
  public function testInvalidUrl(): void {
    $result = $this->validator->validate('not-a-url', 'h1', 'css');

    $this->assertFalse($result->isValid());
    $this->assertEquals(ValidationResult::REASON_INVALID_URL, $result->getReason());
  }

  /**
   * Tests that a policy violation returns an invalid result.
   */
  public function testPolicyViolation(): void {
    $this->targetUrlPolicy
      ->method('assertAllowed')
      ->willThrowException(new \InvalidArgumentException('Non-HTTPS URL'));

    $result = $this->validator->validate('http://example.com', 'h1', 'css');

    $this->assertFalse($result->isValid());
    $this->assertEquals(ValidationResult::REASON_POLICY_VIOLATION, $result->getReason());
  }

  /**
   * Tests that an empty selector returns an invalid result.
   */
  public function testEmptySelector(): void {
    $result = $this->validator->validate('https://example.com', '   ', 'css');

    $this->assertFalse($result->isValid());
    $this->assertEquals(ValidationResult::REASON_EMPTY_SELECTOR, $result->getReason());
  }

  /**
   * Tests that an invalid selector type returns an invalid result.
   */
  public function testInvalidSelectorType(): void {
    $result = $this->validator->validate('https://example.com', 'h1', 'regex');

    $this->assertFalse($result->isValid());
    $this->assertEquals(ValidationResult::REASON_INVALID_SELECTOR_TYPE, $result->getReason());
  }

  /**
   * Tests that an XPath selector type is valid.
   */
  public function testXpathSelectorTypeIsValid(): void {
    $result = $this->validator->validate('https://example.com', '//h1', 'xpath');

    $this->assertTrue($result->isValid());
  }

}
