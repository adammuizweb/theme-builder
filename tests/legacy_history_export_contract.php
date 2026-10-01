<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-legacy-export-' . bin2hex(random_bytes(8));
$workspace = $root . '/workspace';
mkdir($workspace . '/.baselines', 0770, true);
mkdir($workspace . '/.revisions/7/' . hash('sha256', 'file') . '/20260101T000000Z-' . str_repeat('a', 16), 0770, true);
chmod($workspace, 0770);
define('THEME_BUILDER_WORKSPACE', $workspace);

require_once dirname(__DIR__) . '/includes/class-theme-workspace.php';
require_once dirname(__DIR__) . '/includes/class-theme-builder-legacy-history.php';

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
    $baselinePath = $workspace . '/.baselines/theme.json';
    $revisionDir = $workspace . '/.revisions/7/' . hash('sha256', 'file') . '/20260101T000000Z-' . str_repeat('a', 16);
    $revisionMetaPath = $revisionDir . '/revision.json';
    $revisionSourcePath = $revisionDir . '/source.php';
    file_put_contents($baselinePath, "{\"schema\":1,\"legacy\":true}\n");
    file_put_contents($revisionMetaPath, "{\"schema\":1,\"legacy\":true}\n");
    file_put_contents($revisionSourcePath, "<?php echo 'legacy';\n");
    chmod($baselinePath, 0660);
    chmod($revisionMetaPath, 0660);
    chmod($revisionSourcePath, 0660);
    $originalHashes = [
        $baselinePath => hash_file('sha256', $baselinePath),
        $revisionMetaPath => hash_file('sha256', $revisionMetaPath),
        $revisionSourcePath => hash_file('sha256', $revisionSourcePath),
    ];

    $service = new ThemeBuilderLegacyHistory();
    $descriptor = $service->buildExport(17);
    $stat = lstat($descriptor['path']);
    $check(is_file($descriptor['path']) && (fileperms($descriptor['path']) & 0777) === 0600
        && hash_file('sha256', $descriptor['path']) === $descriptor['sha256']
        && filesize($descriptor['path']) === $descriptor['size']
        && $stat['dev'] === $descriptor['dev'] && $stat['ino'] === $descriptor['ino'] && $stat['uid'] === $descriptor['uid'],
        'legacy history export is a private verified ZIP descriptor');

    $zip = new ZipArchive();
    $zip->open($descriptor['path'], ZipArchive::RDONLY);
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) $names[] = $zip->getNameIndex($index);
    $revisionPrefix = 'revisions/7/' . hash('sha256', 'file') . '/20260101T000000Z-' . str_repeat('a', 16) . '/';
    $check(in_array('baselines/theme.json', $names, true)
        && in_array($revisionPrefix . 'revision.json', $names, true)
        && in_array($revisionPrefix . 'source.php', $names, true)
        && in_array('legacy-history.json', $names, true),
        'export contains opaque legacy baselines, revisions, source bytes, and bounded metadata');
    $metadata = json_decode((string)$zip->getFromName('legacy-history.json'), true, 32, JSON_THROW_ON_ERROR);
    $check(($metadata['scope'] ?? null) === 'theme_builder_legacy_history' && ($metadata['exported_by'] ?? null) === 17
        && count($metadata['files'] ?? []) === 3,
        'export metadata identifies legacy-only scope and Site Owner actor');
    $zip->close();

    $handle = $service->openExport($descriptor);
    $downloaded = stream_get_contents($handle);
    fclose($handle);
    $check(is_string($downloaded) && hash('sha256', $downloaded) === $descriptor['sha256'],
        'download opens only descriptor-verified private archive bytes');

    $replacementDescriptor = $service->buildExport(17);
    $replacementBytes = file_get_contents($replacementDescriptor['path']);
    unlink($replacementDescriptor['path']);
    file_put_contents($replacementDescriptor['path'], $replacementBytes);
    chmod($replacementDescriptor['path'], 0600);
    $replacementRejected = false;
    try { $service->openExport($replacementDescriptor); } catch (RuntimeException) { $replacementRejected = true; }
    $check($replacementRejected && !$service->cleanupExport($replacementDescriptor),
        'legacy export descriptors reject same-path inode replacement');
    unlink($replacementDescriptor['path']);

    $check($service->cleanupExport($descriptor) && !file_exists($descriptor['path']),
        'temporary legacy export is removed after consumption');
    foreach ($originalHashes as $path => $hash) {
        $check(hash_file('sha256', $path) === $hash, 'legacy source remains untouched: ' . basename($path));
    }

    $unsafeLink = $workspace . '/.revisions/unsafe-link';
    $symlinkSupported = @symlink('/etc/passwd', $unsafeLink);
    if ($symlinkSupported) {
        $rejected = false;
        try { $service->buildExport(17); } catch (RuntimeException) { $rejected = true; }
        $check($rejected && glob($workspace . '/.legacy-exports/*.zip') === [],
            'legacy export rejects symlinks and cleans failed temporary output');
        unlink($unsafeLink);
    } else {
        echo "SKIP symlink behavior is unavailable\n";
    }

    $specialPath = $workspace . '/.revisions/unsafe-fifo';
    $fifoSupported = function_exists('posix_mkfifo') && @posix_mkfifo($specialPath, 0600);
    if ($fifoSupported) {
        $rejected = false;
        try { $service->buildExport(17); } catch (RuntimeException) { $rejected = true; }
        $check($rejected && glob($workspace . '/.legacy-exports/*.zip') === [],
            'legacy export rejects special filesystem entries');
        unlink($specialPath);
    } else {
        echo "SKIP FIFO behavior is unavailable\n";
    }

    $oversized = $workspace . '/.baselines/oversized.json';
    file_put_contents($oversized, str_repeat('x', 5242881));
    chmod($oversized, 0660);
    $bounded = false;
    try { $service->buildExport(17); } catch (RuntimeException) { $bounded = true; }
    $check($bounded && glob($workspace . '/.legacy-exports/*.zip') === [],
        'legacy export enforces per-file bounds and cleans failed output');
    unlink($oversized);

    $route = (string)file_get_contents(dirname(__DIR__) . '/admin/api/export_legacy_history.php');
    $permissionAt = strpos($route, "adiwira_require_permission(\$pdo, 'core.themes.manage', true)");
    $ownerAt = strpos($route, 'adiwira_require_site_owner($pdo, true)');
    $check($permissionAt !== false && $ownerAt !== false && $permissionAt < $ownerAt
        && str_contains($route, "REQUEST_METHOD'] ?? '') !== 'POST'")
        && str_contains($route, 'adiwira_csrf_validate($csrf)'),
        'legacy export handler requires theme permission then its redundant Site Owner check, POST, and CSRF');
    $check(str_contains($route, 'Cache-Control: no-store') && str_contains($route, 'X-Content-Type-Options: nosniff')
        && str_contains($route, 'fpassthru($handle) === false') && str_contains($route, '!$service->cleanupExport($descriptor)')
        && str_contains($route, 'Failed export cleanup requires operator attention.'),
        'legacy export route streams protected bytes and cleans temporary output');
    $logAt = strrpos($route, "error_log('[theme-builder-legacy-export] '");
    $jsonAt = strrpos($route, "if (!headers_sent()) adiwira_json");
    $check($logAt !== false && $jsonAt !== false && $logAt < $jsonAt,
        'legacy export failures are logged before the JSON responder exits');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Legacy history export contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "RESULT: ALL PASS\n";
