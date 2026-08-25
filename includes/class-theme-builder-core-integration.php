<?php

declare(strict_types=1);

final class ThemeBuilderCoreIntegration
{
    private static bool $registered = false;
    private static ?self $instance = null;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function register(PDO $pdo): void
    {
        if (self::$registered) return;
        self::$registered = true;
        self::$instance = new self($pdo);
        add_action('theme_manager_theme_actions', [self::$instance, 'themeManagerActions'], 10, 3);
        add_filter('theme_update_preflight', [self::$instance, 'themeUpdatePreflight'], 10, 5);
        add_action('theme_update_completed', [self::$instance, 'themeUpdateCompleted'], 10, 4);
        add_action('theme_install_completed', [self::$instance, 'themeInstallCompleted'], 10, 2);
        add_filter('plugin_state_change_preflight', [self::$instance, 'pluginStateChangePreflight'], 10, 3);
    }

    public function themeManagerActions(array $themeRow, array $completeManifest, array $context): void
    {
        try {
            $folder = is_string($context['folder'] ?? null) ? $context['folder'] : (string)($themeRow['folder_name'] ?? '');
            if (!$this->validFolder($folder)) return;
            $base = $this->adminBase($context['admin_base_path'] ?? null);
            $installed = $base . '/?page=admin/tools/theme-builder/installed&theme=' . rawurlencode($folder);
            $fork = $base . '/?page=admin/tools/theme-builder/installed&fork=' . rawurlencode($folder);
            $export = $base . '/?action=api&page=admin/tools/theme-builder/api/export_theme_source';
            $service = new ThemeForkService($this->pdo);
            $dirty = $service->dirtyState($folder);
            $managed = $service->forkState($folder);
            $direct = $service->directEditState($folder);

            echo '<a class="tm-ghost" href="' . $this->escape($installed) . '">' . $this->escape($this->text('Inspect PHP')) . '</a>';
            if (!empty($managed['editable']) || !empty($direct['editable'])) {
                echo '<a class="tm-ghost" href="' . $this->escape($installed) . '">' . $this->escape($this->text('Edit PHP')) . '</a>';
            }
            echo '<a class="tm-ghost" href="' . $this->escape($fork) . '">' . $this->escape($this->text('Fork & Edit')) . '</a>';
            if (function_exists('csrf_token')) {
                echo '<form method="post" action="' . $this->escape($export) . '" style="display:inline;margin:0">'
                    . '<input type="hidden" name="csrf_token" value="' . $this->escape((string)csrf_token()) . '">'
                    . '<input type="hidden" name="theme" value="' . $this->escape($folder) . '">'
                    . '<button class="tm-ghost" type="submit">' . $this->escape($this->text('Export PHP Source')) . '</button></form>';
            }
            echo '<span class="tm-pill">' . $this->escape($this->statusLabel($dirty)) . '</span>';
        } catch (Throwable $error) {
            error_log('[theme-builder-theme-actions] ' . $error->getMessage());
        }
    }

