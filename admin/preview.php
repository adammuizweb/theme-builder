<?php
declare(strict_types=1);

// Theme Builder — Live Site Preview dashboard

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$dashUrl = $base . '/?page=admin/tools/theme-builder';
$editorUrl = $base . '/?page=admin/tools/theme-builder/editor';
$selfUrl = $base . '/?page=admin/tools/theme-builder/preview';

$slug = trim((string)($_GET['theme'] ?? ''));
if ($slug === '') { echo '<p>' . __('No theme selected.') . ' <a href="' . h($dashUrl) . '">' . __('Back') . '</a></p>'; return; }

$themeDir = ThemeWorkspace::themeDir($slug);
if (!is_dir($themeDir)) { echo '<p>' . __('Theme not found.') . ' <a href="' . h($dashUrl) . '">' . __('Back') . '</a></p>'; return; }

$manifest = ThemeWorkspace::readManifest($slug);
$name = $manifest['name'] ?? $slug;

// Sync draft to preview folder and create a secure token.
$sync = ThemePreview::sync($slug);
if (!empty($sync['error'])) {
    echo '<p class="tb-flash tb-flash-error">' . h($sync['error']) . '</p>';
    return;
}

$token = ThemePreview::createToken($slug);
// Use relative URLs so the iframe and quick links inherit the parent page protocol/host.
$previewBase = '/?tb_preview_theme=' . urlencode($slug) . '&tb_preview_token=' . urlencode($token);
$homeUrl = $previewBase;

// Helper to append preview params to a relative URL.
$previewUrl = function (string $path) use ($slug, $token): string {
    $sep = strpos($path, '?') === false ? '?' : '&';
    return $path . $sep . 'tb_preview_theme=' . urlencode($slug) . '&tb_preview_token=' . urlencode($token);
};

// Dynamic frontend prefixes (fall back to defaults if helpers unavailable).
$postsPrefix = function_exists('get_posts_list_routes') ? (get_posts_list_routes($pdo)[0] ?? 'artikel') : 'artikel';
$pagesPrefix = function_exists('get_pages_list_routes') ? (get_pages_list_routes($pdo)[0] ?? 'halaman') : 'halaman';
$categoryPrefixes = function_exists('get_category_routes') ? get_category_routes($pdo) : ['category'];
$categoryPrefix = $categoryPrefixes[0] ?? 'category';

// Sample pages for quick navigation — covers all Theme Builder slots.
$sampleUrls = [
    ['label' => __('Homepage'), 'slot' => 'main.homepage', 'url' => $previewUrl('/')],
    ['label' => __('Search'), 'slot' => 'main.search', 'url' => $previewUrl('/?s=theme')],
    ['label' => __('404'), 'slot' => 'main.404', 'url' => $previewUrl('/this-page-does-not-exist-404')],
    ['label' => __('List Posts'), 'slot' => 'list.post', 'url' => $previewUrl('/' . $postsPrefix . '/')],
    ['label' => __('List Pages'), 'slot' => 'list.page', 'url' => $previewUrl('/' . $pagesPrefix . '/')],
    ['label' => __('List Categories'), 'slot' => 'index.category', 'url' => $previewUrl('/' . $categoryPrefix . '/')],
    ['label' => __('List Authors'), 'slot' => 'index.author', 'url' => $previewUrl('/author/')],
    ['label' => __('List Archive'), 'slot' => 'list.archive', 'url' => $previewUrl('/' . date('Y') . '/')],
];

try {
    $postStmt = $pdo->query("SELECT title, slug FROM posts WHERE type = 'article' AND status = 'published' AND is_deleted = 0 ORDER BY id DESC LIMIT 1");
    $post = $postStmt ? $postStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($post) {
        $sampleUrls[] = ['label' => __('Single Post'), 'slot' => 'single.post', 'url' => $previewUrl('/' . urlencode((string)$post['slug']) . '/')];
    }
    $pageStmt = $pdo->query("SELECT title, slug FROM posts WHERE type = 'page' AND status = 'published' AND is_deleted = 0 ORDER BY id DESC LIMIT 1");
    $page = $pageStmt ? $pageStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($page) {
        $sampleUrls[] = ['label' => __('Single Page'), 'slot' => 'single.page', 'url' => $previewUrl('/' . urlencode((string)$page['slug']) . '/')];
    }
    $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id DESC LIMIT 1");
    $cat = $catStmt ? $catStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($cat) {
        $sampleUrls[] = ['label' => __('Category'), 'slot' => 'list.category', 'url' => $previewUrl('/' . $categoryPrefix . '/' . urlencode((string)$cat['slug']) . '/')];
    }
    $authorStmt = $pdo->query("SELECT username, display_name FROM users WHERE role IN ('admin','editor','author') ORDER BY id DESC LIMIT 1");
    $author = $authorStmt ? $authorStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($author && !empty($author['username'])) {
        $sampleUrls[] = ['label' => __('Author'), 'slot' => 'list.author', 'url' => $previewUrl('/author/' . urlencode((string)$author['username']) . '/')];
    }
} catch (Throwable $e) {}
?>

