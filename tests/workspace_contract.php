<?php

declare(strict_types=1);

$workspace = sys_get_temp_dir() . '/theme-builder-contract-' . bin2hex(random_bytes(8));
define('THEME_BUILDER_WORKSPACE', $workspace);
require_once dirname(__DIR__) . '/includes/class-theme-workspace.php';

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
register_shutdown_function(static function () use ($workspace, $remove): void {
    $remove($workspace);
});

try {
    $base = ThemeWorkspace::baseDir();
    file_put_contents($base . '/workspace-marker', 'keep');

    foreach (['', '!!!', 'UPPER', '../escape', 'foo!!!', '.hidden', 'two words', str_repeat('a', 51)] as $invalid) {
        $check(!ThemeWorkspace::isValidSlug($invalid), 'invalid slug is rejected: ' . json_encode($invalid));
        $threw = false;
        try {
            ThemeWorkspace::themeDir($invalid);
        } catch (InvalidArgumentException) {
            $threw = true;
        }
        $check($threw, 'invalid slug cannot resolve a theme directory: ' . json_encode($invalid));
        $check(!ThemeWorkspace::deleteTheme($invalid), 'invalid slug cannot delete a draft: ' . json_encode($invalid));
        $check(is_file($base . '/workspace-marker'), 'workspace survives invalid deletion: ' . json_encode($invalid));
    }

    $created = ThemeWorkspace::createTheme('safe-theme', 'Safe Theme', 'Contract');
    $check(($created['success'] ?? false) === true, 'valid draft theme is created');
    $themeDir = ThemeWorkspace::themeDir('safe-theme');
    $check(is_dir($themeDir) && dirname((string)realpath($themeDir)) === $base, 'draft resolves directly below workspace');
    $check(ThemeWorkspace::isComplete($themeDir), 'created draft contains every required slot');

    $headerBefore = ThemeWorkspace::readFile('safe-theme', 'header');
    $headerHash = ThemeWorkspace::fileHash('safe-theme', 'header');
    $headerStat = stat($themeDir . '/header.php');
    $check(is_string($headerBefore) && is_string($headerHash), 'slot source and hash are readable');

    $validSource = "<?php\ndeclare(strict_types=1);\necho 'safe';\n";
    $saved = ThemeWorkspace::writeFile('safe-theme', 'header', $validSource, (string)$headerHash);
    $check(($saved['success'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/', (string)($saved['sha256'] ?? '')) === 1, 'valid PHP is saved atomically');
    $check(ThemeWorkspace::readFile('safe-theme', 'header') === $validSource, 'saved PHP bytes match exactly');
    $savedStat = stat($themeDir . '/header.php');
    $check(is_array($headerStat) && is_array($savedStat)
        && $headerStat['uid'] === $savedStat['uid'] && $headerStat['gid'] === $savedStat['gid']
        && ($headerStat['mode'] & 0777) === ($savedStat['mode'] & 0777), 'atomic save preserves owner, group, and mode');

    $stale = ThemeWorkspace::writeFile('safe-theme', 'header', "<?php echo 'stale';", (string)$headerHash);
    $check(($stale['success'] ?? true) === false, 'stale source hash is rejected');
    $check(ThemeWorkspace::readFile('safe-theme', 'header') === $validSource, 'stale save does not alter source');

    $currentHash = ThemeWorkspace::fileHash('safe-theme', 'header');
    $invalidPhp = ThemeWorkspace::writeFile('safe-theme', 'header', '<?php if (', (string)$currentHash);
    $check(($invalidPhp['success'] ?? true) === false, 'invalid PHP syntax is rejected');
    $check(!str_contains((string)($invalidPhp['error'] ?? ''), $workspace), 'lint error does not disclose the workspace path');
    $check(ThemeWorkspace::readFile('safe-theme', 'header') === $validSource, 'lint failure does not alter source');
    $invalidEncoding = ThemeWorkspace::writeFile('safe-theme', 'header', "<?php echo \"\xC3\x28\";", (string)$currentHash);
    $check(($invalidEncoding['success'] ?? true) === false, 'invalid UTF-8 source is rejected');
    $check(ThemeWorkspace::readFile('safe-theme', 'header') === $validSource, 'encoding failure does not alter source');

    $assetHash = ThemeWorkspace::assetHash('safe-theme', 'assets/css/style.css');
    $assetSave = ThemeWorkspace::writeAsset('safe-theme', 'assets/css/style.css', "body { color: #123; }\n", (string)$assetHash);
    $check(($assetSave['success'] ?? false) === true, 'allowlisted asset is saved with a source hash');
    $forbiddenAsset = ThemeWorkspace::writeAsset('safe-theme', 'header.php', 'overwrite', (string)ThemeWorkspace::fileHash('safe-theme', 'header'));
    $check(($forbiddenAsset['success'] ?? true) === false, 'asset API cannot overwrite a PHP slot');
    $traversalAsset = ThemeWorkspace::readAsset('safe-theme', '../../workspace-marker');
    $check($traversalAsset === null, 'asset traversal is rejected');

    $manifestPath = $themeDir . '/theme.json';
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    $manifest['customizer'] = ['sections' => ['example' => ['fields' => ['title' => ['type' => 'text']]]]];
    $manifest['styles'][1] = ['src' => 'assets/css/blocks.css', 'exclude_contexts' => ['main.homepage']];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    $manifestSave = ThemeWorkspace::writeManifest('safe-theme', [
        'description' => 'Updated safely',
        'styles' => ['assets/css/style.css', 'assets/css/blocks.css'],
    ], (string)ThemeWorkspace::manifestHash('safe-theme'));
    $manifestAfter = ThemeWorkspace::readManifest('safe-theme');
    $check(($manifestSave['success'] ?? false) === true, 'manifest changes are saved with hash protection');
    $check(isset($manifestAfter['customizer']['sections']['example']), 'unknown nested manifest contracts survive structured save');
    $check(($manifestAfter['styles'][1]['exclude_contexts'] ?? null) === ['main.homepage'], 'structured manifest asset metadata survives source-list save');
    $check(($manifestAfter['description'] ?? null) === 'Updated safely' && ($manifestAfter['folder'] ?? null) === 'safe-theme', 'manifest identity and requested change are correct');

    $structuredSave = ThemeWorkspace::writeManifest('safe-theme', [
        'styles' => [
            ['src' => 'assets/css/style.css', 'contexts' => ['main.homepage']],
            ['src' => 'assets/css/style.css', 'contexts' => ['single.post']],
            ['src' => 'assets/css/blocks.css', 'exclude_contexts' => ['main.homepage']],
        ],
    ], (string)ThemeWorkspace::manifestHash('safe-theme'));
    $structuredAfter = ThemeWorkspace::readManifest('safe-theme');
    $check(($structuredSave['success'] ?? false) === true
        && ($structuredAfter['styles'][0]['contexts'] ?? null) === ['main.homepage']
        && ($structuredAfter['styles'][1]['contexts'] ?? null) === ['single.post'], 'structured duplicate-source manifest entries survive API submission');

    mkdir($themeDir . '/assets/fonts', 0770);
    file_put_contents($themeDir . '/assets/fonts/Open Sans ü.woff2', 'fixture-font');

    $zip = ThemeWorkspace::buildZip('safe-theme');
    $check(is_string($zip) && is_file($zip), 'validated flat package is built');
    $check(ThemeWorkspace::artifactPath('safe-theme') === $zip, 'fresh package artifact is downloadable');
    if (is_string($zip) && is_file($zip)) {
        $archive = new ZipArchive();
        $opened = $archive->open($zip) === true;
        $check($opened && $archive->locateName('theme.json') !== false, 'package stores theme.json at archive root');
        $check($opened && $archive->locateName('assets/fonts/Open Sans ü.woff2') !== false, 'package accepts safe spaces and Unicode filenames');
        $check($opened && hash('sha256', (string)$archive->getFromName('header.php')) === ThemeWorkspace::fileHash('safe-theme', 'header'), 'packaged PHP bytes match the locked source tree');
        if ($opened) $archive->close();
    }
    $openedArtifact = ThemeWorkspace::openArtifact('safe-theme');
    $artifactBytes = is_array($openedArtifact) && is_resource($openedArtifact['handle'] ?? null)
        ? stream_get_contents($openedArtifact['handle'])
        : false;
    if (is_array($openedArtifact) && is_resource($openedArtifact['handle'] ?? null)) fclose($openedArtifact['handle']);
    $check(is_string($artifactBytes) && hash('sha256', $artifactBytes) === hash_file('sha256', (string)$zip), 'artifact opens as a verified immutable stream');

    $zipBackup = (string)$zip . '.contract-backup';
    rename((string)$zip, $zipBackup);
    $artifactSymlinkSupported = @symlink('/etc/passwd', (string)$zip);
    if ($artifactSymlinkSupported) {
        $check(ThemeWorkspace::openArtifact('safe-theme') === null, 'artifact consumer rejects a symlink swapped into the ZIP path');
        @unlink((string)$zip);
    } else {
        echo "SKIP artifact symlink behavior is unavailable\n";
    }
    rename($zipBackup, (string)$zip);
    $check(ThemeWorkspace::artifactPath('safe-theme') === $zip, 'artifact remains valid after safe path-swap recovery');

    file_put_contents($themeDir . '/assets/css/style.css', "body { color: #456; }\n");
    $check(ThemeWorkspace::artifactPath('safe-theme') === null, 'out-of-band source change makes package artifact stale');

    $zip = ThemeWorkspace::buildZip('safe-theme');
    $check(is_string($zip) && ThemeWorkspace::artifactPath('safe-theme') === $zip, 'package can be rebuilt after a stale artifact');

    $symlinkSupported = @symlink(sys_get_temp_dir(), $themeDir . '/assets/unsafe-link');
    if ($symlinkSupported) {
        $check(ThemeWorkspace::artifactPath('safe-theme') === null, 'unsafe source tree is treated as a stale artifact without throwing');
        $check(ThemeWorkspace::buildZip('safe-theme') === null, 'package build rejects a symlinked theme entry');
        $check(!ThemeWorkspace::deleteTheme('safe-theme'), 'draft deletion refuses a tree containing symlinks');
        $check(is_dir($themeDir), 'failed unsafe deletion leaves draft intact');
        @unlink($themeDir . '/assets/unsafe-link');
    } else {
        echo "SKIP symlink behavior is unavailable\n";
    }

    $lockDir = $base . '/.locks';
    $outsideLocks = $base . '/.outside-locks';
    mkdir($outsideLocks, 0770);
    $lockSymlinkSupported = rename($lockDir, $base . '/.locks-real') && @symlink($outsideLocks, $lockDir);
    if ($lockSymlinkSupported) {
        $blockedByLock = ThemeWorkspace::writeFile('safe-theme', 'header', $validSource, (string)ThemeWorkspace::fileHash('safe-theme', 'header'));
        $check(($blockedByLock['success'] ?? true) === false, 'symlinked lock namespace fails closed');
        $check((scandir($outsideLocks) ?: []) === ['.', '..'], 'symlinked lock namespace creates no external lock file');
        @unlink($lockDir);
        rename($base . '/.locks-real', $lockDir);
    } else {
        if (is_link($lockDir)) @unlink($lockDir);
        if (is_dir($base . '/.locks-real')) rename($base . '/.locks-real', $lockDir);
        echo "SKIP lock symlink behavior is unavailable\n";
    }
    $remove($outsideLocks);

    $legacyDir = $base . '/Legacy_Theme';
    mkdir($legacyDir, 0770);
    file_put_contents($legacyDir . '/theme.json', "{\"folder\":\"Legacy_Theme\",\"name\":\"Legacy\",\"version\":\"1.0.0\"}\n");
    $legacyRows = array_values(array_filter(ThemeWorkspace::listThemes(), static fn(array $theme): bool => $theme['slug'] === 'Legacy_Theme'));
    $check(count($legacyRows) === 1 && ($legacyRows[0]['legacy_slug'] ?? false) === true
        && ($legacyRows[0]['name'] ?? null) === 'Legacy' && ($legacyRows[0]['version'] ?? null) === '1.0.0', 'legacy drafts remain visible with manifest metadata as read-only migration candidates');
    $remove($legacyDir);

    $check(ThemeWorkspace::deleteTheme('safe-theme'), 'validated draft is deleted');
    $check(!file_exists($themeDir) && is_dir($base), 'draft deletion preserves workspace root');
    $check(is_file($base . '/workspace-marker'), 'workspace marker survives valid draft deletion');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Theme Builder workspace contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo "RESULT: ALL PASS\n";
