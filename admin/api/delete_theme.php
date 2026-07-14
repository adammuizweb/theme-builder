<?php
declare(strict_types=1);
header('Content-Type: application/json');
$slug = trim((string)($_GET['theme'] ?? $_POST['theme'] ?? ''));
if ($slug === '') { echo json_encode(['error' => 'Theme required.']); exit; }
echo json_encode(['success' => ThemeWorkspace::deleteTheme($slug)]);
exit;
