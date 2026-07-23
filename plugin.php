<?php
declare(strict_types=1);

// Theme Builder — plugin bootstrap
$__tb_dir = __DIR__;

require_once $__tb_dir . '/includes/class-theme-workspace.php';
require_once $__tb_dir . '/includes/class-var-reference.php';
require_once $__tb_dir . '/includes/class-preview-renderer.php';
require_once $__tb_dir . '/includes/class-theme-preview.php';

// Register live-preview resolver hook when active plugins are loaded.
if (function_exists('add_action')) {
    add_action('init', function () {
        if (class_exists('ThemePreview')) {
            ThemePreview::registerCoreFilter();
        }
    });
}

unset($__tb_dir);
