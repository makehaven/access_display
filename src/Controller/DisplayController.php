<?php

namespace Drupal\access_display\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for the kiosk display page.
 */
class DisplayController extends ControllerBase {

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

    $content = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Display</title>
  <style>
    {$this->getCustomCss()}
  </style>
</head>
<body>
  <!-- KIOSK: Access Display (drop-in) -->
  <main class="kiosk"><h1>Recent Entries</h1></main>

  <script>
  (function () {
    const FEED = '{$feed_url}';

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

    let lastSeen = 0, fetching = false;

    function card(it) {
      const d = new Date(it.last * 1000).toLocaleString([], {hour:'2-digit', minute:'2-digit'});
      const el = document.createElement('article');
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
      meta.textContent = `\${it.door} — \${d}\${it.count > 1 ? ` (x\${it.count})` : ''}`;

      el.appendChild(name);
      el.appendChild(meta);
      return el;
    }

    function render(items) {
      for (const it of items) {
        GRID.prepend(card(it));
        lastSeen = Math.max(lastSeen, it.last);
      }
      while (GRID.children.length > 24) GRID.removeChild(GRID.lastChild);
    }

    async function tick() {
      if (fetching) return; fetching = true;
      try {
        const url = lastSeen ? `\${FEED}?after=\${lastSeen}&limit=24` : `\${FEED}?limit=24`;
        const rsp = await fetch(url, { cache: 'no-store' });
        if (!rsp.ok) { console.error('Feed HTTP', rsp.status); return; }
        const data = await rsp.json();
        if (Array.isArray(data.items) && data.items.length) render(data.items);
      } catch (e) {
        console.error('Feed error', e);
      } finally {
        fetching = false;
      }
    }

    tick();
    setInterval(tick, 7000);
  })();
  </script>
</body>
</html>
HTML;
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
.k-meta { opacity:.85; font-size:14px; padding:4px 12px 12px 12px; color:#c9c9c9; border-top:1px solid rgba(255,255,255,.06) }
@media (max-width:1200px){ .k-grid{ grid-template-columns: repeat(3,1fr) } }
@media (max-width:900px){ .k-grid{ grid-template-columns: repeat(2,1fr) } }
@media (max-width:600px){ .k-grid{ grid-template-columns: repeat(1,1fr) } }';
    return $config->get('custom_css') ?: $default_css;
  }

}
