<?php

namespace Drupal\access_display\Form;

use Drupal\access_display\Service\GuestTokenManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_navigator\Service\WaiverVersionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Streamlined re-sign form rendered when the controller detects a stale waiver
 * version on a guest token.
 *
 * Renders only the change summaries since the user's last signed version plus
 * a typed-name + checkbox e-sign confirmation. Real signature pad is reserved
 * for the original waiver (where webform_signature does the work); for a
 * TOS-style update re-affirmation a typed name + IP + timestamp logged in
 * watchdog is a standard e-sign artifact.
 */
class GuestWaiverResignForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    private GuestTokenManager $tokenManager,
    private WaiverVersionService $waiverVersion,
    private TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('access_display.guest_token_manager'),
      $container->get('makerspace_member_navigator.waiver_version'),
      $container->get('datetime.time'),
    );
    $instance->setRequestStack($container->get('request_stack'));
    return $instance;
  }

  public function getFormId(): string {
    return 'access_display_guest_waiver_resign';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $token = trim((string) ($request?->query->get('t') ?? ''));
    if ($token === '') {
      throw new AccessDeniedHttpException('Missing re-sign token.');
    }
    $user = $this->tokenManager->loadGuestByToken($token);
    if (!$user) {
      throw new AccessDeniedHttpException('Invalid re-sign link.');
    }

    $signedVersion = (int) ($user->get('field_waiver_version')->value ?? 0);
    $diff = $this->waiverVersion->diffSince($signedVersion);

    // Already current — bounce them through to check-in.
    if (empty($diff)) {
      $form_state->setRedirectUrl(Url::fromRoute('access_display.guest_checkin', ['token' => $token]));
      return $form;
    }

    $form['#attributes']['class'][] = 'guest-waiver-resign-form';

    $form['intro'] = [
      '#markup' => '<h2>' . $this->t('Quick re-sign') . '</h2><p>' . $this->t('Hi @name — our waiver text changed since you last signed. Please review the updates and re-sign to check in.', [
        '@name' => $user->get('field_first_name')->value ?: $user->getDisplayName(),
      ]) . '</p>',
    ];

    $items = [];
    foreach ($diff as $entry) {
      $items[] = $this->t('<strong>v@v</strong> — @summary', [
        '@v' => (int) ($entry['version'] ?? 0),
        '@summary' => (string) ($entry['summary'] ?? ''),
      ]);
    }
    $form['changes'] = [
      '#theme' => 'item_list',
      '#title' => $this->t("What's new"),
      '#items' => $items,
    ];

    $form['link_to_full'] = [
      '#markup' => '<p>' . $this->t('Read the <a href="@url" target="_blank">full waiver text</a> if you want the complete picture.', ['@url' => '/membership-agreement']) . '</p>',
    ];

    $form['confirm_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Type your full name'),
      '#required' => TRUE,
      '#default_value' => $user->get('field_legal_name')->value ?: $user->getDisplayName(),
    ];

    $form['confirm_agree'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the updated waiver terms.'),
      '#required' => TRUE,
    ];

    $form['token'] = ['#type' => 'value', '#value' => $token];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Re-sign and check in'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $token = (string) $form_state->getValue('token');
    $user = $this->tokenManager->loadGuestByToken($token);
    if (!$user) {
      throw new AccessDeniedHttpException('Invalid re-sign link.');
    }

    $version = $this->waiverVersion->currentVersion();
    $now = $this->time->getRequestTime();

    $user->set('field_waiver_version', $version);
    $user->set('field_waiver_signed_at', $now);
    $user->save();

    $ip = $this->getRequest()?->getClientIp() ?? '0.0.0.0';
    \Drupal::logger('access_display')->notice('Guest re-signed waiver: uid=@uid name="@name" version=@v ip=@ip', [
      '@uid' => $user->id(),
      '@name' => (string) $form_state->getValue('confirm_name'),
      '@v' => $version,
      '@ip' => $ip,
    ]);

    $form_state->setRedirectUrl(Url::fromRoute('access_display.guest_checkin', ['token' => $token]));
  }

}
