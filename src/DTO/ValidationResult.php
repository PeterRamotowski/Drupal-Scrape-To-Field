<?php

namespace Drupal\scrape_to_field\DTO;

/**
 * Immutable value object for a scraper configuration validation result.
 */
final class ValidationResult {

  /**
   * Machine-readable reason: valid result.
   */
  public const REASON_VALID = '';

  /**
   * Machine-readable reason: URL format is invalid.
   */
  public const REASON_INVALID_URL = 'invalid_url';

  /**
   * Machine-readable reason: URL violates target policy.
   */
  public const REASON_POLICY_VIOLATION = 'policy_violation';

  /**
   * Machine-readable reason: selector is empty.
   */
  public const REASON_EMPTY_SELECTOR = 'empty_selector';

  /**
   * Machine-readable reason: selector type is not recognised.
   */
  public const REASON_INVALID_SELECTOR_TYPE = 'invalid_selector_type';

  /**
   * Machine-readable reason: selector syntax is invalid.
   */
  public const REASON_INVALID_SELECTOR_SYNTAX = 'invalid_selector_syntax';

  /**
   * Constructs a ValidationResult object.
   *
   * @param bool $valid
   *   Whether the configuration is valid.
   * @param string|\Stringable $message
   *   Human-readable validation message.
   * @param string $reason
   *   Machine-readable failure reason constant.
   */
  private function __construct(
    private readonly bool $valid,
    private readonly string|\Stringable $message,
    private readonly string $reason,
  ) {}

  /**
   * Creates a passing result.
   *
   * @param string|\Stringable $message
   *   Optional success message.
   */
  public static function valid(string|\Stringable $message = ''): self {
    return new self(TRUE, $message, self::REASON_VALID);
  }

  /**
   * Creates a failing result.
   *
   * @param string|\Stringable $message
   *   Human-readable error message.
   * @param string $reason
   *   Machine-readable failure reason constant.
   */
  public static function invalid(
    string|\Stringable $message,
    string $reason,
  ): self {
    return new self(FALSE, $message, $reason);
  }

  /**
   * Returns whether the configuration is valid.
   */
  public function isValid(): bool {
    return $this->valid;
  }

  /**
   * Returns the validation message.
   */
  public function getMessage(): string|\Stringable {
    return $this->message;
  }

  /**
   * Returns the machine-readable failure reason.
   */
  public function getReason(): string {
    return $this->reason;
  }

}
