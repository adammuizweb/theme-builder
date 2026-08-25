<?php
declare(strict_types=1);

adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);

$folder = trim((string)($_POST['theme'] ?? ''));
$fileId = trim((string)($_POST['file_id'] ?? ''));
$expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
$content = $_POST['content'] ?? null;
$note = trim((string)($_POST['change_note'] ?? ''));
if ($folder === '' || !is_string($content) || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1
    || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1) {
    adiwira_json(['error' => __('Theme, opaque file ID, original hash, and source are required.')], 400);
}

$actorId = function_exists('current_user_id') ? current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$service = new ThemeForkService($pdo);
$result = $service->savePhp($folder, $fileId, $content, $expectedHash, $actorId, $note);
$status = ($result['success'] ?? false) ? 200 : (str_contains((string)($result['error'] ?? ''), 'Reload') ? 409 : 422);
adiwira_json($result, $status);
