<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-core-integration-' . bin2hex(random_bytes(8));
$themesRoot = $root . '/themes';
$workspace = $root . '/workspace';
mkdir($themesRoot . '/managed-fork', 0770, true);
mkdir($workspace . '/.installed-forks', 0770, true);
chmod($workspace, 0770);
chmod($workspace . '/.installed-forks', 0770);
define('VIEWS_BASE', $themesRoot);
define('DEFAULT_THEME_FOLDER', 'default');
define('THEME_BUILDER_WORKSPACE', $workspace);
define('ADMIN_BASE_PATH', '/owner');

$GLOBALS['_tb_hooks'] = [];
function add_action(string $name, callable $callback, int $priority = 10): void
{
    $GLOBALS['_tb_hooks'][] = ['action', $name, $callback, $priority];
}
function add_filter(string $name, callable $callback, int $priority = 10): void
{
    $GLOBALS['_tb_hooks'][] = ['filter', $name, $callback, $priority];
}
function __(string $text): string { return $text; }

final class ThemeSourceService
{
    public function inventory(string $folder): array
    {
        return ['theme' => [
            'id' => 1, 'folder' => $folder, 'active' => false, 'assigned' => false, 'store' => false, 'system' => false,
        ], 'files' => []];
    }
    public function source(string $folder, string $fileId): ?array { return null; }
}
function theme_source_service(PDO $pdo): ThemeSourceService
{
    static $service;
    return $service ??= new ThemeSourceService();
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE themes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, folder_name TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT \'\', version TEXT NOT NULL DEFAULT \'\', author TEXT NOT NULL DEFAULT \'\',
    manifest_json TEXT, is_active INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0,
    store_url TEXT NOT NULL DEFAULT \'\', store_slug TEXT NOT NULL DEFAULT \'\'
)');
$pdo->exec('CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, slot_key TEXT, theme_id INTEGER, theme_file TEXT, custom_post_id INTEGER)');
$pdo->exec("INSERT INTO themes (folder_name, name, version) VALUES ('managed-fork', 'Managed Fork', '1.0.0')");
$themeId = (int)$pdo->lastInsertId();
file_put_contents($themesRoot . '/managed-fork/theme.json', json_encode([
    'folder' => 'managed-fork', 'name' => 'Managed Fork', 'version' => '1.0.0',
], JSON_THROW_ON_ERROR));
$identity = lstat($themesRoot . '/managed-fork');
$metadataPath = $workspace . '/.installed-forks/' . hash('sha256', 'managed-fork') . '.json';
file_put_contents($metadataPath, json_encode([
    'schema' => 1,
    'folder' => 'managed-fork',
    'theme_id' => $themeId,
    'root_identity' => ['dev' => (int)$identity['dev'], 'ino' => (int)$identity['ino']],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($metadataPath, 0660);
$GLOBALS['pdo'] = $pdo;

require_once dirname(__DIR__) . '/plugin.php';
ThemeBuilderCoreIntegration::register($pdo);

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    @rmdir($path);
};
register_shutdown_function(static function () use ($root, $remove): void { $remove($root); });

try {
    $manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
    $check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.164' && ($manifest['version'] ?? null) === '1.8.0',
        'release manifest targets Core 2.3.164 as Theme Builder 1.8.0');

    $hooks = $GLOBALS['_tb_hooks'];
    $check(count($hooks) === 3 && array_column($hooks, 1) === ['theme_source_editor_actions', 'theme_manager_theme_actions', 'theme_source_edit_policy']
        && count($hooks[0]) === 4 && count($hooks[1]) === 4 && count($hooks[2]) === 4,
        'bootstrap registers source and Theme Manager hooks through Core\'s three-argument registration API');
    $callbacks = [];
    foreach ($hooks as $hook) $callbacks[$hook[1]] = $hook[2];

    ob_start();
    $callbacks['theme_source_editor_actions'](
        ['folder_name' => 'managed-fork'],
        ['folder' => 'managed-fork', 'admin_base_path' => '/owner'],
        $pdo
    );
    $actions = (string)ob_get_clean();
    $check(str_contains($actions, 'Fork &amp; Edit') && str_contains($actions, 'Owner Workspaces')
        && str_contains($actions, 'fork=managed-fork') && str_contains($actions, 'theme=managed-fork')
        && str_contains($actions, 'tb-core-source-action')
        && !str_contains($actions, 'Save') && !str_contains($actions, 'Export'),
        'Core source editor receives fork and owner links but no duplicate save or export action');

    ob_start();
    $callbacks['theme_manager_theme_actions'](
        ['folder_name' => 'managed-fork'],
        ['folder' => 'managed-fork', 'name' => 'Managed Fork'],
        ['folder' => 'managed-fork', 'admin_base_path' => '/owner']
    );
    $managerActions = (string)ob_get_clean();
    $check(str_contains($managerActions, 'tm-action-group--theme-builder')
        && str_contains($managerActions, 'tb-theme-builder-action--primary')
        && str_contains($managerActions, 'Theme Builder')
        && str_contains($managerActions, 'fork=managed-fork') && str_contains($managerActions, 'theme=managed-fork'),
        'Theme Manager cards receive a visibly branded Theme Builder action group');

    $allow = ['allowed' => true, 'message' => ''];
    $row = ['id' => $themeId, 'folder_name' => 'managed-fork'];
    foreach (['edit', 'save', 'restore'] as $operation) {
        $result = $callbacks['theme_source_edit_policy']($allow, $row, ['operation' => $operation, 'folder' => 'managed-fork'], $pdo);
        $check($result === $allow, "inactive unassigned managed fork allows Core {$operation}");
    }

    $pdo->exec("UPDATE themes SET is_active = 1 WHERE folder_name = 'managed-fork'");
    foreach (['edit', 'save', 'restore'] as $operation) {
        $result = $callbacks['theme_source_edit_policy']($allow, $row, ['operation' => $operation, 'folder' => 'managed-fork'], $pdo);
        $check($result['allowed'] === false && str_contains($result['message'], 'active'),
            "active managed fork denies Core {$operation}");
    }
    $priorDeny = ['allowed' => false, 'message' => 'Denied by another plugin.'];
    $check($callbacks['theme_source_edit_policy']($priorDeny, $row, ['operation' => 'save', 'folder' => 'managed-fork'], $pdo) === $priorDeny,
        'managed-fork policy is monotonic and never reverses an earlier denial');

    $pdo->exec("UPDATE themes SET is_active = 0 WHERE folder_name = 'managed-fork'");
    $pdo->prepare('INSERT INTO assignments (slot_key, theme_id) VALUES (?, ?)')->execute(['header', $themeId]);
    $assigned = $callbacks['theme_source_edit_policy']($allow, $row, ['operation' => 'restore', 'folder' => 'managed-fork'], $pdo);
    $check($assigned['allowed'] === false && str_contains($assigned['message'], 'assigned'),
        'assigned managed fork denies Core restore');
    $pdo->exec('DELETE FROM assignments');

    file_put_contents($metadataPath, '{broken');
    $invalid = $callbacks['theme_source_edit_policy']($allow, $row, ['operation' => 'save', 'folder' => 'managed-fork'], $pdo);
    $check($invalid['allowed'] === false && str_contains($invalid['message'], 'provenance'),
        'invalid managed-fork provenance fails Core save closed');

    $source = (string)file_get_contents(dirname(__DIR__) . '/includes/class-theme-builder-core-integration.php');
    foreach (['theme_update_preflight', 'theme_update_completed', 'theme_install_completed', 'plugin_state_change_preflight'] as $removedHook) {
        $check(!str_contains($source, $removedHook), "integration no longer owns {$removedHook}");
    }
    $serviceMethods = array_map(static fn(ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(ThemeForkService::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    foreach (['savePhp', 'saveDirectPhp', 'revisions', 'restoreDirectPhp', 'restoreManagedPhp', 'dirtyState', 'refreshBaseline', 'buildPhpSourceExport'] as $removedMethod) {
        $check(!in_array($removedMethod, $serviceMethods, true), "fork service has no {$removedMethod} source ownership");
    }
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Core integration contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "RESULT: ALL PASS\n";
