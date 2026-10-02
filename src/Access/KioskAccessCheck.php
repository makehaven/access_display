<?php

namespace Drupal\access_display\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gates the kiosk display page and presence feed.
 *
 * The presence feed lists who recently came through the doors (names, photos,
 * doors, times), so it must not be public. Access is granted when the client
 * IP matches the configured allowlist (the building's egress address, where
 * the kiosk screens live) or when the account holds the "view access display"
 * permission (staff viewing remotely). An empty allowlist fails closed: only
 * the permission grants access.
 */
class KioskAccessCheck implements AccessInterface {

  /**
   * Permission that grants access from any network.
   */
  const PERMISSION = 'view access display';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a KioskAccessCheck.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Checks access to a kiosk display or presence feed route.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current account.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result. Never cacheable, since it depends on the client IP.
   */
  public function access(AccountInterface $account, Request $request): AccessResultInterface {
    if ($account->hasPermission(self::PERMISSION)) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    $allowed = $this->configFactory->get('access_display.settings')->get('allowed_ips');
    if (self::ipIsAllowed($request->getClientIp(), is_array($allowed) ? $allowed : [])) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    return AccessResult::forbidden('Client IP is not on the access display allowlist.')->setCacheMaxAge(0);
  }

  /**
   * Determines whether an IP matches any allowlist entry.
   *
   * @param string|null $ip
   *   The client IP address.
   * @param string[] $allowed
   *   Allowlist entries, each an IP address or CIDR range.
   *
   * @return bool
   *   TRUE if the IP matches an entry. An empty allowlist never matches.
   */
  public static function ipIsAllowed(?string $ip, array $allowed): bool {
    $allowed = array_values(array_filter(array_map('trim', $allowed), 'strlen'));
    if ($ip === NULL || $ip === '' || !$allowed) {
      return FALSE;
    }
    return IpUtils::checkIp($ip, $allowed);
  }

  /**
   * Determines whether a string is a valid IP address or CIDR range.
   *
   * @param string $entry
   *   The allowlist entry.
   *
   * @return bool
   *   TRUE if valid.
   */
  public static function isValidEntry(string $entry): bool {
    $parts = explode('/', $entry, 2);
    if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
      return FALSE;
    }
    if (!isset($parts[1])) {
      return TRUE;
    }
    $max = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
    return ctype_digit($parts[1]) && (int) $parts[1] >= 0 && (int) $parts[1] <= $max;
  }

}
