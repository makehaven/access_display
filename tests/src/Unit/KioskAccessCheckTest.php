<?php

declare(strict_types=1);

namespace Drupal\Tests\access_display\Unit;

use Drupal\access_display\Access\KioskAccessCheck;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the kiosk display IP allowlist and permission gate.
 */
#[CoversClass(KioskAccessCheck::class)]
#[Group('access_display')]
class KioskAccessCheckTest extends UnitTestCase {

  /**
   * Builds the access check with the given allowlist.
   */
  protected function buildCheck(mixed $allowed_ips): KioskAccessCheck {
    $config_factory = $this->getConfigFactoryStub([
      'access_display.settings' => ['allowed_ips' => $allowed_ips],
    ]);
    return new KioskAccessCheck($config_factory);
  }

  /**
   * Builds an account with or without the bypass permission.
   */
  protected function account(bool $has_permission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with(KioskAccessCheck::PERMISSION)
      ->willReturn($has_permission);
    return $account;
  }

  /**
   * Builds a request from the given client IP.
   */
  protected function request(string $ip): Request {
    return Request::create('/access-display/presence/_all', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
  }

  /**
   * An exact allowlisted IP is allowed, uncacheably.
   */
  public function testExactIpMatchIsAllowed(): void {
    $result = $this->buildCheck(['32.218.105.26'])
      ->access($this->account(FALSE), $this->request('32.218.105.26'));
    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * An IP inside an allowlisted CIDR range is allowed; outside is not.
   */
  public function testCidrMatchIsAllowed(): void {
    $check = $this->buildCheck(['198.51.100.0/24']);
    $this->assertTrue($check->access($this->account(FALSE), $this->request('198.51.100.77'))->isAllowed());
    $this->assertTrue($check->access($this->account(FALSE), $this->request('198.51.101.1'))->isForbidden());
  }

  /**
   * An IP not on the allowlist is forbidden.
   */
  public function testOtherIpIsForbidden(): void {
    $result = $this->buildCheck(['32.218.105.26'])
      ->access($this->account(FALSE), $this->request('203.0.113.9'));
    $this->assertTrue($result->isForbidden());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * An empty or blank allowlist denies everyone without the permission.
   */
  public function testEmptyAllowlistFailsClosed(): void {
    foreach ([[], NULL, ['', '  ']] as $allowed) {
      $result = $this->buildCheck($allowed)
        ->access($this->account(FALSE), $this->request('127.0.0.1'));
      $this->assertTrue($result->isForbidden());
    }
  }

  /**
   * The bypass permission allows access from any IP.
   */
  public function testPermissionAllowsFromAnyIp(): void {
    $result = $this->buildCheck([])
      ->access($this->account(TRUE), $this->request('203.0.113.9'));
    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Allowlist entry validation accepts IPs and CIDRs only.
   */
  public function testIsValidEntry(): void {
    foreach (['32.218.105.26', '10.0.0.0/8', '2001:db8::1', '2001:db8::/32', '0.0.0.0/0'] as $ok) {
      $this->assertTrue(KioskAccessCheck::isValidEntry($ok), $ok);
    }
    foreach (['', 'example.com', '10.0.0.0/33', '10.0.0.0/', '10.0.0.0/x', '2001:db8::/129', '300.1.1.1'] as $bad) {
      $this->assertFalse(KioskAccessCheck::isValidEntry($bad), $bad);
    }
  }

}
