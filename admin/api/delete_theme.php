<?php
declare(strict_types=1);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);
$slug = trim((string)($_POST['theme'] ?? ''));
if ($slug === '') adiwira_json(['error' => 'Theme required.'], 400);
if (!ThemeWorkspace::isValidSlug($slug)) adiwira_json(['error' => 'Invalid theme slug.'], 400);
adiwira_json(['success' => ThemeWorkspace::deleteTheme($slug)]);