    public function themeUpdatePreflight(array $state, string $folder, array $update, array $completeManifest, PDO $pdo): array
    {
        if (($state['schema'] ?? null) !== 1 || !is_array($state['issues'] ?? null)
            || !array_is_list($state['issues']) || !is_array($state['decisions'] ?? null)) {
            throw new RuntimeException('Theme Builder received malformed Core preflight state.');
        }

        $dirty = (new ThemeForkService($pdo))->dirtyState($folder);
        if (!$this->validDirtyState($dirty)) {
            $state['issues'][] = $this->hardBlockIssue();
            return $state;
        }
        if (($dirty['tracked'] ?? false) === true && ($dirty['locally_modified'] ?? false) === false) return $state;

        $tracked = (bool)$dirty['tracked'];
        $token = $this->preflightToken($folder, $dirty, $update, $completeManifest);
        $decision = $state['decisions']['theme-builder.php-source'] ?? null;
        $resolved = is_array($decision)
            && count($decision) === 2
            && ($decision['choice'] ?? null) === 'replace'
            && is_string($decision['state_token'] ?? null)
            && hash_equals($token, strtolower($decision['state_token']));
        $counts = $dirty['counts'];
        $phpCount = $tracked ? (int)$dirty['changed_count'] : (int)($counts['untracked'] ?? count($dirty['files']));
        $message = $tracked
            ? sprintf('%d physical PHP source file(s) differ from the protected baseline. This update replaces those PHP files; Theme Builder will not merge or reapply PHP.', $phpCount)
            : sprintf('%d physical PHP source file(s) have no protected baseline. This update can replace them; Theme Builder will not merge or reapply PHP.', $phpCount);
        $base = $this->adminBase(defined('ADMIN_BASE_PATH') ? (string)ADMIN_BASE_PATH : null);
        $state['issues'][] = [
            'id' => 'theme-builder.php-source',
            'label' => $tracked ? 'Locally Modified PHP Source' : 'Untracked PHP Source',
            'message' => $message,
            'blocking' => true,
            'resolved' => $resolved,
            'state_token' => $token,
            'choices' => [[
                'id' => 'replace',
                'label' => 'Replace local PHP source with the incoming version',
                'destructive' => true,
            ]],
            'links' => [
                [
                    'label' => 'Export PHP Source & Revisions',
                    'method' => 'POST',
                    'url' => $base . '/?action=api&page=admin/tools/theme-builder/api/export_theme_source',
                    'params' => ['theme' => $folder],
                ],
                [
                    'label' => 'Fork & Edit',
                    'method' => 'GET',
                    'url' => $base . '/?page=admin/tools/theme-builder/installed&fork=' . rawurlencode($folder),
                    'params' => [],
                ],
            ],
            'details' => [
                'scope' => 'physical_php_only',
                'php_files' => $phpCount,
                'modified_php' => (int)($counts['modified'] ?? 0),
                'added_php' => (int)($counts['added'] ?? 0),
                'deleted_php' => (int)($counts['deleted'] ?? 0),
                'baseline_id' => $tracked ? (string)$dirty['baseline_id'] : 'untracked',
                'baseline_version' => $tracked ? (string)($dirty['baseline_version'] ?? '') : 'untracked',
                'current_version' => (string)($completeManifest['version'] ?? $dirty['current_version'] ?? ''),
                'incoming_version' => (string)($update['new_version'] ?? ''),
                'incoming_checksum' => strtolower((string)($update['checksum'] ?? '')),
            ],
        ];
        return $state;
    }

    public function themeUpdateCompleted(string $folder, string $oldVersion, string $newVersion, array $completeManifest): void
    {
        $this->refreshCompletionBaseline($folder, 'core_update');
    }

    public function themeInstallCompleted(string $folder, array $completeManifest): void
    {
        $this->refreshCompletionBaseline($folder, 'core_install');
    }

    public function pluginStateChangePreflight(array $state, string $name, string $operation): array
    {
        $allowed = is_bool($state['allowed'] ?? null) ? $state['allowed'] : false;
        $message = is_string($state['message'] ?? null) ? $this->boundedMessage($state['message']) : '';
        if ($name !== 'theme-builder' || !in_array($operation, ['disable', 'delete'], true)) {
            return ['allowed' => $allowed, 'message' => $message];
        }
        if (!$allowed) {
            return ['allowed' => false, 'message' => $message !== '' ? $message : $this->lifecycleBlockedMessage()];
        }

        try {
            $database = $this->pdo;
            $rows = $database->query('SELECT folder_name, store_url, store_slug FROM themes ORDER BY folder_name')->fetchAll(PDO::FETCH_ASSOC);
            $service = new ThemeForkService($database);
            foreach ($rows as $row) {
                $folder = is_string($row['folder_name'] ?? null) ? $row['folder_name'] : '';
                $storeManaged = trim((string)($row['store_url'] ?? '')) !== '' || trim((string)($row['store_slug'] ?? '')) !== '';
                if (!$storeManaged && $this->validFolder($folder)) {
                    $storeManaged = !empty($service->directEditState($folder)['store']);
                }
                if (!$storeManaged) continue;
                $dirty = $service->dirtyState($folder);
                if (!$this->validDirtyState($dirty) || empty($dirty['tracked']) || !empty($dirty['locally_modified'])) {
                    return ['allowed' => false, 'message' => $this->lifecycleBlockedMessage()];
                }
            }
            return ['allowed' => true, 'message' => ''];
        } catch (Throwable $error) {
            error_log('[theme-builder-plugin-state-preflight] ' . $error->getMessage());
            return ['allowed' => false, 'message' => $this->lifecycleBlockedMessage()];
        }
    }

