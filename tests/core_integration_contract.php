<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-core-integration-' . bin2hex(random_bytes(8));
$themesRoot = $root . '/themes';
$workspace = $root . '/workspace';
mkdir($themesRoot . '/store-theme', 0770, true);
define('VIEWS_BASE', $themesRoot);
define('DEFAULT_THEME_FOLDER', 'default');
define('THEME_BUILDER_WORKSPACE', $workspace);
define('ADMIN_BASE_PATH', '/owner');

$GLOBALS['_tb_hooks'] = [];
$GLOBALS['_tb_core_locks'] = [];
function add_action(string $name, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['_tb_hooks'][] = ['action', $name, $callback, $priority, $acceptedArgs];
}
function add_filter(string $name, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['_tb_hooks'][] = ['filter', $name, $callback, $priority, $acceptedArgs];
}
function theme_operation_acquire(array $folders): array
{
    $folders = array_values(array_unique($folders));
    sort($folders, SORT_STRING);
    $GLOBALS['_tb_core_locks'][] = ['acquire', $folders];
    return $folders;
}
function theme_operation_release(array $locks): void
{
    $GLOBALS['_tb_core_locks'][] = ['release', $locks];
}
function csrf_token(): string { return str_repeat('c', 64); }
function current_user_id(): int { return 17; }
function __(string $text): string { return $text; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE themes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, folder_name TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT \'\', version TEXT NOT NULL DEFAULT \'\', author TEXT NOT NULL DEFAULT \'\',
    manifest_json TEXT, is_active INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0,
    store_url TEXT NOT NULL DEFAULT \'\', store_slug TEXT NOT NULL DEFAULT \'\'
)');
$pdo->exec('CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, slot_key TEXT, theme_id INTEGER, theme_file TEXT, custom_post_id INTEGER)');
$pdo->exec("INSERT INTO themes (folder_name, name, version, store_url, store_slug) VALUES ('store-theme', 'Store Theme', '1.0.0', 'https://store.test', 'store-theme')");
$GLOBALS['pdo'] = $pdo;
file_put_contents($themesRoot . '/store-theme/theme.json', json_encode([
    'folder' => 'store-theme', 'name' => 'Store Theme', 'version' => '1.0.0',
    'store' => ['url' => 'https://store.test', 'slug' => 'store-theme'],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$original = "<?php\necho 'original';\n";
file_put_contents($themesRoot . '/store-theme/header.php', $original);

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
    $pluginManifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
    $check(($pluginManifest['requires']['jyavani'] ?? null) === '>=2.3.87',
        'Phase 4 declares the Core 2.3.87 generic lifecycle and update contract floor');
    $hooks = $GLOBALS['_tb_hooks'];
    $check(count($hooks) === 5 && array_column($hooks, 1) === [
        'theme_manager_theme_actions', 'theme_update_preflight', 'theme_update_completed', 'theme_install_completed',
        'plugin_state_change_preflight',
    ] && ($hooks[4][4] ?? null) === 3, 'plugin bootstrap idempotently registers exactly the five generic Core callbacks');
    $callbacks = [];
    foreach ($hooks as $hook) $callbacks[$hook[1]] = $hook[2];
    $seed = ['schema' => 1, 'issues' => [], 'decisions' => []];
    $update = ['current_version' => '1.0.0', 'new_version' => '2.0.0', 'checksum' => str_repeat('a', 64)];
    $manifest = ['folder' => 'store-theme', 'version' => '1.0.0'];

    $lifecycleSeed = ['allowed' => true, 'message' => ''];
    $lifecycleUntracked = $callbacks['plugin_state_change_preflight']($lifecycleSeed, 'theme-builder', 'disable');
    $check(array_keys($lifecycleUntracked) === ['allowed', 'message'] && $lifecycleUntracked['allowed'] === false
        && str_contains($lifecycleUntracked['message'], 'untracked'), 'plugin disable is denied while any registered Store PHP source is untracked');
    $check($callbacks['plugin_state_change_preflight']($lifecycleSeed, 'another-plugin', 'disable') === $lifecycleSeed,
        'plugin lifecycle filter leaves unrelated plugins unchanged');

    $untracked = $callbacks['theme_update_preflight']($seed, 'store-theme', $update, $manifest, $pdo);
    $issue = $untracked['issues'][0] ?? [];
    $check(($issue['id'] ?? '') === 'theme-builder.php-source' && ($issue['resolved'] ?? true) === false
        && ($issue['details']['php_files'] ?? 0) === 1 && ($issue['choices'][0]['destructive'] ?? false) === true
        && str_contains((string)$issue['message'], 'physical PHP'), 'untracked PHP creates one explicit destructive replacement issue');
    $check(($issue['links'][0]['method'] ?? '') === 'POST' && ($issue['links'][1]['method'] ?? '') === 'GET'
        && str_starts_with((string)$issue['links'][0]['url'], '/owner/') && str_contains((string)$issue['links'][1]['url'], 'fork=store-theme'),
        'preflight exposes same-origin POST export and GET Fork & Edit links');
    $token = (string)$issue['state_token'];
    $wrong = $seed;
    $wrong['decisions'] = ['theme-builder.php-source' => ['choice' => 'replace', 'state_token' => str_repeat('0', 64)]];
    $check(($callbacks['theme_update_preflight']($wrong, 'store-theme', $update, $manifest, $pdo)['issues'][0]['resolved'] ?? true) === false,
        'mismatched decision token never resolves untracked PHP');
    $exact = $seed;
    $exact['decisions'] = ['theme-builder.php-source' => ['choice' => 'replace', 'state_token' => $token]];
    $check(($callbacks['theme_update_preflight']($exact, 'store-theme', $update, $manifest, $pdo)['issues'][0]['resolved'] ?? false) === true,
        'only exact replace choice and current state token resolve the issue');

    $callbacks['theme_install_completed']('store-theme', $manifest);
    $service = new ThemeForkService($pdo);
    $clean = $service->dirtyState('store-theme');
    $check(($clean['tracked'] ?? false) && !($clean['locally_modified'] ?? true)
        && $callbacks['theme_update_preflight']($seed, 'store-theme', $update, $manifest, $pdo)['issues'] === [],
        'install completion captures a verified clean PHP baseline');

    $baselinePath = '';
    foreach (glob($workspace . '/.baselines/*.json') ?: [] as $candidate) {
        $candidateBaseline = json_decode((string)file_get_contents($candidate), true);
        if (($candidateBaseline['theme']['folder'] ?? '') === 'store-theme') { $baselinePath = $candidate; break; }
    }
    $validBaselineJson = (string)file_get_contents($baselinePath);
    $validBaseline = json_decode($validBaselineJson, true, 64, JSON_THROW_ON_ERROR);
    $baselineMutations = [
        'exact top-level keys' => static function (array &$value): void { $value['unexpected'] = true; },
        '32-hex baseline identity' => static function (array &$value): void { $value['baseline_id'] = str_repeat('g', 32); },
        'exact theme keys' => static function (array &$value): void { $value['theme']['unexpected'] = true; },
        'registered identity type' => static function (array &$value): void { $value['theme']['registered_id'] = '1'; },
        'root identity bounds' => static function (array &$value): void { $value['theme']['root_identity']['dev'] = '-1'; },
        'installed metadata types and bounds' => static function (array &$value): void { $value['installed']['store_url'] = str_repeat('x', 2049); },
        'physical PHP scope' => static function (array &$value): void { $value['scope'] = 'all_files'; },
        'capture origin' => static function (array &$value): void { $value['origin'] = 'manual'; },
        'capture timestamp' => static function (array &$value): void { $value['captured_at'] = '2999-01-01T00:00:00+00:00'; },
        'capture actor' => static function (array &$value): void { $value['captured_by'] = -1; },
        'safe PHP paths' => static function (array &$value): void {
            $record = $value['files']['header.php'];
            unset($value['files']['header.php']);
            $record['file_id'] = hash('sha256', "store-theme\0../header.php");
            $value['files']['../header.php'] = $record;
        },
        'exact file keys' => static function (array &$value): void { $value['files']['header.php']['unexpected'] = true; },
        'derived file identity' => static function (array &$value): void { $value['files']['header.php']['file_id'] = str_repeat('0', 64); },
        '64-hex file hash' => static function (array &$value): void { $value['files']['header.php']['sha256'] = str_repeat('g', 64); },
        'nonnegative bounded file size' => static function (array &$value): void { $value['files']['header.php']['size'] = -1; },
        'case-colliding path semantics' => static function (array &$value): void {
            $record = $value['files']['header.php'];
            $record['file_id'] = hash('sha256', "store-theme\0HEADER.php");
            $value['files']['HEADER.php'] = $record;
        },
        'file-count bound' => static function (array &$value): void {
            $value['files'] = [];
            for ($index = 0; $index <= 1000; $index++) {
                $path = 'file-' . $index . '.php';
                $value['files'][$path] = [
                    'file_id' => hash('sha256', "store-theme\0" . $path),
                    'sha256' => str_repeat('a', 64),
                    'size' => 0,
                ];
            }
        },
        'cumulative byte bound' => static function (array &$value): void {
            $value['files'] = [];
            for ($index = 0; $index < 13; $index++) {
                $path = 'large-' . $index . '.php';
                $value['files'][$path] = [
                    'file_id' => hash('sha256', "store-theme\0" . $path),
                    'sha256' => str_repeat('a', 64),
                    'size' => 5242880,
                ];
            }
        },
    ];
    $invalidBaselineReads = 0;
    foreach ($baselineMutations as $mutation) {
        $candidate = $validBaseline;
        $mutation($candidate);
        file_put_contents($baselinePath, json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        if (isset($service->dirtyState('store-theme')['error'])) $invalidBaselineReads++;
    }
    $duplicateKeys = preg_replace('/"schema": 1/', '"schema": 1, "schema": 1', $validBaselineJson, 1);
    file_put_contents($baselinePath, (string)$duplicateKeys);
    if (isset($service->dirtyState('store-theme')['error'])) $invalidBaselineReads++;
    file_put_contents($baselinePath, $validBaselineJson);
    $check($invalidBaselineReads === count($baselineMutations) + 1,
        'every baseline read fails closed for malformed fields, bounds, duplicate keys, and colliding semantics');
    mkdir($themesRoot . '/store-second', 0770);
    file_put_contents($themesRoot . '/store-second/theme.json', json_encode([
        'folder' => 'store-second', 'name' => 'Store Second', 'version' => '1.0.0',
        'store' => ['url' => 'https://store.test', 'slug' => 'store-second'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($themesRoot . '/store-second/header.php', "<?php echo 'second';\n");
    $pdo->exec("INSERT INTO themes (folder_name, name, version, store_url, store_slug) VALUES ('store-second', 'Store Second', '1.0.0', 'https://store.test', 'store-second')");
    $check(($callbacks['plugin_state_change_preflight']($lifecycleSeed, 'theme-builder', 'disable')['allowed'] ?? true) === false,
        'plugin lifecycle checks every registered Store-managed theme');
    $service->refreshBaseline('store-second', 'core_install', 17);
    $check($callbacks['plugin_state_change_preflight']($lifecycleSeed, 'theme-builder', 'disable') === $lifecycleSeed,
        'plugin disable is allowed only when every registered Store PHP source is tracked clean');

    $changed = "<?php\necho 'changed';\n";
    file_put_contents($themesRoot . '/store-theme/header.php', $changed);
    $dirty = $callbacks['theme_update_preflight']($seed, 'store-theme', $update, $manifest, $pdo);
    $dirtyIssue = $dirty['issues'][0] ?? [];
    $check(($dirtyIssue['details']['modified_php'] ?? 0) === 1 && str_contains((string)$dirtyIssue['label'], 'PHP'),
        'tracked dirty PHP reports PHP-only modified counts');
    $check(($callbacks['plugin_state_change_preflight']($lifecycleSeed, 'theme-builder', 'delete')['allowed'] ?? true) === false,
        'plugin deletion is denied while Store PHP source is locally modified');
    $staleDecision = $seed;
    $staleDecision['decisions'] = ['theme-builder.php-source' => ['choice' => 'replace', 'state_token' => (string)$dirtyIssue['state_token']]];
    file_put_contents($themesRoot . '/store-theme/header.php', "<?php\necho 'changed again';\n");
    $check(($callbacks['theme_update_preflight']($staleDecision, 'store-theme', $update, $manifest, $pdo)['issues'][0]['resolved'] ?? true) === false,
        'PHP byte changes invalidate a previously issued decision token');

    $baselinePath = '';
    foreach (glob($workspace . '/.baselines/*.json') ?: [] as $candidate) {
        $candidateBaseline = json_decode((string)file_get_contents($candidate), true);
        if (($candidateBaseline['theme']['folder'] ?? '') === 'store-theme') { $baselinePath = $candidate; break; }
    }
    file_put_contents($baselinePath, '{broken');
    $errorState = $callbacks['theme_update_preflight']($seed, 'store-theme', $update, $manifest, $pdo);
    $errorIssue = $errorState['issues'][0] ?? [];
    $check(($errorIssue['resolved'] ?? true) === false && ($errorIssue['choices'] ?? null) === []
        && str_contains((string)$errorIssue['message'], 'could not safely verify'), 'baseline errors hard-block with no destructive choice');
    $check(($callbacks['plugin_state_change_preflight']($lifecycleSeed, 'theme-builder', 'disable')['allowed'] ?? true) === false,
        'plugin disable fails closed when Store PHP state cannot be verified');

    $pdo->exec("UPDATE themes SET version = '2.0.0' WHERE folder_name = 'store-theme'");
    $callbacks['theme_update_completed']('store-theme', '1.0.0', '2.0.0', ['folder' => 'store-theme', 'version' => '2.0.0']);
    $refreshed = json_decode((string)file_get_contents($baselinePath), true, 64, JSON_THROW_ON_ERROR);
    $check(($refreshed['origin'] ?? '') === 'core_update' && ($refreshed['captured_by'] ?? null) === 17
        && ($refreshed['installed']['version'] ?? '') === '2.0.0'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($refreshed['baseline_id'] ?? '')) === 1,
        'update completion atomically replaces a malformed baseline with current identity and a new ID');

    $inspector = new InstalledThemeInspector($pdo);
    $file = $inspector->inspect('store-theme')['files'][0];
    $source = $inspector->source('store-theme', (string)$file['id']);
    $saved = $service->saveDirectPhp('store-theme', (string)$file['id'], (string)$source['target_token'],
        "<?php\necho 'revision before refresh';\n", (string)$source['sha256'], 17, '', ['direct' => true, 'store' => true]);
    $revisionPath = $workspace . '/.revisions/1/' . $file['id'] . '/' . ($saved['revision_id'] ?? '') . '/source.php';
    $beforeRefreshId = (string)$refreshed['baseline_id'];
    $pdo->exec("UPDATE themes SET version = '2.1.0' WHERE folder_name = 'store-theme'");
    $callbacks['theme_update_completed']('store-theme', '2.0.0', '2.1.0', ['folder' => 'store-theme', 'version' => '2.1.0']);
    $afterRefresh = json_decode((string)file_get_contents($baselinePath), true, 64, JSON_THROW_ON_ERROR);
    $check(($saved['success'] ?? false) && is_file($revisionPath)
        && !hash_equals($beforeRefreshId, (string)$afterRefresh['baseline_id'])
        && ($service->dirtyState('store-theme')['locally_modified'] ?? true) === false,
        'baseline refresh creates a new current-version ID while preserving every revision');
    $coreLockEvents = $GLOBALS['_tb_core_locks'];
    $check(($coreLockEvents[0] ?? null) === ['acquire', ['0-theme-lifecycle', 'store-theme']],
        'Theme Builder source mutations pair the Core lifecycle lock first with the exact affected folder');
    $lifecycleClean = $callbacks['plugin_state_change_preflight']($lifecycleSeed, 'theme-builder', 'delete');
    $check($lifecycleClean === $lifecycleSeed && is_file($revisionPath) && is_file($baselinePath),
        'clean plugin deletion preflight allows Core while retaining protected baselines and revisions');

    $invalidOriginRejected = false;
    try { $service->refreshBaseline('store-theme', 'manual', 17); } catch (InvalidArgumentException) { $invalidOriginRejected = true; }
    $check($invalidOriginRejected, 'baseline refresh accepts only core_install and core_update origins');

    ob_start();
    $callbacks['theme_manager_theme_actions'](
        ['folder_name' => 'store-theme'],
        $manifest,
        ['folder' => 'store-theme', 'admin_base_path' => '/owner']
    );
    $actions = (string)ob_get_clean();
    $check(str_contains($actions, 'Inspect PHP') && str_contains($actions, 'Edit PHP') && str_contains($actions, 'Fork &amp; Edit')
        && str_contains($actions, 'csrf_token') && str_contains($actions, 'Export PHP Source') && str_contains($actions, 'PHP Source Clean'),
        'Theme Manager callback emits escaped inspect/edit/fork, CSRF export, and PHP status controls');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Core integration contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "RESULT: ALL PASS\n";
