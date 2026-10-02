<?php

namespace Drupal\access_display\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;

/**
 * JSON feed for kiosk presence.
 */
class PresenceFeedController extends ControllerBase {

  /**
   * Default recency window, in hours, when none is configured.
   */
  const DEFAULT_WINDOW_HOURS = 24;

  /**
   * Returns recent presence rows as JSON for the kiosk display.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; reads the optional "after" and "limit" query parameters.
   * @param string $permission
   *   A permission machine name to filter by, or "_all".
   * @param string|null $source
   *   (optional) A door name to filter by.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The feed.
   */
  public function feed(Request $request, string $permission, ?string $source = NULL): JsonResponse {
    $after = (int) ($request->query->get('after') ?? 0);
    $limit = max(1, min((int) ($request->query->get('limit') ?? 50), 200));

    // Only recent activity is served: the presence table is never pruned, and
    // the kiosk has no use for entries older than the window.
    $window_hours = (int) $this->config('access_display.settings')->get('feed_window_hours');
    if ($window_hours <= 0) {
      $window_hours = self::DEFAULT_WINDOW_HOURS;
    }
    $cutoff = \Drupal::time()->getRequestTime() - ($window_hours * 3600);

    $db = \Drupal::database();
    $q = $db->select('access_display_presence', 'p')
      ->fields('p', ['uid', 'realname', 'door', 'first_seen', 'last_seen', 'scan_count'])
      ->condition('last_seen', $cutoff, '>=')
      ->orderBy('last_seen', 'DESC')
      ->range(0, $limit);

    if ($after > 0) {
      $q->condition('last_seen', $after, '>');
    }

    if ($source) {
      $q->condition('door', $source);
    }

    if ($permission !== '_all') {
      // Get all role IDs that have the specified permission.
      $roles_with_permission = [];
      $roles = \Drupal\user\Entity\Role::loadMultiple();
      foreach ($roles as $role) {
        if ($role->hasPermission($permission)) {
          $roles_with_permission[] = $role->id();
        }
      }

      if (empty($roles_with_permission)) {
        return new JsonResponse(['items' => [], 'now' => time()]);
      }

      // Get all user IDs that have one of the roles.
      $uids = \Drupal::entityQuery('user')
        ->condition('status', 1)
        ->condition('roles', $roles_with_permission, 'IN')
        ->accessCheck(FALSE)
        ->execute();

      if (empty($uids)) {
        return new JsonResponse(['items' => [], 'now' => time()]);
      }

      $q->condition('p.uid', $uids, 'IN');
    }

    $rows = $q->execute()->fetchAllAssoc('uid');

    $guestCounts = $this->guestCountsForRows($rows);

    $items = [];
    foreach ($rows as $r) {
      $uid = (int) $r->uid;
      $items[] = [
        'uid'   => $uid,
        'name'  => $r->realname,
        'door'  => $r->door,
        'first' => (int) $r->first_seen,
        'last'  => (int) $r->last_seen,
        'count' => (int) $r->scan_count,
        'guest_count' => (int) ($guestCounts[$uid] ?? 0),
        'photo' => $this->photoUrl($uid), // safe, optional
      ];
    }

    // Reverse the array so that the newest items (which we queried for) are last.
    // The javascript client will then display them in that order, so newest is at the top.
    // However, if the client reverses the list, this will be wrong.
    // Based on user feedback, the client expects ASC order.
    // The DB query is DESC to get the N most recent items.
    // We reverse them here to send them to the client in ASC order.
    $items = array_reverse($items);

    $res = new JsonResponse(['items' => $items, 'now' => time()]);
    $res->headers->set('Cache-Control', 'no-store, max-age=0');
    return $res;
  }

  /**
   * Count guest_checkin log rows per host since each host's first_seen.
   *
   * Reads field_host_member, which is set on every guest_checkin row that
   * has a known host: ward pre-check-ins (host = guardian), waiver-signup
   * with a typed host email that resolved to a real user, and any future
   * QR check-ins where the user's last-known host is propagated. Adult
   * guests who never declared a host stay uncounted, by design.
   *
   * @param array $rows
   *   Presence rows keyed by uid (each with first_seen).
   *
   * @return array<int, int>
   *   uid => count of guest_checkin rows since that uid's first_seen.
   */
  private function guestCountsForRows(array $rows): array {
    if (!$rows) {
      return [];
    }
    $counts = [];
    $storage = \Drupal::entityTypeManager()->getStorage('access_control_log');
    foreach ($rows as $r) {
      $uid = (int) $r->uid;
      $first = (int) $r->first_seen;
      if ($uid <= 0 || $first <= 0) {
        continue;
      }
      try {
        $counts[$uid] = (int) $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'guest_checkin')
          ->condition('field_host_member', $uid)
          ->condition('created', $first, '>=')
          ->count()
          ->execute();
      }
      catch (\Throwable $e) {
        \Drupal::logger('access_display')->error('Guest count failed for uid @uid: @msg', [
          '@uid' => $uid,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    return $counts;
  }

  /**
   * Return a styled photo URL for the user's "main" profile, or NULL.
   * Uses storage->loadByProperties() (works across D10/11).
   */
  private function photoUrl(int $uid): ?string {
    try {
      $mh = \Drupal::moduleHandler();
      if (!$mh->moduleExists('profile')) {
        return NULL;
      }

      // Load the user's "main" profile via storage.
      $storage = \Drupal::entityTypeManager()->getStorage('profile');
      $profiles = $storage->loadByProperties(['uid' => $uid, 'type' => 'main']);
      $profile = $profiles ? reset($profiles) : NULL;
      if (!$profile || !$profile->hasField('field_member_photo') || $profile->get('field_member_photo')->isEmpty()) {
        return NULL;
      }

      $file = $profile->get('field_member_photo')->entity;
      if (!$file) return NULL;
      $uri = $file->getFileUri();

      // Use the configured image style.
      if ($mh->moduleExists('image')) {
        $config = $this->config('access_display.settings');
        $image_style_id = $config->get('image_style');
        if ($image_style_id) {
          $style = \Drupal\image\Entity\ImageStyle::load($image_style_id);
          if ($style) {
            return $style->buildUrl($uri);
          }
        }
      }
      return \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
    }
    catch (\Throwable $e) {
      \Drupal::logger('access_display')->error('Photo lookup failed for uid @uid: @msg', [
        '@uid' => $uid,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }
}
