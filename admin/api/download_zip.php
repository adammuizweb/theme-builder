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
if ($slug === '') { http_response_code(400); echo 'Theme required.'; return; }
if (!ThemeWorkspace::isValidSlug($slug)) { http_response_code(400); echo 'Invalid theme slug.'; return; }
$artifact = ThemeWorkspace::openArtifact($slug);
if (!is_array($artifact) || !is_resource($artifact['handle'] ?? null)) { http_response_code(404); echo 'ZIP not found or stale.'; return; }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($slug) . '.zip"');
header('Content-Length: ' . $artifact['size']);
fpassthru($artifact['handle']);
fclose($artifact['handle']);
