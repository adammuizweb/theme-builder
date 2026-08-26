<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-owner-navigation-' . bin2hex(random_bytes(8));
$themesRoot = $root . '/themes';
mkdir($themesRoot . '/active/partials/shortcodes/section', 0770, true);
mkdir($themesRoot . '/active/main/sections', 0770, true);
define('VIEWS_BASE', $themesRoot);
define('DEFAULT_THEME_FOLDER', 'active');

$GLOBALS['_tb_owner_routes'] = [];
$GLOBALS['_tb_owner_allowed'] = [];
$GLOBALS['_tb_owner_core_allowed'] = true;
$GLOBALS['_tb_owner_jvb_status'] = [];
$GLOBALS['_tb_owner_jvb_calls'] = 0;
$GLOBALS['_tb_owner_ct_calls'] = 0;
$GLOBALS['_tb_owner_ct_package_calls'] = 0;
$GLOBALS['_tb_owner_ct_folder'] = 'active';
$GLOBALS['_tb_owner_ct_locales'] = ['id', 'de', '../unsafe', 'id'];
$GLOBALS['_tb_owner_ct_file_calls'] = 0;
$GLOBALS['_tb_owner_ct_file_folder'] = 'active';
$GLOBALS['_tb_owner_ct_file_resource_count'] = 1;

function plugin_resolve_route(string $route): ?array
{
    $plugin = $GLOBALS['_tb_owner_routes'][$route] ?? null;
    return is_string($plugin) ? ['route' => $route, 'plugin' => $plugin] : null;
}

function plugin_route_is_allowed(PDO $pdo, array $route, ?int $userId = null): bool
{
    return ($GLOBALS['_tb_owner_allowed'][$route['route'] ?? ''] ?? false) === true;
}

function current_user_can(PDO $pdo, string $permission, array $context = []): bool
{
    return $permission === 'core.theme_content.update' && $GLOBALS['_tb_owner_core_allowed'] === true;
}

function jvb_layout_statuses(PDO $pdo, array $postIds): array
{
    $GLOBALS['_tb_owner_jvb_calls']++;
    $statuses = [];
    foreach ($postIds as $postId) $statuses[(int)$postId] = (string)($GLOBALS['_tb_owner_jvb_status'][(int)$postId] ?? 'none');
    return $statuses;
}

function ct_parse_theme_section_composition(string $content): ?array
{
    $GLOBALS['_tb_owner_ct_calls']++;
    $GLOBALS['_tb_owner_ct_package_calls']++;
    return str_contains($content, 'widget:theme_section') ? [['name' => 'hero']] : null;
}

function ct_theme_section_source_resource(PDO $pdo, array $post, bool $renderPreviews = true): ?array
{
    $GLOBALS['_tb_owner_ct_calls']++;
    $GLOBALS['_tb_owner_ct_package_calls']++;
    return ($post['type'] ?? '') === 'theme'
        ? ['theme_folder' => $GLOBALS['_tb_owner_ct_folder'], 'sections' => [['name' => 'hero']]]
        : null;
}

function ct_enabled_locales(PDO $pdo): array
{
    $GLOBALS['_tb_owner_ct_calls']++;
    return $GLOBALS['_tb_owner_ct_locales'];
}

function ct_theme_file_resources(PDO $pdo, ?string $themeFolder = null): array
{
    $GLOBALS['_tb_owner_ct_file_calls']++;
    $resources = [];
    for ($index = 0; $index < $GLOBALS['_tb_owner_ct_file_resource_count']; $index++) {
        $slot = $index === 0 ? 'main.homepage' : 'custom.' . $index;
        $folder = $GLOBALS['_tb_owner_ct_file_folder'];
        $resources[$folder . ':' . $slot] = [
            'id' => $folder . ':' . $slot,
            'theme_folder' => $folder,
            'slot_key' => $slot,
            'label' => $index === 0 ? 'Homepage (main.homepage)' : 'Custom ' . $index,
            'fields' => $index === 0 ? ['headline' => [], 'summary' => []] : ['label' => []],
        ];
    }
    return $resources;
}

