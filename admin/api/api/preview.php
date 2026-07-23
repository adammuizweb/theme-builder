<?php
declare(strict_types=1);
$slug = trim((string)($_GET['theme'] ?? ''));
if ($slug === '') { echo '<p>Theme required.</p>'; return; }
$themeDir = ThemeWorkspace::themeDir($slug);
if (!is_dir($themeDir)) { echo '<p>Theme not found.</p>'; return; }
$asset = trim((string)($_GET['asset'] ?? ''));
if ($asset !== '') { header('Content-Type: text/plain'); echo ThemeWorkspace::readAsset($slug, $asset) ?? ''; return; }
$slot = trim((string)($_GET['slot'] ?? 'main.homepage'));
header('Content-Type: text/html; charset=utf-8');
echo !empty($_GET['full']) ? PreviewRenderer::renderFullPage($themeDir, $slot) : PreviewRenderer::render($themeDir, $slot);
