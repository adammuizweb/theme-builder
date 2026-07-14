<?php
declare(strict_types=1);
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { echo json_encode(['error' => 'POST required']); exit; }
$slug = trim((string)($_POST['slug'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$author = trim((string)($_POST['author'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$colorMode = trim((string)($_POST['color_mode'] ?? 'both'));
if ($slug === '' || $name === '') { echo json_encode(['error' => 'Slug and name required.']); exit; }
echo json_encode(ThemeWorkspace::createTheme($slug, $name, $author, $description, $colorMode));
exit;
