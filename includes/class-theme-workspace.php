<?php
declare(strict_types=1);

class ThemeWorkspace
{
    private const SLOT_FILES = [
        'header'         => 'header.php',
        'footer'         => 'footer.php',
        'sidebar'        => 'sidebar.php',
        'main.homepage'  => 'main/homepage.php',
        'main.search'    => 'main/search.php',
        'main.404'       => 'main/404.php',
        'list.post'      => 'main/list/post.php',
        'list.page'      => 'main/list/page.php',
        'list.category'  => 'main/list/category.php',
        'list.archive'   => 'main/list/archive.php',
        'list.author'    => 'main/list/author.php',
        'single.post'    => 'main/single/post.php',
        'single.page'    => 'main/single/page.php',
        'index.category' => 'main/index/category.php',
        'index.author'   => 'main/index/author.php',
    ];

    private const SLOT_LABELS = [
        'header'         => 'Header',
        'footer'         => 'Footer',
        'sidebar'        => 'Sidebar',
        'main.homepage'  => 'Homepage',
        'main.search'    => 'Search Results',
        'main.404'       => '404 — Not Found',
        'list.post'      => 'List — Posts',
        'list.page'      => 'List — Pages',
        'list.category'  => 'List — Category',
        'list.archive'   => 'List — Archive',
        'list.author'    => 'List — Author',
        'single.post'    => 'Single — Post',
        'single.page'    => 'Single — Page',
        'index.category' => 'Index — Categories',
        'index.author'   => 'Index — Authors',
    ];