    private function refreshCompletionBaseline(string $folder, string $origin): void
    {
        try {
            (new ThemeForkService($this->pdo))->refreshBaseline($folder, $origin, $this->actorId());
        } catch (Throwable $error) {
            error_log('[theme-builder-baseline-refresh] ' . $error->getMessage());
            throw $error;
        }
    }

    private function validDirtyState(array $state): bool
    {
        if (isset($state['error']) || !is_bool($state['tracked'] ?? null) || !is_bool($state['locally_modified'] ?? null)
            || !is_int($state['changed_count'] ?? null) || $state['changed_count'] < 0
            || !is_array($state['counts'] ?? null) || !is_array($state['files'] ?? null)
            || (int)($state['registered_theme_id'] ?? 0) < 1
            || preg_match('/\A\d+\z/D', (string)($state['root_identity']['dev'] ?? '')) !== 1
            || preg_match('/\A\d+\z/D', (string)($state['root_identity']['ino'] ?? '')) !== 1) return false;
        if ($state['tracked'] && (!is_string($state['baseline_id'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', $state['baseline_id']) !== 1)) return false;
        if ($state['tracked'] && !$state['locally_modified'] && $state['changed_count'] !== 0) return false;
        if ($state['tracked'] && $state['locally_modified'] && $state['changed_count'] < 1) return false;
        if (!$state['tracked'] && ($state['locally_modified'] || $state['changed_count'] !== 0)) return false;
        foreach ($state['counts'] as $count) if (!is_int($count) || $count < 0) return false;
        if ($state['tracked'] && $state['changed_count'] !== (int)($state['counts']['modified'] ?? -1)
            + (int)($state['counts']['added'] ?? -1) + (int)($state['counts']['deleted'] ?? -1)) return false;
        $actualCounts = [];
        foreach ($state['files'] as $path => $file) {
            if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')
                || str_starts_with($path, '/') || !str_ends_with(strtolower($path), '.php')
                || in_array('', explode('/', $path), true) || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)
                || !is_array($file) || !in_array($file['status'] ?? null, ['clean', 'modified', 'added', 'deleted', 'untracked'], true)
                || (($file['baseline_sha256'] ?? null) !== null && preg_match('/\A[a-f0-9]{64}\z/D', (string)$file['baseline_sha256']) !== 1)
                || (($file['current_sha256'] ?? null) !== null && preg_match('/\A[a-f0-9]{64}\z/D', (string)$file['current_sha256']) !== 1)) return false;
            $status = (string)$file['status'];
            if (!$state['tracked'] && (($file['status'] ?? null) !== 'untracked' || ($file['baseline_sha256'] ?? null) !== null
                || !is_string($file['current_sha256'] ?? null))) return false;
            if ($state['tracked'] && ($status === 'untracked'
                || (in_array($status, ['clean', 'modified'], true) && (!is_string($file['baseline_sha256'] ?? null) || !is_string($file['current_sha256'] ?? null)))
                || ($status === 'added' && (($file['baseline_sha256'] ?? null) !== null || !is_string($file['current_sha256'] ?? null)))
                || ($status === 'deleted' && (!is_string($file['baseline_sha256'] ?? null) || ($file['current_sha256'] ?? null) !== null)))) return false;
            $actualCounts[$status] = ($actualCounts[$status] ?? 0) + 1;
        }
        if (!$state['tracked'] && (int)($state['counts']['untracked'] ?? -1) !== count($state['files'])) return false;
        if ($state['tracked']) {
            foreach (['clean', 'modified', 'added', 'deleted'] as $status) {
                if ((int)($state['counts'][$status] ?? -1) !== (int)($actualCounts[$status] ?? 0)) return false;
            }
        }
        return true;
    }

    private function hardBlockIssue(): array
    {
        return [
            'id' => 'theme-builder.php-source',
            'label' => 'PHP Source State Unresolved',
            'message' => 'Theme Builder could not safely verify the physical PHP source state. The update is blocked without a destructive override choice.',
            'blocking' => true,
            'resolved' => false,
            'choices' => [],
            'links' => [],
            'details' => ['scope' => 'physical_php_only'],
        ];
    }

    private function preflightToken(string $folder, array $dirty, array $update, array $manifest): string
    {
        $files = [];
        foreach ($dirty['files'] as $path => $file) {
            $files[$path] = [
                'status' => (string)$file['status'],
                'baseline_sha256' => $file['baseline_sha256'],
                'current_sha256' => $file['current_sha256'],
            ];
        }
        ksort($files, SORT_STRING);
        $identity = [
            'contract' => 'theme-builder-update-replace-v1',
            'folder' => $folder,
            'registered_theme_id' => (int)$dirty['registered_theme_id'],
            'root_identity' => [
                'dev' => (string)$dirty['root_identity']['dev'],
                'ino' => (string)$dirty['root_identity']['ino'],
            ],
            'baseline_id' => $dirty['tracked'] ? (string)$dirty['baseline_id'] : 'untracked',
            'php_files' => $files,
            'versions' => [
                'baseline' => $dirty['tracked'] ? (string)($dirty['baseline_version'] ?? '') : 'untracked',
                'current' => (string)($manifest['version'] ?? $dirty['current_version'] ?? ''),
                'incoming' => (string)($update['new_version'] ?? ''),
            ],
            'incoming_checksum' => strtolower((string)($update['checksum'] ?? '')),
        ];
        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function statusLabel(array $dirty): string
    {
        if (isset($dirty['error'])) return $this->text('PHP Source Error');
        if (!empty($dirty['locally_modified'])) return sprintf($this->text('PHP Modified: %d'), (int)$dirty['changed_count']);
        if (!empty($dirty['tracked'])) return $this->text('PHP Source Clean');
        return sprintf($this->text('PHP Untracked: %d'), (int)($dirty['counts']['untracked'] ?? 0));
    }

    private function actorId(): int
    {
        if (function_exists('current_user_id')) return max(0, (int)current_user_id());
        return max(0, (int)($_SESSION['user_id'] ?? 0));
    }

    private function lifecycleBlockedMessage(): string
    {
        return $this->boundedMessage($this->text('Theme Builder cannot be disabled or deleted while registered Store theme PHP source is untracked, modified, or unresolved. Export or fork the source and restore clean tracking first.'));
    }

    private function boundedMessage(string $message): string
    {
        $message = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', trim($message));
        return strlen($message) <= 1000 ? $message : substr($message, 0, 1000);
    }

    private function adminBase(mixed $base): string
    {
        $base = is_string($base) ? rtrim($base, '/') : '';
        return $base !== '' && str_starts_with($base, '/') && !str_starts_with($base, '//') ? $base : '/adiwira';
    }

    private function validFolder(string $folder): bool
    {
        return strlen($folder) <= 128
            && preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) === 1
            && !in_array($folder, ['.', '..'], true);
    }

    private function text(string $text): string
    {
        return function_exists('__') ? (string)__($text) : $text;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
