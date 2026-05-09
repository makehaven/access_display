<?php

namespace Drupal\access_display\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Mints, stores, and resolves guest check-in tokens.
 *
 * Mirrors event_access_unifi/EventAccessManager's pattern of bin2hex(random_bytes(16))
 * but stores the token directly on the user entity (field_guest_token) rather
 * than a custom table — guests are first-class users with the `guest` role, so
 * the user record is the right place.
 */
class GuestTokenManager {

  public function __construct(
    private EntityTypeManagerInterface $etm,
  ) {}

  /**
   * Mint a fresh token, persist it on the user, and return it.
   *
   * Idempotent: if the user already has a token and $force is FALSE, returns
   * the existing one rather than rotating.
   */
  public function mintToken(UserInterface $account, bool $force = FALSE): string {
    $existing = $this->getToken($account);
    if ($existing !== NULL && !$force) {
      return $existing;
    }

    $token = bin2hex(random_bytes(16));
    $account->set('field_guest_token', $token);
    $account->set('field_guest_qr_url', $this->buildCheckinUrl($token));
    $account->save();

    return $token;
  }

  public function rotateToken(UserInterface $account): string {
    return $this->mintToken($account, TRUE);
  }

  public function getToken(UserInterface $account): ?string {
    if (!$account->hasField('field_guest_token')) {
      return NULL;
    }
    $value = $account->get('field_guest_token')->value;
    return $value === '' ? NULL : $value;
  }

  /**
   * Resolve a token to its owner user. Returns NULL on miss.
   */
  public function loadGuestByToken(string $token): ?UserInterface {
    if ($token === '' || strlen($token) > 64) {
      return NULL;
    }
    $ids = $this->etm->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_guest_token', $token)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $user = $this->etm->getStorage('user')->load(reset($ids));
    return $user instanceof UserInterface ? $user : NULL;
  }

  public function buildCheckinUrl(string $token): string {
    return Url::fromRoute('access_display.guest_checkin', ['token' => $token], ['absolute' => TRUE])->toString();
  }

}
