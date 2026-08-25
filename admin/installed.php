<?php
declare(strict_types=1);

// Installed Theme Inspector - source is displayed but never executed or mutated.

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }
adiwira_require_site_owner($pdo, false);

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$dashUrl = $base . '/?page=admin/tools/theme-builder';
$selfUrl = $base . '/?page=admin/tools/theme-builder/installed';
$inspector = new InstalledThemeInspector($pdo);
$folder = trim((string)($_GET['theme'] ?? ''));

if ($folder === ''):
    try {
        $themes = $inspector->themes();
    } catch (Throwable $e) {
        echo '<div class="tb-flash tb-flash-error">' . h($e->getMessage()) . '</div>';
        return;
    }
?>
<div class="tb-dashboard tb-installed-list">
  <div class="tb-header tb-header-row">
    <div>
      <h2><?= __('Installed Themes') ?></h2>
      <p class="muted"><?= __('Read-only PHP source inventory for themes registered with Jyavani.') ?></p>
    </div>
    <a href="<?= h($dashUrl) ?>" class="btn btn-outline">&larr; <?= __('Theme Builder') ?></a>
  </div>

  <?php if (!$themes): ?>
    <div class="tb-inspector-empty"><?= __('No registered themes were found.') ?></div>
  <?php else: ?>
    <div class="tb-theme-grid">
      <?php foreach ($themes as $theme): ?>
        <article class="tb-theme-card <?= !$theme['inspectable'] ? 'tb-theme-card-error' : '' ?>">
          <div class="tb-theme-card-header">
            <h4><?= h($theme['name']) ?></h4>
            <span class="tb-version"><?= $theme['version'] !== '' ? 'v' . h($theme['version']) : h($theme['folder']) ?></span>
          </div>
          <p class="tb-theme-desc"><?= h($theme['description'] ?: __('No description')) ?></p>
          <div class="tb-theme-meta">
            <?php if ($theme['active']): ?><span class="tb-inspector-badge is-active"><?= __('Active') ?></span><?php endif; ?>
            <?php if ($theme['system']): ?><span class="tb-inspector-badge is-system"><?= __('System') ?></span><?php endif; ?>
            <?php if ($theme['store']): ?><span class="tb-inspector-badge is-store"><?= __('Store') ?></span><?php endif; ?>
            <span class="tb-files"><?= (int)$theme['php_files'] ?> <?= __('PHP files') ?></span>
          </div>
          <?php if ($theme['inspectable']): ?>
            <a class="btn btn-sm btn-primary" href="<?= h($selfUrl . '&theme=' . rawurlencode($theme['folder'])) ?>"><?= __('Inspect Source') ?></a>
          <?php else: ?>
            <p class="tb-inspector-error"><?= h((string)$theme['error']) ?></p>
            <button class="btn btn-sm btn-outline" disabled><?= __('Unavailable') ?></button>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
    return;
endif;

try {
    $inspection = $inspector->inspect($folder);
    $files = $inspection['files'];
    $requestedFile = trim((string)($_GET['file'] ?? ''));
    $fileId = preg_match('/\A[a-f0-9]{64}\z/D', $requestedFile) ? $requestedFile : '';
    if ($fileId === '' && $files) $fileId = (string)$files[0]['id'];
    $source = $fileId !== '' ? $inspector->source($folder, $fileId) : null;
    if ($source === null && $files) {
        $fileId = (string)$files[0]['id'];
        $source = $inspector->source($folder, $fileId);
    }
} catch (Throwable $e) {
    echo '<div class="tb-flash tb-flash-error">' . h($e->getMessage()) . '</div>';
    echo '<p><a class="btn btn-outline" href="' . h($selfUrl) . '">&larr; ' . h(__('Installed Themes')) . '</a></p>';
    return;
}

$theme = $inspection['theme'];
$groupedFiles = [];
foreach ($files as $file) $groupedFiles[$file['category_label']][] = $file;
$fileUrl = static function (string $id) use ($selfUrl, $folder): string {
    return $selfUrl . '&theme=' . rawurlencode($folder) . '&file=' . rawurlencode($id);
};
?>
<link rel="stylesheet" href="/static/vendor/codemirror/codemirror.min.css">
<link rel="stylesheet" href="/static/vendor/codemirror/theme/dracula.min.css">
<script src="/static/vendor/codemirror/codemirror.min.js"></script>
<script src="/static/vendor/codemirror/mode/xml/xml.min.js"></script>
<script src="/static/vendor/codemirror/mode/javascript/javascript.min.js"></script>
<script src="/static/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="/static/vendor/codemirror/mode/clike/clike.min.js"></script>
<script src="/static/vendor/codemirror/mode/php/php.min.js"></script>

