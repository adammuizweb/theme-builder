<?php
declare(strict_types=1);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);
$slug = trim((string)($_POST['theme'] ?? ''));
$slot = trim((string)($_POST['slot'] ?? ''));
$content = (string)($_POST['content'] ?? '');
if ($slug === '') adiwira_json(['error' => 'Theme required.'], 400);
if ($slot === '_asset') {
    $path = trim((string)($_POST['asset_path'] ?? ''));
    if ($path === '') adiwira_json(['error' => 'Asset path required.'], 400);
    adiwira_json(['success' => ThemeWorkspace::writeAsset($slug, $path, $content)]);
}
if ($slot === '') adiwira_json(['error' => 'Slot required.'], 400);
adiwira_json(['success' => ThemeWorkspace::writeFile($slug, $slot, $content)]);
