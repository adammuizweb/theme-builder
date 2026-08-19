<?php
declare(strict_types=1);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);
$slug = trim((string)($_POST['theme'] ?? ''));
$json = (string)($_POST['manifest'] ?? '');
if ($slug === '' || $json === '') adiwira_json(['error' => 'Required fields missing.'], 400);
$manifest = json_decode($json, true);
if (!is_array($manifest)) adiwira_json(['error' => 'Invalid JSON.'], 400);
$manifest['folder'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $manifest['folder'] ?? $slug);
$manifest['color_mode'] = in_array($manifest['color_mode'] ?? '', ['light', 'dark', 'both'], true) ? $manifest['color_mode'] : 'both';
adiwira_json(['success' => ThemeWorkspace::writeManifest($slug, $manifest)]);
