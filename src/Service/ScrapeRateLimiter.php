<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;

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
  ) {}

  /**
   * Attempts to claim the next request slot for a host.
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
      return FALSE;
    }

    $key = 'scrape_to_field.host_rate.' . Crypt::hashBase64($normalized_host);
    $current_time = $this->time->getRequestTime();
    $last_request = (int) $this->state->get($key, 0);

    if (($current_time - $last_request) < $interval) {
      return FALSE;
    }

    $this->state->set($key, $current_time);
    return TRUE;
  }

}
