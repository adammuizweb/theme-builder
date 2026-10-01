<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

try {
    $manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    $manifest = [];
    $failures[] = 'plugin.json parses';
}

$check(($manifest['name'] ?? null) === 'theme-builder', 'manifest identity is theme-builder');
$check(($manifest['version'] ?? null) === '1.8.2' && ($manifest['requires']['jyavani'] ?? null) === '>=2.3.164',
    'manifest releases Theme Builder 1.8.2 with the Core source-service floor');
$check(in_array('tokenizer', $manifest['requires']['extensions'] ?? [], true),
    'manifest declares tokenizer for bounded Core-source dependency navigation');
$check(!array_key_exists('permissions', $manifest), 'manifest declares no delegated Theme Builder permissions');

$pages = $manifest['admin']['pages'] ?? [];
$routes = array_column(is_array($pages) ? $pages : [], 'route');
$check(count($routes) === 14, 'manifest exposes only draft, package, fork, navigation, legacy-export, and read-asset routes');
$requiredRoutes = [
    'admin/tools/theme-builder',
    'admin/tools/theme-builder/editor',
    'admin/tools/theme-builder/installed',
    'admin/tools/theme-builder/api/save_file',
    'admin/tools/theme-builder/api/create_theme',
    'admin/tools/theme-builder/api/delete_theme',
    'admin/tools/theme-builder/api/build_zip',
    'admin/tools/theme-builder/api/read_asset',
    'admin/tools/theme-builder/api/save_manifest',
    'admin/tools/theme-builder/api/install_theme',
    'admin/tools/theme-builder/api/download_zip',
    'admin/tools/theme-builder/api/fork_theme',
    'admin/tools/theme-builder/api/delete_fork',
    'admin/tools/theme-builder/api/export_legacy_history',
];
$check($routes === $requiredRoutes, 'draft/fork ownership and narrow legacy-history export routes remain intact');

$removedEndpoints = [
    'save_fork_file.php',
    'save_installed_file.php',
    'list_theme_revisions.php',
    'restore_theme_revision.php',
    'export_theme_source.php',
];
foreach ($removedEndpoints as $file) {
    $route = 'admin/tools/theme-builder/api/' . basename($file, '.php');
    $check(!in_array($route, $routes, true) && !is_file($root . '/admin/api/' . $file),
        "{$file} is neither registered nor physically reachable");
}

foreach (is_array($pages) ? $pages : [] as $page) {
    $route = (string)($page['route'] ?? 'unknown');
    $check(($page['site_owner'] ?? false) === true && ($page['roles'] ?? null) === ['admin']
        && !array_key_exists('permission', $page), "{$route} remains Site Owner-only");
    $file = (string)($page['file'] ?? '');
    $real = $file !== '' ? realpath($root . '/' . $file) : false;
    $check($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR), "{$route} resolves inside the plugin");
    $source = $real !== false ? (string)file_get_contents($real) : '';
    $permissionAt = strpos($source, "adiwira_require_permission(\$pdo, 'core.themes.manage'");
    $ownerAt = strpos($source, 'adiwira_require_site_owner($pdo');
    $check($permissionAt !== false && $ownerAt !== false && $permissionAt < $ownerAt,
        "{$route} handler enforces core.themes.manage before its redundant Site Owner check");
}

$dashboard = (string)file_get_contents($root . '/admin/index.php');
$editor = (string)file_get_contents($root . '/admin/editor.php');
$installed = (string)file_get_contents($root . '/admin/installed.php');
$integration = (string)file_get_contents($root . '/includes/class-theme-builder-core-integration.php');
$inspector = (string)file_get_contents($root . '/includes/class-installed-theme-inspector.php');
$navigator = (string)file_get_contents($root . '/includes/class-theme-owner-navigator.php');
$forkService = (string)file_get_contents($root . '/includes/class-theme-fork-service.php');
$legacyService = (string)file_get_contents($root . '/includes/class-theme-builder-legacy-history.php');
$builderCss = (string)file_get_contents($root . '/assets/css/builder.css');
$readme = (string)file_get_contents($root . '/README.md');

$check(str_contains($dashboard, "adiwira_require_permission(\$pdo, 'core.themes.manage', false)")
    && str_contains($editor, "adiwira_require_permission(\$pdo, 'core.themes.manage', false)")
    && str_contains($installed, "adiwira_require_permission(\$pdo, 'core.themes.manage', false)"),
    'all Theme Builder pages require executable theme capability');
$check(str_contains($dashboard, 'api/save_file') === false && str_contains($editor, 'api/save_file')
    && str_contains($dashboard, 'api/build_zip') && str_contains($dashboard, 'api/install_theme'),
    'draft editor, build, and install workflows remain wired');