    public static function baseDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/cfg/var/theme-builder';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    public static function themeDir(string $slug): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
        return self::baseDir() . '/' . $slug;
    }

    public static function slotFiles(): array { return self::SLOT_FILES; }
    public static function slotLabels(): array { return self::SLOT_LABELS; }

    public static function listThemes(): array
    {
        $base = self::baseDir();
        $themes = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            $slug = basename($dir);
            $manifestFile = $dir . '/theme.json';
            $manifest = is_file($manifestFile) ? (json_decode((string)file_get_contents($manifestFile), true) ?: []) : [];
            $themes[] = [
                'slug' => $slug,
                'name' => $manifest['name'] ?? $slug,
                'version' => $manifest['version'] ?? '0.1.0',
                'author' => $manifest['author'] ?? '',
                'description' => $manifest['description'] ?? '',
                'color_mode' => $manifest['color_mode'] ?? 'both',
                'dir' => $dir,
                'modified' => filemtime($dir),
                'files' => self::countFiles($dir),
                'complete' => self::isComplete($dir),
            ];
        }
        usort($themes, fn($a, $b) => $b['modified'] - $a['modified']);
        return $themes;
    }

    public static function countFiles(string $dir): int
    {
        $count = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) { if ($f->isFile()) $count++; }
        return $count;
    }

    public static function isComplete(string $dir): bool
    {
        if (!is_file($dir . '/theme.json')) return false;
        foreach (self::SLOT_FILES as $file) {
            if (!is_file($dir . '/' . $file)) return false;
        }
        return true;
    }

    public static function completionStatus(string $dir): array
    {
        $status = [];
        foreach (self::SLOT_FILES as $slot => $file) {
            $path = $dir . '/' . $file;
            $status[$slot] = [
                'file' => $file,
                'label' => self::SLOT_LABELS[$slot] ?? $slot,
                'exists' => is_file($path),
                'size' => is_file($path) ? filesize($path) : 0,
                'lines' => is_file($path) ? count(file($path)) : 0,
            ];
        }
        return $status;
    }

    public static function createTheme(string $slug, string $name, string $author, string $description = '', string $colorMode = 'both'): array
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
        if ($slug === '') return ['error' => 'Invalid slug.'];
        $dir = self::themeDir($slug);
        if (is_dir($dir)) return ['error' => 'Theme already exists.'];

        $dirs = ['', 'main', 'main/list', 'main/single', 'main/index', 'assets', 'assets/css', 'assets/js'];
        foreach ($dirs as $d) @mkdir($dir . '/' . $d, 0755, true);

        $starterDir = dirname(__DIR__) . '/templates/starter';
        self::copyDir($starterDir, $dir);

        $manifest = [
            'folder' => $slug, 'name' => $name, 'description' => $description,
            'version' => '0.1.0', 'author' => $author, 'screenshot' => 'img.png',
            'color_mode' => in_array($colorMode, ['light', 'dark', 'both'], true) ? $colorMode : 'both',
            'styles' => ['assets/css/style.css', 'assets/css/blocks.css'],
            'scripts' => ['assets/js/script.js'],
        ];
        file_put_contents($dir . '/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ['success' => true, 'slug' => $slug, 'dir' => $dir];
    }

    public static function deleteTheme(string $slug): bool
    {
        $dir = self::themeDir($slug);
        if (!is_dir($dir)) return false;
        self::rrmdir($dir);
        return true;
    }

    public static function readFile(string $slug, string $slot): ?string
    {
        $file = self::SLOT_FILES[$slot] ?? null;
        if (!$file) return null;
        $path = self::themeDir($slug) . '/' . $file;
        return is_file($path) ? (string)file_get_contents($path) : null;
    }

    public static function writeFile(string $slug, string $slot, string $content): bool
    {
        $file = self::SLOT_FILES[$slot] ?? null;
        if (!$file) return false;
        $path = self::themeDir($slug) . '/' . $file;
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return file_put_contents($path, $content) !== false;
    }

    public static function readAsset(string $slug, string $path): ?string
    {
        $path = str_replace(['..', "\0"], '', $path);
        $path = ltrim($path, '/');
        $full = self::themeDir($slug) . '/' . $path;
        $real = realpath($full);
        $base = realpath(self::themeDir($slug));
        if (!$real || !$base || strpos($real, $base) !== 0) return null;
        return is_file($real) ? (string)file_get_contents($real) : null;
    }

    public static function writeAsset(string $slug, string $path, string $content): bool
    {
        $path = str_replace(['..', "\0"], '', $path);
        $path = ltrim($path, '/');
        $full = self::themeDir($slug) . '/' . $path;
        $dir = dirname($full);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return file_put_contents($full, $content) !== false;
    }

    public static function readManifest(string $slug): array
    {
        $path = self::themeDir($slug) . '/theme.json';
        return is_file($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
    }

    public static function writeManifest(string $slug, array $manifest): bool
    {
        return file_put_contents(self::themeDir($slug) . '/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    public static function buildZip(string $slug): ?string
    {
        $dir = self::themeDir($slug);
        if (!is_dir($dir)) return null;
        $zipPath = self::baseDir() . '/' . $slug . '.zip';
        if (is_file($zipPath)) @unlink($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) return null;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $zip->addFile($file->getRealPath(), substr($file->getRealPath(), strlen($dir) + 1));
        }
        $zip->close();
        return is_file($zipPath) ? $zipPath : null;
    }

    public static function installTheme(string $slug): array
    {
        $dir = self::themeDir($slug);
        if (!is_dir($dir)) return ['error' => 'Theme not found.'];
        $targetBase = dirname(__DIR__, 3) . '/public/views/themes';
        $target = $targetBase . '/' . $slug;
        if (is_dir($target)) return ['error' => 'Theme "' . $slug . '" already installed. Delete it first.'];
        self::copyDir($dir, $target);
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO && function_exists('register_all_themes_from_fs')) {
            try { register_all_themes_from_fs($pdo); } catch (Throwable $e) {}
        }
        return ['success' => true, 'path' => $target];
    }

    private static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) @mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $dst . '/' . $it->getSubPathName();
            if ($item->isDir()) { if (!is_dir($target)) @mkdir($target, 0755, true); }
            else { $td = dirname($target); if (!is_dir($td)) @mkdir($td, 0755, true); copy($item->getRealPath(), $target); }
        }
    }

    private static function rrmdir(string $dir): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            if ($file->isDir()) @rmdir($file->getRealPath()); else @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }
}