<div class="tb-installed">
  <div class="tb-editor-topbar tb-installed-topbar">
    <a href="<?= h($selfUrl) ?>" class="btn btn-sm btn-outline">&larr; <?= __('Installed Themes') ?></a>
    <div class="tb-installed-title">
      <h3><?= h($theme['name']) ?> <span class="tb-version"><?= $theme['version'] !== '' ? 'v' . h($theme['version']) : '' ?></span></h3>
      <code><?= h($theme['folder']) ?></code>
    </div>
    <div class="tb-theme-meta">
      <?php if ($theme['active']): ?><span class="tb-inspector-badge is-active"><?= __('Active') ?></span><?php endif; ?>
      <?php if ($theme['system']): ?><span class="tb-inspector-badge is-system"><?= __('System') ?></span><?php endif; ?>
      <?php if ($theme['store']): ?><span class="tb-inspector-badge is-store"><?= __('Store') ?></span><?php endif; ?>
    </div>
  </div>

  <div class="tb-inspector-notice">
    <strong><?= __('Read-only inspector.') ?></strong>
    <?= __('Files are opened from the registered physical theme root. Source is escaped and is never executed.') ?>
  </div>

  <div class="tb-inspector-stats">
    <div><strong><?= count($files) ?></strong><span><?= __('PHP Files') ?></span></div>
    <div><strong><?= count($inspection['categories']) ?></strong><span><?= __('Categories') ?></span></div>
    <div><strong><?= count(array_filter($inspection['slots'], static fn(array $slot): bool => $slot['status'] === 'physical')) ?></strong><span><?= __('Physical Slots') ?></span></div>
    <div><strong><?= count(array_filter($inspection['slots'], static fn(array $slot): bool => $slot['status'] === 'inherited')) ?></strong><span><?= __('Fallback Slots') ?></span></div>
  </div>

  <div class="tb-inspector-main">
    <aside class="tb-inspector-files" aria-label="<?= h(__('PHP source inventory')) ?>">
      <?php foreach ($groupedFiles as $label => $categoryFiles): ?>
        <section>
          <h4><?= h($label) ?> <span><?= count($categoryFiles) ?></span></h4>
          <ul>
            <?php foreach ($categoryFiles as $file): ?>
              <li><a href="<?= h($fileUrl($file['id'])) ?>" class="<?= $file['id'] === $fileId ? 'active' : '' ?>" title="<?= h($file['path']) ?>">
                <span><?= h($file['name']) ?></span>
                <?php if ($file['directory'] !== ''): ?><small><?= h($file['directory']) ?></small><?php endif; ?>
              </a></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    </aside>

    <main class="tb-inspector-source">
      <?php if ($source): ?>
        <div class="tb-code-header">
          <span class="tb-code-file"><?= h($source['path']) ?></span>
          <span class="tb-code-slot"><?= h($source['category_label']) ?></span>
        </div>
        <?php if (!$source['utf8']): ?>
          <div class="tb-flash tb-flash-error"><?= __('This source is not valid UTF-8; invalid bytes are replaced for display.') ?></div>
        <?php endif; ?>
        <textarea id="tb-installed-source" readonly><?= htmlspecialchars($source['source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      <?php else: ?>
        <div class="tb-inspector-empty"><?= __('This theme contains no PHP files.') ?></div>
      <?php endif; ?>
    </main>

    <aside class="tb-inspector-details">
      <?php if ($source): ?>
        <h4><?= __('File Details') ?></h4>
        <dl>
          <dt><?= __('Size') ?></dt><dd><?= number_format((int)$source['size']) ?> bytes</dd>
          <dt><?= __('Lines') ?></dt><dd><?= (int)$source['lines'] ?></dd>
          <dt><?= __('Modified') ?></dt><dd><?= h(date('Y-m-d H:i:s T', (int)$source['modified_at'])) ?></dd>
          <dt><?= __('Mode') ?></dt><dd><code><?= h($source['mode']) ?></code></dd>
          <dt><?= __('Owner') ?></dt><dd><?= h($source['owner'] . ':' . $source['group']) ?></dd>
          <dt><?= __('Direct edit') ?></dt><dd><?= $source['writable'] ? __('Filesystem writable') : __('Filesystem read-only') ?></dd>
        </dl>
        <h4>SHA-256</h4>
        <code class="tb-inspector-hash"><?= h($source['sha256']) ?></code>
        <h4><?= __('Literal Dependencies') ?></h4>
        <?php if (!$source['dependencies_scanned']): ?>
          <p class="muted"><?= __('Dependency scan skipped for source files larger than 256 KiB.') ?></p>
        <?php elseif ($source['dependencies']): ?>
          <ul class="tb-dependency-list">
            <?php foreach ($source['dependencies'] as $dependency): ?>
              <li><a href="<?= h($fileUrl($dependency['id'])) ?>"><?= h($dependency['path']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="muted"><?= __('No local literal require/include dependencies detected.') ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </aside>
  </div>

  <details class="tb-slot-matrix">
    <summary><?= __('Canonical Slot Resolution') ?> <span class="muted"><?= __('physical, default-theme fallback, or missing') ?></span></summary>
    <div class="tb-slot-grid">
      <?php foreach ($inspection['slots'] as $slot): ?>
        <div>
          <code><?= h($slot['slot']) ?></code>
          <small><?= h($slot['path']) ?></small>
          <?php if ($slot['file_id']): ?>
            <a href="<?= h($fileUrl($slot['file_id'])) ?>" class="tb-slot-state is-<?= h($slot['status']) ?>"><?= h(ucfirst($slot['status'])) ?></a>
          <?php else: ?>
            <span class="tb-slot-state is-<?= h($slot['status']) ?>"><?= h(ucfirst($slot['status'])) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </details>
</div>

<?php if ($source): ?>
<script>
(function() {
  var source = CodeMirror.fromTextArea(document.getElementById('tb-installed-source'), {
    mode: 'application/x-httpd-php',
    lineNumbers: true,
    lineWrapping: false,
    readOnly: true,
    cursorBlinkRate: -1,
    viewportMargin: 40
  });
  source.setSize('100%', 'calc(100vh - 310px)');
  var dark = document.documentElement.classList.contains('theme-dark')
    || !document.documentElement.classList.contains('theme-light');
  source.setOption('theme', dark ? 'dracula' : 'default');
})();
</script>
<?php endif; ?>
