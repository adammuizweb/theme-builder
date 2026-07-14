<?php
declare(strict_types=1);
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { echo json_encode(['error' => 'POST required']); exit; }
$slug = trim((string)($_POST['theme'] ?? ''));
$slot = trim((string)($_POST['slot'] ?? ''));
$content = (string)($_POST['content'] ?? '');
if ($slug === '') { echo json_encode(['error' => 'Theme required.']); exit; }
if ($slot === '_asset') {
    $path = trim((string)($_POST['asset_path'] ?? ''));
    if ($path === '') { echo json_encode(['error' => 'Asset path required.']); exit; }
    echo json_encode(['success' => ThemeWorkspace::writeAsset($slug, $path, $content)]);
    exit;
}
if ($slot === '') { echo json_encode(['error' => 'Slot required.']); exit; }
echo json_encode(['success' => ThemeWorkspace::writeFile($slug, $slot, $content)]);
exit;
