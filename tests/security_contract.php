<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "PASS {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL {$message}\n";
};

try {
    $manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $manifest = [];
    $failures[] = 'plugin.json parses';
}

$check(($manifest['name'] ?? null) === 'theme-builder', 'manifest identity is theme-builder');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.85', 'manifest requires the hardened Core theme installer');
$check(in_array('tokenizer', $manifest['requires']['extensions'] ?? [], true), 'manifest declares the dependency-parser tokenizer extension');
$check(!array_key_exists('permissions', $manifest), 'manifest declares no delegable Theme Builder permissions');
$pages = $manifest['admin']['pages'] ?? [];
$check(is_array($pages) && count($pages) === 13, 'manifest declares all thirteen Theme Builder routes');
foreach (is_array($pages) ? $pages : [] as $page) {
    $route = (string)($page['route'] ?? 'unknown');
    $check(($page['site_owner'] ?? false) === true, "{$route} is declared Site Owner-only");
    $check(!array_key_exists('permission', $page), "{$route} has no delegable permission override");
    $check(($page['roles'] ?? null) === ['admin'], "{$route} retains the coarse admin route guard");
    $file = (string)($page['file'] ?? '');
    $real = $file !== '' ? realpath($root . '/' . $file) : false;
    $check($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR), "{$route} resolves inside the plugin");
}
foreach (($manifest['admin']['nav'] ?? []) as $item) {
    $check(($item['site_owner'] ?? false) === true, 'Theme Builder navigation is declared Site Owner-only');
}

$dashboard = (string)file_get_contents($root . '/admin/index.php');
$editor = (string)file_get_contents($root . '/admin/editor.php');
$installed = (string)file_get_contents($root . '/admin/installed.php');
$builderCss = (string)file_get_contents($root . '/assets/css/builder.css');
$check(str_contains($dashboard, 'adiwira_require_site_owner($pdo, false)'), 'Theme Builder dashboard requires Site Owner');
$check(str_contains($editor, 'adiwira_require_site_owner($pdo, false)'), 'Theme Builder editor requires Site Owner');
$check(str_contains($installed, 'adiwira_require_site_owner($pdo, false)'), 'installed theme inspector requires Site Owner');
$check(!str_contains($dashboard, 'plugin.theme-builder.') && !str_contains($editor, 'plugin.theme-builder.') && !str_contains($installed, 'plugin.theme-builder.'), 'Theme Builder UI has no delegated permission path');
$check(str_contains($installed, 'api/fork_theme') && str_contains($installed, 'api/save_fork_file') && !str_contains($installed, 'asset_path'), 'installed workflow uses dedicated fork and opaque-ID save APIs');
$check(str_contains($installed, "data.append('file_id',") && str_contains($installed, "data.append('expected_hash',") && !str_contains($installed, "data.append('path',"), 'managed-fork save submits an opaque file ID and original hash, never a path');
$check(str_contains($installed, 'readOnly: <?= $fileEditable') && str_contains($installed, 'base64_encode($sourceContent)') && str_contains($installed, "new TextDecoder('utf-8', { ignoreBOM: true })"), 'installed PHP source uses a BOM-preserving byte-safe browser transfer and is writable only for an eligible managed fork file');
$check(str_contains($installed, 'addon/fold/foldgutter.js') && str_contains($installed, 'addon/fold/brace-fold.js') && str_contains($installed, 'addon/fold/xml-fold.js') && str_contains($installed, 'foldGutter: true'), 'installed source viewer restores Core CodeMirror folding after loading its local instance');
$check(str_contains($builderCss, '.tb-dashboard .btn-primary:hover') && str_contains($builderCss, 'var(--adam-primary-gradient-hover') && str_contains($builderCss, 'color:#fff'), 'Theme Builder primary hover retains high-contrast text and uses the dashboard hover gradient');
$check(!preg_match('/\b(?:include|require|eval)\s*\(?(?:\$source|\$file)/', $installed), 'installed PHP source is never executed by the inspector UI');
$check(str_contains($dashboard, 'name="csrf_token"') && str_contains($dashboard, 'json_encode($csrfToken'), 'dashboard exposes CSRF through its form and safe JavaScript serialization');
$check(substr_count($dashboard, "method: 'POST'") === 4, 'all dashboard mutations use POST');
$check(substr_count($dashboard, "fd.append('csrf_token', csrfToken)") === 3, 'build, install, and delete send CSRF explicitly');
$check(!str_contains($dashboard, 'api/build_zip&theme=') && !str_contains($dashboard, 'api/install_theme&theme=') && !str_contains($dashboard, 'api/delete_theme&theme='), 'dashboard mutation URLs contain no GET mutation parameters');
$check(str_contains($dashboard, 'a.href = data.download_url'), 'successful builds retain a read-only ZIP download');
$check(str_contains($editor, 'json_encode($csrfToken'), 'editor serializes its CSRF token safely');
$check(substr_count($editor, "fd.append('csrf_token', csrfToken)") === 3, 'slot, manifest, and asset writes send CSRF');
$check(substr_count($editor, "fd.append('expected_hash'") === 3, 'slot, manifest, and asset writes send an optimistic source hash');
$check(!str_contains($editor, 'allow-same-origin') && !str_contains($editor, 'tb-preview-frame'), 'editor does not expose an authenticated same-origin PHP preview iframe');
$check(!str_contains($editor, 'preview&theme=') || !str_contains($editor, 'preview&theme=' . "' + encodeURIComponent(slug) + '&csrf_token="), 'preview URLs do not expose CSRF tokens');

