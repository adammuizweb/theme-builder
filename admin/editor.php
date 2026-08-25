<?php
declare(strict_types=1);

// Theme Builder — Editor

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }
adiwira_require_site_owner($pdo, false);
$csrfToken = csrf_token();

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$dashUrl = $base . '/?page=admin/tools/theme-builder';
$selfUrl = $base . '/?page=admin/tools/theme-builder/editor';

$slug = trim((string)($_GET['theme'] ?? ''));
if ($slug === '') { echo '<p>No theme. <a href="' . h($dashUrl) . '">Back</a></p>'; return; }
if (!ThemeWorkspace::isValidSlug($slug)) { echo '<p>Invalid theme. <a href="' . h($dashUrl) . '">Back</a></p>'; return; }

$themeDir = ThemeWorkspace::themeDir($slug);
if (!is_dir($themeDir)) { echo '<p>Theme not found. <a href="' . h($dashUrl) . '">Back</a></p>'; return; }

$manifestState = ThemeWorkspace::readManifestState($slug);
$manifest = is_array($manifestState['manifest'] ?? null) ? $manifestState['manifest'] : [];
$completion = ThemeWorkspace::completionStatus($themeDir);
$slotLabels = ThemeWorkspace::slotLabels();
$slotFiles = ThemeWorkspace::slotFiles();

$currentSlot = trim((string)($_GET['slot'] ?? 'header'));
if (!isset($slotFiles[$currentSlot])) $currentSlot = 'header';
$currentState = ThemeWorkspace::readFileState($slug, $currentSlot);
$currentContent = is_string($currentState['content'] ?? null) ? $currentState['content'] : '';
$currentFile = $slotFiles[$currentSlot] ?? '';
$currentHash = is_string($currentState['sha256'] ?? null) ? $currentState['sha256'] : '';
$manifestHash = is_string($manifestState['sha256'] ?? null) ? $manifestState['sha256'] : '';
$assetLines = static function (mixed $entries): string {
    if (!is_array($entries)) return '';
    $sources = [];
    foreach ($entries as $entry) {
        $source = is_string($entry) ? $entry : (is_array($entry) && is_string($entry['src'] ?? null) ? $entry['src'] : null);
        if (is_string($source)) $sources[] = $source;
    }
    return implode("\n", $sources);
};
?>

<link rel="stylesheet" href="/static/vendor/codemirror/codemirror.min.css">
<link rel="stylesheet" href="/static/vendor/codemirror/theme/dracula.min.css">
<link rel="stylesheet" href="/static/vendor/codemirror/addon/fold/foldgutter.css">
<script src="/static/vendor/codemirror/codemirror.min.js"></script>
<script src="/static/vendor/codemirror/mode/xml/xml.min.js"></script>
<script src="/static/vendor/codemirror/mode/javascript/javascript.min.js"></script>
<script src="/static/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="/static/vendor/codemirror/mode/clike/clike.min.js"></script>
<script src="/static/vendor/codemirror/mode/php/php.min.js"></script>
<script src="/static/vendor/codemirror/mode/css/css.min.js"></script>
<script src="/static/vendor/codemirror/addon/edit/closebrackets.min.js"></script>
<script src="/static/vendor/codemirror/addon/edit/closetag.min.js"></script>
<script src="/static/vendor/codemirror/addon/selection/active-line.min.js"></script>
<script src="/static/vendor/codemirror/addon/fold/foldcode.js"></script>
<script src="/static/vendor/codemirror/addon/fold/foldgutter.js"></script>
<script src="/static/vendor/codemirror/addon/fold/brace-fold.js"></script>
<script src="/static/vendor/codemirror/addon/fold/xml-fold.js"></script>
<script src="/static/vendor/codemirror/addon/fold/comment-fold.js"></script>