<div class="tb-preview-dashboard">
  <div class="tb-preview-topbar">
    <div class="tb-preview-brand">
      <a href="<?= h($dashUrl) ?>" class="btn btn-sm btn-outline">&larr; <?= __('Dashboard') ?></a>
      <a href="<?= h($editorUrl . '&theme=' . urlencode($slug)) ?>" class="btn btn-sm btn-outline"><?= __('Edit') ?></a>
    </div>
    <h3><?= __('Live Preview') ?> — <?= h($name) ?></h3>
    <div class="tb-preview-actions">
      <button id="tb-preview-reload" class="btn btn-sm btn-outline"><?= __('Reload') ?></button>
      <button id="tb-preview-close" class="btn btn-sm btn-danger"><?= __('Stop Preview') ?></button>
    </div>
  </div>

  <div class="tb-preview-toolbar">
    <div class="tb-preview-urlbar">
      <label for="tb-preview-url"><?= __('URL') ?></label>
      <input type="text" id="tb-preview-url" value="<?= h($homeUrl) ?>" readonly>
      <button id="tb-preview-copy" class="btn btn-sm btn-outline"><?= __('Copy') ?></button>
    </div>
    <div class="tb-preview-viewports">
      <button class="tb-vp-btn active" data-vp="100%" title="Desktop">&#x25A1;</button>
      <button class="tb-vp-btn" data-vp="768px" title="Tablet">&#x25AD;</button>
      <button class="tb-vp-btn" data-vp="375px" title="Mobile">&#x25B7;</button>
    </div>
  </div>

  <?php if (!empty($sampleUrls)): ?>
  <div class="tb-preview-samples">
    <label for="tb-preview-sample-select"><?= __('Quick links') ?></label>
    <select id="tb-preview-sample-select" class="tb-preview-select">
      <option value=""><?= __('- Choose page to preview -') ?></option>
      <?php foreach ($sampleUrls as $s): ?>
        <option value="<?= h($s['url']) ?>"><?= h($s['label']) ?> — <?= h($s['slot'] ?? '') ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="tb-preview-frame-wrap">
    <iframe id="tb-preview-frame" src="<?= h($homeUrl) ?>" class="tb-preview-frame"></iframe>
  </div>
</div>

<script>
(function() {
  var frame = document.getElementById('tb-preview-frame');
  var urlInput = document.getElementById('tb-preview-url');
  var reloadBtn = document.getElementById('tb-preview-reload');
  var closeBtn = document.getElementById('tb-preview-close');
  var copyBtn = document.getElementById('tb-preview-copy');
  var vpBtns = document.querySelectorAll('.tb-vp-btn');
  var sampleSelect = document.getElementById('tb-preview-sample-select');

  reloadBtn.addEventListener('click', function() {
    frame.contentWindow.location.reload();
  });

  closeBtn.addEventListener('click', function() {
    if (confirm('<?= __('Stop live preview and return to dashboard?') ?>')) {
      window.location.href = <?= json_encode($dashUrl) ?>;
    }
  });

  copyBtn.addEventListener('click', function() {
    urlInput.select();
    document.execCommand('copy');
    var old = copyBtn.textContent;
    copyBtn.textContent = '<?= __('Copied') ?>';
    setTimeout(function() { copyBtn.textContent = old; }, 1500);
  });

  vpBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      vpBtns.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      frame.style.width = this.dataset.vp;
    });
  });

  if (sampleSelect) {
    sampleSelect.addEventListener('change', function() {
      var url = this.value;
      if (!url) return;
      frame.src = url;
      urlInput.value = url;
      // Keep the selected option visible so the user knows which page they are previewing.
    });
  }

  // Update URL bar when iframe navigates (same origin only).
  frame.addEventListener('load', function() {
    try {
      var loc = frame.contentWindow.location.href;
      if (loc) urlInput.value = loc;
    } catch (err) {}
  });
})();
</script>
