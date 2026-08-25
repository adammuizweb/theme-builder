<?php
declare(strict_types=1);

// Theme Builder — plugin bootstrap
$__tb_dir = __DIR__;

require_once $__tb_dir . '/includes/class-theme-workspace.php';
require_once $__tb_dir . '/includes/class-installed-theme-inspector.php';
require_once $__tb_dir . '/includes/class-theme-fork-service.php';
require_once $__tb_dir . '/includes/class-theme-builder-core-integration.php';
require_once $__tb_dir . '/includes/class-var-reference.php';

if (class_exists('PDO', false) && ($GLOBALS['pdo'] ?? null) instanceof PDO
    && function_exists('add_action') && function_exists('add_filter')) {
    ThemeBuilderCoreIntegration::register($GLOBALS['pdo']);
}

unset($__tb_dir);