$bootstrap = (string)file_get_contents($root . '/plugin.php');
$preview = (string)file_get_contents($root . '/admin/api/preview.php');
$check(!str_contains($bootstrap, 'class-preview-renderer.php') && !is_file($root . '/includes/class-preview-renderer.php'), 'plugin package contains no in-process PHP preview renderer');
$check(str_contains($bootstrap, 'class-theme-fork-service.php'), 'plugin bootstrap loads the managed fork service');
$check(str_contains($preview, "http_response_code(501)") && !str_contains($preview, 'PreviewRenderer::'), 'preview endpoint refuses PHP execution');

$mutations = [
    'create_theme.php' => 'ThemeWorkspace::createTheme',
    'save_file.php' => 'ThemeWorkspace::write',
    'save_manifest.php' => 'ThemeWorkspace::writeManifest',
    'build_zip.php' => 'ThemeWorkspace::buildZip',
    'install_theme.php' => 'ThemeWorkspace::installTheme',
    'delete_theme.php' => 'ThemeWorkspace::deleteTheme',
];
foreach ($mutations as $file => $operation) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $methodAt = strpos($source, 'REQUEST_METHOD');
    $csrfAt = strpos($source, 'adiwira_csrf_validate');
    $operationAt = strpos($source, $operation);
    $check(str_contains($source, 'adiwira_require_site_owner($pdo, true)'), "{$file} requires Site Owner");
    $check($methodAt !== false && str_contains($source, "!== 'POST'") && str_contains($source, '405'), "{$file} is POST-only");
    $check($csrfAt !== false && str_contains($source, "POST['csrf_token']") && str_contains($source, '419'), "{$file} validates POST CSRF");
    $check($methodAt !== false && $csrfAt !== false && $operationAt !== false && $methodAt < $operationAt && $csrfAt < $operationAt, "{$file} completes security preflight before mutation");
}

foreach (['build_zip.php', 'install_theme.php', 'delete_theme.php'] as $file) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $check(!str_contains($source, "GET['theme']"), "{$file} no longer accepts a GET theme parameter");
}

foreach (['create_theme.php', 'save_file.php', 'save_manifest.php', 'build_zip.php', 'install_theme.php', 'delete_theme.php'] as $file) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $check(str_contains($source, 'ThemeWorkspace::isValidSlug'), "{$file} rejects malformed slugs instead of sanitizing them");
}

$forkMutations = [
    'fork_theme.php' => '->fork(',
    'save_fork_file.php' => '->savePhp(',
];
foreach ($forkMutations as $file => $operation) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $methodAt = strpos($source, 'REQUEST_METHOD');
    $csrfAt = strpos($source, 'adiwira_csrf_validate');
    $operationAt = strpos($source, $operation);
    $check(str_contains($source, 'adiwira_require_site_owner($pdo, true)'), "{$file} requires Site Owner");
    $check($methodAt !== false && str_contains($source, "!== 'POST'") && str_contains($source, '405'), "{$file} is POST-only");
    $check($csrfAt !== false && str_contains($source, "POST['csrf_token']") && str_contains($source, '419'), "{$file} validates POST CSRF");
    $check($methodAt < $operationAt && $csrfAt < $operationAt, "{$file} completes security preflight before mutation");
}
$forkApi = (string)file_get_contents($root . '/admin/api/fork_theme.php');
$forkSaveApi = (string)file_get_contents($root . '/admin/api/save_fork_file.php');
$check(!str_contains($forkApi, 'source_path') && !str_contains($forkApi, 'target_path'), 'fork API accepts theme identities instead of filesystem paths');
$check(str_contains($forkSaveApi, "POST['file_id']") && !str_contains($forkSaveApi, "POST['path']"), 'managed-fork save API accepts no client path');

