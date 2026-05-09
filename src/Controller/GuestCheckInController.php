<?php

namespace Drupal\access_display\Controller;

use Drupal\access_display\Service\GuestTokenManager;
use Drupal\access_display\Service\PresenceUpdater;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_navigator\Service\WaiverVersionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Handles GET /checkin/guest/{token}.
 *
 * Flow:
 *   1. Flood-check the requesting IP (5/min). Register on miss to throttle scrapers.
 *   2. Resolve token to a user via GuestTokenManager. 403 on miss (no enumeration).
 *   3. If the user's signed waiver version is stale, redirect to the re-sign webform.
 *   4. Insert an access_control_log row (bundle guest_checkin).
 *   5. Upsert into access_display_presence so the kiosk grid picks them up on its next 7s poll.
 *   6. Render a confirmation page with the manual-sticker reminder (Phase 1 fallback).
 */
class GuestCheckInController extends ControllerBase implements ContainerInjectionInterface {

  private const FLOOD_EVENT = 'access_display.guest_checkin';
  private const FLOOD_THRESHOLD = 5;
  private const FLOOD_WINDOW = 60;

  public function __construct(
    private GuestTokenManager $tokenManager,
    private PresenceUpdater $presenceUpdater,
    private WaiverVersionService $waiverVersion,
    private FloodInterface $flood,
    private TimeInterface $time,
    private RequestStack $requestStack,
    private LoggerChannelInterface $logger,
    private EntityTypeManagerInterface $etm,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('access_display.guest_token_manager'),
      $container->get('access_display.updater'),
      $container->get('makerspace_member_navigator.waiver_version'),
      $container->get('flood'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('access_display'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Look up the most recent prior guest_checkin row for this user that has a
   * field_host_member set. NULL on miss. Used to propagate last-known host
   * onto a fresh QR self-checkin so the host's "+N" count stays accurate
   * across return visits without re-asking the guest who they came in with.
   */
  private function lastKnownHost(int $uid): ?int {
    $ids = $this->etm->getStorage('access_control_log')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'guest_checkin')
      ->condition('field_access_request_user', $uid)
      ->exists('field_host_member')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $log = $this->etm->getStorage('access_control_log')->load(reset($ids));
    $hostId = $log?->get('field_host_member')->target_id;
    return $hostId ? (int) $hostId : NULL;
  }

  public function check(string $token) {
    $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '0.0.0.0';

    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW, $ip)) {
      throw new TooManyRequestsHttpException(self::FLOOD_WINDOW, 'Too many check-in attempts. Try again in a minute.');
    }

    $user = $this->tokenManager->loadGuestByToken($token);
    if (!$user) {
      $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $ip);
      throw new AccessDeniedHttpException('Invalid check-in link.');
    }

    if (!$this->waiverVersion->userIsCurrent($user)) {
      $resign = Url::fromUri('internal:/form/guest_waiver_resign', [
        'query' => ['t' => $token],
      ])->toString();
      return new RedirectResponse($resign);
    }

    $now = $this->time->getRequestTime();

    $logFields = [
      'type' => 'guest_checkin',
      'field_access_request_user' => ['target_id' => $user->id()],
      'field_access_request_method' => 'guest_qr',
    ];
    if ($lastHost = $this->lastKnownHost((int) $user->id())) {
      $logFields['field_host_member'] = ['target_id' => $lastHost];
    }
    $this->etm->getStorage('access_control_log')->create($logFields)->save();

    $this->presenceUpdater->upsert($user, 'guest_qr', $now);

    $this->logger->notice('Guest check-in: @name (uid @uid) via guest_qr.', [
      '@name' => $user->getDisplayName(),
      '@uid' => $user->id(),
    ]);

    return [
      '#theme' => 'access_display_guest_checkin_confirmation',
      '#guest_name' => $user->getDisplayName(),
      '#checked_in_at' => $now,
      '#cache' => ['max-age' => 0],
    ];
  }

}
