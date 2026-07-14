<?php
declare(strict_types=1);

// Theme Builder — Editor

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$dashUrl = $base . '/?page=admin/tools/theme-builder';
$selfUrl = $base . '/?page=admin/tools/theme-builder/editor';

$slug = trim((string)($_GET['theme'] ?? ''));
if ($slug === '') { echo '<p>No theme. <a href="' . h($dashUrl) . '">Back</a></p>'; return; }

$themeDir = ThemeWorkspace::themeDir($slug);
if (!is_dir($themeDir)) { echo '<p>Theme not found. <a href="' . h($dashUrl) . '">Back</a></p>'; return; }

$manifest = ThemeWorkspace::readManifest($slug);
$completion = ThemeWorkspace::completionStatus($themeDir);
$slotLabels = ThemeWorkspace::slotLabels();
$slotFiles = ThemeWorkspace::slotFiles();

$currentSlot = trim((string)($_GET['slot'] ?? 'header'));
if (!isset($slotFiles[$currentSlot])) $currentSlot = 'header';
$currentContent = ThemeWorkspace::readFile($slug, $currentSlot) ?? '';
$currentFile = $slotFiles[$currentSlot] ?? '';
?>

<link rel="stylesheet" href="/static/vendor/codemirror/codemirror.min.css">
<script src="/static/vendor/codemirror/codemirror.min.js"></script>
<script src="/static/vendor/codemirror/mode/xml/xml.min.js"></script>
<script src="/static/vendor/codemirror/mode/javascript/javascript.min.js"></script>
<script src="/static/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="/static/vendor/codemirror/mode/css/css.min.js"></script>
<script src="/static/vendor/codemirror/mode/php/php.min.js"></script>
<script src="/static/vendor/codemirror/addon/edit/closebrackets.min.js"></script>
<script src="/static/vendor/codemirror/addon/edit/closetag.min.js"></script>
<script src="/static/vendor/codemirror/addon/selection/active-line.min.js"></script>

