<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "PASS {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL {$message}\n";
};

try {
    $manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $manifest = [];
    $failures[] = 'plugin.json parses';
}

$check(($manifest['name'] ?? null) === 'theme-builder', 'manifest identity is theme-builder');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.72', 'manifest declares the Site Owner-capable Core minimum');
$check(!array_key_exists('permissions', $manifest), 'manifest declares no delegable Theme Builder permissions');
$pages = $manifest['admin']['pages'] ?? [];
$check(is_array($pages) && count($pages) === 10, 'manifest declares all ten Theme Builder routes');
foreach (is_array($pages) ? $pages : [] as $page) {
    $route = (string)($page['route'] ?? 'unknown');
    $check(($page['site_owner'] ?? false) === true, "{$route} is declared Site Owner-only");
    $check(!array_key_exists('permission', $page), "{$route} has no delegable permission override");
    $check(($page['roles'] ?? null) === ['admin'], "{$route} retains the coarse admin route guard");
    $file = (string)($page['file'] ?? '');
    $real = $file !== '' ? realpath($root . '/' . $file) : false;
    $check($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR), "{$route} resolves inside the plugin");
}
foreach (($manifest['admin']['nav'] ?? []) as $item) {
    $check(($item['site_owner'] ?? false) === true, 'Theme Builder navigation is declared Site Owner-only');
}

$dashboard = (string)file_get_contents($root . '/admin/index.php');
$editor = (string)file_get_contents($root . '/admin/editor.php');
$check(str_contains($dashboard, 'adiwira_require_site_owner($pdo, false)'), 'Theme Builder dashboard requires Site Owner');
$check(str_contains($editor, 'adiwira_require_site_owner($pdo, false)'), 'Theme Builder editor requires Site Owner');
$check(!str_contains($dashboard, 'plugin.theme-builder.') && !str_contains($editor, 'plugin.theme-builder.'), 'Theme Builder UI has no delegated permission path');
$check(str_contains($dashboard, 'name="csrf_token"') && str_contains($dashboard, 'json_encode($csrfToken'), 'dashboard exposes CSRF through its form and safe JavaScript serialization');
$check(substr_count($dashboard, "method: 'POST'") === 4, 'all dashboard mutations use POST');
$check(substr_count($dashboard, "fd.append('csrf_token', csrfToken)") === 3, 'build, install, and delete send CSRF explicitly');
$check(!str_contains($dashboard, 'api/build_zip&theme=') && !str_contains($dashboard, 'api/install_theme&theme=') && !str_contains($dashboard, 'api/delete_theme&theme='), 'dashboard mutation URLs contain no GET mutation parameters');
$check(str_contains($dashboard, 'a.href = data.download_url'), 'successful builds retain a read-only ZIP download');
$check(str_contains($editor, 'json_encode($csrfToken'), 'editor serializes its CSRF token safely');
$check(substr_count($editor, "fd.append('csrf_token', csrfToken)") === 3, 'slot, manifest, and asset writes send CSRF');
$check(!str_contains($editor, 'preview&theme=') || !str_contains($editor, 'preview&theme=' . "' + encodeURIComponent(slug) + '&csrf_token="), 'preview URLs do not expose CSRF tokens');

$mutations = [
    'create_theme.php' => 'ThemeWorkspace::createTheme',
    'save_file.php' => 'ThemeWorkspace::write',
    'save_manifest.php' => 'ThemeWorkspace::writeManifest',
    'build_zip.php' => 'ThemeWorkspace::buildZip',
    'install_theme.php' => 'ThemeWorkspace::installTheme',
    'delete_theme.php' => 'ThemeWorkspace::deleteTheme',
];
foreach ($mutations as $file => $operation) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $methodAt = strpos($source, 'REQUEST_METHOD');
    $csrfAt = strpos($source, 'adiwira_csrf_validate');
    $operationAt = strpos($source, $operation);
    $check(str_contains($source, 'adiwira_require_site_owner($pdo, true)'), "{$file} requires Site Owner");
    $check($methodAt !== false && str_contains($source, "!== 'POST'") && str_contains($source, '405'), "{$file} is POST-only");
    $check($csrfAt !== false && str_contains($source, "POST['csrf_token']") && str_contains($source, '419'), "{$file} validates POST CSRF");
    $check($methodAt !== false && $csrfAt !== false && $operationAt !== false && $methodAt < $operationAt && $csrfAt < $operationAt, "{$file} completes security preflight before mutation");
}

foreach (['build_zip.php', 'install_theme.php', 'delete_theme.php'] as $file) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $check(!str_contains($source, "GET['theme']"), "{$file} no longer accepts a GET theme parameter");
}

$build = (string)file_get_contents($root . '/admin/api/build_zip.php');
$check(str_contains($build, '?action=api&page=admin/tools/theme-builder/api/download_zip&theme='), 'build response uses the pre-layout API download route');

foreach (['preview.php', 'download_zip.php'] as $file) {
    $source = (string)file_get_contents($root . '/admin/api/' . $file);
    $check(str_contains($source, 'adiwira_require_site_owner($pdo, false)'), "{$file} requires Site Owner");
    $check(str_contains($source, "!== 'GET'") && str_contains($source, '405'), "{$file} is GET-only");
    $check(!str_contains($source, 'adiwira_csrf_validate') && !str_contains($source, 'csrf_check'), "{$file} remains a CSRF-free read endpoint");
}

if ($failures !== []) {
    fwrite(STDERR, 'Theme Builder security contract failed: ' . implode('; ', array_unique($failures)) . "\n");
    exit(1);
}

echo "RESULT: ALL PASS\n";
