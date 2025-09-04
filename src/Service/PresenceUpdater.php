<?php

namespace Drupal\access_display\Service;

use Drupal\Core\Database\Connection;
use Drupal\user\UserInterface;

class PresenceUpdater {
  public function __construct(private Connection $db, private $etm) {}

  const DEBOUNCE_SECONDS = 300;

  public function upsert(UserInterface $account, string $door, int $ts): void {
    $uid = (int) $account->id();
    $uuid = $account->uuid();
    $realname = $account->getDisplayName();

    $existing = $this->db->select('access_display_presence', 'p')
      ->fields('p', ['last_seen', 'door', 'scan_count'])
      ->condition('uid', $uid)
      ->execute()->fetchAssoc();

    if (!$existing) {
      $this->db->insert('access_display_presence')->fields([
        'uid' => $uid,
        'user_uuid' => $uuid,
        'realname' => $realname,
        'door' => $door,
        'first_seen' => $ts,
        'last_seen' => $ts,
        'scan_count' => 1,
      ])->execute();
      return;
    }

    $sameWindow = ($ts - (int) $existing['last_seen']) <= self::DEBOUNCE_SECONDS;
    $newCount = $sameWindow ? ((int) $existing['scan_count'] + 1) : 1;
    $newDoor = $sameWindow ? ($existing['door'] ?: $door) : $door;

    $this->db->update('access_display_presence')
      ->fields([
        'realname' => $realname,
        'door' => $newDoor,
        'last_seen' => $ts,
        'scan_count' => $newCount,
      ])
      ->condition('uid', $uid)
      ->execute();
  }
}