<div class="tb-editor">
  <div class="tb-editor-topbar">
    <a href="<?= h($dashUrl) ?>" class="btn btn-sm btn-outline">&larr; <?= __('Dashboard') ?></a>
    <h3><?= h($manifest['name'] ?? $slug) ?> <span class="tb-version">v<?= h($manifest['version'] ?? '0.1.0') ?></span></h3>
    <div class="tb-editor-actions">
      <button id="tb-btn-save" class="btn btn-sm btn-primary"><?= __('Save') ?></button>
      <button id="tb-btn-preview" class="btn btn-sm btn-outline"><?= __('Preview') ?></button>
      <button id="tb-btn-manifest" class="btn btn-sm btn-outline"><?= __('theme.json') ?></button>
      <button id="tb-btn-assets" class="btn btn-sm btn-outline"><?= __('CSS/JS') ?></button>
    </div>
  </div>

  <div class="tb-editor-main">
    <div class="tb-editor-sidebar">
      <div class="tb-sidebar-section">
        <h4><?= __('Slots') ?></h4>
        <ul class="tb-slot-list">
          <?php foreach ($slotLabels as $slotKey => $label):
            $info = $completion[$slotKey] ?? [];
            $isActive = $slotKey === $currentSlot;
            $isDone = !empty($info['exists']);
          ?>
            <li><a href="<?= h($selfUrl) ?>&theme=<?= h($slug) ?>&slot=<?= h($slotKey) ?>" class="tb-slot-link <?= $isActive ? 'active' : '' ?> <?= $isDone ? 'done' : '' ?>">
              <span class="tb-slot-status"><?= $isDone ? '&#10003;' : '&#9675;' ?></span> <?= h($label) ?>
            </a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="tb-editor-code">
      <div class="tb-code-header">
        <span class="tb-code-file"><?= h($currentFile) ?></span>
        <span class="tb-code-slot"><?= h($slotLabels[$currentSlot] ?? $currentSlot) ?></span>
      </div>
      <textarea id="tb-code-editor"><?= h($currentContent) ?></textarea>
    </div>

    <div class="tb-editor-ref">
      <h4><?= __('Variables') ?></h4>
      <?= VarReference::renderPanel($currentSlot) ?>
    </div>
  </div>

  <div id="tb-preview-panel" class="tb-preview-panel" style="display:none">
    <div class="tb-preview-header">
      <h4><?= __('Preview') ?> — <?= h($slotLabels[$currentSlot] ?? $currentSlot) ?></h4>
      <div class="tb-preview-controls">
        <select id="tb-preview-slot">
          <?php foreach ($slotLabels as $sk => $sl): ?>
            <option value="<?= h($sk) ?>" <?= $sk === $currentSlot ? 'selected' : '' ?>><?= h($sl) ?></option>
          <?php endforeach; ?>
        </select>
        <button id="tb-preview-full" class="btn btn-sm btn-outline"><?= __('Full Page') ?></button>
        <button id="tb-preview-close" class="btn btn-sm btn-outline">&times;</button>
      </div>
    </div>
    <iframe id="tb-preview-frame" class="tb-preview-frame" sandbox="allow-scripts allow-same-origin"></iframe>
  </div>

  <div id="tb-manifest-modal" class="tb-modal" style="display:none">
    <div class="tb-modal-content">
      <div class="tb-modal-header"><h4><?= __('Edit theme.json') ?></h4><button class="tb-modal-close">&times;</button></div>
      <div class="tb-modal-body">
        <div class="tb-field"><label><?= __('Name') ?></label><input type="text" id="tb-m-name" value="<?= h($manifest['name'] ?? '') ?>"></div>
        <div class="tb-field"><label><?= __('Description') ?></label><textarea id="tb-m-description" rows="2"><?= h($manifest['description'] ?? '') ?></textarea></div>
        <div class="tb-form-row">
          <div class="tb-field"><label><?= __('Version') ?></label><input type="text" id="tb-m-version" value="<?= h($manifest['version'] ?? '0.1.0') ?>"></div>
          <div class="tb-field"><label><?= __('Author') ?></label><input type="text" id="tb-m-author" value="<?= h($manifest['author'] ?? '') ?>"></div>
        </div>
        <div class="tb-form-row">
          <div class="tb-field"><label><?= __('Color Mode') ?></label>
            <select id="tb-m-color-mode">
              <option value="both" <?= ($manifest['color_mode'] ?? 'both') === 'both' ? 'selected' : '' ?>><?= __('Both') ?></option>
              <option value="light" <?= ($manifest['color_mode'] ?? '') === 'light' ? 'selected' : '' ?>><?= __('Light Only') ?></option>
              <option value="dark" <?= ($manifest['color_mode'] ?? '') === 'dark' ? 'selected' : '' ?>><?= __('Dark Only') ?></option>
            </select>
          </div>
          <div class="tb-field"><label><?= __('Screenshot') ?></label><input type="text" id="tb-m-screenshot" value="<?= h($manifest['screenshot'] ?? 'img.png') ?>"></div>
        </div>
        <div class="tb-field"><label><?= __('CSS Files (one per line)') ?></label><textarea id="tb-m-styles" rows="3"><?= h(implode("\n", $manifest['styles'] ?? ['assets/css/style.css'])) ?></textarea></div>
        <div class="tb-field"><label><?= __('JS Files (one per line)') ?></label><textarea id="tb-m-scripts" rows="2"><?= h(implode("\n", $manifest['scripts'] ?? ['assets/js/script.js'])) ?></textarea></div>
      </div>
      <div class="tb-modal-footer">
        <button id="tb-manifest-save" class="btn btn-primary"><?= __('Save Manifest') ?></button>
        <button class="btn btn-outline tb-modal-close"><?= __('Cancel') ?></button>
      </div>
    </div>
  </div>

  <div id="tb-asset-modal" class="tb-modal" style="display:none">
    <div class="tb-modal-content tb-modal-wide">
      <div class="tb-modal-header"><h4><?= __('Edit CSS/JS Assets') ?></h4><button class="tb-modal-close">&times;</button></div>
      <div class="tb-modal-body">
        <div class="tb-asset-tabs">
          <button class="tb-asset-tab active" data-asset="assets/css/style.css">style.css</button>
          <button class="tb-asset-tab" data-asset="assets/css/blocks.css">blocks.css</button>
          <button class="tb-asset-tab" data-asset="assets/js/script.js">script.js</button>
        </div>
        <textarea id="tb-asset-editor"></textarea>
      </div>
      <div class="tb-modal-footer">
        <button id="tb-asset-save" class="btn btn-primary"><?= __('Save Asset') ?></button>
        <button class="btn btn-outline tb-modal-close"><?= __('Cancel') ?></button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  var base = <?= json_encode($base) ?>;
  var slug = <?= json_encode($slug) ?>;
  var currentSlot = <?= json_encode($currentSlot) ?>;
  var currentAsset = 'assets/css/style.css';

  var editor = CodeMirror.fromTextArea(document.getElementById('tb-code-editor'), {
    mode: 'application/x-httpd-php', lineNumbers: true, autoCloseBrackets: true,
    autoCloseTags: true, styleActiveLine: true, indentUnit: 2, tabSize: 2,
    lineWrapping: true, viewportMargin: Infinity
  });
  editor.setSize('100%', 'calc(100vh - 240px)');

  document.getElementById('tb-btn-save').addEventListener('click', function() {
    var btn = this; btn.disabled = true; btn.textContent = '<?= __('Saving...') ?>';
    var fd = new FormData(); fd.append('theme', slug); fd.append('slot', currentSlot); fd.append('content', editor.getValue());
    fetch(base + '/?page=admin/tools/theme-builder/api/save_file', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) { btn.textContent = '<?= __('Saved!') ?>'; setTimeout(function() { btn.textContent = '<?= __('Save') ?>'; btn.disabled = false; }, 1500); }
      else { alert(data.error || 'Save failed.'); btn.textContent = '<?= __('Save') ?>'; btn.disabled = false; }
    });
  });

  var previewPanel = document.getElementById('tb-preview-panel');
  var previewFrame = document.getElementById('tb-preview-frame');
  function loadPreview(slot, full) {
    previewFrame.src = base + '/?page=admin/tools/theme-builder/api/preview&theme=' + encodeURIComponent(slug) + '&slot=' + encodeURIComponent(slot || currentSlot) + (full ? '&full=1' : '');
    previewPanel.style.display = 'flex';
  }
  document.getElementById('tb-btn-preview').addEventListener('click', function() { loadPreview(currentSlot, false); });
  document.getElementById('tb-preview-full').addEventListener('click', function() { loadPreview(document.getElementById('tb-preview-slot').value, true); });
  document.getElementById('tb-preview-close').addEventListener('click', function() { previewPanel.style.display = 'none'; });
  document.getElementById('tb-preview-slot').addEventListener('change', function() { loadPreview(this.value, false); });

  var manifestModal = document.getElementById('tb-manifest-modal');
  document.getElementById('tb-btn-manifest').addEventListener('click', function() { manifestModal.style.display = 'flex'; });
  document.getElementById('tb-manifest-save').addEventListener('click', function() {
    var fd = new FormData(); fd.append('theme', slug);
    fd.append('manifest', JSON.stringify({
      folder: slug, name: document.getElementById('tb-m-name').value,
      description: document.getElementById('tb-m-description').value,
      version: document.getElementById('tb-m-version').value,
      author: document.getElementById('tb-m-author').value,
      screenshot: document.getElementById('tb-m-screenshot').value,
      color_mode: document.getElementById('tb-m-color-mode').value,
      styles: document.getElementById('tb-m-styles').value.split('\n').filter(function(s) { return s.trim(); }),
      scripts: document.getElementById('tb-m-scripts').value.split('\n').filter(function(s) { return s.trim(); })
    }));
    fetch(base + '/?page=admin/tools/theme-builder/api/save_manifest', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) { if (data.success) { manifestModal.style.display = 'none'; window.location.reload(); } else { alert(data.error || 'Failed.'); } });
  });

  var assetModal = document.getElementById('tb-asset-modal');
  var assetEditor = null;
  document.getElementById('tb-btn-assets').addEventListener('click', function() { assetModal.style.display = 'flex'; loadAsset(currentAsset); });
  function loadAsset(path) {
    currentAsset = path;
    fetch(base + '/?page=admin/tools/theme-builder/api/preview&theme=' + encodeURIComponent(slug) + '&asset=' + encodeURIComponent(path))
    .then(function(r) { return r.text(); })
    .then(function(content) {
      if (assetEditor) assetEditor.toTextArea();
      var ta = document.getElementById('tb-asset-editor'); ta.value = content;
      assetEditor = CodeMirror.fromTextArea(ta, { mode: path.endsWith('.css') ? 'css' : 'javascript', lineNumbers: true, autoCloseBrackets: true, styleActiveLine: true, indentUnit: 2, tabSize: 2, lineWrapping: true, viewportMargin: Infinity });
      assetEditor.setSize('100%', '400px');
    });
  }
  document.querySelectorAll('.tb-asset-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      document.querySelectorAll('.tb-asset-tab').forEach(function(t) { t.classList.remove('active'); });
      this.classList.add('active'); loadAsset(this.dataset.asset);
    });
  });
  document.getElementById('tb-asset-save').addEventListener('click', function() {
    if (!assetEditor) return;
    var fd = new FormData(); fd.append('theme', slug); fd.append('slot', '_asset'); fd.append('asset_path', currentAsset); fd.append('content', assetEditor.getValue());
    fetch(base + '/?page=admin/tools/theme-builder/api/save_file', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) { if (data.success) { alert('<?= __('Asset saved!') ?>'); } else { alert(data.error || 'Failed.'); } });
  });

  document.querySelectorAll('.tb-modal-close').forEach(function(btn) {
    btn.addEventListener('click', function() { this.closest('.tb-modal').style.display = 'none'; });
  });
  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); document.getElementById('tb-btn-save').click(); }
  });
})();
</script>
