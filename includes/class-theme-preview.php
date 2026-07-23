<?php
declare(strict_types=1);

/**
 * Theme Builder — Live Site Preview helper.
 *
 * Provides a safe sandbox for admins to preview a draft theme using real
 * site data. The draft theme is synced to a temporary folder under the
 * public views directory so the core theme resolver can load it.
 */
class ThemePreview
{
    /** Prefix for temporary preview theme folders. */
    public const PREVIEW_PREFIX = '__tb_preview_';

    /** Session key for preview token verification. */
    public const TOKEN_KEY = '__tb_preview_token';

    /** Cookie name that carries the preview theme slug. */
    public const COOKIE_NAME = 'tb_preview_theme';

    /**
     * Return the public views base directory.
     */
    public static function viewsBase(): string
    {
        return defined('VIEWS_BASE')
            ? VIEWS_BASE
            : (defined('PUBLIC_PATH') ? rtrim(PUBLIC_PATH, '/\\') . '/views/themes' : dirname(__DIR__, 3) . '/public/views/themes');
    }

    /**
     * Return the temporary preview folder path for a draft theme slug.
     */
    public static function previewFolder(string $slug): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
        return rtrim(self::viewsBase(), '/\\') . '/' . self::PREVIEW_PREFIX . $slug;
    }

    /**
     * Return the preview theme folder name (basename) used by core resolver.
     */
    public static function previewFolderName(string $slug): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
        return self::PREVIEW_PREFIX . $slug;
    }

    /**
     * Sync draft theme files to the temporary preview folder.
     */
    public static function sync(string $slug): array
    {
        $draftDir = ThemeWorkspace::themeDir($slug);
        if (!is_dir($draftDir)) {
            return ['error' => __('Draft theme not found.')];
        }

        $previewDir = self::previewFolder($slug);

        // Remove stale preview folder to ensure a clean sync.
        if (is_dir($previewDir)) {
            self::rrmdir($previewDir);
        }

        if (!@mkdir($previewDir, 0755, true) && !is_dir($previewDir)) {
            return ['error' => __('Unable to create preview folder.')];
        }

        self::copyDir($draftDir, $previewDir);

        return ['success' => true, 'folder' => $previewDir];
    }

    /**
     * Clean up the temporary preview folder for a slug.
     */
    public static function cleanup(string $slug): bool
    {
        $dir = self::previewFolder($slug);
        if (is_dir($dir)) {
            self::rrmdir($dir);
            return true;
        }
        return false;
    }

    /**
     * Generate and store a preview token tied to the admin session.
     */
    public static function createToken(string $slug): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::TOKEN_KEY] = [
            'token' => $token,
            'slug' => $slug,
            'created' => time(),
        ];
        return $token;
    }

    /**
     * Validate a preview token against the session.
     */
    public static function validateToken(?string $token, ?string $slug): bool
    {
        if (!$token || !$slug) return false;
        $stored = $_SESSION[self::TOKEN_KEY] ?? null;
        if (!$stored || !is_array($stored)) return false;
        if (($stored['token'] ?? '') !== $token) return false;
        if (($stored['slug'] ?? '') !== $slug) return false;
        // Tokens expire after 2 hours of inactivity.
        if (time() - ($stored['created'] ?? 0) > 7200) {
            unset($_SESSION[self::TOKEN_KEY]);
            return false;
        }
        // Refresh created time on successful validation.
        $_SESSION[self::TOKEN_KEY]['created'] = time();
        return true;
    }

    /**
     * Clear the preview token from the session.
     */
    public static function clearToken(): void
    {
        unset($_SESSION[self::TOKEN_KEY]);
    }

    /**
     * Check if the current admin request should use live preview.
     * Returns the preview theme slug or null.
     */
    public static function currentPreviewSlug(): ?string
    {
        $token = $_GET['tb_preview_token'] ?? $_COOKIE['tb_preview_token'] ?? null;
        $slug = $_GET['tb_preview_theme'] ?? $_COOKIE['tb_preview_theme'] ?? null;
        if (!$token || !$slug) return null;
        if (!self::validateToken($token, $slug)) return null;
        return $slug;
    }

    /**
     * Register the core filter so preview theme is used for slot resolution.
     * Requires a small core hook in resolve_template().
     */
    public static function registerCoreFilter(): void
    {
        if (!function_exists('add_filter')) return;

        // Override active theme folder so assets, theme mods, and color mode
        // also load from the preview theme.
        add_filter('active_theme_folder', function ($folder, ?PDO $pdo) {
            $slug = self::currentPreviewSlug();
            if (!$slug) return $folder;
            return self::previewFolderName($slug);
        }, 10, 2);

        // Override slot template resolution to use the preview theme files.
        add_filter('resolve_template', function ($resolved, string $slot_key, ?PDO $pdo) {
            $slug = self::currentPreviewSlug();
            if (!$slug) return $resolved;

            $previewFolder = self::previewFolderName($slug);
            $file = slot_to_file($slot_key);

            return [
                'type' => 'theme_file',
                'theme_folder' => $previewFolder,
                'theme_file' => $file,
                '__tb_preview' => true,
            ];
        }, 10, 3);
    }

    private static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) @mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) @mkdir($target, 0755, true);
            } else {
                $td = dirname($target);
                if (!is_dir($td)) @mkdir($td, 0755, true);
                @copy($item->getRealPath(), $target);
            }
        }
    }

    private static function rrmdir(string $dir): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) @rmdir($file->getRealPath());
            else @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }
}
