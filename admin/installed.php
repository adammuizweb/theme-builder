<?php
declare(strict_types=1);

// Installed-theme fork workflow and cross-owner navigation. Core owns source editing.

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }
adiwira_require_permission($pdo, 'core.themes.manage', false);
adiwira_require_site_owner($pdo, false);

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$dashUrl = $base . '/?page=admin/tools/theme-builder';
$selfUrl = $base . '/?page=admin/tools/theme-builder/installed';
$legacyExportUrl = $base . '/?action=api&page=admin/tools/theme-builder/api/export_legacy_history';
$coreSourceUrl = static fn(string $theme): string => $base . '/?page=admin/themes/source&folder=' . rawurlencode($theme);
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
        // Invalid, unregistered, or unavailable Core inventory never opens the workflow.
    }
}

$renderForkModal = static function () use ($base, $coreSourceUrl, $csrfToken, $forkRequest): void {
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
        <p class="muted"><?= __('Theme Builder copies and verifies the complete physical tree, detaches Store identity, and registers the fork inactive. Core then owns source inspection and editing.') ?></p>
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
      method: 'POST', body: new FormData(form)
    })
    .then(function(response) { return response.json(); })
    .then(function(result) {
      if (!result.success) throw new Error(result.error || <?= json_encode(__('Fork creation failed.')) ?>);
       window.location.href = <?= json_encode($base . '/?page=admin/themes/source&folder=') ?> + encodeURIComponent(result.folder);
    })
    .catch(function(error) {
      alert(error.message);
      submit.disabled = false;
      submit.textContent = <?= json_encode(__('Create Inactive Fork')) ?>;
    });
  });
  document.querySelectorAll('[data-tb-delete-fork]').forEach(function(button) {
    button.addEventListener('click', function() {
      var theme = this.dataset.tbDeleteFork || '';
      if (!theme || !confirm(<?= json_encode(__('Delete this inactive managed fork and its Theme Zone data? Legacy Theme Builder revision data is retained. This cannot be undone.')) ?>)) return;
      button.disabled = true;
      var data = new FormData();
      data.append('csrf_token', <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
      data.append('theme', theme);
      fetch(<?= json_encode($base . '/?action=api&page=admin/tools/theme-builder/api/delete_fork') ?>, { method: 'POST', body: data })
      .then(function(response) { return response.json(); })
      .then(function(result) {
        if (!result.success) throw new Error(result.error || <?= json_encode(__('Managed fork deletion failed.')) ?>);
        if (result.warning) alert(result.warning);
        window.location.href = <?= json_encode($base . '/?page=admin/tools/theme-builder/installed') ?>;
      })
      .catch(function(error) { alert(error.message); button.disabled = false; });
    });
  });
})();
</script>
<?php
};

if ($folder === ''):
    try {
        $themes = $inspector->themes();
    } catch (Throwable $error) {
        echo '<div class="tb-flash tb-flash-error">' . h($error->getMessage()) . '</div>';
        return;
    }
?>
<div class="tb-dashboard tb-installed-list">
  <div class="tb-header tb-header-row">
    <div>
      <h2><?= __('Installed Themes') ?></h2>
      <p class="muted"><?= __('Open Core for source inspection, edits, revisions, and exports, or create a complete inactive fork here.') ?></p>
    </div>
    <div class="tb-theme-actions">
      <form method="post" action="<?= h($legacyExportUrl) ?>" style="display:inline;margin:0">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-outline"><?= __('Export Legacy History') ?></button>
      </form>
      <a href="<?= h($dashUrl) ?>" class="btn btn-outline">&larr; <?= __('Theme Builder') ?></a>
    </div>
  </div>
  <div class="tb-theme-grid">
    <?php foreach ($themes as $theme): ?>
      <?php $managed = $forkService->forkState((string)$theme['folder']); ?>
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
          <?php if ($managed['managed']): ?><span class="tb-inspector-badge is-fork"><?= __('Managed Fork') ?></span><?php endif; ?>
          <?php if ($theme['inspectable']): ?><span class="tb-files"><?= (int)$theme['php_files'] ?> <?= __('PHP files') ?></span><?php endif; ?>
        </div>
        <?php if (!$theme['inspectable']): ?><p class="tb-inspector-error"><?= h((string)$theme['error']) ?></p><?php endif; ?>
        <div class="tb-theme-actions">
          <a class="btn btn-sm btn-primary" href="<?= h($coreSourceUrl((string)$theme['folder'])) ?>"><?= __('Open Core Source Editor') ?></a>
          <a class="btn btn-sm btn-outline" href="<?= h($selfUrl . '&theme=' . rawurlencode((string)$theme['folder'])) ?>"><?= __('Owner Workspaces') ?></a>
          <?php if ($theme['inspectable']): ?><button type="button" class="btn btn-sm btn-outline" data-tb-fork data-source="<?= h($theme['folder']) ?>" data-name="<?= h($theme['name']) ?>"><?= __('Fork & Edit') ?></button><?php endif; ?>
          <?php if ($managed['managed']): ?><button type="button" class="btn btn-sm btn-danger" data-tb-delete-fork="<?= h($theme['folder']) ?>" <?= $managed['editable'] ? '' : 'disabled' ?>><?= __('Delete Fork') ?></button><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php $renderForkModal(); ?>
