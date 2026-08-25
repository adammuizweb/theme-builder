<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-export-' . bin2hex(random_bytes(8));
$themesRoot = $root . '/themes';
$workspace = $root . '/workspace';
mkdir($themesRoot . '/exported', 0770, true);
define('VIEWS_BASE', $themesRoot);
define('DEFAULT_THEME_FOLDER', 'default');
define('THEME_BUILDER_WORKSPACE', $workspace);
$GLOBALS['_export_lock_events'] = [];
function theme_operation_acquire(array $folders): array
{
    $folders = array_values(array_unique($folders));
    sort($folders, SORT_STRING);
    $GLOBALS['_export_lock_events'][] = ['acquire', $folders];
    return $folders;
}
function theme_operation_release(array $locks): void
{
    $GLOBALS['_export_lock_events'][] = ['release', $locks];
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
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    @rmdir($path);
};
register_shutdown_function(static function () use ($root, $remove): void { $remove($root); });

try {
    $manifest = '{"folder":"exported","name":"Exported","version":"1.0.0","store":{"url":"https://store.test","slug":"exported"}}';
    $headerOriginal = "<?php\necho 'header original';\n";
    $footer = "<?php\necho 'footer exact';\n";
    file_put_contents($themesRoot . '/exported/theme.json', $manifest);
    file_put_contents($themesRoot . '/exported/header.php', $headerOriginal);
    file_put_contents($themesRoot . '/exported/footer.php', $footer);
    file_put_contents($themesRoot . '/exported/style.css', "body{color:red}\n");
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE themes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, folder_name TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT \'\', version TEXT NOT NULL DEFAULT \'\', author TEXT NOT NULL DEFAULT \'\',
        manifest_json TEXT, is_active INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0,
        store_url TEXT NOT NULL DEFAULT \'\', store_slug TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, slot_key TEXT, theme_id INTEGER, theme_file TEXT, custom_post_id INTEGER)');
    $pdo->exec("CREATE TABLE customizations (secret TEXT)");
    $pdo->exec("INSERT INTO customizations VALUES ('must-not-export')");
    $pdo->exec("INSERT INTO themes (folder_name, name, version, store_url, store_slug) VALUES ('exported', 'Exported', '1.0.0', 'https://store.test', 'exported')");
    $service = new ThemeForkService($pdo);
    $baseline = $service->refreshBaseline('exported', 'core_install', 9);

    $inspector = new InstalledThemeInspector($pdo);
    $files = [];
    foreach ($inspector->inspect('exported')['files'] as $file) $files[$file['path']] = $file;
    $source = $inspector->source('exported', (string)$files['header.php']['id']);
    $headerChanged = "<?php\necho 'header changed';\n";
    $save = $service->saveDirectPhp('exported', (string)$files['header.php']['id'], (string)$source['target_token'],
        $headerChanged, (string)$source['sha256'], 9, 'Export fixture', ['direct' => true, 'store' => true]);
    $check(($save['success'] ?? false) === true, 'fixture creates a verified current-root PHP revision');
    unlink($themesRoot . '/exported/header.php');

    $revisionDir = $workspace . '/.revisions/1/' . $files['header.php']['id'] . '/' . $save['revision_id'];
    $oldRootRevisionId = '20000101T000000Z-' . str_repeat('b', 16);
    $oldRootDir = dirname($revisionDir) . '/' . $oldRootRevisionId;
    mkdir($oldRootDir, 0770);
    copy($revisionDir . '/source.php', $oldRootDir . '/source.php');
    chmod($oldRootDir . '/source.php', 0660);
    $oldRootMetadata = json_decode((string)file_get_contents($revisionDir . '/revision.json'), true, 32, JSON_THROW_ON_ERROR);
    $oldRootMetadata['revision_id'] = $oldRootRevisionId;
    $oldRootMetadata['root_identity']['ino'] = (string)((int)$oldRootMetadata['root_identity']['ino'] + 1);
    file_put_contents($oldRootDir . '/revision.json', json_encode($oldRootMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    chmod($oldRootDir . '/revision.json', 0660);

    $result = $service->buildPhpSourceExport('exported', 9);
    $check(is_file($result['path']) && (fileperms($result['path']) & 0777) === 0600
        && hash_file('sha256', $result['path']) === $result['sha256'] && filesize($result['path']) === $result['size'],
        'source export is a private 0600 ZIP with verified size and SHA-256');
    $zip = new ZipArchive();
    $zip->open($result['path'], ZipArchive::RDONLY);
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) $names[] = $zip->getNameIndex($index);
    $revisionPrefix = 'revisions/' . $files['header.php']['id'] . '/' . $save['revision_id'] . '/';
    $check(in_array('export.json', $names, true) && in_array('baseline.json', $names, true)
        && in_array('current-php/footer.php', $names, true) && !in_array('current-php/header.php', $names, true),
        'archive contains the exact current PHP tree and omits a deleted current file');
    $check($zip->getFromName('current-php/footer.php') === $footer
        && $zip->getFromName($revisionPrefix . 'source.php') === $headerOriginal
        && is_string($zip->getFromName($revisionPrefix . 'revision.json'))
        && !in_array('revisions/' . $files['header.php']['id'] . '/' . $oldRootRevisionId . '/source.php', $names, true),
        'archive preserves deleted-file revision bytes and skips only a verified old-root revision');
    $check(!in_array('theme.json', $names, true) && !in_array('style.css', $names, true)
        && !str_contains(implode("\n", array_map(static fn(string $name): string => (string)$zip->getFromName($name), $names)), 'must-not-export')
        && !str_contains(implode("\n", $names), $themesRoot),
        'archive excludes arbitrary assets, manifests, database customizations, and absolute paths');
    $exportMetadata = json_decode((string)$zip->getFromName('export.json'), true, 64, JSON_THROW_ON_ERROR);
    $baselineMetadata = json_decode((string)$zip->getFromName('baseline.json'), true, 64, JSON_THROW_ON_ERROR);
    $check(($exportMetadata['dirty']['counts']['deleted'] ?? 0) === 1
        && ($baselineMetadata['baseline_id'] ?? '') === ($baseline['baseline_id'] ?? ''),
        'export metadata records dirty deleted PHP and the exact protected baseline');
    $zip->close();
    $cleaned = $service->cleanupPhpSourceExport($result['path']);
    $check($cleaned && !file_exists($result['path']) && !$service->cleanupPhpSourceExport($result['path']),
        'source export cleanup returns verified success and fails closed for an absent path');

    $excludedQuotaDirs = [];
    $excludedSourceBytes = 5 * 1024 * 1024;
    $excludedSourceHash = '';
    for ($excludedIndex = 0; $excludedIndex < 26; $excludedIndex++) {
        $excludedRevisionId = sprintf('20020101T%06dZ-%016x', $excludedIndex, $excludedIndex + 64);
        $excludedRevisionDir = dirname($revisionDir) . '/' . $excludedRevisionId;
        mkdir($excludedRevisionDir, 0770);
        $excludedHandle = fopen($excludedRevisionDir . '/source.php', 'xb');
        ftruncate($excludedHandle, $excludedSourceBytes);
        fclose($excludedHandle);
        chmod($excludedRevisionDir . '/source.php', 0660);
        if ($excludedSourceHash === '') $excludedSourceHash = (string)hash_file('sha256', $excludedRevisionDir . '/source.php');
        $excludedMetadata = $oldRootMetadata;
        $excludedMetadata['revision_id'] = $excludedRevisionId;
        $excludedMetadata['previous_sha256'] = $excludedSourceHash;
        file_put_contents($excludedRevisionDir . '/revision.json', json_encode($excludedMetadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        chmod($excludedRevisionDir . '/revision.json', 0660);
        $excludedQuotaDirs[] = $excludedRevisionDir;
    }
    $quotaResult = $service->buildPhpSourceExport('exported', 9);
    $quotaZip = new ZipArchive();
    $quotaZip->open($quotaResult['path'], ZipArchive::RDONLY);
    $quotaMetadata = json_decode((string)$quotaZip->getFromName('export.json'), true, 64, JSON_THROW_ON_ERROR);
    $quotaHasCurrentRevision = $quotaZip->getFromName($revisionPrefix . 'source.php') === $headerOriginal;
    $quotaZip->close();
    $check(is_file($quotaResult['path']) && ($quotaMetadata['revision_count'] ?? null) === 1
        && $quotaHasCurrentRevision && $service->cleanupPhpSourceExport($quotaResult['path']),
        '26 validated old-root revisions exceeding 128 MiB reserve no quota and do not reject the current-root export');
    foreach ($excludedQuotaDirs as $excludedRevisionDir) $remove($excludedRevisionDir);

    $exportDir = $workspace . '/.exports';
    $staleExport = $exportDir . '/theme-source-' . str_repeat('1', 32) . '.zip';
    $recentExport = $exportDir . '/theme-source-' . str_repeat('2', 32) . '.zip';
    file_put_contents($staleExport, 'abandoned');
    file_put_contents($recentExport, 'recent');
    chmod($staleExport, 0600);
    chmod($recentExport, 0600);
    touch($staleExport, time() - 7200);
    $agedResult = $service->buildPhpSourceExport('exported', 9);
    $check(!file_exists($staleExport) && is_file($recentExport),
        'export start removes only valid private 0600 ZIPs abandoned for more than one hour');
    $check($service->cleanupPhpSourceExport($agedResult['path']) && $service->cleanupPhpSourceExport($recentExport),
        'generated and recent private exports remain explicitly cleanable');

    $largeRevisionDirs = [];
    $largeChunk = str_repeat('x', 65536);
    $largeBytes = 4 * 1024 * 1024;
    for ($largeIndex = 0; $largeIndex < 10; $largeIndex++) {
        $largeRevisionId = sprintf('20010101T%06dZ-%016x', $largeIndex, $largeIndex + 16);
        $largeRevisionDir = dirname($revisionDir) . '/' . $largeRevisionId;
        mkdir($largeRevisionDir, 0770);
        $largeHandle = fopen($largeRevisionDir . '/source.php', 'xb');
        for ($written = 0; $written < $largeBytes; $written += strlen($largeChunk)) fwrite($largeHandle, $largeChunk);
        fclose($largeHandle);
        chmod($largeRevisionDir . '/source.php', 0660);
        $largeMetadata = json_decode((string)file_get_contents($revisionDir . '/revision.json'), true, 32, JSON_THROW_ON_ERROR);
        $largeMetadata['revision_id'] = $largeRevisionId;
        $largeMetadata['previous_sha256'] = hash_file('sha256', $largeRevisionDir . '/source.php');
        file_put_contents($largeRevisionDir . '/revision.json', json_encode($largeMetadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        chmod($largeRevisionDir . '/revision.json', 0660);
        $largeRevisionDirs[] = $largeRevisionDir;
    }
    if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
    $boundedExport = $service->buildPhpSourceExport('exported', 9);
    $boundedPeak = memory_get_peak_usage(true);
    $check($boundedPeak < 24 * 1024 * 1024 && $service->cleanupPhpSourceExport($boundedExport['path']),
        'exporting 40 MiB of revisions keeps peak PHP memory below 24 MiB');
    foreach ($largeRevisionDirs as $largeRevisionDir) $remove($largeRevisionDir);

    file_put_contents($revisionDir . '/source.php', $headerOriginal . "<?php echo 'tampered';\n");
    $tamperedRejected = false;
    try { $service->buildPhpSourceExport('exported', 9); } catch (Throwable) { $tamperedRejected = true; }
    $check($tamperedRejected, 'same-root revision source hash tampering rejects the whole export instead of being omitted');

    $coreFolder = '-' . str_repeat('A', 127);
    mkdir($themesRoot . '/' . $coreFolder, 0770);
    file_put_contents($themesRoot . '/' . $coreFolder . '/theme.json', json_encode([
        'folder' => $coreFolder, 'name' => 'Core Folder', 'version' => '1.0.0',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($themesRoot . '/' . $coreFolder . '/header.php', "<?php echo 'core folder';\n");
    $pdo->prepare('INSERT INTO themes (folder_name, name, version) VALUES (?, ?, ?)')->execute([$coreFolder, 'Core Folder', '1.0.0']);
    $coreDirty = $service->dirtyState($coreFolder);
    $coreExport = $service->buildPhpSourceExport($coreFolder, 9);
    $check(($service->directEditState($coreFolder)['editable'] ?? false) && !isset($coreDirty['error'])
        && is_file($coreExport['path']) && $service->cleanupPhpSourceExport($coreExport['path']),
        'direct, dirty, and export APIs accept the exact 128-byte Core installed-folder grammar');
    $unsafeExport = $exportDir . '/theme-source-' . str_repeat('3', 32) . '.zip';
    file_put_contents($unsafeExport, 'unsafe mode');
    chmod($unsafeExport, 0644);
    $unsafeCleanupRejected = false;
    try { $service->buildPhpSourceExport($coreFolder, 9); } catch (Throwable) { $unsafeCleanupRejected = true; }
    $check($unsafeCleanupRejected && is_file($unsafeExport), 'export start rejects unsafe abandoned entries without deleting them');
    unlink($unsafeExport);
    foreach (['.', '..', str_repeat('a', 129)] as $invalidFolder) {
        $invalidRejected = false;
        try { $service->buildPhpSourceExport($invalidFolder, 9); } catch (InvalidArgumentException) { $invalidRejected = true; }
        $check($invalidRejected, 'source export rejects non-Core folder identity: ' . strlen($invalidFolder));
    }
    $events = $GLOBALS['_export_lock_events'];
    $pairedLocks = count($events) % 2 === 0;
    for ($eventIndex = 0; $pairedLocks && $eventIndex < count($events); $eventIndex += 2) {
        $pairedLocks = ($events[$eventIndex][0] ?? '') === 'acquire' && ($events[$eventIndex + 1][0] ?? '') === 'release'
            && ($events[$eventIndex][1] ?? null) === ($events[$eventIndex + 1][1] ?? null);
    }
    $check(($events[0] ?? null) === ['acquire', ['0-theme-lifecycle', 'exported']] && $pairedLocks,
        'write and export operations acquire sorted Core locks and release them last');

    $serviceSource = (string)file_get_contents(dirname(__DIR__) . '/includes/class-theme-fork-service.php');
    $currentLoader = substr($serviceSource, (int)strpos($serviceSource, 'private function currentExportFiles('),
        (int)strpos($serviceSource, 'private function exportRevisionFiles(') - (int)strpos($serviceSource, 'private function currentExportFiles('));
    $revisionLoader = substr($serviceSource, (int)strpos($serviceSource, 'private function exportRevisionFiles('),
        (int)strpos($serviceSource, 'private function reserveExportEntry(') - (int)strpos($serviceSource, 'private function exportRevisionFiles('));
    $archiveVerifier = substr($serviceSource, (int)strpos($serviceSource, 'private function verifyExportArchive('),
        (int)strpos($serviceSource, 'private function cleanupAbandonedExports(') - (int)strpos($serviceSource, 'private function verifyExportArchive('));
    $check(strpos($currentLoader, 'reserveExportEntry(') < strpos($currentLoader, 'captureExportSourceFile(')
        && strpos($revisionLoader, 'validateRevisionRecord(') < strpos($revisionLoader, 'reserveExportEntry(')
        && strpos($revisionLoader, "if (!\$verified['current_root']) continue;") < strpos($revisionLoader, 'reserveExportEntry(')
        && str_contains($revisionLoader, 'rootIdentity, false)') && str_contains($serviceSource, '->addFile(')
        && str_contains($archiveVerifier, '->getStream(') && !str_contains($archiveVerifier, 'getFromIndex('),
        'export validates bounded revision reads before reserving current-root quota and streams verified file paths');

    $route = (string)file_get_contents(dirname(__DIR__) . '/admin/api/export_theme_source.php');
    $check(str_contains($route, 'adiwira_require_site_owner($pdo, true)') && str_contains($route, "!== 'POST'")
        && str_contains($route, 'adiwira_csrf_validate($csrf)') && !str_contains($route, "GET['theme']")
        && str_contains($route, 'Cache-Control: no-store') && str_contains($route, 'X-Content-Type-Options: nosniff')
        && str_contains($route, "isset(\$_POST['path']) || isset(\$_POST['file']) || isset(\$_POST['file_id'])")
        && str_contains($route, 'cleanupPhpSourceExport'), 'export route is Site Owner POST+CSRF, rejects any forbidden selector, and always cleans up');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Source export contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "RESULT: ALL PASS\n";
