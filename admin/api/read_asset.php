<?php
declare(strict_types=1);

adiwira_require_site_owner($pdo, false);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'GET required.';
    return;
}

$slug = trim((string)($_GET['theme'] ?? ''));
if ($slug === '' || !ThemeWorkspace::isValidSlug($slug)) {
    http_response_code(400);
    echo 'Invalid theme slug.';
    return;
}

$asset = trim((string)($_GET['asset'] ?? ''));
if ($asset === '') {
    http_response_code(400);
    echo 'Asset required.';
    return;
}

$state = ThemeWorkspace::readAssetState($slug, $asset);
if (!is_array($state)) {
    http_response_code(404);
    echo 'Asset not found.';
    return;
}

header('X-Theme-Builder-SHA256: ' . $state['sha256']);
echo $state['content'];
