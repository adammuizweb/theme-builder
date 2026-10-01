<?php
declare(strict_types=1);

adiwira_require_permission($pdo, 'core.themes.manage', true);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);

$folder = $_POST['theme'] ?? '';
if (!is_string($folder) || !ThemeWorkspace::isValidSlug($folder)) {
    adiwira_json(['error' => __('Invalid managed fork folder.')], 400);
}

$result = (new ThemeForkService($pdo))->deleteFork($folder);
adiwira_json($result, ($result['success'] ?? false) ? 200 : 422);
