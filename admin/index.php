<?php
declare(strict_types=1);

// Theme Builder — Dashboard

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$selfUrl = $base . '/?page=admin/tools/theme-builder';
$editorUrl = $base . '/?page=admin/tools/theme-builder/editor';

$flash = $_GET['flash'] ?? '';
$flashType = $_GET['flash_type'] ?? 'success';

$themes = ThemeWorkspace::listThemes();
?>

<div class="tb-dashboard">
  <div class="tb-header">
    <h2><?= __('Theme Builder') ?></h2>
    <p class="muted"><?= __('Create custom themes with HTML, CSS, and JS. Edit slot templates with variable reference, preview live, then build an installable ZIP.') ?></p>
  </div>

  <?php if ($flash): ?>
    <div class="tb-flash tb-flash-<?= h($flashType) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="tb-create">
    <h3><?= __('Create New Theme') ?></h3>
    <form id="tb-create-form" class="tb-form">
      <div class="tb-form-row">
        <div class="tb-field">
          <label for="tb-slug"><?= __('Slug') ?> *</label>
          <input type="text" id="tb-slug" name="slug" required pattern="[a-zA-Z0-9_\-]+" placeholder="my-theme" maxlength="50">
          <small><?= __('Folder name — lowercase, no spaces') ?></small>
        </div>
        <div class="tb-field">
          <label for="tb-name"><?= __('Name') ?> *</label>
          <input type="text" id="tb-name" name="name" required placeholder="My Theme" maxlength="100">
        </div>
      </div>
      <div class="tb-form-row">
        <div class="tb-field">
          <label for="tb-author"><?= __('Author') ?></label>
          <input type="text" id="tb-author" name="author" placeholder="Your Name" maxlength="100">
        </div>
        <div class="tb-field">
          <label for="tb-color-mode"><?= __('Color Mode') ?></label>
          <select id="tb-color-mode" name="color_mode">
            <option value="both"><?= __('Both (Light + Dark)') ?></option>
            <option value="light"><?= __('Light Only') ?></option>
            <option value="dark"><?= __('Dark Only') ?></option>
          </select>
        </div>
      </div>
      <div class="tb-field">
        <label for="tb-description"><?= __('Description') ?></label>
        <textarea id="tb-description" name="description" rows="2" placeholder="<?= __('A brief description of your theme') ?>"></textarea>
      </div>
      <button type="submit" class="btn btn-primary"><?= __('Create Theme') ?></button>
    </form>
  </div>

  <?php if (!empty($themes)): ?>
    <div class="tb-themes">
      <h3><?= __('Draft Themes') ?> <span class="badge"><?= count($themes) ?></span></h3>
      <div class="tb-theme-grid">
        <?php foreach ($themes as $t): ?>
          <div class="tb-theme-card">
            <div class="tb-theme-card-header">
              <h4><?= h($t['name']) ?></h4>
              <span class="tb-version">v<?= h($t['version']) ?></span>
            </div>
            <p class="tb-theme-desc"><?= h($t['description'] ?: __('No description')) ?></p>
            <div class="tb-theme-meta">
              <span class="tb-badge tb-badge-<?= $t['color_mode'] ?>"><?= h(ucfirst($t['color_mode'])) ?></span>
              <span class="tb-files"><?= $t['files'] ?> <?= __('files') ?></span>
              <span class="tb-status <?= $t['complete'] ? 'tb-complete' : 'tb-incomplete' ?>">
                <?= $t['complete'] ? __('Complete') : __('Incomplete') ?>
              </span>
            </div>
            <div class="tb-theme-actions">
              <a href="<?= $editorUrl ?>&theme=<?= h($t['slug']) ?>" class="btn btn-sm btn-primary"><?= __('Edit') ?></a>
              <button class="btn btn-sm btn-outline tb-btn-build" data-slug="<?= h($t['slug']) ?>"><?= __('Build') ?></button>
              <button class="btn btn-sm btn-outline tb-btn-install" data-slug="<?= h($t['slug']) ?>"><?= __('Install') ?></button>
              <button class="btn btn-sm btn-danger tb-btn-delete" data-slug="<?= h($t['slug']) ?>">&times;</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function() {
  var base = <?= json_encode($base) ?>;
  var editorUrl = <?= json_encode($editorUrl) ?>;

  document.getElementById('tb-create-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.textContent = '<?= __('Creating...') ?>';
    fetch(base + '/?action=api&page=admin/tools/theme-builder/api/create_theme', { method: 'POST', body: new FormData(this) })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) { window.location.href = editorUrl + '&theme=' + data.slug; }
      else { alert(data.error || 'Failed.'); btn.disabled = false; btn.textContent = '<?= __('Create Theme') ?>'; }
    })
    .catch(function(err) { alert('Error: ' + err.message); btn.disabled = false; btn.textContent = '<?= __('Create Theme') ?>'; });
  });

  document.querySelectorAll('.tb-btn-build').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var slug = this.dataset.slug; this.disabled = true; this.textContent = '<?= __('Building...') ?>'; var self = this;
      fetch(base + '/?action=api&page=admin/tools/theme-builder/api/build_zip&theme=' + encodeURIComponent(slug))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) { var a = document.createElement('a'); a.href = data.download_url; a.download = slug + '.zip'; a.click(); }
        else { alert(data.error || 'Build failed.'); }
        self.disabled = false; self.textContent = '<?= __('Build') ?>';
      })
      .catch(function(err) { alert('Error: ' + err.message); self.disabled = false; self.textContent = '<?= __('Build') ?>'; });
    });
  });

  document.querySelectorAll('.tb-btn-install').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var slug = this.dataset.slug;
      if (!confirm('<?= __('Install theme to themes directory?') ?>')) return;
      this.disabled = true; var self = this;
      fetch(base + '/?action=api&page=admin/tools/theme-builder/api/install_theme&theme=' + encodeURIComponent(slug))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) { alert('<?= __('Theme installed! Go to Themes to activate it.') ?>'); }
        else { alert(data.error || 'Install failed.'); }
        self.disabled = false;
      })
      .catch(function(err) { alert('Error: ' + err.message); self.disabled = false; });
    });
  });

  document.querySelectorAll('.tb-btn-delete').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var slug = this.dataset.slug;
      if (!confirm('<?= __('Delete this draft theme? This cannot be undone.') ?>')) return;
      this.disabled = true; var self = this;
      fetch(base + '/?action=api&page=admin/tools/theme-builder/api/delete_theme&theme=' + encodeURIComponent(slug))
      .then(function(r) { return r.json(); })
      .then(function(data) { if (data.success) { window.location.reload(); } else { alert(data.error || 'Delete failed.'); self.disabled = false; } })
      .catch(function(err) { alert('Error: ' + err.message); self.disabled = false; });
    });
  });
})();
</script>
