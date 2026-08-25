<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-inspector-' . bin2hex(random_bytes(8));
$themesRoot = $root . '/themes';
mkdir($themesRoot, 0770, true);
define('VIEWS_BASE', $themesRoot);
define('DEFAULT_THEME_FOLDER', 'foundation');
require_once dirname(__DIR__) . '/includes/class-theme-workspace.php';
require_once dirname(__DIR__) . '/includes/class-installed-theme-inspector.php';

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
    $write('foundation/theme.json', "{\"name\":\"Default\",\"version\":\"2.3.86\"}\n");
    $write('foundation/header.php', "<?php echo 'default header';\n");
    $write('foundation/footer.php', "<?php echo 'default footer';\n");

    $write('apu/theme.json', json_encode([
        'name' => 'APU Fixture',
        'version' => '3.2.2',
        'description' => 'Nested section fixture',
        'store' => ['url' => 'https://example.test/themes/apu', 'slug' => 'apu'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    $write('apu/header.php', "<?php echo 'apu header';\n");
    $write('apu/helpers/cards.php', "<?php function apu_cards(): array { return []; }\n");
    $write('apu/main/sections/hero.php', "<?php echo 'hero';\n");
    $write('apu/main/sections/gallery.php', "<?php echo 'galeri';\n");
    $write('apu/partials/shortcodes/section/hero.php', "<?php\n// require __DIR__ . '/../../../main/sections/gallery.php';\nREQUIRE __DIR__ . '/../../../main/sections/hero.php';\n");
    $write('apu/partials/components/card.php', "<?php echo 'card';\n");
    $write('apu/large.php', '<?php /* ' . str_repeat('x', 270000) . ' */');
    $write('unregistered/header.php', "<?php echo 'hidden';\n");

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE themes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_name TEXT NOT NULL UNIQUE,
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
    $insert = $pdo->prepare('INSERT INTO themes (folder_name, name, version, is_active, is_system) VALUES (?, ?, ?, ?, ?)');
    $insert->execute(['foundation', 'Database Default', '2.3.86', 0, 1]);
    $insert->execute(['apu', 'Database APU', '3.2.1', 1, 0]);

    $unsafeDir = $themesRoot . '/unsafe';
    mkdir($unsafeDir, 0770);
    $symlinkSupported = @symlink('/etc/passwd', $unsafeDir . '/leak.php');
    if ($symlinkSupported) $insert->execute(['unsafe', 'Unsafe', '1.0.0', 0, 0]);

    $inspector = new InstalledThemeInspector($pdo);
    $themes = $inspector->themes();
    $apuRows = array_values(array_filter($themes, static fn(array $theme): bool => $theme['folder'] === 'apu'));
    $check(count($apuRows) === 1, 'registered APU fixture appears in the theme list');
    $check(($apuRows[0]['name'] ?? null) === 'APU Fixture' && ($apuRows[0]['version'] ?? null) === '3.2.2', 'physical manifest metadata takes precedence over stale registry metadata');
    $check(($apuRows[0]['active'] ?? false) === true && ($apuRows[0]['store'] ?? false) === true, 'active and Store status are reported');
    $check(($apuRows[0]['php_files'] ?? null) === 7 && ($apuRows[0]['inspectable'] ?? false) === true, 'recursive PHP inventory count is reported');

    if ($symlinkSupported) {
        $unsafeRows = array_values(array_filter($themes, static fn(array $theme): bool => $theme['folder'] === 'unsafe'));
        $check(count($unsafeRows) === 1 && ($unsafeRows[0]['inspectable'] ?? true) === false, 'a registered theme containing a symlink fails closed in the list');
    } else {
        echo "SKIP symlink behavior is unavailable\n";
    }

    $beforeHashes = [];
    foreach (['header.php', 'helpers/cards.php', 'large.php', 'main/sections/hero.php', 'main/sections/gallery.php', 'partials/shortcodes/section/hero.php', 'partials/components/card.php'] as $path) {
        $beforeHashes[$path] = hash_file('sha256', $themesRoot . '/apu/' . $path);
    }

    $inspection = $inspector->inspect('apu');
    $files = $inspection['files'];
    $byPath = [];
    foreach ($files as $file) $byPath[$file['path']] = $file;
    $check(count($files) === 7, 'all nested physical PHP files are inventoried');
    $check(($byPath['header.php']['category'] ?? null) === 'slot' && ($byPath['header.php']['slot'] ?? null) === 'header', 'canonical physical source is classified as a slot');
    $check(($byPath['partials/shortcodes/section/hero.php']['category'] ?? null) === 'section-wrapper', 'APUJ-style section wrapper is classified');
    $check(($byPath['main/sections/hero.php']['category'] ?? null) === 'section', 'nested section leaf is classified');
    $check(($byPath['helpers/cards.php']['category'] ?? null) === 'helper', 'helper PHP is classified');
    $check(preg_match('/\A[a-f0-9]{64}\z/D', (string)($byPath['main/sections/hero.php']['id'] ?? '')) === 1, 'file identity is an opaque SHA-256 token');

    $wrapper = $inspector->source('apu', (string)$byPath['partials/shortcodes/section/hero.php']['id']);
    $wrapperDependencies = $wrapper['dependencies'] ?? [];
    $check(($wrapper['dependencies_scanned'] ?? false) === true && count($wrapperDependencies) === 1 && ($wrapperDependencies[0]['path'] ?? null) === 'main/sections/hero.php', 'token-aware literal __DIR__ dependency ignores comments and resolves uppercase require');
    $large = $inspector->source('apu', (string)$byPath['large.php']['id']);
    $check(($large['dependencies_scanned'] ?? true) === false && ($large['dependencies'] ?? null) === [], 'large source remains readable while dependency tokenization is skipped');

    $slots = [];
    foreach ($inspection['slots'] as $slot) $slots[$slot['slot']] = $slot;
    $check(($slots['header']['status'] ?? null) === 'physical' && !empty($slots['header']['file_id']), 'physical canonical slot is linked to its opaque file ID');
    $check(($slots['footer']['status'] ?? null) === 'inherited' && empty($slots['footer']['file_id']), 'missing selected-theme slot follows configured default-theme fallback');
    $check(($slots['sidebar']['status'] ?? null) === 'missing', 'slot absent from selected and default themes reports missing');

    $hero = $inspector->source('apu', (string)$byPath['main/sections/hero.php']['id']);
    $check(($hero['path'] ?? null) === 'main/sections/hero.php' && ($hero['source'] ?? null) === "<?php echo 'hero';\n", 'opaque ID opens the expected regular-file bytes');
    $check(($hero['sha256'] ?? null) === hash('sha256', "<?php echo 'hero';\n") && ($hero['utf8'] ?? false) === true, 'source metadata contains a verified hash and encoding status');
    $check($inspector->source('apu', str_repeat('0', 64)) === null, 'unknown opaque file ID reveals no source');
    $check($inspector->source('apu', '../../etc/passwd') === null, 'path-shaped file selector is rejected');

    $unregisteredRejected = false;
    try {
        $inspector->inspect('unregistered');
    } catch (RuntimeException) {
        $unregisteredRejected = true;
    }
    $check($unregisteredRejected, 'physical but unregistered theme cannot be inspected');

    $invalidFolderRejected = false;
    try {
        $inspector->inspect('../apu');
    } catch (InvalidArgumentException) {
        $invalidFolderRejected = true;
    }
    $check($invalidFolderRejected, 'theme-folder traversal is rejected before filesystem resolution');

    foreach ($beforeHashes as $path => $hash) {
        $check(hash_file('sha256', $themesRoot . '/apu/' . $path) === $hash, 'inspection does not mutate ' . $path);
    }
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Installed theme inspector contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo "RESULT: ALL PASS\n";
