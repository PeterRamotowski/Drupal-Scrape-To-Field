<?php

namespace Drupal\Tests\scrape_to_field\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\scrape_to_field\Service\TargetUrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests the scrape target URL policy.
 */
class TargetUrlPolicyTest extends TestCase {

  /**
   * Tests that public HTTPS IP targets are allowed.
   */
  public function testPublicHttpsTargetIsAllowed(): void {
    $policy = new TargetUrlPolicy($this->createConfigFactory(''));

    $this->assertTrue($policy->isAllowed('https://93.184.216.34/index.html'));
  }

  /**
   * Tests that plain HTTP targets are blocked.
   */
  public function testHttpTargetIsBlocked(): void {
    $policy = new TargetUrlPolicy($this->createConfigFactory(''));

    $this->assertFalse($policy->isAllowed('http://example.com/index.html'));
  }

  /**
   * Tests that loopback targets are blocked.
   */
  public function testLoopbackTargetIsBlocked(): void {
    $policy = new TargetUrlPolicy($this->createConfigFactory(''));

    $this->assertFalse($policy->isAllowed('https://127.0.0.1/'));
    $this->assertFalse($policy->isAllowed('https://[::1]/'));
  }

  /**
   * Tests that embedded URL credentials are blocked.
   */
  public function testEmbeddedCredentialsAreBlocked(): void {
    $policy = new TargetUrlPolicy($this->createConfigFactory(''));

    $this->assertFalse($policy->isAllowed('https://user:pass@example.com/'));
  }

  /**
   * Tests the optional domain allowlist.
   */
  public function testDomainAllowlistBlocksOtherDomains(): void {
    $policy = new TargetUrlPolicy($this->createConfigFactory('example.com'));

    $this->assertFalse($policy->isAllowed('https://93.184.216.34/'));
  }

  /**
   * Creates a config factory mock with an allowed domain value.
   */
  protected function createConfigFactory(string $allowed_domains): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config
      ->method('get')
      ->willReturnMap([
        ['allowed_domains', $allowed_domains],
      ]);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory
      ->method('get')
      ->with('scrape_to_field.settings')
      ->willReturn($config);

    return $config_factory;
  }

}
