<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-direct-' . bin2hex(random_bytes(8));
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

final class DirectContractPdo extends PDO
{
    public bool $failNextCommit = false;

    public function commit(): bool
    {
        if ($this->failNextCommit) {
            $this->failNextCommit = false;
            throw new RuntimeException('Injected direct commit failure.');
        }
        return parent::commit();
    }
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
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $remove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$writeTheme = static function (string $folder, string $source) use ($themesRoot): void {
    mkdir($themesRoot . '/' . $folder, 0770, true);
    file_put_contents($themesRoot . '/' . $folder . '/theme.json', json_encode([
        'folder' => $folder,
        'name' => ucfirst($folder),
        'version' => '1.2.3',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    file_put_contents($themesRoot . '/' . $folder . '/header.php', $source);
};
register_shutdown_function(static function () use ($root, $remove): void { $remove($root); });

try {
    $original = "<?php\necho 'original';\n";
    $changed = "<?php\necho 'changed';\n";
    $writeTheme('active-store', $original);
    $writeTheme('system', $original);
    $writeTheme('default', $original);
    $writeTheme('collision', $original);
    file_put_contents($themesRoot . '/collision/Header.php', "<?php\necho 'upper original';\n");
    file_put_contents($themesRoot . '/active-store/theme.json', json_encode([
        'name' => 'Active Store',
        'version' => '1.2.3',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

    $pdo = new DirectContractPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
    $insert = $pdo->prepare('INSERT INTO themes (folder_name, name, version, is_active, is_system, store_url, store_slug) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute(['active-store', 'Active Store', '1.2.3', 1, 0, 'https://store.example.test', 'active-store']);
    $themeId = (int)$pdo->lastInsertId();
    $insert->execute(['system', 'System', '1.2.3', 0, 1, '', '']);
    $insert->execute(['default', 'Default', '1.2.3', 0, 0, '', '']);
    $insert->execute(['collision', 'Collision', '1.2.3', 0, 0, '', '']);
    $collisionThemeId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES (?, ?, ?)')->execute(['header', $themeId, 'header.php']);

    $service = new ThemeForkService($pdo);
    $inspector = new InstalledThemeInspector($pdo);
    $inspection = $inspector->inspect('active-store');
    $fileId = (string)$inspection['files'][0]['id'];
    $source = $inspector->source('active-store', $fileId);
    $check(is_array($source) && preg_match('/\A[a-f0-9]{64}\z/D', (string)$source['target_token']) === 1,
        'source inspection returns a physical target token');
    $state = $service->directEditState('active-store');
    $check(($state['editable'] ?? false) && ($state['active'] ?? false) && ($state['store'] ?? false),
        'a folderless manifest uses its physical folder and active assigned Store theme remains direct-editable');
    $wrongCaseRejected = false;
    try { $inspector->inspect('ACTIVE-STORE'); } catch (RuntimeException) { $wrongCaseRejected = true; }
    $check($wrongCaseRejected && !($service->directEditState('ACTIVE-STORE')['editable'] ?? false),
        'case-insensitive database matches cannot substitute for exact installed folder identity');
    $check(($service->dirtyState('active-store')['tracked'] ?? true) === false, 'unmodified source is untracked rather than falsely clean');

    $missingAck = $service->saveDirectPhp('active-store', $fileId, (string)$source['target_token'], $changed,
        (string)$source['sha256'], 7, '', ['direct' => true]);
    $check(($missingAck['success'] ?? true) === false && file_get_contents($themesRoot . '/active-store/header.php') === $original,
        'active and Store risk acknowledgements are enforced before mutation');

    $saved = $service->saveDirectPhp('active-store', $fileId, (string)$source['target_token'], $changed,
        (string)$source['sha256'], 7, 'Direct contract edit', ['direct' => true, 'active' => true, 'store' => true]);
    $check(($saved['success'] ?? false) === true && file_get_contents($themesRoot . '/active-store/header.php') === $changed,
        'assigned active Store PHP is linted and atomically saved after explicit acknowledgement');
    $check(($saved['dirty']['tracked'] ?? false) && ($saved['dirty']['locally_modified'] ?? false)
        && ($saved['dirty']['changed_count'] ?? 0) === 1, 'first direct save captures baseline and immediately reports dirty state');
    $check(isset($saved['target_token']) && !hash_equals((string)$source['target_token'], (string)$saved['target_token']),
        'atomic replacement returns a new physical target token');

    $baselineFiles = glob($workspace . '/.baselines/*.json') ?: [];
    $baseline = $baselineFiles ? json_decode((string)file_get_contents($baselineFiles[0]), true, 64, JSON_THROW_ON_ERROR) : [];
    $check(count($baselineFiles) === 1 && ($baseline['files']['header.php']['sha256'] ?? null) === hash('sha256', $original),
        'immutable protected baseline contains the exact pre-first-edit hash');
    $revisionPath = $workspace . '/.revisions/' . $themeId . '/' . $fileId . '/' . $saved['revision_id'];
    $check(file_get_contents($revisionPath . '/source.php') === $original, 'direct save revision contains exact displaced bytes');

    $collisionFiles = [];
    foreach ($inspector->inspect('collision')['files'] as $collisionFile) $collisionFiles[$collisionFile['path']] = $collisionFile;
    $collisionSource = $inspector->source('collision', (string)$collisionFiles['header.php']['id']);
    $collisionSave = $service->saveDirectPhp('collision', (string)$collisionFiles['header.php']['id'],
        (string)$collisionSource['target_token'], $changed, (string)$collisionSource['sha256'], 7, '', ['direct' => true]);
    $check(($collisionSave['success'] ?? true) === false
        && file_get_contents($themesRoot . '/collision/header.php') === $original
        && file_get_contents($themesRoot . '/collision/Header.php') === "<?php\necho 'upper original';\n"
        && count(glob($workspace . '/.baselines/*.json') ?: []) === 1
        && !file_exists($workspace . '/.revisions/' . $collisionThemeId),
        'case-colliding generated baseline fails before first direct edit publishes baseline, revision, or source bytes');

    $staleTarget = $service->saveDirectPhp('active-store', $fileId, (string)$source['target_token'], "<?php echo 'stale';\n",
        (string)$saved['sha256'], 7, '', ['direct' => true, 'active' => true, 'store' => true]);
    $check(($staleTarget['success'] ?? true) === false && file_get_contents($themesRoot . '/active-store/header.php') === $changed,
        'stale physical target token cannot authorize a replacement even with current bytes');

    $revisions = $service->revisions('active-store', $fileId);
    $check(count($revisions) === 1 && !array_key_exists('source', $revisions[0]), 'revision listing returns verified metadata without source bytes');
    $restored = $service->restoreDirectPhp('active-store', $fileId, (string)$saved['target_token'], (string)$saved['revision_id'],
        (string)$saved['sha256'], 7, 'Restore contract', ['direct' => true, 'active' => true, 'store' => true]);
    $check(($restored['success'] ?? false) === true && file_get_contents($themesRoot . '/active-store/header.php') === $original,
        'revision restore uses the guarded replacement pipeline and reproduces exact bytes');
    $check(($restored['dirty']['locally_modified'] ?? true) === false && ($restored['dirty']['changed_count'] ?? -1) === 0,
        'restoring baseline bytes returns tracked source to clean state');
    $undoPath = $workspace . '/.revisions/' . $themeId . '/' . $fileId . '/' . $restored['revision_id'] . '/source.php';
    $check(file_get_contents($undoPath) === $changed, 'restore creates an undo revision containing displaced current bytes');

    $pdo->prepare('UPDATE themes SET is_active = 0 WHERE folder_name = ?')->execute(['active-store']);
    $assignedSource = $inspector->source('active-store', $fileId);
    $missingAssignedAck = $service->saveDirectPhp('active-store', $fileId, (string)$assignedSource['target_token'], $changed,
        (string)$assignedSource['sha256'], 7, '', ['direct' => true, 'store' => true]);
    $check(($missingAssignedAck['success'] ?? true) === false && file_get_contents($themesRoot . '/active-store/header.php') === $original,
        'inactive but assigned source still requires the live-impact acknowledgement');

    $pdo->prepare('DELETE FROM assignments WHERE theme_id = ?')->execute([$themeId]);
    $lockState = new ReflectionMethod(ThemeForkService::class, 'lockThemeDatabaseState');
    $assertDirect = new ReflectionMethod(ThemeForkService::class, 'assertLockedDirectEditable');
    $newAssignmentRejected = false;
    try {
        $lockState->invoke($service, 'active-store');
        $pdo->prepare('INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES (?, ?, ?)')
            ->execute(['header', $themeId, 'header.php']);
        $assertDirect->invoke($service, 'active-store', $themeId, ['direct' => true, 'store' => true]);
    } catch (RuntimeException $error) {
        $newAssignmentRejected = str_contains($error->getMessage(), 'confirmation');
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    $pdo->prepare('INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES (?, ?, ?)')
        ->execute(['header', $themeId, 'header.php']);
    $check($newAssignmentRejected, 'a newly live assignment is rechecked and requires the original active acknowledgement');

    $pdo->failNextCommit = true;
    $commitSave = $service->saveDirectPhp('active-store', $fileId, (string)$assignedSource['target_token'], $changed,
        (string)$assignedSource['sha256'], 7, '', ['direct' => true, 'active' => true, 'store' => true]);
    $check(($commitSave['success'] ?? false) && isset($commitSave['warning'], $commitSave['target_token'])
        && file_get_contents($themesRoot . '/active-store/header.php') === $changed,
        'ambiguous direct commit reports verified success with the replacement target token');
    $followup = $service->saveDirectPhp('active-store', $fileId, (string)$commitSave['target_token'], $original,
        (string)$commitSave['sha256'], 7, '', ['direct' => true, 'active' => true, 'store' => true]);
    $check(($followup['success'] ?? false) && file_get_contents($themesRoot . '/active-store/header.php') === $original,
        'target token returned after ambiguous commit authorizes the next current-state save');

    file_put_contents($themesRoot . '/active-store/header.php', $changed);
    $pdo->prepare('UPDATE themes SET version = ? WHERE folder_name = ?')->execute(['2.0.0', 'active-store']);
    $upstream = $service->dirtyState('active-store');
    $check(($upstream['upstream_changed'] ?? false) === true && ($upstream['baseline_version'] ?? null) === '1.2.3'
        && ($upstream['current_version'] ?? null) === '2.0.0', 'version-changing upstream source is distinguished from ordinary local modification');

    $tamperedMetadataPath = $revisionPath . '/revision.json';
    $tamperedMetadata = json_decode((string)file_get_contents($tamperedMetadataPath), true, 32, JSON_THROW_ON_ERROR);
    $tamperedMetadata['result_sha256'] = 'invalid';
    file_put_contents($tamperedMetadataPath, json_encode($tamperedMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    $currentAfterTamper = $inspector->source('active-store', $fileId);
    $tamperedRestore = $service->restoreDirectPhp('active-store', $fileId, (string)$currentAfterTamper['target_token'],
        (string)$saved['revision_id'], (string)$currentAfterTamper['sha256'], 7, '', ['direct' => true, 'active' => true, 'store' => true]);
    $check($service->revisions('active-store', $fileId) === [] && ($tamperedRestore['success'] ?? true) === false,
        'revision list and restore fail closed for malformed same-root metadata');

    foreach (['system', 'default'] as $readOnlyFolder) {
        $readInspection = $inspector->inspect($readOnlyFolder);
        $readId = (string)$readInspection['files'][0]['id'];
        $readSource = $inspector->source($readOnlyFolder, $readId);
        $readResult = $service->saveDirectPhp($readOnlyFolder, $readId, (string)$readSource['target_token'], $changed,
            (string)$readSource['sha256'], 7, '', ['direct' => true]);
        $check(($readResult['success'] ?? true) === false && file_get_contents($themesRoot . '/' . $readOnlyFolder . '/header.php') === $original,
            $readOnlyFolder . ' theme remains API-enforced read-only');
    }
    $events = $GLOBALS['_theme_core_lock_events'] ?? [];
    $check(($events[0][0] ?? '') === 'acquire' && ($events[0][1] ?? []) === ['0-theme-lifecycle', 'active-store']
        && ($events[count($events) - 1][0] ?? '') === 'release', 'direct and restore writes use and finally release the generic Core lock');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Direct edit contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo "RESULT: ALL PASS\n";