$check(str_contains($installed, 'api/fork_theme') && str_contains($installed, 'api/delete_fork')
    && !str_contains($installed, 'api/save_fork_file') && !str_contains($installed, 'api/save_installed_file')
    && !str_contains($installed, 'api/restore_theme_revision') && !str_contains($installed, 'api/export_theme_source'),
    'installed page retains fork create/delete without duplicate source mutation routes');
$check(str_contains($installed, 'api/export_legacy_history') && str_contains($installed, 'Export Legacy History'),
    'installed page exposes the protected read-only legacy history export');
$check(str_contains($installed, 'No database Theme Templates were found.')
    && str_contains($installed, 'Showing the first 200 Theme Templates.')
    && str_contains($installed, 'Content Translation is unavailable')
    && str_contains($installed, 'Not started')
    && str_contains($installed, 'No bounded legacy main/sections dependency detected.')
    && str_contains($installed, 'Draft Collection Layout and Theme Section authoring is not supported'),
    'Owner Workspaces UI retains empty, status, error, and truncation messaging');
$check(str_contains($installed, 'page=admin/themes/source&folder=') && str_contains($installed, 'Open Core Source Editor')
    && !str_contains($installed, 'CodeMirror') && !str_contains($installed, '<textarea'),
    'installed source inspection and editing link to Core rather than rendering a plugin editor');

$check(substr_count($integration, "add_action('") + substr_count($integration, "add_filter('") === 4
    && str_contains($integration, "add_action('theme_source_editor_actions'")
    && str_contains($integration, "add_action('theme_manager_theme_actions'")
    && str_contains($integration, "add_filter('theme_source_edit_policy'")
    && str_contains($integration, "add_filter('theme_delete_preflight'")
    && !str_contains($integration, "], 10, 3)") && !str_contains($integration, "], 10, 4)"),
    'integration registers exactly four hooks through Core\'s three-argument API');
foreach (['theme_update_preflight', 'theme_update_completed', 'theme_install_completed', 'plugin_state_change_preflight'] as $hook) {
    $check(!str_contains($integration, $hook), "integration has no {$hook} ownership");
}
$check(str_contains($integration, "['edit', 'save', 'restore']")
    && str_contains($integration, 'if (!$allowed) return') && str_contains($integration, 'managedEditPolicy($folder)'),
    'managed-fork Core policy is operation-bounded, monotonic, and provenance-backed');
$check(str_contains($integration, 'hasManagedForkMarker($folder)')
    && str_contains($forkService, 'public function hasManagedForkMarker('),
    'managed-fork deletion preflight uses read-only provenance marker detection');
$check(str_contains($integration, 'Fork & Edit') && str_contains($integration, 'Owner Workspaces')
    && !str_contains($integration, 'Export PHP') && !str_contains($integration, 'Inspect PHP'),
    'Core surfaces receive only fork and owner navigation actions');
$check(str_contains($integration, 'tm-action-group--theme-builder')
    && str_contains($integration, 'theme-source-action-group--theme-builder')
    && substr_count($integration, 'Theme Builder') >= 2
    && str_contains($builderCss, '.tm-action-group--theme-builder')
    && str_contains($builderCss, '.theme-source-action-group--theme-builder')
    && str_contains($builderCss, '.theme-source-action-group--theme-builder .theme-source-action')
    && str_contains($builderCss, 'display:flex')
    && str_contains($builderCss, '.tb-theme-builder-action--primary')
    && str_contains($builderCss, 'html.theme-dark .tm-action-group--theme-builder')
    && str_contains($builderCss, 'html.theme-dark .theme-source-action-group--theme-builder')
    && !str_contains($builderCss, '.tb-core-source-action'),
    'Theme Builder owner groups remain visually distinct from Core on both surfaces in light and dark themes');
$check(str_contains((string)file_get_contents($root . '/plugin.json'), 'theme-builder.css?v=1.8.2'),
    'changed owner-action stylesheet uses a cache-distinct asset URL');

$check(str_contains($inspector, 'theme_source_service($this->pdo)')
    && !str_contains($inspector, 'RecursiveDirectoryIterator') && !str_contains($inspector, 'VIEWS_BASE'),
    'installed navigation consumes Core inventory with no plugin filesystem fallback');
$check(str_contains($navigator, "page=admin/themes/source&folder=")
    && str_contains($navigator, 'literalDependencies($folder, $rendererIds)')
    && str_contains($navigator, "'scope' => 'section'")
    && str_contains($navigator, "'source' => 'theme'")
    && str_contains($inspector, 'token_get_all($source)') && str_contains($inspector, 'MAX_DEPENDENCY_TOTAL_BYTES'),
    'owner navigation uses dedicated Core editors plus bounded opaque compatibility links');