<div class="tb-editor">
  <div class="tb-editor-topbar">
    <a href="<?= h($dashUrl) ?>" class="btn btn-sm btn-outline">&larr; <?= __('Dashboard') ?></a>
    <h3><?= h($manifest['name'] ?? $slug) ?> <span class="tb-version">v<?= h($manifest['version'] ?? '0.1.0') ?></span></h3>
    <div class="tb-editor-actions">
      <button id="tb-btn-save" class="btn btn-sm btn-primary"><?= __('Save') ?></button>
      <button class="btn btn-sm btn-outline" disabled title="<?= __('PHP preview is disabled until an isolated preview runtime is available.') ?>"><?= __('Preview unavailable') ?></button>
      <button id="tb-btn-manifest" class="btn btn-sm btn-outline"><?= __('theme.json') ?></button>
      <button id="tb-btn-assets" class="btn btn-sm btn-outline"><?= __('CSS/JS') ?></button>
    </div>
  </div>

  <div class="tb-editor-main" data-mobile-panel="code">
    <div class="tb-editor-sidebar">
      <div class="tb-sidebar-section">
        <div class="tb-sidebar-header">
          <h4><?= __('Slots') ?></h4>
          <button type="button" class="tb-collapse-btn" id="tb-toggle-slots" aria-controls="tb-slots-content" aria-expanded="true" aria-label="<?= h(__('Toggle Slots panel')) ?>" title="<?= __('Collapse') ?>">&laquo;</button>
        </div>
        <ul class="tb-slot-list" id="tb-slots-content">
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
        <div style="display:flex;align-items:center;gap:.5rem;">
          <span class="tb-code-slot"><?= h($slotLabels[$currentSlot] ?? $currentSlot) ?></span>
          <button type="button" class="tb-collapse-btn" id="tb-toggle-editor" aria-controls="tb-code-content" aria-expanded="true" aria-pressed="false" aria-label="<?= h(__('Toggle code editor')) ?>" title="<?= __('Maximize') ?>">&#x26F6;</button>
        </div>
      </div>
      <textarea id="tb-code-editor"><?= h($currentContent) ?></textarea>
    </div>

    <div class="tb-editor-ref">
      <div class="tb-ref-header">
        <h4><?= __('Variables') ?></h4>
        <button type="button" class="tb-collapse-btn" id="tb-toggle-vars" aria-controls="tb-vars-content" aria-expanded="true" aria-label="<?= h(__('Toggle Variables panel')) ?>" title="<?= __('Collapse') ?>">&raquo;</button>
      </div>
      <div id="tb-vars-content"><?= VarReference::renderPanel($currentSlot) ?></div>
    </div>

    <button type="button" class="tb-restore-btn tb-restore-slots" id="tb-restore-slots" aria-controls="tb-slots-content" aria-label="<?= h(__('Show Slots')) ?>" title="<?= __('Show Slots') ?>">&raquo;</button>
    <button type="button" class="tb-restore-btn tb-restore-vars" id="tb-restore-vars" aria-controls="tb-vars-content" aria-label="<?= h(__('Show Variables')) ?>" title="<?= __('Show Variables') ?>">&laquo;</button>
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
        <div class="tb-field"><label><?= __('CSS Files (one per line)') ?></label><textarea id="tb-m-styles" rows="3"><?= h($assetLines($manifest['styles'] ?? ['assets/css/style.css'])) ?></textarea></div>
        <div class="tb-field"><label><?= __('JS Files (one per line)') ?></label><textarea id="tb-m-scripts" rows="2"><?= h($assetLines($manifest['scripts'] ?? ['assets/js/script.js'])) ?></textarea></div>
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
  var currentHash = <?= json_encode($currentHash) ?>;
  var manifestHash = <?= json_encode($manifestHash) ?>;
  var csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var currentAsset = 'assets/css/style.css';
  var currentAssetHash = '';
  var assetRequest = 0;

  var editor = CodeMirror.fromTextArea(document.getElementById('tb-code-editor'), {
    mode: 'application/x-httpd-php', lineNumbers: true, autoCloseBrackets: true,
    autoCloseTags: true, styleActiveLine: true, indentUnit: 2, tabSize: 2,
    lineWrapping: true, viewportMargin: Infinity,
    foldGutter: true, gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"]
  });
  editor.setSize('100%', 'calc(100vh - 240px)');
  editor.getWrapperElement().id = 'tb-code-content';

  document.getElementById('tb-btn-save').addEventListener('click', function() {
    var btn = this; btn.disabled = true; btn.textContent = '<?= __('Saving...') ?>';
    var fd = new FormData(); fd.append('theme', slug); fd.append('slot', currentSlot); fd.append('content', editor.getValue()); fd.append('expected_hash', currentHash); fd.append('csrf_token', csrfToken);
    fetch(base + '/?action=api&page=admin/tools/theme-builder/api/save_file', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) { currentHash = data.sha256; btn.textContent = '<?= __('Saved!') ?>'; setTimeout(function() { btn.textContent = '<?= __('Save') ?>'; btn.disabled = false; }, 1500); }
      else { alert(data.error || 'Save failed.'); btn.textContent = '<?= __('Save') ?>'; btn.disabled = false; }
    })
    .catch(function(err) { alert('Error: ' + err.message); btn.textContent = '<?= __('Save') ?>'; btn.disabled = false; });
  });

  var manifestModal = document.getElementById('tb-manifest-modal');
  document.getElementById('tb-btn-manifest').addEventListener('click', function() { manifestModal.style.display = 'flex'; });
  document.getElementById('tb-manifest-save').addEventListener('click', function() {
    var fd = new FormData(); fd.append('theme', slug); fd.append('expected_hash', manifestHash); fd.append('csrf_token', csrfToken);
    fd.append('manifest', JSON.stringify({
      name: document.getElementById('tb-m-name').value,
      description: document.getElementById('tb-m-description').value,
      version: document.getElementById('tb-m-version').value,
      author: document.getElementById('tb-m-author').value,
      screenshot: document.getElementById('tb-m-screenshot').value,
      color_mode: document.getElementById('tb-m-color-mode').value,
      styles: document.getElementById('tb-m-styles').value.split('\n').filter(function(s) { return s.trim(); }),
      scripts: document.getElementById('tb-m-scripts').value.split('\n').filter(function(s) { return s.trim(); })
    }));
    fetch(base + '/?action=api&page=admin/tools/theme-builder/api/save_manifest', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) { if (data.success) { manifestModal.style.display = 'none'; window.location.reload(); } else { alert(data.error || 'Failed.'); } });
  });

  var assetModal = document.getElementById('tb-asset-modal');
  var assetEditor = null;
  var assetSaveButton = document.getElementById('tb-asset-save');
  document.getElementById('tb-btn-assets').addEventListener('click', function() { assetModal.style.display = 'flex'; loadAsset(currentAsset); });
  function loadAsset(path) {
    var request = ++assetRequest;
    assetSaveButton.disabled = true;
    fetch(base + '/?action=api&page=admin/tools/theme-builder/api/preview&theme=' + encodeURIComponent(slug) + '&asset=' + encodeURIComponent(path))
    .then(function(r) {
      if (!r.ok) throw new Error('Asset load failed.');
      var hash = r.headers.get('X-Theme-Builder-SHA256') || '';
      return r.text().then(function(content) { return { content: content, hash: hash }; });
    })
    .then(function(result) {
      if (request !== assetRequest) return;
      currentAsset = path;
      currentAssetHash = result.hash;
      if (assetEditor) assetEditor.toTextArea();
      var ta = document.getElementById('tb-asset-editor'); ta.value = result.content;
      assetEditor = CodeMirror.fromTextArea(ta, { mode: path.endsWith('.css') ? 'css' : 'javascript', lineNumbers: true, autoCloseBrackets: true, styleActiveLine: true, indentUnit: 2, tabSize: 2, lineWrapping: true, viewportMargin: Infinity });
      assetEditor.setSize('100%', '400px');
      assetSaveButton.disabled = false;
    })
    .catch(function(err) { if (request === assetRequest) assetSaveButton.disabled = true; alert('Error: ' + err.message); });
  }
  document.querySelectorAll('.tb-asset-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      document.querySelectorAll('.tb-asset-tab').forEach(function(t) { t.classList.remove('active'); });
      this.classList.add('active'); loadAsset(this.dataset.asset);
    });
  });
  document.getElementById('tb-asset-save').addEventListener('click', function() {
    if (!assetEditor) return;
    var fd = new FormData(); fd.append('theme', slug); fd.append('slot', '_asset'); fd.append('asset_path', currentAsset); fd.append('content', assetEditor.getValue()); fd.append('expected_hash', currentAssetHash); fd.append('csrf_token', csrfToken);
    fetch(base + '/?action=api&page=admin/tools/theme-builder/api/save_file', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) { if (data.success) { currentAssetHash = data.sha256; alert('<?= __('Asset saved!') ?>'); } else { alert(data.error || 'Failed.'); } })
    .catch(function(err) { alert('Error: ' + err.message); });
  });

  document.querySelectorAll('.tb-modal-close').forEach(function(btn) {
    btn.addEventListener('click', function() { this.closest('.tb-modal').style.display = 'none'; });
  });
  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); document.getElementById('tb-btn-save').click(); }
  });

  // Desktop collapse and mobile accordion share controls but retain separate state.
  var mainEl = document.querySelector('.tb-editor-main');
  var slotsBtn = document.getElementById('tb-toggle-slots');
  var varsBtn = document.getElementById('tb-toggle-vars');
  var editorBtn = document.getElementById('tb-toggle-editor');
  var restoreSlotsBtn = document.getElementById('tb-restore-slots');
  var restoreVarsBtn = document.getElementById('tb-restore-vars');
  var mobileQuery = window.matchMedia('(max-width: 900px)');
  var mobilePanel = 'code';
  var isMaximized = false;

  function refreshEditorAfterLayout() {
    requestAnimationFrame(function() { requestAnimationFrame(function() { editor.refresh(); }); });
  }
  function syncPanelControls() {
    var mobile = mobileQuery.matches;
    var slotsOpen = mobile ? mobilePanel === 'slots' : !mainEl.classList.contains('slots-collapsed');
    var varsOpen = mobile ? mobilePanel === 'vars' : !mainEl.classList.contains('vars-collapsed');
    var codeOpen = !mobile || mobilePanel === 'code';
    slotsBtn.setAttribute('aria-expanded', String(slotsOpen));
    varsBtn.setAttribute('aria-expanded', String(varsOpen));
    editorBtn.setAttribute('aria-expanded', String(codeOpen));
    editorBtn.setAttribute('aria-pressed', String(!mobile && isMaximized));
    if (mobile) {
      slotsBtn.innerHTML = slotsOpen ? '&minus;' : '&plus;';
      varsBtn.innerHTML = varsOpen ? '&minus;' : '&plus;';
      editorBtn.innerHTML = codeOpen ? '&minus;' : '&plus;';
      slotsBtn.title = slotsOpen ? '<?= __('Collapse') ?>' : '<?= __('Expand') ?>';
      varsBtn.title = varsOpen ? '<?= __('Collapse') ?>' : '<?= __('Expand') ?>';
      editorBtn.title = codeOpen ? '<?= __('Collapse') ?>' : '<?= __('Expand') ?>';
    } else {
      slotsBtn.innerHTML = slotsOpen ? '&laquo;' : '&raquo;';
      varsBtn.innerHTML = varsOpen ? '&raquo;' : '&laquo;';
      editorBtn.innerHTML = isMaximized ? '&#x2716;' : '&#x26F6;';
      slotsBtn.title = slotsOpen ? '<?= __('Collapse') ?>' : '<?= __('Expand') ?>';
      varsBtn.title = varsOpen ? '<?= __('Collapse') ?>' : '<?= __('Expand') ?>';
      editorBtn.title = isMaximized ? '<?= __('Restore') ?>' : '<?= __('Maximize') ?>';
    }
  }
  function openMobilePanel(panel) {
    mobilePanel = mobilePanel === panel ? 'none' : panel;
    mainEl.dataset.mobilePanel = mobilePanel;
    syncPanelControls();
    if (mobilePanel === 'code') refreshEditorAfterLayout();
  }
  function applyResponsiveMode() {
    if (mobileQuery.matches) mainEl.dataset.mobilePanel = mobilePanel;
    else delete mainEl.dataset.mobilePanel;
    syncPanelControls();
    refreshEditorAfterLayout();
  }

  slotsBtn.addEventListener('click', function() {
    if (mobileQuery.matches) { openMobilePanel('slots'); return; }
    mainEl.classList.toggle('slots-collapsed');
    this.innerHTML = mainEl.classList.contains('slots-collapsed') ? '&raquo;' : '&laquo;';
    this.title = mainEl.classList.contains('slots-collapsed') ? '<?= __('Expand') ?>' : '<?= __('Collapse') ?>';
    syncPanelControls();
    refreshEditorAfterLayout();
  });
  varsBtn.addEventListener('click', function() {
    if (mobileQuery.matches) { openMobilePanel('vars'); return; }
    mainEl.classList.toggle('vars-collapsed');
    this.innerHTML = mainEl.classList.contains('vars-collapsed') ? '&laquo;' : '&raquo;';
    this.title = mainEl.classList.contains('vars-collapsed') ? '<?= __('Expand') ?>' : '<?= __('Collapse') ?>';
    syncPanelControls();
    refreshEditorAfterLayout();
  });

  editorBtn.addEventListener('click', function() {
    if (mobileQuery.matches) { openMobilePanel('code'); return; }
    isMaximized = !isMaximized;
    mainEl.classList.toggle('slots-collapsed', isMaximized);
    mainEl.classList.toggle('vars-collapsed', isMaximized);
    editorBtn.innerHTML = isMaximized ? '&#x2716;' : '&#x26F6;';
    editorBtn.title = isMaximized ? '<?= __('Restore') ?>' : '<?= __('Maximize') ?>';
    syncPanelControls();
    refreshEditorAfterLayout();
  });

  restoreSlotsBtn.addEventListener('click', function() {
    mainEl.classList.remove('slots-collapsed');
    slotsBtn.innerHTML = '&laquo;';
    slotsBtn.title = '<?= __('Collapse') ?>';
    isMaximized = false;
    editorBtn.innerHTML = '&#x26F6;';
    editorBtn.title = '<?= __('Maximize') ?>';
    syncPanelControls();
    slotsBtn.focus();
    refreshEditorAfterLayout();
  });
  restoreVarsBtn.addEventListener('click', function() {
    mainEl.classList.remove('vars-collapsed');
    varsBtn.innerHTML = '&raquo;';
    varsBtn.title = '<?= __('Collapse') ?>';
    isMaximized = false;
    editorBtn.innerHTML = '&#x26F6;';
    editorBtn.title = '<?= __('Maximize') ?>';
    syncPanelControls();
    varsBtn.focus();
    refreshEditorAfterLayout();
  });
  mainEl.addEventListener('transitionend', function(event) {
    if (event.propertyName === 'grid-template-columns') editor.refresh();
  });
  if (mobileQuery.addEventListener) mobileQuery.addEventListener('change', applyResponsiveMode);
  else mobileQuery.addListener(applyResponsiveMode);
  applyResponsiveMode();

  // Sync CodeMirror theme with CMS dark mode
  function syncCMTheme() {
    var isDark = document.documentElement.classList.contains('theme-dark') ||
      (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches && !document.documentElement.classList.contains('theme-light'));
    var wrap = editor.getWrapperElement();
    if (isDark) {
      wrap.classList.remove('cm-s-light');
      wrap.classList.add('cm-s-dracula');
    } else {
      wrap.classList.remove('cm-s-dracula');
      wrap.classList.add('cm-s-light');
    }
  }
  syncCMTheme();
  new MutationObserver(syncCMTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
})();
</script>