</div>
<?php
    return;
endif;

try {
    $inspection = $inspector->inspect($folder);
    $theme = $inspection['theme'];
    $relationships = (new ThemeOwnerNavigator($pdo))->relationships($folder, $inspection, $base);
} catch (Throwable $error) {
    echo '<div class="tb-flash tb-flash-error">' . h($error->getMessage()) . '</div>';
    echo '<p><a class="btn btn-outline" href="' . h($selfUrl) . '">&larr; ' . h(__('Installed Themes')) . '</a></p>';
    return;
}
?>
<div class="tb-dashboard tb-installed-list">
  <div class="tb-header tb-header-row">
    <div>
      <h2><?= h($theme['name']) ?></h2>
      <p class="muted"><?= __('Theme Builder provides navigation only. Core owns this installed theme’s PHP source and history.') ?></p>
    </div>
    <div class="tb-theme-actions">
      <a class="btn btn-primary" href="<?= h($coreSourceUrl($folder)) ?>"><?= __('Open Core Source Editor') ?></a>
      <button type="button" class="btn btn-outline" data-tb-fork data-source="<?= h($folder) ?>" data-name="<?= h($theme['name']) ?>"><?= __('Fork & Edit') ?></button>
      <a class="btn btn-outline" href="<?= h($selfUrl) ?>">&larr; <?= __('Installed Themes') ?></a>
    </div>
  </div>

  <section class="tb-owner-map" aria-labelledby="tb-owner-map-title">
    <div class="tb-owner-map-heading"><div><h4 id="tb-owner-map-title"><?= __('Owner Workspaces') ?></h4><p><?= __('Content remains stored and edited by Core or its owning plugin.') ?></p></div></div>
    <div class="tb-owner-map-grid">
      <article class="tb-owner-card">
        <header><div><span><?= __('Core-owned data') ?></span><h5><?= __('Theme Templates') ?></h5></div><strong><?= count($relationships['templates']['items']) ?></strong></header>
        <?php if ($relationships['templates']['error'] !== null): ?>
          <p class="tb-owner-empty"><?= h(__((string)$relationships['templates']['error'])) ?></p>
        <?php elseif ($relationships['templates']['items'] === []): ?>
          <p class="tb-owner-empty"><?= __('No database Theme Templates were found.') ?></p>
        <?php else: ?>
          <?php foreach ($relationships['templates']['items'] as $template): ?>
            <div class="tb-owner-item">
              <div class="tb-owner-item-title"><strong><?= h($template['title'] ?: '#' . $template['id']) ?></strong><code><?= h($template['slug']) ?></code></div>
              <div class="tb-owner-meta">
                <span><?= h(__(ucfirst($template['status']))) ?></span>
                <span><?= $template['slots'] ? h(implode(', ', $template['slots'])) : h(__('Unassigned')) ?></span>
                <?php if ($template['builder'] !== null): ?><span><?= __('Jy Builder') ?>: <?= h(__(ucfirst($template['builder']['status']))) ?></span><?php endif; ?>
              </div>
              <div class="tb-owner-actions">
                <?php if ($template['core_url'] !== null): ?><a class="btn btn-sm btn-outline" href="<?= h($template['core_url']) ?>"><?= __('Edit in Core') ?></a><?php endif; ?>
                <?php if ($template['builder'] !== null): ?><a class="btn btn-sm btn-outline" href="<?= h($template['builder']['url']) ?>"><?= __('Open Jy Builder') ?></a><?php endif; ?>
                <?php foreach ($template['translations'] as $translation): ?><a class="btn btn-sm btn-outline" href="<?= h($translation['url']) ?>"><?= h(sprintf(__('Translate %s'), strtoupper($translation['locale']))) ?></a><?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($relationships['templates']['truncated']): ?><p class="tb-owner-note"><?= __('Showing the first 200 Theme Templates.') ?></p><?php endif; ?>
          <?php if ($relationships['templates']['translation_locales_truncated']): ?><p class="tb-owner-note"><?= __('Showing Content Translation links for the first 20 valid locales.') ?></p><?php endif; ?>
          <?php if (!$theme['active']): ?><p class="tb-owner-note"><?= __('Content Translation package links are shown only for the active physical theme.') ?></p><?php endif; ?>
        <?php endif; ?>
      </article>
      <article class="tb-owner-card">
        <header><div><span><?= __('Customizer-backed data') ?></span><h5><?= __('Theme Files') ?></h5></div><strong><?= count($relationships['theme_files']['items']) ?></strong></header>
        <?php if ($relationships['theme_files']['error'] !== null): ?>
          <p class="tb-owner-empty"><?= h(__((string)$relationships['theme_files']['error'])) ?></p>
        <?php elseif (!$relationships['theme_files']['available']): ?>
          <p class="tb-owner-empty"><?= __('Content Translation is unavailable or access to its Theme File editor is not allowed.') ?></p>
        <?php elseif ($relationships['theme_files']['items'] === []): ?>
          <p class="tb-owner-empty"><?= __('This theme declares no translatable Theme File resources.') ?></p>
        <?php else: ?>
          <?php foreach ($relationships['theme_files']['items'] as $resource): ?>
            <div class="tb-owner-item">
              <div class="tb-owner-item-title"><strong><?= h($resource['label']) ?></strong><code><?= h($resource['id']) ?></code></div>
              <div class="tb-owner-meta">
                <span><?= h(sprintf(__('%d translatable fields'), $resource['field_count'])) ?></span>
                <?php if ($resource['source_path'] !== null): ?><span><?= h($resource['source_path']) ?></span><?php endif; ?>
              </div>
              <div class="tb-owner-actions">
                <?php if ($resource['source_url'] !== null): ?><a class="btn btn-sm btn-outline" href="<?= h($resource['source_url']) ?>"><?= __('Open Source in Core') ?></a><?php endif; ?>
                <?php foreach ($resource['translations'] as $translation): ?>
                  <?php $translationStatus = $translation['status'] !== null ? ucfirst($translation['status']) : 'Not started'; ?>
                  <a class="btn btn-sm btn-outline" href="<?= h($translation['url']) ?>"><?= h(strtoupper($translation['locale']) . ': ' . __($translationStatus)) ?></a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($relationships['theme_files']['locales_truncated']): ?><p class="tb-owner-note"><?= __('Showing Content Translation links for the first 20 valid locales.') ?></p><?php endif; ?>
        <?php endif; ?>
      </article>
      <article class="tb-owner-card">
        <header><div><span><?= __('Physical PHP source') ?></span><h5><?= __('Theme Sections') ?></h5></div><strong><?= count($relationships['sections']['items']) ?></strong></header>
        <?php if ($relationships['sections']['items'] === []): ?>
          <p class="tb-owner-empty"><?= __('No registered Theme Section wrappers were found in this theme.') ?></p>
        <?php else: ?>
          <?php foreach ($relationships['sections']['items'] as $section): ?>
            <div class="tb-owner-item">
              <div class="tb-owner-item-title"><a href="<?= h($section['url']) ?>"><strong><?= h(basename($section['path'], '.php')) ?></strong></a><code><?= h($section['path']) ?></code></div>
              <?php if ($section['error'] !== null): ?>
                <p class="tb-owner-note"><?= h(__((string)$section['error'])) ?></p>
              <?php elseif ($section['scan_reason'] === 'aggregate_limit'): ?>
                <p class="tb-owner-note"><?= __('Dependency scan skipped after the 16 MiB navigation budget was reached.') ?></p>
              <?php elseif (!$section['scanned']): ?>
                <p class="tb-owner-note"><?= __('Dependency scan skipped because this wrapper exceeds 256 KiB.') ?></p>
              <?php elseif ($section['dependencies'] === []): ?>
                <p class="tb-owner-note"><?= __('No local literal leaf dependency detected.') ?></p>
              <?php else: ?>
                <div class="tb-owner-dependencies">
                  <?php foreach ($section['dependencies'] as $dependency): ?><a href="<?= h($dependency['url']) ?>"><span><?= h($dependency['path']) ?></span><small><?= h($dependency['category']) ?></small></a><?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </article>
    </div>
  </section>
  <?php $renderForkModal(); ?>
</div>
