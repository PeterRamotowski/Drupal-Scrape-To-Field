<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Validates scrape target URLs before outbound requests are made.
 */
class TargetUrlPolicy {

  /**
   * Hostnames that must never be scraped.
   */
  private const DISALLOWED_HOSTS = [
    'localhost',
    'localhost.localdomain',
  ];

  /**
   * Network ranges that must never be scraped.
   */
  private const DISALLOWED_IP_RANGES = [
    '0.0.0.0/8',
    '10.0.0.0/8',
    '100.64.0.0/10',
    '127.0.0.0/8',
    '169.254.0.0/16',
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.0.2.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '224.0.0.0/4',
    '240.0.0.0/4',
    '::/128',
    '::1/128',
    '::ffff:0:0/96',
    '64:ff9b::/96',
    '100::/64',
    '2001::/23',
    '2001:db8::/32',
    'fc00::/7',
    'fe80::/10',
    'ff00::/8',
  ];

  /**
   * Constructs a TargetUrlPolicy object.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Checks whether a URL is allowed by the target policy.
   */
  public function isAllowed(string $url): bool {
    try {
      $this->assertAllowed($url);
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates that a URL is safe to request from the Drupal server.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the URL violates the target policy.
   */
  public function assertAllowed(string $url): void {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      throw new \InvalidArgumentException('The URL format is invalid.');
    }

    $parts = parse_url($url);
    if ($parts === FALSE) {
      throw new \InvalidArgumentException('The URL could not be parsed.');
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'https') {
      throw new \InvalidArgumentException('Only https:// URLs are permitted.');
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new \InvalidArgumentException('URLs with embedded credentials are not permitted.');
    }

    $host = $this->normalizeHost($parts['host'] ?? '');
    if ($host === '') {
      throw new \InvalidArgumentException('The URL host is missing.');
    }

    if (in_array($host, self::DISALLOWED_HOSTS, TRUE)) {
      throw new \InvalidArgumentException('Requests to localhost are not permitted.');
    }

    if (!$this->isIpAddress($host) && !str_contains($host, '.')) {
      throw new \InvalidArgumentException('Single-label hostnames are not permitted.');
    }

    $this->assertDomainAllowed($host);

    foreach ($this->resolveHost($host) as $ip_address) {
      $this->assertPublicIpAddress($ip_address);
    }
  }

  /**
   * Gets a normalized host from a URL.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the URL does not contain a host.
   */
  public function getHost(string $url): string {
    $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === '') {
      throw new \InvalidArgumentException('The URL host is missing.');
    }

    return $host;
  }

  /**
   * Normalizes a host for comparison and DNS resolution.
   */
  protected function normalizeHost(string $host): string {
    return rtrim(strtolower(trim($host, "[] \t\n\r\0\x0B")), '.');
  }

  /**
   * Checks whether a host string is an IP address.
   */
  protected function isIpAddress(string $host): bool {
    return filter_var($host, FILTER_VALIDATE_IP) !== FALSE;
  }

  /**
   * Resolves a host to all known IPv4 and IPv6 addresses.
   *
   * @return string[]
   *   The resolved IP addresses.
   */
  protected function resolveHost(string $host): array {
    if ($this->isIpAddress($host)) {
      return [$host];
    }

    $addresses = [];
    $records = dns_get_record($host, DNS_A + DNS_AAAA);
    if (is_array($records)) {
      foreach ($records as $record) {
        if (!empty($record['ip'])) {
          $addresses[] = $record['ip'];
        }
        if (!empty($record['ipv6'])) {
          $addresses[] = $record['ipv6'];
        }
      }
    }

    if (empty($addresses)) {
      $ipv4_addresses = gethostbynamel($host);
      if (is_array($ipv4_addresses)) {
        $addresses = array_merge($addresses, $ipv4_addresses);
      }
    }

    $addresses = array_values(array_unique($addresses));
    if (empty($addresses)) {
      throw new \InvalidArgumentException('The URL host could not be resolved.');
    }

    return $addresses;
  }

  /**
   * Asserts that a resolved IP address is publicly routable.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the IP is private, reserved, or invalid.
   */
  protected function assertPublicIpAddress(string $ip_address): void {
    if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
      throw new \InvalidArgumentException('The resolved host address is invalid.');
    }

    foreach (self::DISALLOWED_IP_RANGES as $range) {
      if (IpUtils::checkIp($ip_address, $range)) {
        throw new \InvalidArgumentException('Requests to private or reserved addresses are not permitted.');
      }
    }

    $is_public = filter_var(
      $ip_address,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($is_public === FALSE) {
      throw new \InvalidArgumentException('Requests to private or reserved addresses are not permitted.');
    }
  }

  /**
   * Asserts that the host matches the optional configured domain allowlist.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the host is not in the configured allowlist.
   */
  protected function assertDomainAllowed(string $host): void {
    $allowed_domains = $this->getAllowedDomains();
    if ($allowed_domains === []) {
      return;
    }

    if ($this->isIpAddress($host)) {
      throw new \InvalidArgumentException('IP address URLs are not permitted when a domain allowlist is configured.');
    }

    foreach ($allowed_domains as $allowed_domain) {
      if ($host === $allowed_domain || str_ends_with($host, '.' . $allowed_domain)) {
        return;
      }
    }

    throw new \InvalidArgumentException('The URL host is not in the allowed domain list.');
  }

  /**
   * Gets normalized domains from configuration.
   *
   * @return string[]
   *   Allowed domain names.
   */
  protected function getAllowedDomains(): array {
    $configured_domains = (string) ($this->configFactory
      ->get('scrape_to_field.settings')
      ->get('allowed_domains') ?? '');

    $domains = preg_split('/[\s,]+/', strtolower($configured_domains)) ?: [];
    $normalized_domains = [];

    foreach ($domains as $domain) {
      $domain = trim($domain, " \t\n\r\0\x0B.");
      if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/', $domain)) {
        $normalized_domains[] = $domain;
      }
    }

    return array_values(array_unique($normalized_domains));
  }

}
