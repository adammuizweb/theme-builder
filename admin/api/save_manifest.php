<?php
declare(strict_types=1);
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { echo json_encode(['error' => 'POST required']); return; }
$slug = trim((string)($_POST['theme'] ?? ''));
$json = (string)($_POST['manifest'] ?? '');
if ($slug === '' || $json === '') { echo json_encode(['error' => 'Required fields missing.']); return; }
$manifest = json_decode($json, true);
if (!is_array($manifest)) { echo json_encode(['error' => 'Invalid JSON.']); return; }
$manifest['folder'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $manifest['folder'] ?? $slug);
$manifest['color_mode'] = in_array($manifest['color_mode'] ?? '', ['light', 'dark', 'both'], true) ? $manifest['color_mode'] : 'both';
echo json_encode(['success' => ThemeWorkspace::writeManifest($slug, $manifest)]);
