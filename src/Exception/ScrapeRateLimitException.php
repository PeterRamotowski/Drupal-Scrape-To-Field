<?php

namespace Drupal\scrape_to_field\Exception;

/**
 * Indicates that a scrape target must be retried after a pacing delay.
 */
final class ScrapeRateLimitException extends \RuntimeException {

  /**
   * Constructs a ScrapeRateLimitException object.
   *
   * @param int $retryDelay
   *   The minimum retry delay in seconds.
   */
  public function __construct(
    private readonly int $retryDelay,
  ) {
    parent::__construct('Per-host scrape rate limit exceeded.');
  }

  /**
   * Returns the minimum retry delay in seconds.
   */
  public function getRetryDelay(): int {
    return $this->retryDelay;
  }

}
