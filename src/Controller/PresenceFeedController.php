<?php

namespace Drupal\access_display\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;

/**
 * JSON feed for kiosk presence.
 */
class PresenceFeedController extends ControllerBase {

  public function feed(Request $request): JsonResponse {
    $after = (int) ($request->query->get('after') ?? 0);
    $limit = max(1, min((int) ($request->query->get('limit') ?? 50), 200));

    $db = \Drupal::database();
    $q = $db->select('access_display_presence', 'p')
      ->fields('p', ['uid','user_uuid','realname','door','first_seen','last_seen','scan_count'])
      ->orderBy('last_seen', 'ASC')
      ->range(0, $limit);

    if ($after > 0) {
      $q->condition('last_seen', $after, '>');
    }

    $rows = $q->execute()->fetchAllAssoc('uid');

    $items = [];
    foreach ($rows as $r) {
      $uid = (int) $r->uid;
      $items[] = [
        'uid'   => $uid,
        'uuid'  => $r->user_uuid,
        'name'  => $r->realname,
        'door'  => $r->door,
        'first' => (int) $r->first_seen,
        'last'  => (int) $r->last_seen,
        'count' => (int) $r->scan_count,
        'photo' => $this->photoUrl($uid), // safe, optional
      ];
    }

    $res = new JsonResponse(['items' => $items, 'now' => time()]);
    $res->headers->set('Cache-Control', 'no-store, max-age=0');
    return $res;
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

      // Prefer 'member_photo' image style if available.
      if ($mh->moduleExists('image')) {
        $style = \Drupal\image\Entity\ImageStyle::load('member_photo');
        if ($style) {
          return $style->buildUrl($uri);
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
