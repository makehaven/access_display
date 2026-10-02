<?php

namespace Drupal\access_display\Form;

use Drupal\access_display\Access\KioskAccessCheck;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Configure access display settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'access_display_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['access_display.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('access_display.settings');

    $image_styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    $options = [];
    foreach ($image_styles as $image_style) {
      $options[$image_style->id()] = $image_style->label();
    }

    $form['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Style'),
      '#description' => $this->t('Select the image style to use for the user photos.'),
      '#options' => $options,
      '#default_value' => $config->get('image_style'),
    ];

    $form['code_word'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Code Word'),
      '#description' => $this->t('A secret code word to include in the URL for simple protection against scraping. If left blank, no code word is required.'),
      '#default_value' => $config->get('code_word'),
    ];

    $allowed_ips = $config->get('allowed_ips');
    $form['allowed_ips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed IP addresses'),
      '#description' => $this->t('One IP address or CIDR range per line (e.g. <code>203.0.113.7</code> or <code>203.0.113.0/24</code>). The display page and presence feed are served only to these addresses, or to accounts with the "View the access display and presence feed from any network" permission. Leave empty to allow only that permission. Use the building\'s public egress IP for the kiosk screens, and re-check it after any ISP or router change, or the kiosks go blank. Your current IP, as this site sees it, is %ip.', ['%ip' => $this->getRequest()->getClientIp() ?? '?']),
      '#default_value' => is_array($allowed_ips) ? implode("\n", $allowed_ips) : '',
      '#rows' => 4,
    ];

    $form['feed_window_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Feed window (hours)'),
      '#description' => $this->t('Only entries seen within this many hours are returned by the presence feed.'),
      '#default_value' => $config->get('feed_window_hours') ?: 24,
      '#min' => 1,
      '#max' => 168,
      '#required' => TRUE,
    ];

    $form['usage'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Usage'),
    ];

    $form['usage']['help'] = [
      '#markup' => $this->t('<p>The access display page is available at the following URLs:</p>
        <ul>
          <li><code>/display/access-request/{code_word}</code> - Displays all access records.</li>
          <li><code>/display/access-request/{code_word}/{permission}</code> - Filters by a user permission.</li>
          <li><code>/display/access-request/{code_word}/{permission}/{source}</code> - Filters by permission and a source (e.g., a door name).</li>
        </ul>
        <p><strong>URL Parameters:</strong></p>
        <ul>
          <li><code>{code_word}</code>: The secret code word configured above.</li>
          <li><code>{permission}</code>: The machine name of a Drupal user permission (e.g., <code>door</code>).</li>
          <li><code>{source}</code>: The name of the source (e.g., a door name like <code>main-entrance</code>).</li>
        </ul>
        <p><strong>Example:</strong></p>
        <p>If your code word is <code>kiosk123</code>, you want to filter by the permission <code>door</code>, and the source is <code>main-entrance</code>, your URL would be:</p>
        <code>/display/access-request/kiosk123/door/main-entrance</code>'),
    ];

    $default_css = $config->get('custom_css') ?: '.kiosk { font-family: system-ui, sans-serif; background:#000; color:#fff; padding:16px; min-height:100vh }
.kiosk h1 { margin:0 0 12px; font-size:28px; color:#cfcfcf }
.k-grid { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:16px }
.k-card { background:#111; border-radius:16px; box-shadow:0 2px 10px rgba(0,0,0,.35); overflow:hidden; display:flex; flex-direction:column }
.k-photo { width:100%; height:220px; object-fit:cover; display:block; background:#222 }
.k-name { font-weight:600; font-size:18px; padding:10px 12px 0 12px }
.k-meta { opacity:.85; font-size:14px; padding:4px 12px 12px 12px; color:#c9c9c9; border-top:1px solid rgba(255,255,255,.06) }
@media (max-width:1200px){ .k-grid{ grid-template-columns: repeat(3,1fr) } }
@media (max-width:900px){ .k-grid{ grid-template-columns: repeat(2,1fr) } }
@media (max-width:600px){ .k-grid{ grid-template-columns: repeat(1,1fr) } }';

    $form['custom_css'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom CSS'),
      '#description' => $this->t('Add custom CSS to style the display page. This will be added to the <code>&lt;style&gt;</code> tag on the page.'),
      '#default_value' => $default_css,
      '#rows' => 15,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    foreach ($this->parseAllowedIps((string) $form_state->getValue('allowed_ips')) as $entry) {
      if (!KioskAccessCheck::isValidEntry($entry)) {
        $form_state->setErrorByName('allowed_ips', $this->t('%entry is not a valid IP address or CIDR range.', ['%entry' => $entry]));
      }
    }
  }

  /**
   * Splits the allowed IPs textarea into trimmed, non-empty entries.
   *
   * @param string $value
   *   The raw textarea value.
   *
   * @return string[]
   *   The entries.
   */
  protected function parseAllowedIps(string $value): array {
    $lines = preg_split('/\R/', $value) ?: [];
    return array_values(array_unique(array_filter(array_map('trim', $lines), 'strlen')));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('access_display.settings')
      ->set('image_style', $form_state->getValue('image_style'))
      ->set('code_word', $form_state->getValue('code_word'))
      ->set('custom_css', $form_state->getValue('custom_css'))
      ->set('allowed_ips', $this->parseAllowedIps((string) $form_state->getValue('allowed_ips')))
      ->set('feed_window_hours', (int) $form_state->getValue('feed_window_hours'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
