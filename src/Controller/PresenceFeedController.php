<?php

namespace Drupal\access_display\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Drupal\profile\Entity\Profile;
use Drupal\image\Entity\ImageStyle;

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

    // Helper to get profile photo URL (style: member_photo). Fallback: null.
    $photo = function (int $uid): ?string {
      /** @var \Drupal\user\UserInterface|null $u */
      $u = User::load($uid);
      if (!$u) return NULL;

      // Load the user's "main" profile.
      /** @var \Drupal\profile\ProfileInterface|null $prof */
      $prof = Profile::loadByUser($u, 'main');
      if (!$prof || !$prof->hasField('field_member_photo') || $prof->get('field_member_photo')->isEmpty()) {
        return NULL;
      }
      $file = $prof->get('field_member_photo')->entity;
      if (!$file) return NULL;
      $uri = $file->getFileUri();

      // Use your existing image style from the View.
      $style = ImageStyle::load('member_photo');
      return $style ? $style->buildUrl($uri) : file_create_url($uri);
    };

    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        'uid'   => (int) $r->uid,
        'uuid'  => $r->user_uuid,
        'name'  => $r->realname,
        'door'  => $r->door,
        'first' => (int) $r->first_seen,
        'last'  => (int) $r->last_seen,
        'count' => (int) $r->scan_count,
        'photo' => $photo((int) $r->uid),  // <-- NEW
      ];
    }

    $res = new JsonResponse(['items' => $items, 'now' => time()]);
    $res->headers->set('Cache-Control', 'no-store, max-age=0');
    return $res;
  }
}
