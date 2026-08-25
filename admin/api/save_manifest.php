<?php
declare(strict_types=1);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['error' => __('Method not allowed')], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !adiwira_csrf_validate($csrf)) adiwira_json(['error' => __('CSRF invalid')], 419);
$slug = trim((string)($_POST['theme'] ?? ''));
$json = (string)($_POST['manifest'] ?? '');
$expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
if ($slug === '' || $json === '') adiwira_json(['error' => 'Required fields missing.'], 400);
if (!ThemeWorkspace::isValidSlug($slug)) adiwira_json(['error' => 'Invalid theme slug.'], 400);
if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1) adiwira_json(['error' => 'Original manifest hash is required.'], 400);
try {
    $changes = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    adiwira_json(['error' => 'Invalid JSON.'], 400);
}
if (!is_array($changes)) adiwira_json(['error' => 'Invalid JSON.'], 400);
adiwira_json(ThemeWorkspace::writeManifest($slug, $changes, $expectedHash));
