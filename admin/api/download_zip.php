<?php
declare(strict_types=1);
$slug = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($_GET['theme'] ?? '')));
if ($slug === '') { http_response_code(400); echo 'Theme required.'; exit; }
$zipPath = ThemeWorkspace::baseDir() . '/' . $slug . '.zip';
if (!is_file($zipPath)) { http_response_code(404); echo 'ZIP not found.'; exit; }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($slug) . '.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
exit;
