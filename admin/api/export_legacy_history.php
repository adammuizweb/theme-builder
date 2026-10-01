<?php
declare(strict_types=1);

[$actorId] = adiwira_require_permission($pdo, 'core.themes.manage', true);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);

$service = new ThemeBuilderLegacyHistory();
$descriptor = null;
$handle = null;
$failure = null;
try {
    $descriptor = $service->buildExport((int)$actorId);
    $handle = $service->openExport($descriptor);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode((string)$descriptor['download_name']) . '"');
    header('Content-Length: ' . (int)$descriptor['size']);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (fpassthru($handle) === false) throw new RuntimeException('Legacy history export streaming failed.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (is_resource($handle)) fclose($handle);
    if (is_array($descriptor) && !$service->cleanupExport($descriptor)) {
        error_log('[theme-builder-legacy-export] Failed export cleanup requires operator attention.');
    }
}
if ($failure !== null) {
    error_log('[theme-builder-legacy-export] ' . $failure->getMessage());
    if (!headers_sent()) adiwira_json(['error' => __('Legacy Theme Builder history could not be exported.')], 422);
}