function ct_theme_file_translation_statuses(PDO $pdo, array $resources): array
{
    $GLOBALS['_tb_owner_ct_file_calls']++;
    return ['active:main.homepage' => ['id' => 'published', 'de' => 'draft']];
}

require_once dirname(__DIR__) . '/includes/class-theme-workspace.php';
require_once dirname(__DIR__) . '/includes/class-installed-theme-inspector.php';
require_once dirname(__DIR__) . '/includes/class-theme-owner-navigator.php';

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
    file_put_contents($themesRoot . '/active/theme.json', json_encode([
        'folder' => 'active', 'name' => 'Active Theme', 'version' => '1.0.0',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($themesRoot . '/active/partials/shortcodes/section/hero.php',
        "<?php\nrequire __DIR__ . '/../../../main/sections/hero.php';\n");
    file_put_contents($themesRoot . '/active/main/sections/hero.php', "<?php echo 'hero';\n");
    file_put_contents($themesRoot . '/active/main/homepage.php', "<?php echo 'homepage';\n");
    $wrapperHash = hash_file('sha256', $themesRoot . '/active/partials/shortcodes/section/hero.php');
    $leafHash = hash_file('sha256', $themesRoot . '/active/main/sections/hero.php');

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE themes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, folder_name TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT \'\', version TEXT NOT NULL DEFAULT \'\', author TEXT NOT NULL DEFAULT \'\',
        is_active INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0,
        store_url TEXT NOT NULL DEFAULT \'\', store_slug TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE posts (
        id INTEGER PRIMARY KEY, title TEXT NOT NULL, slug TEXT NOT NULL, content TEXT NOT NULL,
        type TEXT NOT NULL, status TEXT NOT NULL, created_by INTEGER, is_deleted INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slot_key TEXT NOT NULL, theme_id INTEGER,
        theme_file TEXT, custom_post_id INTEGER, created_by INTEGER
    )');
    $pdo->exec("INSERT INTO themes (folder_name, name, version, is_active) VALUES ('active', 'Active Theme', '1.0.0', 1)");
    $packageContent = '[[widget:theme_section name="hero"]]';
    $insertPost = $pdo->prepare('INSERT INTO posts (id, title, slug, content, type, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insertPost->execute([10, 'Package Template', 'package', $packageContent, 'theme', 'published', 7]);
    $insertPost->execute([20, 'Plain Template', 'plain', '<p>Plain</p>', 'theme', 'draft', 8]);
    $pdo->exec("INSERT INTO assignments (slot_key, custom_post_id) VALUES ('main.homepage', 10)");

    $inspector = new InstalledThemeInspector($pdo);
    $inspection = $inspector->inspect('active');
    $navigator = new ThemeOwnerNavigator($pdo);
    $pdo->exec('PRAGMA query_only = ON');

    $absent = $navigator->relationships('active', $inspection, '/owner');
    $check(count($absent['templates']['items']) === 2
        && $absent['templates']['builder_available'] === false
        && $absent['templates']['translation_available'] === false,
        'absent or disabled owner routes leave Core inventory available without plugin links');
    $check($GLOBALS['_tb_owner_jvb_calls'] === 0 && $GLOBALS['_tb_owner_ct_calls'] === 0
        && $GLOBALS['_tb_owner_ct_file_calls'] === 0,
        'absent or disabled owner routes invoke no owner-plugin storage API');
    $check(str_starts_with((string)$absent['templates']['items'][0]['core_url'], '/owner/?')
        && !str_contains((string)$absent['templates']['items'][0]['core_url'], '/adiwira'),
        'Core editor navigation uses only the supplied ADMIN_BASE_PATH');

    $builderRoute = 'admin/tools/jyavani-builder';
    $translationRoute = 'admin/tools/content-translation/theme-section-edit';
    $themeFileRoute = 'admin/tools/content-translation/theme-file-edit';
    $GLOBALS['_tb_owner_routes'] = [
        $builderRoute => 'jyavani-builder',
        $translationRoute => 'content-translation',
        $themeFileRoute => 'content-translation',
    ];
    $GLOBALS['_tb_owner_allowed'] = [$builderRoute => false, $translationRoute => false, $themeFileRoute => false];
    $fileCallsBeforeDenied = $GLOBALS['_tb_owner_ct_file_calls'];
    $unauthorized = $navigator->relationships('active', $inspection, '/owner');
    $check($unauthorized['templates']['builder_available'] === false
        && $unauthorized['templates']['translation_available'] === false
        && $unauthorized['theme_files']['available'] === false
        && $GLOBALS['_tb_owner_jvb_calls'] === 0 && $GLOBALS['_tb_owner_ct_calls'] === 0
        && $GLOBALS['_tb_owner_ct_file_calls'] === $fileCallsBeforeDenied,
        'unauthorized owner routes fail closed before invoking owner-plugin APIs');

    $GLOBALS['_tb_owner_allowed'] = [$builderRoute => true, $translationRoute => true, $themeFileRoute => true];
    $GLOBALS['_tb_owner_jvb_status'] = [10 => 'published', 20 => 'none'];
    $available = $navigator->relationships('active', $inspection, '/secret-admin');
    $byId = [];
    foreach ($available['templates']['items'] as $template) $byId[$template['id']] = $template;
    $check(($byId[10]['slots'] ?? null) === ['main.homepage'] && ($byId[20]['slots'] ?? null) === [],
        'Core assignments map database Theme Templates to their exact slots');
    $check(($byId[10]['builder']['status'] ?? null) === 'published'
        && str_contains((string)($byId[10]['builder']['url'] ?? ''), 'post_id=10')
        && ($byId[20]['builder'] ?? null) === null,
        'existing Jy Builder layouts link to the owner editor while missing layouts stay absent');
    $check(array_column($byId[10]['translations'] ?? [], 'locale') === ['id', 'de']
        && ($byId[20]['translations'] ?? null) === []
        && str_starts_with((string)($byId[10]['translations'][0]['url'] ?? ''), '/secret-admin/?'),
        'package-composed templates link only valid locales to the authorized Content Translation editor');
    $themeFiles = $available['theme_files'];
    $check($themeFiles['available'] === true && count($themeFiles['items']) === 1
        && ($themeFiles['items'][0]['id'] ?? null) === 'active:main.homepage'
        && ($themeFiles['items'][0]['field_count'] ?? null) === 2
        && ($themeFiles['items'][0]['source_path'] ?? null) === 'main/homepage.php',
        'declared Theme File resources map exact owner and slot identities to physical source');
    $fileTranslations = $themeFiles['items'][0]['translations'] ?? [];
    $check(array_column($fileTranslations, 'locale') === ['id', 'de']
        && array_column($fileTranslations, 'status') === ['published', 'draft']
        && str_contains((string)($fileTranslations[0]['url'] ?? ''), 'theme_folder=active')
        && str_contains((string)($fileTranslations[0]['url'] ?? ''), 'slot_key=main.homepage'),
        'Theme File navigation exposes bounded locale status and exact editor identity');
    parse_str((string)parse_url((string)$fileTranslations[0]['url'], PHP_URL_QUERY), $themeFileQuery);
    $check(($themeFileQuery['return_to'] ?? null) === '/secret-admin/?page=admin/tools/theme-builder/installed&theme=active',
        'Theme File editor navigation preserves the exact inspector return URL');

    $GLOBALS['_tb_owner_ct_locales'] = array_map(static fn(int $index): string => 'x-' . $index, range(1, 25));
    $localeBound = $navigator->relationships('active', $inspection, '/owner');
    $localeTemplates = [];
    foreach ($localeBound['templates']['items'] as $template) $localeTemplates[$template['id']] = $template;
    $check(count($localeTemplates[10]['translations'] ?? []) === 20
        && $localeBound['templates']['translation_locales_truncated'] === true
        && count($localeBound['theme_files']['items'][0]['translations'] ?? []) === 20
        && $localeBound['theme_files']['locales_truncated'] === true,
        'Content Translation navigation bounds and reports oversized locale sets');
    $GLOBALS['_tb_owner_ct_locales'] = ['id', 'de'];

    $sections = $available['sections']['items'];
    $check(count($sections) === 1
        && ($sections[0]['path'] ?? null) === 'partials/shortcodes/section/hero.php'
        && ($sections[0]['dependencies'][0]['path'] ?? null) === 'main/sections/hero.php',
        'registered Theme Section wrappers map to literal local PHP leaf dependencies');
    $check(str_contains((string)$sections[0]['url'], 'file=')
        && str_contains((string)$sections[0]['dependencies'][0]['url'], 'file='),
        'Theme Section navigation uses opaque inspector file identities rather than client paths');

    $inactiveInspection = $inspection;
    $inactiveInspection['theme']['active'] = false;
    $ctPackageCallsBeforeInactive = $GLOBALS['_tb_owner_ct_package_calls'];
    $inactive = $navigator->relationships('active', $inactiveInspection, '/owner');
    $check($inactive['templates']['translation_available'] === false
        && ($inactive['templates']['items'][0]['translations'] ?? null) === []
        && $GLOBALS['_tb_owner_ct_package_calls'] === $ctPackageCallsBeforeInactive,
        'inactive physical themes never resolve or inspect active-theme translation packages');

    $GLOBALS['_tb_owner_ct_folder'] = 'another-theme';
    $ownerMismatch = $navigator->relationships('active', $inspection, '/owner');
    $mismatchLinks = array_merge(...array_map(static fn(array $template): array => $template['translations'], $ownerMismatch['templates']['items']));
    $check($mismatchLinks === [], 'Content Translation links require exact physical theme ownership');
    $GLOBALS['_tb_owner_ct_file_folder'] = 'another-theme';
    $fileOwnerMismatch = $navigator->relationships('active', $inspection, '/owner');
    $check($fileOwnerMismatch['theme_files']['items'] === [],
        'Theme File navigation rejects resources owned by a different physical theme');
    $GLOBALS['_tb_owner_ct_file_folder'] = 'active';

    $GLOBALS['_tb_owner_core_allowed'] = false;
    $coreDenied = $navigator->relationships('active', $inspection, '/owner');
    $check(array_filter(array_column($coreDenied['templates']['items'], 'core_url')) === [],
        'unauthorized Core Theme Template editor links fail closed');

    $GLOBALS['_tb_owner_ct_file_resource_count'] = 101;
    $resourceBound = $navigator->relationships('active', $inspection, '/owner');
    $check($resourceBound['theme_files']['items'] === [] && $resourceBound['theme_files']['error'] !== null,
        'oversized Theme File resource inventories fail closed');
    $GLOBALS['_tb_owner_ct_file_resource_count'] = 1;

    $pdo->exec('PRAGMA query_only = OFF');
    $pdo->exec('DELETE FROM assignments');
    $insertAssignment = $pdo->prepare('INSERT INTO assignments (slot_key, custom_post_id) VALUES (?, 10)');
    for ($index = 0; $index <= 1000; $index++) $insertAssignment->execute(['custom.' . $index]);
    $pdo->exec('PRAGMA query_only = ON');
    $assignmentBound = $navigator->relationships('active', $inspection, '/owner');
    $check($assignmentBound['templates']['items'] === [] && $assignmentBound['templates']['error'] !== null,
        'oversized Core assignment inventories fail closed instead of labeling templates unassigned');

    $invalidBaseRejected = false;
    try { $navigator->relationships('active', $inspection, '//external.example'); }
    catch (InvalidArgumentException) { $invalidBaseRejected = true; }
    $check($invalidBaseRejected, 'owner navigation rejects an unsafe admin base path');
    $check(hash_file('sha256', $themesRoot . '/active/partials/shortcodes/section/hero.php') === $wrapperHash
        && hash_file('sha256', $themesRoot . '/active/main/sections/hero.php') === $leafHash,
        'relationship inventory preserves every physical PHP source byte');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Owner navigation contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo "RESULT: ALL PASS\n";
