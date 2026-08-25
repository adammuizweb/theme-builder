<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-fork-' . bin2hex(random_bytes(8));
$themesRoot = $root . '/themes';
$workspace = $root . '/workspace';
mkdir($themesRoot, 0770, true);
define('VIEWS_BASE', $themesRoot);
define('DEFAULT_THEME_FOLDER', 'default');
define('THEME_BUILDER_WORKSPACE', $workspace);

function theme_operation_acquire(array $folders): array
{
    $folders = array_values(array_unique($folders));
    sort($folders, SORT_STRING);
    foreach ($folders as $folder) {
        if (!is_string($folder) || strlen($folder) > 128
            || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) !== 1 || in_array($folder, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid standalone Core lock folder.');
        }
    }
    $GLOBALS['_theme_core_lock_events'][] = ['acquire', $folders];
    return $folders;
}

function theme_operation_release(array $locks): void
{
    $GLOBALS['_theme_core_lock_events'][] = ['release', $locks];
}

function package_publication_recovery_paths(string $target): array
{
    return $GLOBALS['_theme_publication_recovery_paths'][$target] ?? [];
}

final class ForkContractPdo extends PDO
{
    public bool $failNextCommit = false;

    public function commit(): bool
    {
        if ($this->failNextCommit) {
            $this->failNextCommit = false;
            throw new RuntimeException('Injected commit failure.');
        }
        return parent::commit();
    }
}

function register_theme_in_db($pdoOrNull, string $folderName, array $manifest = [], bool $is_active = false): bool
{
    if ($folderName === 'registration-failure') throw new RuntimeException('Injected registration failure.');
    if (!$pdoOrNull instanceof PDO) throw new RuntimeException('PDO required.');
    $stmt = $pdoOrNull->prepare('INSERT INTO themes
        (folder_name, name, description, version, author, manifest_json, is_active, is_system, store_url, store_slug)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)');
    return $stmt->execute([
        $folderName,
        (string)($manifest['name'] ?? $folderName),
        (string)($manifest['description'] ?? ''),
        (string)($manifest['version'] ?? ''),
        (string)($manifest['author'] ?? ''),
        json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $is_active || !empty($manifest['is_active']) ? 1 : 0,
        (string)($manifest['store']['url'] ?? ''),
        (string)($manifest['store']['slug'] ?? ''),
    ]);
}

require_once dirname(__DIR__) . '/includes/class-theme-workspace.php';
require_once dirname(__DIR__) . '/includes/class-installed-theme-inspector.php';
require_once dirname(__DIR__) . '/includes/class-theme-fork-service.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $remove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$write = static function (string $relative, string $content) use ($themesRoot): void {
    $path = $themesRoot . '/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0770, true);
    file_put_contents($path, $content);
};
register_shutdown_function(static function () use ($root, $remove): void { $remove($root); });

