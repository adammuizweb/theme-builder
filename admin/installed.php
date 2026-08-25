<?php
declare(strict_types=1);

// Installed Theme Inspector and managed Fork & Edit workflow.

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
$forkService = new ThemeForkService($pdo);
$csrfToken = csrf_token();
$folder = is_string($_GET['theme'] ?? null) ? $_GET['theme'] : '';
$forkRequest = '';
$forkCandidate = $_GET['fork'] ?? null;
if (is_string($forkCandidate) && strlen($forkCandidate) <= 128
    && preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $forkCandidate) === 1
    && !in_array($forkCandidate, ['.', '..'], true)) {
    try {
        $inspector->inspect($forkCandidate);
        $forkRequest = $forkCandidate;
    } catch (Throwable) {
        // Invalid, unregistered, or unsafe GET targets never open the fork workflow.
    }
}
$exportUrl = $base . '/?action=api&page=admin/tools/theme-builder/api/export_theme_source';
$renderExportForm = static function (string $themeFolder, string $label = 'Export PHP Source') use ($exportUrl, $csrfToken): void {
?>
<form method="post" action="<?= h($exportUrl) ?>" class="tb-export-form">
  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
  <input type="hidden" name="theme" value="<?= h($themeFolder) ?>">
  <button type="submit" class="btn btn-sm btn-outline"><?= h(__($label)) ?></button>
</form>
<?php
};
$renderForkModal = static function () use ($base, $selfUrl, $csrfToken, $forkRequest): void {
?>
<div id="tb-fork-modal" class="tb-modal" style="display:none">
  <div class="tb-modal-content">
    <div class="tb-modal-header">
      <h4><?= __('Fork Installed Theme') ?></h4>
      <button type="button" class="tb-modal-close" data-tb-fork-close>&times;</button>
    </div>
    <form id="tb-fork-form">
      <div class="tb-modal-body">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" id="tb-fork-source" name="source_theme" value="">
        <p class="muted"><?= __('The complete physical theme will be copied, detached from its Store identity, registered inactive, and opened for safe PHP editing. Database customizations and assignments are not copied.') ?></p>
        <div class="tb-field">
          <label for="tb-fork-target"><?= __('New Folder') ?> *</label>
          <input type="text" id="tb-fork-target" name="target_folder" required pattern="[a-z0-9][a-z0-9_\-]{0,49}" maxlength="50" placeholder="my-theme-fork">
          <small><?= __('Lowercase letters, numbers, hyphens, and underscores only.') ?></small>
        </div>
        <div class="tb-field">
          <label for="tb-fork-name"><?= __('Runtime Name') ?> *</label>
          <input type="text" id="tb-fork-name" name="name" required maxlength="150">
        </div>
        <div class="tb-field">
          <label for="tb-fork-title"><?= __('Human Title') ?> *</label>
          <input type="text" id="tb-fork-title" name="title" required maxlength="150">
        </div>
      </div>
      <div class="tb-modal-footer">
        <button type="button" class="btn btn-outline" data-tb-fork-close><?= __('Cancel') ?></button>
        <button type="submit" id="tb-fork-submit" class="btn btn-primary"><?= __('Create Inactive Fork') ?></button>
      </div>
    </form>
  </div>
</div>
<script>
(function() {
  var modal = document.getElementById('tb-fork-modal');
  var form = document.getElementById('tb-fork-form');
  if (!modal || !form) return;
  var submit = document.getElementById('tb-fork-submit');
  var sourceInput = document.getElementById('tb-fork-source');
  var targetInput = document.getElementById('tb-fork-target');
  var nameInput = document.getElementById('tb-fork-name');
  var titleInput = document.getElementById('tb-fork-title');
  document.querySelectorAll('[data-tb-fork]').forEach(function(button) {
    button.addEventListener('click', function() {
      var source = this.dataset.source || '';
      var name = this.dataset.name || source;
      sourceInput.value = source;
      targetInput.value = (source.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^[-_]+|[-_]+$/g, '') + '-fork').slice(0, 50);
      nameInput.value = name + ' Fork';
      titleInput.value = name + ' Fork';
      modal.style.display = 'flex';
      targetInput.focus();
    });
  });
  document.querySelectorAll('[data-tb-fork-close]').forEach(function(button) {
    button.addEventListener('click', function() { modal.style.display = 'none'; });
  });
  var requestedFork = <?= json_encode($forkRequest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (requestedFork) {
    var requestedButton = Array.from(document.querySelectorAll('[data-tb-fork]')).find(function(button) {
      return (button.dataset.source || '') === requestedFork;
    });
    if (requestedButton) requestedButton.click();
  }
  form.addEventListener('submit', function(event) {
    event.preventDefault();
    submit.disabled = true;
    submit.textContent = <?= json_encode(__('Forking and verifying...')) ?>;
    fetch(<?= json_encode($base . '/?action=api&page=admin/tools/theme-builder/api/fork_theme') ?>, {
      method: 'POST',
      body: new FormData(form)
    })
    .then(function(response) { return response.json(); })
    .then(function(result) {
      if (!result.success) throw new Error(result.error || <?= json_encode(__('Fork creation failed.')) ?>);
      window.location.href = <?= json_encode($selfUrl . '&theme=') ?> + encodeURIComponent(result.folder);
    })
    .catch(function(error) {
      alert(error.message);
      submit.disabled = false;
      submit.textContent = <?= json_encode(__('Create Inactive Fork')) ?>;
    });
  });
})();
</script>
<?php
};

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
      <p class="muted"><?= __('Inspect registered PHP source or create an inactive editable fork.') ?></p>
    </div>
    <a href="<?= h($dashUrl) ?>" class="btn btn-outline">&larr; <?= __('Theme Builder') ?></a>
  </div>

  <?php if (!$themes): ?>
    <div class="tb-inspector-empty"><?= __('No registered themes were found.') ?></div>
  <?php else: ?>
    <div class="tb-theme-grid">
      <?php foreach ($themes as $theme): ?>
        <?php $dirty = $theme['inspectable'] ? $forkService->dirtyState((string)$theme['folder']) : ['tracked' => false]; ?>
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
            <?php if ($theme['system'] || $theme['folder'] === (defined('DEFAULT_THEME_FOLDER') ? (string)DEFAULT_THEME_FOLDER : 'default')): ?>
              <span class="tb-inspector-badge is-system"><?= __('Source Read-only') ?></span>
            <?php elseif (isset($dirty['error'])): ?>
              <span class="tb-inspector-badge is-upstream"><?= __('PHP Source Error') ?></span>
            <?php elseif (!empty($dirty['upstream_changed'])): ?>
              <span class="tb-inspector-badge is-upstream"><?= __('Upstream PHP Changed') ?> · <?= (int)$dirty['changed_count'] ?></span>
            <?php elseif (!empty($dirty['locally_modified'])): ?>
              <span class="tb-inspector-badge is-dirty"><?= __('Locally Modified PHP') ?> · <?= (int)$dirty['changed_count'] ?></span>
            <?php elseif (!empty($dirty['tracked'])): ?>
              <span class="tb-inspector-badge is-clean"><?= __('PHP Source Clean') ?></span>
            <?php else: ?>
              <span class="tb-inspector-badge"><?= __('PHP Source Not Tracked') ?> · <?= (int)($dirty['counts']['untracked'] ?? $theme['php_files']) ?></span>
            <?php endif; ?>
            <span class="tb-files"><?= (int)$theme['php_files'] ?> <?= __('PHP files') ?></span>
          </div>
          <?php if ($theme['inspectable']): ?>
            <div class="tb-theme-actions">
              <a class="btn btn-sm btn-primary" href="<?= h($selfUrl . '&theme=' . rawurlencode($theme['folder'])) ?>"><?= __('Inspect Source') ?></a>
              <button type="button" class="btn btn-sm btn-outline" data-tb-fork data-source="<?= h($theme['folder']) ?>" data-name="<?= h($theme['name']) ?>"><?= __('Fork & Edit') ?></button>
              <?php $renderExportForm((string)$theme['folder']); ?>
            </div>
          <?php else: ?>
            <p class="tb-inspector-error"><?= h((string)$theme['error']) ?></p>
            <button class="btn btn-sm btn-outline" disabled><?= __('Unavailable') ?></button>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php $renderForkModal(); ?>
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
$forkState = $forkService->forkState($folder);
$directState = $forkService->directEditState($folder);
$dirtyState = $forkService->dirtyState($folder);
$managedEditable = (bool)$forkState['editable'];
$directEligible = !$managedEditable && (bool)$directState['editable'];
$sourceContent = is_string($source['source'] ?? null) ? $source['source'] : '';
$hasCrLf = str_contains($sourceContent, "\r\n");
$withoutCrLf = str_replace("\r\n", '', $sourceContent);
$mixedLineEndings = str_contains($withoutCrLf, "\r") || ($hasCrLf && str_contains($withoutCrLf, "\n"));
$lineSeparator = $hasCrLf && !$mixedLineEndings ? "\r\n" : "\n";
$sourceRoundTripSafe = $source !== null && !empty($source['utf8']) && !$mixedLineEndings;
$fileEditable = ($managedEditable || $directEligible) && $sourceRoundTripSafe;
$startsReadOnly = $directEligible;
$revisions = $source !== null ? $forkService->revisions($folder, $fileId, 20) : [];
$groupedFiles = [];
foreach ($files as $file) $groupedFiles[$file['category_label']][] = $file;
$fileUrl = static function (string $id) use ($selfUrl, $folder): string {
    return $selfUrl . '&theme=' . rawurlencode($folder) . '&file=' . rawurlencode($id);
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
<script src="/static/vendor/codemirror/addon/fold/foldcode.js"></script>
<script src="/static/vendor/codemirror/addon/fold/foldgutter.js"></script>
<script src="/static/vendor/codemirror/addon/fold/brace-fold.js"></script>
<script src="/static/vendor/codemirror/addon/fold/xml-fold.js"></script>
<script src="/static/vendor/codemirror/addon/fold/comment-fold.js"></script>

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
      <?php if ($forkState['managed']): ?><span class="tb-inspector-badge is-fork"><?= __('Managed Fork') ?></span><?php endif; ?>
      <?php if (isset($dirtyState['error'])): ?>
        <span class="tb-inspector-badge is-upstream"><?= __('PHP Source Error') ?></span>
      <?php elseif (!empty($dirtyState['upstream_changed'])): ?>
        <span class="tb-inspector-badge is-upstream"><?= __('Upstream PHP Changed') ?> · <?= (int)$dirtyState['changed_count'] ?></span>
      <?php elseif (!empty($dirtyState['locally_modified'])): ?>
        <span class="tb-inspector-badge is-dirty"><?= __('Locally Modified PHP') ?> · <?= (int)$dirtyState['changed_count'] ?></span>
      <?php elseif (!empty($dirtyState['tracked'])): ?>
        <span class="tb-inspector-badge is-clean"><?= __('PHP Source Clean') ?></span>
      <?php else: ?>
        <span class="tb-inspector-badge"><?= __('PHP Source Not Tracked') ?> · <?= (int)($dirtyState['counts']['untracked'] ?? count($files)) ?></span>
      <?php endif; ?>
    </div>
    <div class="tb-editor-actions">
      <?php if ($managedEditable && $fileEditable): ?><button type="button" id="tb-save-fork" class="btn btn-sm btn-primary"><?= __('Save PHP') ?></button><?php endif; ?>
      <?php if ($directEligible && $fileEditable): ?>
        <button type="button" id="tb-enable-direct" class="btn btn-sm btn-outline"><?= __('Enable Direct Edit') ?></button>
        <button type="button" id="tb-save-direct" class="btn btn-sm btn-primary" hidden><?= __('Save PHP') ?></button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline" data-tb-fork data-source="<?= h($theme['folder']) ?>" data-name="<?= h($theme['name']) ?>"><?= __('Fork & Edit') ?></button>
      <?php $renderExportForm($folder); ?>
    </div>
  </div>

  <div class="tb-inspector-notice <?= ($managedEditable || $directEligible) ? 'is-editable' : '' ?>">
    <?php if ($managedEditable && $fileEditable): ?>
      <strong><?= __('Inactive managed fork.') ?></strong>
      <?= __('Existing physical PHP files can be edited with stale-write protection, lint validation, atomic replacement, and a private pre-change revision.') ?>
    <?php elseif ($directEligible && $fileEditable): ?>
      <strong><?= __('Advanced direct editing is available.') ?></strong>
      <?= __('Enable it only after reviewing the active and Store update risks. The first change captures a protected PHP baseline and every replacement creates a revision.') ?>
    <?php elseif ($managedEditable || $directEligible): ?>
      <strong><?= __('Current file is read-only.') ?></strong>
      <?= __('Invalid UTF-8 or mixed line endings cannot be round-tripped safely in the browser editor.') ?>
    <?php else: ?>
      <strong><?= __('Read-only inspector.') ?></strong>
      <?= h(__((string)($directState['reason'] ?: ($forkState['managed'] ? $forkState['reason'] : 'Fork this theme before editing.')))) ?>
    <?php endif; ?>
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
        <textarea id="tb-installed-source" <?= ($fileEditable && !$startsReadOnly) ? '' : 'readonly' ?>></textarea>
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
          <dt><?= __('Editor state') ?></dt><dd><?= $managedEditable && $fileEditable ? __('Editable inactive fork') : ($directEligible && $fileEditable ? __('Direct edit available') : __('Read-only source')) ?></dd>
          <dt><?= __('PHP Baseline') ?></dt><dd><?= isset($dirtyState['error']) ? __('Error: source state could not be verified') : (!empty($dirtyState['tracked']) ? (!empty($dirtyState['upstream_changed']) ? __('Upstream PHP version changed') : (!empty($dirtyState['locally_modified']) ? __('Locally modified PHP') : __('PHP source clean'))) : __('PHP source not tracked')) ?></dd>
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
        <h4><?= __('Source Revisions') ?></h4>
        <?php if ($revisions): ?>
          <ul class="tb-revision-list">
            <?php foreach ($revisions as $revision): ?>
              <li>
                <span><?= h((string)$revision['created_at']) ?></span>
                <small><?= h((string)($revision['operation'] ?? 'save')) ?> · <?= h(substr((string)$revision['previous_sha256'], 0, 10)) ?></small>
                <?php if ($fileEditable): ?>
                  <button type="button" class="btn btn-sm btn-outline" data-tb-restore="<?= h((string)$revision['revision_id']) ?>"><?= __('Restore') ?></button>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="muted"><?= __('No restorable revisions for this source file.') ?></p>
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
  <?php $renderForkModal(); ?>
  <?php if ($directEligible && $fileEditable): ?>
    <div id="tb-direct-modal" class="tb-modal" style="display:none">
      <div class="tb-modal-content">
        <div class="tb-modal-header"><h4><?= __('Enable Direct PHP Editing') ?></h4><button type="button" class="tb-modal-close" data-tb-direct-close>&times;</button></div>
        <div class="tb-modal-body">
          <p><strong><?= h($theme['name']) ?></strong> <code><?= h($folder) ?></code></p>
          <label class="tb-risk-check"><input type="checkbox" id="tb-ack-direct"> <?= __('I understand that saved PHP executes on the server and can break this site.') ?></label>
          <?php if ($directState['active'] || $directState['assigned']): ?><label class="tb-risk-check"><input type="checkbox" id="tb-ack-active"> <?= __('I understand this theme is active or assigned and saved changes can affect live requests immediately.') ?></label><?php endif; ?>
          <?php if ($directState['store']): ?><label class="tb-risk-check"><input type="checkbox" id="tb-ack-store"> <?= __('I understand Store updates require an explicit destructive decision before replacing locally modified or untracked PHP source.') ?></label><?php endif; ?>
        </div>
        <div class="tb-modal-footer"><button type="button" class="btn btn-outline" data-tb-direct-close><?= __('Cancel') ?></button><button type="button" id="tb-confirm-direct" class="btn btn-danger"><?= __('Enable Direct Edit') ?></button></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($source): ?>
<script>
(function() {
  var encodedSource = <?= json_encode(base64_encode($sourceContent)) ?>;
  var binarySource = atob(encodedSource);
  var sourceBytes = new Uint8Array(binarySource.length);
  for (var byteIndex = 0; byteIndex < binarySource.length; byteIndex++) sourceBytes[byteIndex] = binarySource.charCodeAt(byteIndex);
  var initialSource = new TextDecoder('utf-8', { ignoreBOM: true }).decode(sourceBytes);
  var source = CodeMirror.fromTextArea(document.getElementById('tb-installed-source'), {
    mode: 'application/x-httpd-php',
    lineNumbers: true,
    lineWrapping: false,
    readOnly: <?= ($fileEditable && !$startsReadOnly) ? 'false' : 'true' ?>,
    cursorBlinkRate: <?= ($fileEditable && !$startsReadOnly) ? '530' : '-1' ?>,
    lineSeparator: <?= json_encode($lineSeparator) ?>,
    viewportMargin: 40,
    foldGutter: true,
    gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter']
  });
  source.setValue(initialSource);
  source.setSize('100%', 'calc(100vh - 310px)');
  var dark = document.documentElement.classList.contains('theme-dark')
    || !document.documentElement.classList.contains('theme-light');
  source.setOption('theme', dark ? 'dracula' : 'default');
  <?php if ($fileEditable): ?>
  var currentHash = <?= json_encode((string)$source['sha256']) ?>;
  var currentTargetToken = <?= json_encode((string)($source['target_token'] ?? '')) ?>;
  var directMode = <?= $directEligible ? 'true' : 'false' ?>;
  var directEnabled = !directMode;
  var saveButton = document.getElementById(directMode ? 'tb-save-direct' : 'tb-save-fork');
  var cleanGeneration = source.changeGeneration();
  var directModal = document.getElementById('tb-direct-modal');
  var enableButton = document.getElementById('tb-enable-direct');
  function acknowledgement(name) {
    var input = document.getElementById('tb-ack-' + name);
    return !input || input.checked;
  }
  function enableDirectEditing() {
    if (!acknowledgement('direct') || !acknowledgement('active') || !acknowledgement('store')) {
      alert(<?= json_encode(__('Confirm every applicable risk before enabling direct editing.')) ?>);
      return;
    }
    directEnabled = true;
    source.setOption('readOnly', false);
    source.setOption('cursorBlinkRate', 530);
    directModal.style.display = 'none';
    enableButton.hidden = true;
    saveButton.hidden = false;
    source.focus();
  }
  if (enableButton) enableButton.addEventListener('click', function() { directModal.style.display = 'flex'; });
  var confirmDirect = document.getElementById('tb-confirm-direct');
  if (confirmDirect) confirmDirect.addEventListener('click', enableDirectEditing);
  document.querySelectorAll('[data-tb-direct-close]').forEach(function(button) {
    button.addEventListener('click', function() { directModal.style.display = 'none'; });
  });
  function appendDirectFields(data) {
    data.append('target_token', currentTargetToken);
    data.append('ack_direct', '1');
    data.append('ack_active', <?= (!empty($directState['active']) || !empty($directState['assigned'])) ? "'1'" : "'0'" ?>);
    data.append('ack_store', <?= !empty($directState['store']) ? "'1'" : "'0'" ?>);
  }
  function saveInstalledSource() {
    if (!directEnabled || !saveButton || saveButton.disabled) return;
    saveButton.disabled = true;
    saveButton.textContent = <?= json_encode(__('Saving and linting...')) ?>;
    var data = new FormData();
    data.append('csrf_token', <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    data.append('theme', <?= json_encode($folder) ?>);
    data.append('file_id', <?= json_encode($fileId) ?>);
    data.append('expected_hash', currentHash);
    data.append('content', source.getValue());
    if (directMode) appendDirectFields(data);
    fetch(directMode
      ? <?= json_encode($base . '/?action=api&page=admin/tools/theme-builder/api/save_installed_file') ?>
      : <?= json_encode($base . '/?action=api&page=admin/tools/theme-builder/api/save_fork_file') ?>,
      { method: 'POST', body: data })
    .then(function(response) { return response.json(); })
    .then(function(result) {
      if (!result.success) throw new Error(result.error || <?= json_encode(__('Save failed.')) ?>);
      currentHash = result.sha256;
      if (result.target_token) currentTargetToken = result.target_token;
      cleanGeneration = source.changeGeneration();
      saveButton.textContent = result.unchanged ? <?= json_encode(__('No changes')) ?> : <?= json_encode(__('Saved with revision')) ?>;
      setTimeout(function() { window.location.reload(); }, 700);
    })
    .catch(function(error) {
      alert(error.message);
      saveButton.disabled = false;
      saveButton.textContent = <?= json_encode(__('Save PHP')) ?>;
    });
  }
  saveButton.addEventListener('click', saveInstalledSource);
  document.querySelectorAll('[data-tb-restore]').forEach(function(button) {
    button.addEventListener('click', function() {
      if (!directEnabled) {
        directModal.style.display = 'flex';
        return;
      }
      if (!source.isClean(cleanGeneration)
          && !confirm(<?= json_encode(__('The editor has unsaved changes. Restore will discard that browser buffer; only the current saved server source is revisioned. Continue?')) ?>)) return;
      if (!confirm(<?= json_encode(__('Restore this revision? Current source will first be saved as an undo revision.')) ?>)) return;
      button.disabled = true;
      var data = new FormData();
      data.append('csrf_token', <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
      data.append('theme', <?= json_encode($folder) ?>);
      data.append('file_id', <?= json_encode($fileId) ?>);
      data.append('revision_id', button.dataset.tbRestore || '');
      data.append('expected_hash', currentHash);
      data.append('edit_policy', directMode ? 'direct' : 'managed');
      appendDirectFields(data);
      fetch(<?= json_encode($base . '/?action=api&page=admin/tools/theme-builder/api/restore_theme_revision') ?>, { method: 'POST', body: data })
      .then(function(response) { return response.json(); })
      .then(function(result) {
        if (!result.success) throw new Error(result.error || <?= json_encode(__('Restore failed.')) ?>);
        cleanGeneration = source.changeGeneration();
        window.location.reload();
      })
      .catch(function(error) { alert(error.message); button.disabled = false; });
    });
  });
  document.addEventListener('keydown', function(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      saveInstalledSource();
    }
  });
  window.addEventListener('beforeunload', function(event) {
    if (source.isClean(cleanGeneration)) return;
    event.preventDefault();
    event.returnValue = '';
  });
  <?php endif; ?>
})();
</script>
<?php endif; ?>
