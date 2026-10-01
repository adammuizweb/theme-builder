<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/theme-builder-lock-contract-' . bin2hex(random_bytes(8));
mkdir($root, 0770, true);
define('VIEWS_BASE', $root);
define('THEME_BUILDER_WORKSPACE', $root . '/workspace');

require_once dirname(__DIR__) . '/includes/class-theme-workspace.php';
require_once dirname(__DIR__) . '/includes/class-theme-fork-service.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$result = (new ThemeForkService($pdo))->fork('source', 'target', 'Target', 'Target', 1);
$ok = ($result['success'] ?? true) === false
    && str_contains((string)($result['error'] ?? ''), 'Core theme operation locking is unavailable');

echo ($ok ? 'PASS ' : 'FAIL ') . 'forking fails closed when Core lifecycle lock helpers are unavailable' . PHP_EOL;
@rmdir(THEME_BUILDER_WORKSPACE);
@rmdir($root);
if (!$ok) exit(1);
echo "RESULT: ALL PASS\n";
