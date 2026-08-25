<?php
declare(strict_types=1);

adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo 'POST required.';
    return;
}
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) {
    http_response_code(419);
    echo 'CSRF invalid.';
    return;
}
$folder = $_POST['theme'] ?? null;
if (!is_string($folder) || strlen($folder) > 128
    || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) !== 1
    || in_array($folder, ['.', '..'], true)
    || isset($_POST['path']) || isset($_POST['file']) || isset($_POST['file_id'])) {
    http_response_code(400);
    echo 'An exact registered theme is required.';
    return;
}

$actorId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$service = new ThemeForkService($pdo);
$export = null;
$handle = null;
try {
    $export = $service->buildPhpSourceExport($folder, $actorId);
    $path = (string)$export['path'];
    $before = @lstat($path);
    $handle = @fopen($path, 'rb');
    $opened = is_resource($handle) ? fstat($handle) : false;
    if (!is_array($before) || !is_array($opened) || !is_resource($handle) || is_link($path)
        || (($opened['mode'] & 0170000) !== 0100000) || (($opened['mode'] & 0077) !== 0)
        || (int)$opened['dev'] !== (int)$before['dev'] || (int)$opened['ino'] !== (int)$before['ino']
        || (int)$opened['size'] !== (int)$export['size']) {
        throw new RuntimeException('Source export is unavailable.');
    }
    $hash = hash_init('sha256');
    while (!feof($handle)) {
        $chunk = fread($handle, 65536);
        if ($chunk === false) throw new RuntimeException('Source export verification failed.');
        if ($chunk !== '') hash_update($hash, $chunk);
    }
    $afterRead = fstat($handle);
    clearstatcache(true, $path);
    $after = @lstat($path);
    $verifiedHash = hash_final($hash);
    if (!is_array($afterRead) || !is_array($after)
        || (int)$opened['dev'] !== (int)$afterRead['dev'] || (int)$opened['ino'] !== (int)$afterRead['ino']
        || (int)$opened['size'] !== (int)$afterRead['size']
        || (int)$afterRead['dev'] !== (int)$after['dev'] || (int)$afterRead['ino'] !== (int)$after['ino']
        || !hash_equals((string)$export['sha256'], $verifiedHash) || fseek($handle, 0) !== 0) {
        throw new RuntimeException('Source export verification failed.');
    }

    header('Content-Type: application/zip');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . (string)$export['download_name'] . '"');
    header('Content-Length: ' . (int)$export['size']);
    if (fpassthru($handle) === false) throw new RuntimeException('Source export streaming failed.');
} catch (Throwable $error) {
    error_log('[theme-builder-source-export] ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo 'Unable to export theme PHP source safely.';
    }
} finally {
    if (is_resource($handle)) fclose($handle);
    if (is_array($export) && is_string($export['path'] ?? null)) {
        if (!$service->cleanupPhpSourceExport($export['path'])) {
            error_log('[theme-builder-source-export] Could not remove the streamed private source export.');
        }
    }
}
