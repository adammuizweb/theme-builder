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
        add_action('theme_source_editor_actions', [self::$instance, 'themeSourceEditorActions'], 10);
        add_action('theme_manager_theme_actions', [self::$instance, 'themeManagerThemeActions'], 10);
        add_filter('theme_source_edit_policy', [self::$instance, 'themeSourceEditPolicy'], 10);
    }

    public function themeSourceEditorActions(array $themeRow, array $context, PDO $pdo): void
    {
        try {
            $folder = is_string($context['folder'] ?? null)
                ? $context['folder']
                : (string)($themeRow['folder_name'] ?? '');
            if (!$this->validFolder($folder)) return;

            $base = $this->adminBase($context['admin_base_path'] ?? null);
            $fork = $base . '/?page=admin/tools/theme-builder/installed&fork=' . rawurlencode($folder);
            $owners = $base . '/?page=admin/tools/theme-builder/installed&theme=' . rawurlencode($folder);
            echo '<a class="tm-ghost tb-core-source-action" href="' . $this->escape($fork) . '">' . $this->escape($this->text('Fork & Edit')) . '</a>';
            echo '<a class="tm-ghost tb-core-source-action" href="' . $this->escape($owners) . '">' . $this->escape($this->text('Owner Workspaces')) . '</a>';
        } catch (Throwable $error) {
            error_log('[theme-builder-source-actions] ' . $error->getMessage());
        }
    }

    public function themeManagerThemeActions(array $themeRow, array $manifest, array $context): void
    {
        try {
            $folder = is_string($context['folder'] ?? null)
                ? $context['folder']
                : (string)($themeRow['folder_name'] ?? '');
            if (!$this->validFolder($folder)) return;

            $base = $this->adminBase($context['admin_base_path'] ?? null);
            $fork = $base . '/?page=admin/tools/theme-builder/installed&fork=' . rawurlencode($folder);
            $owners = $base . '/?page=admin/tools/theme-builder/installed&theme=' . rawurlencode($folder);
            echo '<div class="tm-action-group tm-action-group--theme-builder">';
            echo '<span class="tm-action-owner tb-action-owner">' . $this->escape($this->text('Theme Builder')) . '</span>';
            echo '<a class="tm-action tb-theme-builder-action tb-theme-builder-action--primary" href="' . $this->escape($fork) . '">' . $this->escape($this->text('Fork & Edit')) . '</a>';
            echo '<a class="tm-action tb-theme-builder-action" href="' . $this->escape($owners) . '">' . $this->escape($this->text('Owner Workspaces')) . '</a>';
            echo '</div>';
        } catch (Throwable $error) {
            error_log('[theme-builder-manager-actions] ' . $error->getMessage());
        }
    }

    public function themeSourceEditPolicy(array $state, array $themeRow, array $context, PDO $pdo): array
    {
        $allowed = ($state['allowed'] ?? null) === true;
        $message = is_string($state['message'] ?? null) ? $this->boundedMessage($state['message']) : '';
        if (!$allowed) return ['allowed' => false, 'message' => $message];

        $operation = $context['operation'] ?? null;
        $folder = is_string($context['folder'] ?? null)
            ? $context['folder']
            : (string)($themeRow['folder_name'] ?? '');
        if (!in_array($operation, ['edit', 'save', 'restore'], true) || !$this->validFolder($folder)) {
            return ['allowed' => true, 'message' => ''];
        }

        try {
            $policy = (new ThemeForkService($pdo))->managedEditPolicy($folder);
            if ($policy === null || $policy['allowed']) return ['allowed' => true, 'message' => ''];
            return ['allowed' => false, 'message' => $this->boundedMessage((string)$policy['message'])];
        } catch (Throwable $error) {
            error_log('[theme-builder-source-policy] ' . $error->getMessage());
            return ['allowed' => false, 'message' => $this->text('Theme Builder could not verify managed-fork provenance. Editing is blocked.')];
        }
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

    private function boundedMessage(string $message): string
    {
        $message = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', trim($message));
        return strlen($message) <= 1000 ? $message : substr($message, 0, 1000);
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
