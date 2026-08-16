<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\scrape_to_field\Exception\ScrapeRateLimitException;

/**
 * Coordinates conservative per-host pacing for scrape requests.
 */
class ScrapeRateLimiter {

  /**
   * Constructs a ScrapeRateLimiter object.
   */
  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected LockBackendInterface $lockBackend,
  ) {}

  /**
   * Claims the next request slot for a host.
   *
   * @throws \Drupal\scrape_to_field\Exception\ScrapeRateLimitException
   *   Thrown when the caller must retry after a pacing delay.
   */
  public function claim(string $host): bool {
    $interval = (int) ($this->configFactory
      ->get('scrape_to_field.settings')
      ->get('host_rate_limit_interval') ?? 1);

    if ($interval <= 0) {
      return TRUE;
    }

    $normalized_host = strtolower(trim($host));
    if ($normalized_host === '') {
      throw new \InvalidArgumentException('A host is required for rate limiting.');
    }

    $host_hash = Crypt::hashBase64($normalized_host);
    $key = 'scrape_to_field.host_rate.' . $host_hash;
    $lock_name = 'scrape_to_field.host_rate_lock.' . $host_hash;
    if (!$this->lockBackend->acquire($lock_name, 5.0)) {
      throw new ScrapeRateLimitException(1);
    }

    try {
      $current_time = $this->time->getCurrentTime();
      $last_request = (int) $this->state->get($key, 0);
      $elapsed = $current_time - $last_request;

      if ($elapsed < $interval) {
        throw new ScrapeRateLimitException(max(1, $interval - $elapsed));
      }

      $this->state->set($key, $current_time);
      return TRUE;
    }
    finally {
      $this->lockBackend->release($lock_name);
    }
  }

}
