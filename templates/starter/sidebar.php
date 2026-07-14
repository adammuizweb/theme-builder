<?php
$helperPath = dirname(__DIR__, 3) . '/cfg/helpers/widget_helper.php';
if (is_file($helperPath)) require_once $helperPath;
if (!function_exists('widget')) return;
global $pdo;
if (isset($pdo) && $pdo instanceof PDO && function_exists('load_preset_widgets')) load_preset_widgets($pdo);
if (function_exists('render_sidebar_widgets')) {
    $managed = render_sidebar_widgets($pdo);
    if ($managed !== '') { echo '<div class="sidebar-widgets">' . $managed . '</div>'; return; }
}
?>
<div class="sidebar-widgets">
  <?= widget('search_form', ['placeholder' => __('Search...')]) ?>
  <?= widget('recent_posts', ['title' => __('Recent Posts'), 'limit' => 5]) ?>
  <?= widget('categories_list', ['title' => __('Categories'), 'limit' => 20]) ?>
</div>