$check(str_contains($forkService, 'assertCoreInventoryAvailable($sourceFolder)')
    && str_contains($forkService, 'theme_source_service($this->pdo)'),
    'complete fork creation requires Core installed-source inventory');

foreach (['savePhp(', 'saveDirectPhp(', 'restorePhp(', 'revisions(', 'dirtyState(', 'refreshBaseline(', 'buildPhpSourceExport('] as $method) {
    $check(!str_contains($forkService, $method), "fork service no longer implements {$method}");
}
$check(!str_contains($forkService, "privateDirectory('.baselines')")
    && !str_contains($forkService, "privateDirectory('.revisions')")
    && !str_contains($forkService, "privateDirectory('.exports')"),
    'fork service has no baseline, revision, or installed-source export authority');
$check(str_contains($installed, 'Legacy Theme Builder revision data is retained')
    && str_contains($readme, 'Existing legacy') && str_contains($readme, 'left untouched'),
    'legacy baseline and revision retention is explicit');
$check(str_contains($readme, 'Theme Builder 1.8.2') && str_contains($readme, 'Core 2.3.164'),
    'README documents the released Core and Theme Builder compatibility pair');
$check(version_compare('2.3.163', substr((string)$manifest['requires']['jyavani'], 2), '<')
    && str_contains($readme, 'activation remains blocked on older'),
    'release compatibility rejects Core versions older than 2.3.164');
$check(str_contains($legacyService, "['.baselines' => 'baselines', '.revisions' => 'revisions']")
    && str_contains($legacyService, 'MAX_TOTAL_BYTES') && str_contains($legacyService, 'verifyArchive(')
    && str_contains($legacyService, 'cleanupExport(') && !str_contains($legacyService, 'theme_source_service')
    && !str_contains($legacyService, 'FROM themes') && !str_contains($legacyService, 'scanTree('),
    'legacy exporter is bounded, private, verified, and independent of installed source');

$draftMutations = [
    'create_theme.php' => 'ThemeWorkspace::createTheme',
    'save_file.php' => 'ThemeWorkspace::write',
    'save_manifest.php' => 'ThemeWorkspace::writeManifest',
    'build_zip.php' => 'ThemeWorkspace::buildZip',
    'install_theme.php' => 'ThemeWorkspace::installTheme',
    'delete_theme.php' => 'ThemeWorkspace::deleteTheme',
];
foreach ($draftMutations as $file => $operation) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $methodAt = strpos($source, 'REQUEST_METHOD');
    $csrfAt = strpos($source, 'adiwira_csrf_validate');
    $operationAt = strpos($source, $operation);
    $check(str_contains($source, 'adiwira_require_site_owner($pdo, true)') && $methodAt !== false
        && strpos($source, "adiwira_require_permission(\$pdo, 'core.themes.manage', true)") < strpos($source, 'adiwira_require_site_owner($pdo, true)')
        && str_contains($source, "!== 'POST'") && $csrfAt !== false && $operationAt !== false
        && $methodAt < $operationAt && $csrfAt < $operationAt,
        "{$file} keeps Site Owner, POST, and CSRF checks before draft mutation");
}

foreach (['fork_theme.php' => '->fork(', 'delete_fork.php' => '->deleteFork('] as $file => $operation) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $check(str_contains($source, 'adiwira_require_site_owner($pdo, true)')
        && strpos($source, "adiwira_require_permission(\$pdo, 'core.themes.manage', true)") < strpos($source, 'adiwira_require_site_owner($pdo, true)')
        && strpos($source, 'REQUEST_METHOD') < strpos($source, $operation)
        && strpos($source, 'adiwira_csrf_validate') < strpos($source, $operation),
        "{$file} keeps Site Owner, POST, and CSRF checks before fork mutation");
}

$check(!str_contains($forkService, "'0-theme-lifecycle'")
    && str_contains($forkService, "function_exists('theme_lifecycle_lock_keys')")
    && str_contains($forkService, 'theme_lifecycle_lock_keys(')
    && str_contains($forkService, 'package_publication_recovery_paths($target)')
    && str_contains($forkService, 'scanTree($sourceRoot)') && str_contains($forkService, 'assertForkSnapshot('),
    'complete physical forks derive locks from Core and retain recovery checks, bounded copy, and verification');
$check(str_contains($forkService, 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')
    && str_contains($forkService, 'SELECT id FROM assignments WHERE theme_id = ? ORDER BY id')
    && str_contains($forkService, 'assertManagedRootIdentity($root, $metadata)'),
    'managed-fork deletion retains serializable assignment and provenance checks');

if ($failures !== []) {
    fwrite(STDERR, 'Theme Builder security contract failed: ' . implode('; ', array_unique($failures)) . PHP_EOL);
    exit(1);
}
echo "RESULT: ALL PASS\n";
