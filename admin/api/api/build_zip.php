<?php
declare(strict_types=1);
header('Content-Type: application/json');
$slug = trim((string)($_GET['theme'] ?? $_POST['theme'] ?? ''));
if ($slug === '') { echo json_encode(['error' => 'Theme required.']); return; }
$zipPath = ThemeWorkspace::buildZip($slug);
if (!$zipPath) { echo json_encode(['error' => 'Build failed.']); return; }
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$adminPath = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
echo json_encode(['success' => true, 'zip_size' => filesize($zipPath), 'download_url' => $baseUrl . $adminPath . '/?page=admin/tools/theme-builder/api/download_zip&theme=' . rawurlencode($slug)]);
