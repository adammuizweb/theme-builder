<?php
declare(strict_types=1);

adiwira_require_site_owner($pdo, false);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') adiwira_json(['error' => __('Method not allowed')], 405);

$folder = trim((string)($_GET['theme'] ?? ''));
$fileId = trim((string)($_GET['file_id'] ?? ''));
if ($folder === '' || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1) {
    adiwira_json(['success' => false, 'code' => 'invalid_request', 'error' => __('Theme and opaque file ID are required.')], 400);
}

$records = (new ThemeForkService($pdo))->revisions($folder, $fileId, 20);
adiwira_json(['success' => true, 'revisions' => $records]);
