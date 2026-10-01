<?php

declare(strict_types=1);

final class ThemeSourceService
{
    public array $inventories = [];
    public array $sources = [];
    public array $calls = [];

    public function inventory(string $folder): array
    {
        $this->calls[] = ['inventory', $folder];
        return $this->inventories[$folder] ?? ['files' => []];
    }

    public function source(string $folder, string $fileId): ?array
    {
        $this->calls[] = ['source', $folder, $fileId];
        return $this->sources[$folder][$fileId] ?? null;
    }
}

function theme_source_service(PDO $pdo): ThemeSourceService
{
    return $GLOBALS['_tb_theme_source_service'];
}

require_once dirname(__DIR__) . '/includes/class-theme-workspace.php';
require_once dirname(__DIR__) . '/includes/class-installed-theme-inspector.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE themes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, folder_name TEXT COLLATE NOCASE NOT NULL UNIQUE, name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT \'\', version TEXT NOT NULL DEFAULT \'\', author TEXT NOT NULL DEFAULT \'\',
        is_active INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0,
        store_url TEXT NOT NULL DEFAULT \'\', store_slug TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec("INSERT INTO themes (folder_name, name, description, version, is_active, store_url, store_slug)
        VALUES ('apu', 'APU Theme', 'Fixture', '3.2.2', 1, 'https://store.test', 'apu')");

    $headerId = hash('sha256', 'header');
    $sectionId = hash('sha256', 'section');
    $leafId = hash('sha256', 'leaf');
    $service = new ThemeSourceService();
    $headerSource = "<?php echo 'apu';\n";
    $sectionSource = "<?php require __DIR__ . '/../../../main/sections/hero.php';\n";
    $leafSource = "<?php echo 'hero';\n";
    $service->inventories['apu'] = ['theme' => [
        'id' => 1, 'folder' => 'apu', 'active' => true, 'assigned' => true, 'store' => true, 'system' => true,
    ], 'files' => [
        ['id' => $sectionId, 'path' => 'partials/shortcodes/section/hero.php', 'size' => strlen($sectionSource), 'sha256' => hash('sha256', $sectionSource)],
        ['id' => $leafId, 'path' => 'main/sections/hero.php', 'size' => strlen($leafSource), 'sha256' => hash('sha256', $leafSource)],
        ['id' => $headerId, 'path' => 'header.php', 'size' => strlen($headerSource), 'sha256' => hash('sha256', $headerSource)],
    ]];
    $service->sources['apu'][$headerId] = [
        'id' => $headerId, 'path' => 'header.php', 'source' => $headerSource, 'sha256' => hash('sha256', $headerSource),
    ];
    $service->sources['apu'][$sectionId] = [
        'id' => $sectionId, 'path' => 'partials/shortcodes/section/hero.php', 'source' => $sectionSource,
        'sha256' => hash('sha256', $sectionSource),
    ];
    $GLOBALS['_tb_theme_source_service'] = $service;

    $inspector = new InstalledThemeInspector($pdo);
    $themes = $inspector->themes();
    $check(count($themes) === 1 && ($themes[0]['php_files'] ?? null) === 3 && ($themes[0]['active'] ?? false)
        && ($themes[0]['assigned'] ?? false) && ($themes[0]['store'] ?? false) && ($themes[0]['system'] ?? false),
        'installed list combines registry metadata with authoritative Core inventory state');

    $inspection = $inspector->inspect('apu');
    $files = [];
    foreach ($inspection['files'] as $file) $files[$file['path']] = $file;
    $check(($files['header.php']['id'] ?? null) === $headerId && ($files['header.php']['category'] ?? null) === 'slot'
        && ($files['header.php']['slot'] ?? null) === 'header', 'Core file records are normalized for owner navigation');
    $check(($files['partials/shortcodes/section/hero.php']['category'] ?? null) === 'section-wrapper',
        'Core inventory paths retain Theme Builder navigation classification');
    $check(array_filter($service->calls, static fn(array $call): bool => $call === ['inventory', 'apu']) !== [],
        'installed inventory is obtained from theme_source_service');

    $source = $inspector->source('apu', $headerId);
    $check(($source['source'] ?? null) === "<?php echo 'apu';\n"
        && in_array(['source', 'apu', $headerId], $service->calls, true),
        'read compatibility delegates opaque source lookup to Core without filesystem fallback');
    $check($inspector->source('apu', "bad\0id") === null, 'invalid opaque source identity is rejected before Core dispatch');

    $dependencyMap = $inspector->literalDependencies('apu', [$sectionId]);
    $check(($dependencyMap[$sectionId]['scanned'] ?? false) === true
        && ($dependencyMap[$sectionId]['dependencies'][0]['id'] ?? null) === $leafId,
        'literal dependency parsing maps Core source bytes back to a Core opaque inventory identity');
    $sourceCallsBeforeLimit = count(array_filter($service->calls, static fn(array $call): bool => $call[0] === 'source'));
    foreach ($service->inventories['apu']['files'] as &$record) {
        if ($record['id'] === $sectionId) $record['size'] = 262145;
    }
    unset($record);
    $limited = $inspector->literalDependencies('apu', [$sectionId]);
    $sourceCallsAfterLimit = count(array_filter($service->calls, static fn(array $call): bool => $call[0] === 'source'));
    $check(($limited[$sectionId]['reason'] ?? null) === 'file_limit' && $sourceCallsAfterLimit === $sourceCallsBeforeLimit,
        'oversized dependency source is rejected from token processing before Core source bytes are requested');

    $wrongCaseRejected = false;
    try { $inspector->inspect('APU'); } catch (RuntimeException) { $wrongCaseRejected = true; }
    $check($wrongCaseRejected, 'registered theme identity remains exact-case even with NOCASE collation');

    $service->inventories['apu'] = ['theme' => [
        'id' => 1, 'folder' => 'apu', 'active' => true, 'assigned' => false, 'store' => true, 'system' => false,
    ], 'files' => [[
        'id' => $headerId, 'path' => '../header.php', 'size' => 1, 'sha256' => hash('sha256', 'x'),
    ]]];
    $invalidCoreInventoryRejected = false;
    try { $inspector->inspect('apu'); } catch (RuntimeException) { $invalidCoreInventoryRejected = true; }
    $check($invalidCoreInventoryRejected, 'malformed Core inventory fails closed rather than invoking a plugin filesystem scanner');

    $sourceCode = (string)file_get_contents(dirname(__DIR__) . '/includes/class-installed-theme-inspector.php');
    $check(str_contains($sourceCode, 'theme_source_service($this->pdo)')
        && !str_contains($sourceCode, 'RecursiveDirectoryIterator') && !str_contains($sourceCode, 'VIEWS_BASE'),
        'installed adapter contains no duplicate physical source inventory implementation');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Installed theme inspector contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "RESULT: ALL PASS\n";
