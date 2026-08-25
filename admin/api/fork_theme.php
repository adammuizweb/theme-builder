<?php
declare(strict_types=1);

adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);

$source = trim((string)($_POST['source_theme'] ?? ''));
$target = trim((string)($_POST['target_folder'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
if ($source === '' || $target === '' || $name === '' || $title === '') {
    adiwira_json(['error' => __('Source theme, new folder, name, and title are required.')], 400);
}
if (!ThemeWorkspace::isValidSlug($target)) {
    adiwira_json(['error' => __('Use a new lowercase folder containing only letters, numbers, hyphens, and underscores.')], 400);
}

$actorId = function_exists('current_user_id') ? current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$service = new ThemeForkService($pdo);
$result = $service->fork($source, $target, $name, $title, $actorId);
adiwira_json($result, ($result['success'] ?? false) ? 200 : 422);
