<?php

namespace Drupal\access_display\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_kiosk\KioskResilience;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for the kiosk display page.
 */
class DisplayController extends ControllerBase {

  /**
   * The shared kiosk resilience runtime.
   *
   * @var \Drupal\makerspace_kiosk\KioskResilience
   */
  protected KioskResilience $kioskResilience;

  /**
   * Constructs a DisplayController.
   *
   * @param \Drupal\makerspace_kiosk\KioskResilience $kiosk_resilience
   *   The shared kiosk resilience runtime.
   */
  public function __construct(KioskResilience $kiosk_resilience) {
    $this->kioskResilience = $kiosk_resilience;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('makerspace_kiosk.resilience'));
  }

  /**
   * Unwraps a <style> element so its CSS can be merged into this page's
   * existing single style block.
   *
   * @param string $style_element
   *   A style element, possibly empty.
   *
   * @return string
   *   The bare CSS.
   */
  protected function stripStyleTag(string $style_element): string {
    return preg_replace('#^<style>|</style>$#', '', $style_element) ?? '';
  }

  /**
   * Renders the kiosk display page.
   *
   * @param string $code_word
   *   The secret code word for access.
   * @param string|null $permission
   *   The permission to filter by.
   * @param string|null $source
   *   (optional) The source to filter by.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The rendered page.
   */
  public function displayPage(string $code_word, ?string $permission = NULL, ?string $source = NULL) {
    $config_code_word = $this->config('access_display.settings')->get('code_word');
    if ($config_code_word && $code_word !== $config_code_word) {
      throw new AccessDeniedHttpException();
    }

    $feed_permission = $permission ?? '_all';
    $feed_url = '/access-display/presence/' . $feed_permission;
    if ($source) {
      $feed_url .= '/' . $source;
    }

    $template = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Display</title>
  <style>
%s
  </style>
</head>
<body>
  <!-- KIOSK: Access Display (drop-in) -->
  <main class="kiosk"><h1>Recent Entries</h1></main>

  <script>
  (function () {
    const FEED = '%s';

    // Ensure a grid exists even if the editor strips it.
    let main = document.querySelector('main.kiosk');
    if (!main) {
      main = document.createElement('main');
      main.className = 'kiosk';
      const h1 = document.createElement('h1'); h1.textContent = 'Recent Entries';
      main.appendChild(h1);
      document.body.appendChild(main);
    }
    let GRID = document.getElementById('kiosk-grid');
    if (!GRID) {
      GRID = document.createElement('div');
      GRID.id = 'kiosk-grid';
      GRID.className = 'k-grid';
      main.appendChild(GRID);
    }

    let lastSeen = 0;

    function card(it) {
      const d = new Date(it.last * 1000).toLocaleString([], {hour:'2-digit', minute:'2-digit', timeZone: 'America/New_York'});
      const el = document.createElement('article');
      el.id = `k-card-${it.uid}`;
      el.className = 'k-card';

      if (it.photo) {
        const img = document.createElement('img');
        img.className = 'k-photo';
        img.alt = it.name;
        img.src = it.photo;
        el.appendChild(img);
      }

      const name = document.createElement('div');
      name.className = 'k-name';
      name.textContent = it.name;

      const meta = document.createElement('div');
      meta.className = 'k-meta';
      meta.textContent = `${it.door} — ${d}${it.count > 1 ? ` (x${it.count})` : ''}`;

      el.appendChild(name);
      el.appendChild(meta);

      if (it.guest_count && it.guest_count > 0) {
        const guests = document.createElement('div');
        guests.className = 'k-guests';
        guests.textContent = `+ ${it.guest_count} guest${it.guest_count === 1 ? '' : 's'}`;
        el.appendChild(guests);
      }
      return el;
    }

    function render(items) {
      for (const it of items) {
        document.getElementById(`k-card-${it.uid}`)?.remove();
        GRID.prepend(card(it));
        lastSeen = Math.max(lastSeen, it.last);
      }
      while (GRID.children.length > 24) GRID.removeChild(GRID.lastChild);
    }

    // Polling, backoff, staleness and recovery all belong to the shared kiosk
    // runtime (makerspace_kiosk). This board previously swallowed every fetch
    // error, and because the grid only ever appends, a dead feed rendered
    // identically to a live one - it was failing silently for who knows how
    // long. The status chip is the fix for that specifically.
    window.__accessDisplayFeedUrl = function () {
      return lastSeen ? `${FEED}?after=${lastSeen}&limit=24` : `${FEED}?limit=24`;
    };
    window.__accessDisplayRender = function (data) {
      if (Array.isArray(data.items) && data.items.length) render(data.items);
    };
  })();
  </script>
%s
</body>
</html>
HTML;
    $kiosk = $this->kioskResilience;
    $script = $kiosk->script(
      [
        'screenId' => 'faces' . ($permission ? '-' . $permission : ''),
        // Unchanged from the previous hardcoded cadence: the 7s poll was
        // ~12k requests/day against Pantheon's pages-served limit, and
        // presence changes do not need sub-30s freshness.
        'intervalMs' => 30000,
        'statusChipPosition' => 'bottom-right',
      ],
      [
        'feedUrl' => 'function () { return window.__accessDisplayFeedUrl(); }',
        'onData' => 'function (data) { window.__accessDisplayRender(data); }',
      ]
    );

    $content = sprintf(
      $template,
      $this->getCustomCss() . "\n" . $this->stripStyleTag($kiosk->styles()),
      $feed_url,
      $script
    );
    return new Response($content);
  }

  /**
   * Gets the custom CSS from the configuration.
   *
   * @return string
   *   The custom CSS.
   */
  protected function getCustomCss() {
    $config = $this->config('access_display.settings');
    $default_css = '.kiosk { font-family: system-ui, sans-serif; background:#000; color:#fff; padding:16px; min-height:100vh }
.kiosk h1 { margin:0 0 12px; font-size:28px; color:#cfcfcf }
.k-grid { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:16px }
.k-card { background:#111; border-radius:16px; box-shadow:0 2px 10px rgba(0,0,0,.35); overflow:hidden; display:flex; flex-direction:column }
.k-photo { width:100%; height:220px; object-fit:cover; display:block; background:#222 }
.k-name { font-weight:600; font-size:18px; padding:10px 12px 0 12px }
.k-meta { opacity:.85; font-size:14px; padding:4px 12px 4px 12px; color:#c9c9c9; border-top:1px solid rgba(255,255,255,.06) }
.k-guests { font-size:14px; padding:4px 12px 12px 12px; color:#fff; background:rgba(255,255,255,.08); font-weight:600 }
@media (max-width:1200px){ .k-grid{ grid-template-columns: repeat(3,1fr) } }
@media (max-width:900px){ .k-grid{ grid-template-columns: repeat(2,1fr) } }
@media (max-width:600px){ .k-grid{ grid-template-columns: repeat(1,1fr) } }';
    return $config->get('custom_css') ?: $default_css;
  }

}