try {
    $manifestSource = <<<'JSON'
{
  "folder": "source",
  "name": "Source Theme",
  "title": "Source Human Title",
  "description": "Complete source fixture",
  "version": "4.5.6",
  "author": "Fixture",
  "is_active": true,
  "store": {"url": "https://store.example.test", "slug": "source"},
  "customizer": {"sections": {"hero": {"fields": {}}}},
  "layout": {"header": {"positions": {}}},
  "unknown_empty_object": {},
  "unknown_empty_array": [],
  "unknown_decimal": 1.0,
  "styles": [{"src": "assets/css/style.css", "contexts": ["main.homepage"]}],
  "scripts": ["assets/js/app.js"]
}
JSON;
    $write('source/theme.json', $manifestSource . PHP_EOL);
    $write('source/header.php', "<?php\necho 'source';\n");
    $write('source/main/sections/hero.php', "<?php\necho 'hero';\n");
    $write('source/partials/shortcodes/section/hero.php', "<?php\nrequire __DIR__ . '/../../../main/sections/hero.php';\n");
    $write('source/assets/css/style.css', "body { color: #123; }\n");
    $write('source/assets/js/app.js', "console.log('source');\n");
    $write('source/assets/fonts/Binary Font.woff2', "\x00\x01fixture\xFF");
    $write('source/.well-known/fixture.txt', 'dot-directory');
    $write('source/empty.bin', '');

    $pdo = new ForkContractPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE themes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_name TEXT COLLATE NOCASE NOT NULL UNIQUE,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT \'\',
        version TEXT NOT NULL DEFAULT \'\',
        author TEXT NOT NULL DEFAULT \'\',
        manifest_json TEXT,
        is_active INTEGER NOT NULL DEFAULT 0,
        is_system INTEGER NOT NULL DEFAULT 0,
        store_url TEXT NOT NULL DEFAULT \'\',
        store_slug TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, slot_key TEXT, theme_id INTEGER, theme_file TEXT, custom_post_id INTEGER)');
    $pdo->exec('CREATE TABLE theme_zone_items (id INTEGER PRIMARY KEY AUTOINCREMENT, theme_folder TEXT, zone_slug TEXT)');
    $insert = $pdo->prepare('INSERT INTO themes (folder_name, name, version, is_active, store_url, store_slug) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute(['source', 'Source Theme', '4.5.5', 1, 'https://store.example.test', 'source']);
    $sourceId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES (?, ?, ?)')->execute(['header', $sourceId, 'header.php']);
    $pdo->prepare('INSERT INTO theme_zone_items (theme_folder, zone_slug) VALUES (?, ?)')->execute(['source', 'header']);

    $sourceHashes = [];
    foreach (['header.php', 'main/sections/hero.php', 'partials/shortcodes/section/hero.php', 'assets/css/style.css', 'assets/js/app.js', 'assets/fonts/Binary Font.woff2', '.well-known/fixture.txt', 'empty.bin'] as $relative) {
        $sourceHashes[$relative] = hash_file('sha256', $themesRoot . '/source/' . $relative);
    }

    $service = new ThemeForkService($pdo);
    $wrongCaseFork = $service->fork('SOURCE', 'wrong-case-fork', 'Wrong Case', 'Wrong Case', 7);
    $check(($wrongCaseFork['success'] ?? true) === false && !file_exists($themesRoot . '/wrong-case-fork'),
        'fork source lookup requires exact case even with case-insensitive database collation');
    $residualTarget = $themesRoot . '/residual-fork';
    $residualPath = $themesRoot . '/.package-publication-recovery-residual-fork-contract-old';
    mkdir($residualPath, 0770);
    file_put_contents($residualPath . '/preserved.marker', 'recovery');
    $GLOBALS['_theme_publication_recovery_paths'][$residualTarget] = [$residualPath];
    $residualFork = $service->fork('source', 'residual-fork', 'Residual Fork', 'Residual Fork', 7);
    unset($GLOBALS['_theme_publication_recovery_paths'][$residualTarget]);
    $check(($residualFork['success'] ?? true) === false
        && str_contains((string)($residualFork['error'] ?? ''), 'Inspect and restore or archive')
        && !file_exists($residualTarget) && is_file($residualPath . '/preserved.marker')
        && glob($themesRoot . '/.theme-builder-fork-residual-fork-*') === [],
        'fresh managed-fork publication rejects and preserves Core recovery residuals before private staging or registration');
    $remove($residualPath);
    $fork = $service->fork('source', 'source-fork', 'Source Fork Runtime', 'Source Fork Title', 7);
    $check(($fork['success'] ?? false) === true && ($fork['folder'] ?? null) === 'source-fork', 'registered source is forked to a new exact target');
    $forkRoot = $themesRoot . '/source-fork';
    $check(is_dir($forkRoot) && !is_link($forkRoot), 'fork is atomically published as a physical directory');
    foreach ($sourceHashes as $relative => $hash) {
        $check(is_file($forkRoot . '/' . $relative) && hash_file('sha256', $forkRoot . '/' . $relative) === $hash, 'fork preserves exact bytes: ' . $relative);
        $check(hash_file('sha256', $themesRoot . '/source/' . $relative) === $hash, 'fork does not mutate source: ' . $relative);
    }

    $forkManifestObject = json_decode((string)file_get_contents($forkRoot . '/theme.json'));
    $forkManifest = json_decode((string)file_get_contents($forkRoot . '/theme.json'), true, 64, JSON_THROW_ON_ERROR);
    $check(($forkManifest['folder'] ?? null) === 'source-fork' && ($forkManifest['name'] ?? null) === 'Source Fork Runtime'
        && ($forkManifest['title'] ?? null) === 'Source Fork Title', 'fork manifest receives its new physical and human identity');
    $check(!isset($forkManifest['store']) && empty($forkManifest['is_active']), 'fork manifest removes Store identity and cannot auto-activate');
    $check(isset($forkManifest['customizer']['sections']['hero']) && isset($forkManifest['layout']['header']), 'modern nested manifest contracts survive the fork');
    $check($forkManifestObject->unknown_empty_object instanceof stdClass && is_array($forkManifestObject->unknown_empty_array)
        && $forkManifestObject->unknown_decimal === 1.0, 'unknown manifest object, array, and decimal types survive');
    $check((string)file_get_contents($themesRoot . '/source/theme.json') === $manifestSource . PHP_EOL, 'source manifest remains byte-identical');

    $targetRow = $pdo->query("SELECT * FROM themes WHERE folder_name = 'source-fork'")->fetch(PDO::FETCH_ASSOC);
    $check(is_array($targetRow) && (int)$targetRow['id'] !== $sourceId && (int)$targetRow['is_active'] === 0
        && (int)$targetRow['is_system'] === 0 && $targetRow['store_url'] === '' && $targetRow['store_slug'] === '', 'Core registration creates a distinct inactive non-Store fork');
    $check((int)$pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM theme_zone_items")->fetchColumn() === 1, 'fork does not copy or alter assignments and Theme Zones');

    $metadataPath = $workspace . '/.installed-forks/' . hash('sha256', 'source-fork') . '.json';
    $metadata = json_decode((string)file_get_contents($metadataPath), true, 32, JSON_THROW_ON_ERROR);
    $check(($metadata['source']['folder'] ?? null) === 'source' && ($metadata['source']['version'] ?? null) === '4.5.6'
        && ($metadata['created_by'] ?? null) === 7, 'protected metadata records fork provenance outside the public theme');
    $privateDrafts = array_filter(ThemeWorkspace::listThemes(), static fn(array $theme): bool => str_contains((string)$theme['slug'], 'fork') || str_contains((string)$theme['slug'], 'revision'));
    $check($privateDrafts === [], 'hidden fork metadata and revision namespaces never appear as draft themes');
    $state = $service->forkState('source-fork');
    $check(($state['managed'] ?? false) === true && ($state['editable'] ?? false) === true, 'inactive Theme Builder fork is eligible for editing');
    $check(($service->forkState('source')['editable'] ?? true) === false, 'original Store theme remains read-only');

    $pdo->prepare('INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES (?, ?, ?)')->execute(['footer', (int)$targetRow['id'], 'footer.php']);
    $check(($service->forkState('source-fork')['editable'] ?? true) === false, 'inactive fork assigned to a live slot is read-only');
    $pdo->prepare('DELETE FROM assignments WHERE theme_id = ?')->execute([(int)$targetRow['id']]);
    $check(($service->forkState('source-fork')['editable'] ?? false) === true, 'unassigned inactive fork becomes editable again');

    $originalForkRoot = $themesRoot . '/.source-fork-original';
    rename($forkRoot, $originalForkRoot);
    mkdir($forkRoot, 0770);
    file_put_contents($forkRoot . '/theme.json', json_encode(['folder' => 'source-fork', 'name' => 'Replacement', 'version' => '1.0.0']));
    $check(($service->forkState('source-fork')['managed'] ?? true) === false, 'replacement physical root cannot reuse managed-fork metadata');
    $remove($forkRoot);
    rename($originalForkRoot, $forkRoot);
    $check(($service->forkState('source-fork')['editable'] ?? false) === true, 'original managed fork root remains bound to its metadata');

    $inspector = new InstalledThemeInspector($pdo);
    $inspection = $inspector->inspect('source-fork');
    $files = [];
    foreach ($inspection['files'] as $file) $files[$file['path']] = $file;
    $leafId = (string)$files['main/sections/hero.php']['id'];
    $leafBefore = $inspector->source('source-fork', $leafId);
    $changed = "<?php\necho 'edited fork';\n";
    $save = $service->savePhp('source-fork', $leafId, $changed, (string)$leafBefore['sha256'], 7, 'Contract edit');
    $check(($save['success'] ?? false) === true && hash_file('sha256', $forkRoot . '/main/sections/hero.php') === hash('sha256', $changed), 'nested managed-fork PHP is linted and atomically saved');
    $revision = $workspace . '/.revisions/' . $targetRow['id'] . '/' . $leafId . '/' . $save['revision_id'];
    $check(is_file($revision . '/source.php') && (string)file_get_contents($revision . '/source.php') === "<?php\necho 'hero';\n", 'durable private revision contains exact pre-change bytes');
    $revisionMeta = json_decode((string)file_get_contents($revision . '/revision.json'), true, 32, JSON_THROW_ON_ERROR);
    $check(($revisionMeta['relative_path'] ?? null) === 'main/sections/hero.php' && ($revisionMeta['actor_user_id'] ?? null) === 7
        && ($revisionMeta['change_note'] ?? null) === 'Contract edit', 'revision metadata records source identity and actor');
    unset($revisionMeta['root_identity'], $revisionMeta['operation'], $revisionMeta['restored_from_revision_id']);
    file_put_contents($revision . '/revision.json', json_encode($revisionMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    $legacyRevisions = $service->revisions('source-fork', $leafId);
    $check(count($legacyRevisions) === 1 && ($legacyRevisions[0]['operation'] ?? '') === 'save',
        'concrete legacy managed-fork revision without root or operation remains valid as save');

    $headerId = (string)$files['header.php']['id'];
    $headerBefore = $inspector->source('source-fork', $headerId);
    $headerChanged = "<?php\necho 'commit-safe';\n";
    $pdo->failNextCommit = true;
    $commitFailureSave = $service->savePhp('source-fork', $headerId, $headerChanged, (string)$headerBefore['sha256'], 7, 'Commit failure fixture');
    $commitRevision = $workspace . '/.revisions/' . $targetRow['id'] . '/' . $headerId . '/' . ($commitFailureSave['revision_id'] ?? 'missing');
    $check(($commitFailureSave['success'] ?? false) === true && isset($commitFailureSave['warning'])
        && (string)file_get_contents($forkRoot . '/header.php') === $headerChanged && is_file($commitRevision . '/source.php'),
        'ambiguous database commit after replacement reports verified success and preserves its revision');

    $stale = $service->savePhp('source-fork', $leafId, "<?php echo 'stale';\n", (string)$leafBefore['sha256'], 7);
    $check(($stale['success'] ?? true) === false && (string)file_get_contents($forkRoot . '/main/sections/hero.php') === $changed, 'stale fork save is rejected without changing source');
    $current = $inspector->source('source-fork', $leafId);
    $invalid = $service->savePhp('source-fork', $leafId, '<?php if (', (string)$current['sha256'], 7);
    $check(($invalid['success'] ?? true) === false && (string)file_get_contents($forkRoot . '/main/sections/hero.php') === $changed, 'invalid PHP never replaces managed fork source');

    $pdo->prepare('UPDATE themes SET is_active = 1 WHERE folder_name = ?')->execute(['source-fork']);
    $check(($service->forkState('source-fork')['editable'] ?? true) === false, 'managed fork becomes read-only while active');
    $activeSave = $service->savePhp('source-fork', $leafId, "<?php echo 'active';\n", hash('sha256', $changed), 7);
    $check(($activeSave['success'] ?? true) === false && (string)file_get_contents($forkRoot . '/main/sections/hero.php') === $changed, 'active managed fork cannot be edited in Phase 2');
    $pdo->prepare('UPDATE themes SET is_active = 0 WHERE folder_name = ?')->execute(['source-fork']);

    $duplicate = $service->fork('source', 'source-fork', 'Duplicate', 'Duplicate', 7);
    $check(($duplicate['success'] ?? true) === false, 'existing physical and registered fork target is rejected');
    $traversal = $service->fork('source', '../escape', 'Escape', 'Escape', 7);
    $check(($traversal['success'] ?? true) === false && !file_exists($root . '/escape'), 'fork target traversal is rejected exactly');

    mkdir($themesRoot . '/unsafe-source', 0770);
    file_put_contents($themesRoot . '/unsafe-source/theme.json', '{"folder":"unsafe-source","name":"Unsafe","version":"1.0.0"}');
    $symlinkSupported = @symlink('/etc/passwd', $themesRoot . '/unsafe-source/leak.php');
    if ($symlinkSupported) {
        $insert->execute(['unsafe-source', 'Unsafe', '1.0.0', 0, '', '']);
        $unsafe = $service->fork('unsafe-source', 'unsafe-fork', 'Unsafe Fork', 'Unsafe Fork', 7);
        $check(($unsafe['success'] ?? true) === false && !file_exists($themesRoot . '/unsafe-fork'), 'source tree symlink prevents fork publication');
    } else {
        echo "SKIP symlink behavior is unavailable\n";
    }

    $failedRegistration = $service->fork('source', 'registration-failure', 'Failure', 'Failure', 7);
    $failedRow = $pdo->query("SELECT COUNT(*) FROM themes WHERE folder_name = 'registration-failure'")->fetchColumn();
    $check(($failedRegistration['success'] ?? true) === false && !file_exists($themesRoot . '/registration-failure') && (int)$failedRow === 0,
        'registration failure rolls back database, metadata, and promoted executable tree');

    $write('huge-number/theme.json', '{"folder":"huge-number","name":"Huge","version":"1.0.0","extension_id":9223372036854775807}');
    $write('huge-number/header.php', "<?php echo 'huge';\n");
    $insert->execute(['huge-number', 'Huge', '1.0.0', 0, '', '']);
    $huge = $service->fork('huge-number', 'huge-number-fork', 'Huge Fork', 'Huge Fork', 7);
    $check(($huge['success'] ?? true) === false && !file_exists($themesRoot . '/huge-number-fork'), 'manifest integers too large for lossless decoding fail before publication');

    $write('precise-number/theme.json', '{"folder":"precise-number","name":"Precise","version":"1.0.0","ratio":0.1234567890123456789}');
    $write('precise-number/header.php', "<?php echo 'precise';\n");
    $insert->execute(['precise-number', 'Precise', '1.0.0', 0, '', '']);
    $precise = $service->fork('precise-number', 'precise-number-fork', 'Precise Fork', 'Precise Fork', 7);
    $check(($precise['success'] ?? true) === false && !file_exists($themesRoot . '/precise-number-fork'), 'manifest decimals too precise for lossless decoding fail before publication');
    $events = $GLOBALS['_theme_core_lock_events'] ?? [];
    $check(($events[0] ?? null) === ['acquire', ['0-theme-lifecycle', 'SOURCE', 'wrong-case-fork']]
        && in_array(['acquire', ['0-theme-lifecycle', 'residual-fork', 'source']], $events, true)
        && in_array(['acquire', ['0-theme-lifecycle', 'source', 'source-fork']], $events, true)
        && ($events[count($events) - 1][0] ?? '') === 'release', 'fork publication acquires sorted Core folders and releases the Core lock last');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Theme fork and edit contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo "RESULT: ALL PASS\n";