$saveFile = (string)file_get_contents($root . '/admin/api/save_file.php');
$saveManifest = (string)file_get_contents($root . '/admin/api/save_manifest.php');
$download = (string)file_get_contents($root . '/admin/api/download_zip.php');
$check(str_contains($saveFile, "POST['expected_hash']") && str_contains($saveManifest, "POST['expected_hash']"), 'source mutation APIs require an original SHA-256');
$check(str_contains($download, 'ThemeWorkspace::openArtifact') && str_contains($download, 'fpassthru') && !str_contains($download, "preg_replace('/[^a-zA-Z0-9_-]/'"), 'ZIP download streams a validated immutable descriptor and rejects malformed slugs');
$workspaceSource = (string)file_get_contents($root . '/includes/class-theme-workspace.php');
$inspectorSource = (string)file_get_contents($root . '/includes/class-installed-theme-inspector.php');
$forkServiceSource = (string)file_get_contents($root . '/includes/class-theme-fork-service.php');
$check(str_contains($workspaceSource, 'phpCliBinary()') && str_contains($workspaceSource, 'PHP_VERSION_ID ===') && !str_contains($workspaceSource, "proc_open([PHP_BINARY"), 'PHP lint resolves a CLI binary matching the PHP-FPM runtime version');
$check(str_contains($workspaceSource, 'acquireThemeLock($slug)') && str_contains($workspaceSource, 'zipHashes($temporary)'), 'save, delete, and package operations share a theme lock and verify archive bytes');
$check(str_contains($workspaceSource, 'openRegularFileState($zip, true)') && str_contains($workspaceSource, 'createPrivateInstallStage($slug)') && str_contains($workspaceSource, '($mode & 01000)') && str_contains($workspaceSource, "\$stage = \$stageDir . '/package.zip'"), 'download and installation consume descriptor-verified bytes through sticky-parent private staging');
$check(str_contains($inspectorSource, 'SELECT * FROM themes WHERE folder_name = ? LIMIT 1') && str_contains($inspectorSource, 'hash_equals((string)$file[\'id\'], $fileId)'), 'inspector binds source lookup to a registered theme and opaque file ID');
$check(str_contains($inspectorSource, 'is_link($path)') && str_contains($inspectorSource, 'fstat($handle)') && str_contains($inspectorSource, 'realpath($path)'), 'inspector rejects symlinks and verifies regular-file descriptors inside the theme root');
$check(!str_contains($inspectorSource, "defined('ABSPATH')") && !str_contains($inspectorSource, 'CATCH_GET_CHILD'), 'inspector bootstrap is Jyavani-native and unreadable subtrees fail closed');
$check(str_contains($forkServiceSource, 'SELECT id FROM assignments ORDER BY id') && str_contains($forkServiceSource, ' FOR UPDATE')
    && str_contains($forkServiceSource, 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE') && str_contains($forkServiceSource, 'assertLockedEditable('), 'managed-fork save serializably locks Core theme and assignment state and rechecks eligibility before replacement');
$check(str_contains($forkServiceSource, 'root_identity') && str_contains($forkServiceSource, 'quarantineAndRemove($target, $targetFolder, $promotedIdentity)'), 'managed-fork metadata and rollback bind to the promoted physical root identity');
$check(str_contains($forkServiceSource, "mkdir(\$stage, 0700)") && str_contains($forkServiceSource, 'applyPublishedModes($stage)'), 'fork copy remains private until its verified tree is ready for publication');

$build = (string)file_get_contents($root . '/admin/api/build_zip.php');
$check(str_contains($build, '?action=api&page=admin/tools/theme-builder/api/download_zip&theme='), 'build response uses the pre-layout API download route');

foreach (['preview.php', 'download_zip.php'] as $file) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $check(str_contains($source, 'adiwira_require_site_owner($pdo, false)'), "{$file} requires Site Owner");
    $check(str_contains($source, "!== 'GET'") && str_contains($source, '405'), "{$file} is GET-only");
    $check(!str_contains($source, 'adiwira_csrf_validate') && !str_contains($source, 'csrf_check'), "{$file} remains a CSRF-free read endpoint");
}

if ($failures !== []) {
    fwrite(STDERR, 'Theme Builder security contract failed: ' . implode('; ', array_unique($failures)) . "\n");
    exit(1);
}

echo "RESULT: ALL PASS\n";
