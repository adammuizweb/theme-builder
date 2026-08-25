<?php
declare(strict_types=1);
adiwira_require_site_owner($pdo, false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'GET required.';
    return;
}
$slug = trim((string)($_GET['theme'] ?? ''));
if ($slug === '') { echo '<p>Theme required.</p>'; return; }
if (!ThemeWorkspace::isValidSlug($slug)) { http_response_code(400); echo 'Invalid theme slug.'; return; }
$asset = trim((string)($_GET['asset'] ?? ''));
if ($asset !== '') {
    $state = ThemeWorkspace::readAssetState($slug, $asset);
    if (!is_array($state)) { http_response_code(404); echo 'Asset not found.'; return; }
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Theme-Builder-SHA256: ' . $state['sha256']);
    echo $state['content'];
    return;
}
http_response_code(501);
header('Content-Type: text/plain; charset=utf-8');
echo 'PHP preview is disabled until an isolated preview runtime is available.';
