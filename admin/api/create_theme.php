<?php
declare(strict_types=1);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);
$slug = trim((string)($_POST['slug'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$author = trim((string)($_POST['author'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$colorMode = trim((string)($_POST['color_mode'] ?? 'both'));
if ($slug === '' || $name === '') adiwira_json(['error' => 'Slug and name required.'], 400);
if (!ThemeWorkspace::isValidSlug($slug)) adiwira_json(['error' => 'Use a lowercase slug containing only letters, numbers, hyphens, and underscores.'], 400);
adiwira_json(ThemeWorkspace::createTheme($slug, $name, $author, $description, $colorMode));
