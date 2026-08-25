<?php

declare(strict_types=1);

class ThemeWorkspace
{
    private const SLUG_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,49}\z/D';
    private const MAX_SOURCE_BYTES = 2_097_152;
    private const MAX_ARCHIVE_ENTRIES = 500;
    private const MAX_ENTRY_BYTES = 67_108_864;
    private const MAX_ARCHIVE_BYTES = 268_435_456;

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
        'main.404'       => '404 - Not Found',
        'list.post'      => 'List - Posts',
        'list.page'      => 'List - Pages',
        'list.category'  => 'List - Category',
        'list.archive'   => 'List - Archive',
        'list.author'    => 'List - Author',
        'single.post'    => 'Single - Post',
        'single.page'    => 'Single - Page',
        'index.category' => 'Index - Categories',
        'index.author'   => 'Index - Authors',
    ];

    private const EDITABLE_ASSETS = [
        'assets/css/style.css' => 'css',
        'assets/css/blocks.css' => 'css',
        'assets/js/script.js' => 'javascript',
    ];

    public static function baseDir(): string
    {
        $configured = defined('THEME_BUILDER_WORKSPACE') ? (string)THEME_BUILDER_WORKSPACE : '';
        $dir = $configured !== '' ? $configured : dirname(__DIR__, 3) . '/cfg/var/theme-builder';
        if (file_exists($dir) && (is_link($dir) || !is_dir($dir))) {
            throw new RuntimeException('Theme Builder workspace is unsafe.');
        }
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the Theme Builder workspace.');
        }
        $real = realpath($dir);
        if ($real === false || is_link($dir)) throw new RuntimeException('Could not resolve the Theme Builder workspace.');
        return $real;
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    public static function isLegacySlug(string $slug): bool
    {
        return !self::isValidSlug($slug)
            && preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $slug) === 1;
    }

    public static function themeDir(string $slug): string
    {
        if (!self::isValidSlug($slug)) throw new InvalidArgumentException('Invalid theme slug.');
        return self::baseDir() . DIRECTORY_SEPARATOR . $slug;
    }

    public static function slotFiles(): array
    {
        return self::SLOT_FILES;
    }

    public static function slotLabels(): array
    {
        return self::SLOT_LABELS;
    }

    public static function editableAssets(): array
    {
        return self::EDITABLE_ASSETS;
    }

    public static function listThemes(): array
    {
        $base = self::baseDir();
        $themes = [];
        foreach (scandir($base) ?: [] as $slug) {
            if (!self::isValidSlug($slug) && !self::isLegacySlug($slug)) continue;
            $dir = $base . DIRECTORY_SEPARATOR . $slug;
            $resolved = self::resolveThemeDirectory($slug, true);
            if ($resolved === null || !hash_equals($resolved, $dir)) continue;
            $manifest = self::readManifestPath($dir . '/theme.json');
            $colorMode = in_array($manifest['color_mode'] ?? '', ['light', 'dark', 'both'], true)
                ? (string)$manifest['color_mode']
                : 'both';
            $themes[] = [
                'slug' => $slug,
                'legacy_slug' => self::isLegacySlug($slug),
                'name' => is_string($manifest['name'] ?? null) ? $manifest['name'] : $slug,
                'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.1.0',
                'author' => is_string($manifest['author'] ?? null) ? $manifest['author'] : '',
                'description' => is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
                'color_mode' => $colorMode,
                'dir' => $dir,
                'modified' => (int)(filemtime($dir) ?: 0),
                'files' => self::countFiles($dir),
                'complete' => self::isComplete($dir),
            ];
        }
        usort($themes, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return $themes;
    }

    public static function countFiles(string $dir): int
    {
        if (!is_dir($dir) || is_link($dir)) return 0;
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) return 0;
            if ($item->isFile()) $count++;
        }
        return $count;
    }

    public static function isComplete(string $dir): bool
    {
        if (!is_dir($dir) || is_link($dir) || !is_file($dir . '/theme.json')) return false;
        foreach (self::SLOT_FILES as $file) {
            if (!is_file($dir . '/' . $file) || is_link($dir . '/' . $file)) return false;
        }
        return true;
    }

    public static function completionStatus(string $dir): array
    {
        $status = [];
        foreach (self::SLOT_FILES as $slot => $file) {
            $path = $dir . '/' . $file;
            $exists = is_file($path) && !is_link($path);
            $status[$slot] = [
                'file' => $file,
                'label' => self::SLOT_LABELS[$slot] ?? $slot,
                'exists' => $exists,
                'size' => $exists ? (int)(filesize($path) ?: 0) : 0,
                'lines' => $exists ? count(file($path, FILE_IGNORE_NEW_LINES) ?: []) : 0,
            ];
        }
        return $status;
    }

    public static function createTheme(string $slug, string $name, string $author, string $description = '', string $colorMode = 'both'): array
    {
        if (!self::isValidSlug($slug)) return ['success' => false, 'error' => 'Use a lowercase slug containing only letters, numbers, hyphens, and underscores.'];
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) return ['success' => false, 'error' => 'Theme name is invalid.'];
        if (mb_strlen($author) > 100 || mb_strlen($description) > 2_000) return ['success' => false, 'error' => 'Theme metadata is too long.'];
        $colorMode = in_array($colorMode, ['light', 'dark', 'both'], true) ? $colorMode : 'both';
        $lock = self::acquireThemeLock($slug);
        if (!is_resource($lock)) return ['success' => false, 'error' => 'Could not lock the theme workspace.'];
        $base = self::baseDir();
        $stage = $base . '/.' . $slug . '.stage-' . bin2hex(random_bytes(8));
        try {
            $target = self::themeDir($slug);
            if (file_exists($target) || is_link($target)) return ['success' => false, 'error' => 'Theme already exists.'];
            if (!mkdir($stage, 0770)) throw new RuntimeException('Could not create the theme stage.');
            self::copyTree(dirname(__DIR__) . '/templates/starter', $stage);
            $manifest = [
                'folder' => $slug,
                'name' => $name,
                'description' => $description,
                'version' => '0.1.0',
                'author' => $author,
                'screenshot' => 'img.png',
                'color_mode' => $colorMode,
                'styles' => ['assets/css/style.css', 'assets/css/blocks.css'],
                'scripts' => ['assets/js/script.js'],
            ];
            self::writeNewFile($stage . '/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, 0660);
            self::lintPhpTree($stage);
            if (!rename($stage, $target)) throw new RuntimeException('Could not publish the draft theme.');
            return ['success' => true, 'slug' => $slug];
        } catch (Throwable $error) {
            self::removeTree($stage);
            return ['success' => false, 'error' => $error->getMessage()];
        } finally {
            self::releaseLock($lock);
        }
    }

    public static function deleteTheme(string $slug): bool
    {
        if (!self::isValidSlug($slug)) return false;
        $lock = self::acquireThemeLock($slug);
        if (!is_resource($lock)) return false;
        try {
            $dir = self::resolveThemeDirectory($slug);
            if ($dir === null || !self::treeHasOnlyRegularEntries($dir)) return false;
            $quarantine = self::baseDir() . '/.delete-' . $slug . '-' . bin2hex(random_bytes(8));
            if (!rename($dir, $quarantine)) return false;
            if (is_link($quarantine)) {
                @unlink($quarantine);
                return false;
            }
            $removed = self::removeTree($quarantine);
            self::invalidateArtifact($slug);
            return $removed && !file_exists($dir) && !is_link($dir) && !file_exists($quarantine) && !is_link($quarantine);
        } finally {
            self::releaseLock($lock);
        }
    }

    public static function readFile(string $slug, string $slot): ?string
    {
        $state = self::readFileState($slug, $slot);
        return $state['content'] ?? null;
    }

    public static function readFileState(string $slug, string $slot): ?array
    {
        $relative = self::SLOT_FILES[$slot] ?? null;
        if ($relative === null) return null;
        $path = self::resolveThemeFile($slug, $relative);
        return $path !== null ? self::readSourceState($path) : null;
    }

    public static function fileHash(string $slug, string $slot): ?string
    {
        $state = self::readFileState($slug, $slot);
        return $state['sha256'] ?? null;
    }

    public static function writeFile(string $slug, string $slot, string $content, string $expectedHash): array
    {
        $relative = self::SLOT_FILES[$slot] ?? null;
        if ($relative === null) return ['success' => false, 'error' => 'Unknown theme slot.'];
        return self::saveExistingFile($slug, $relative, $content, $expectedHash, true);
    }

    public static function readAsset(string $slug, string $relative): ?string
    {
        $state = self::readAssetState($slug, $relative);
        return $state['content'] ?? null;
    }

    public static function readAssetState(string $slug, string $relative): ?array
    {
        if (!isset(self::EDITABLE_ASSETS[$relative])) return null;
        $path = self::resolveThemeFile($slug, $relative);
        return $path !== null ? self::readSourceState($path) : null;
    }

    public static function assetHash(string $slug, string $relative): ?string
    {
        $state = self::readAssetState($slug, $relative);
        return $state['sha256'] ?? null;
    }

    public static function writeAsset(string $slug, string $relative, string $content, string $expectedHash): array
    {
        if (!isset(self::EDITABLE_ASSETS[$relative])) return ['success' => false, 'error' => 'Asset is not editable.'];
        return self::saveExistingFile($slug, $relative, $content, $expectedHash, false);
    }

    public static function readManifest(string $slug): array
    {
        $state = self::readManifestState($slug);
        return is_array($state['manifest'] ?? null) ? $state['manifest'] : [];
    }

    public static function readManifestState(string $slug): ?array
    {
        $path = self::resolveThemeFile($slug, 'theme.json');
        if ($path === null) return null;
        try {
            $source = self::readSourceState($path);
            if ($source === null) return null;
            $manifest = json_decode($source['content'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) return null;
            return ['manifest' => $manifest, 'content' => $source['content'], 'sha256' => $source['sha256']];
        } catch (Throwable) {
            return null;
        }
    }

    public static function manifestHash(string $slug): ?string
    {
        $state = self::readManifestState($slug);
        return $state['sha256'] ?? null;
    }

    public static function writeManifest(string $slug, array $changes, string $expectedHash): array
    {
        if (!self::isValidSlug($slug)) return ['success' => false, 'error' => 'Invalid theme slug.'];
        $allowed = ['name', 'description', 'version', 'author', 'screenshot', 'color_mode', 'styles', 'scripts'];
        if (array_diff(array_keys($changes), $allowed) !== []) return ['success' => false, 'error' => 'Manifest contains unsupported changes.'];
        $state = self::readManifestState($slug);
        $manifest = is_array($state['manifest'] ?? null) ? $state['manifest'] : [];
        if ($manifest === []) return ['success' => false, 'error' => 'Theme manifest is missing or invalid.'];
        foreach (['styles', 'scripts'] as $key) {
            if (array_key_exists($key, $changes)) {
                if (!is_array($changes[$key])) return ['success' => false, 'error' => 'Manifest asset list is invalid.'];
                $changes[$key] = self::mergeManifestAssetEntries($manifest[$key] ?? [], $changes[$key]);
            }
        }
        $manifest = array_replace($manifest, $changes);
        $manifest['folder'] = $slug;
        $validation = self::validateManifest($manifest, $slug);
        if ($validation !== '') return ['success' => false, 'error' => $validation];
        try {
            $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (Throwable $error) {
            return ['success' => false, 'error' => $error->getMessage()];
        }
        return self::saveExistingFile($slug, 'theme.json', $encoded, $expectedHash, false);
    }

    public static function buildZip(string $slug): ?string
    {
        if (!self::isValidSlug($slug)) return null;
        $lock = self::acquireThemeLock($slug);
        if (!is_resource($lock)) return null;
        try {
            $dir = self::resolveThemeDirectory($slug);
            if ($dir === null || !self::isComplete($dir) || !self::treeHasOnlyRegularEntries($dir)) return null;
            $manifest = self::readManifest($slug);
            if (self::validateManifest($manifest, $slug) !== '') return null;
            self::lintPhpTree($dir);
            $files = self::treeFiles($dir);
            if ($files === [] || count($files) > self::MAX_ARCHIVE_ENTRIES) return null;
            $sourceHashes = self::treeHashes($dir, $files);
            $totalBytes = 0;
            foreach ($files as $file) {
                $bytes = filesize($dir . '/' . $file);
                if ($bytes === false || $bytes > self::MAX_ENTRY_BYTES) return null;
                $totalBytes += $bytes;
                if ($totalBytes > self::MAX_ARCHIVE_BYTES) return null;
            }

            $base = self::baseDir();
            $temporary = $base . '/.' . $slug . '-' . bin2hex(random_bytes(8)) . '.zip';
            $final = $base . '/' . $slug . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) return null;
            $ok = true;
            foreach ($files as $relative) {
                if (!$zip->addFile($dir . '/' . $relative, $relative)) {
                    $ok = false;
                    break;
                }
            }
            if (!$zip->close() || !$ok) {
                @unlink($temporary);
                return null;
            }
            if ($sourceHashes !== self::treeHashes($dir, $files) || $sourceHashes !== self::zipHashes($temporary)) {
                @unlink($temporary);
                return null;
            }
            if (is_file($final) && !unlink($final)) {
                @unlink($temporary);
                return null;
            }
            if (!rename($temporary, $final)) {
                @unlink($temporary);
                return null;
            }
            chmod($final, 0660);
            $meta = [
                'slug' => $slug,
                'tree_sha256' => self::hashMap($sourceHashes),
                'zip_sha256' => hash_file('sha256', $final),
                'created_at' => gmdate('c'),
            ];
            self::writeJsonFile($base . '/' . $slug . '.zip.json', $meta);
            return $final;
        } catch (Throwable) {
            return null;
        } finally {
            self::releaseLock($lock);
        }
    }

    public static function artifactPath(string $slug): ?string
    {
        if (!self::isValidSlug($slug)) return null;
        $lock = self::acquireThemeLock($slug);
        if (!is_resource($lock)) return null;
        try {
            return self::artifactPathUnlocked($slug);
        } catch (Throwable) {
            return null;
        } finally {
            self::releaseLock($lock);
        }
    }

    public static function openArtifact(string $slug): ?array
    {
        if (!self::isValidSlug($slug)) return null;
        $lock = self::acquireThemeLock($slug);
        if (!is_resource($lock)) return null;
        try {
            return self::openArtifactUnlocked($slug);
        } catch (Throwable) {
            return null;
        } finally {
            self::releaseLock($lock);
        }
    }

    private static function artifactPathUnlocked(string $slug): ?string
    {
        $artifact = self::openArtifactUnlocked($slug);
        if (!is_array($artifact) || !is_resource($artifact['handle'] ?? null)) return null;
        fclose($artifact['handle']);
        return $artifact['path'];
    }

    private static function openArtifactUnlocked(string $slug): ?array
    {
        $dir = self::resolveThemeDirectory($slug);
        $zip = self::baseDir() . '/' . $slug . '.zip';
        $metaFile = self::baseDir() . '/' . $slug . '.zip.json';
        if ($dir === null || !is_file($zip) || is_link($zip) || !is_file($metaFile) || is_link($metaFile)) return null;
        try {
            $metaState = self::openRegularFileState($metaFile, false);
            if ($metaState === null) return null;
            $meta = json_decode($metaState['content'], true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($meta) || ($meta['slug'] ?? null) !== $slug
            || !hash_equals((string)($meta['tree_sha256'] ?? ''), self::treeHash($dir))) return null;
        $zipState = self::openRegularFileState($zip, true);
        if ($zipState === null || !hash_equals((string)($meta['zip_sha256'] ?? ''), $zipState['sha256'])) {
            if (is_resource($zipState['handle'] ?? null)) fclose($zipState['handle']);
            return null;
        }
        return ['handle' => $zipState['handle'], 'size' => $zipState['size'], 'sha256' => $zipState['sha256'], 'path' => $zip];
    }

    public static function installTheme(string $slug): array
    {
        if (!self::isValidSlug($slug)) return ['success' => false, 'error' => 'Invalid theme slug.'];
        $zip = self::artifactPath($slug) ?? self::buildZip($slug);
        if ($zip === null) return ['success' => false, 'error' => 'Build a valid theme package before installation.'];
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO || !function_exists('install_theme_from_zip')) {
            return ['success' => false, 'error' => 'Core theme installer is unavailable.'];
        }
        $lock = self::acquireThemeLock($slug);
        if (!is_resource($lock)) return ['success' => false, 'error' => 'Could not lock the theme package.'];
        $artifactHandle = null;
        $stageDir = '';
        $stage = '';
        try {
            $artifact = self::openArtifactUnlocked($slug);
            if (!is_array($artifact) || !is_resource($artifact['handle'] ?? null)) return ['success' => false, 'error' => 'Theme package changed before installation.'];
            $artifactHandle = $artifact['handle'];
            $stageDir = self::createPrivateInstallStage($slug);
            if ($stageDir === '') return ['success' => false, 'error' => 'Could not create private installation staging.'];
            $stage = $stageDir . '/package.zip';
            $output = fopen($stage, 'xb');
            if (!is_resource($output)) return ['success' => false, 'error' => 'Could not stage the verified theme package.'];
            try {
                if (stream_copy_to_stream($artifactHandle, $output) !== $artifact['size'] || !fflush($output)
                    || (function_exists('fsync') && !fsync($output))) {
                    return ['success' => false, 'error' => 'Could not stage the complete verified theme package.'];
                }
            } finally {
                fclose($output);
            }
            if (!chmod($stage, 0600) || !hash_equals($artifact['sha256'], (string)hash_file('sha256', $stage))) {
                return ['success' => false, 'error' => 'Verified theme package staging failed.'];
            }
            $result = install_theme_from_zip($pdo, $stage, false, null, $slug);
            if (($result['success'] ?? false) !== true) {
                return ['success' => false, 'error' => (string)($result['message'] ?? 'Theme installation failed.')];
            }
            return ['success' => true, 'folder' => $result['folder'] ?? $slug];
        } finally {
            if (is_resource($artifactHandle)) fclose($artifactHandle);
            if ($stageDir !== '') self::removeTree($stageDir);
            self::releaseLock($lock);
        }
    }

    private static function saveExistingFile(string $slug, string $relative, string $content, string $expectedHash, bool $lintPhp): array
    {
        if (!self::isValidSlug($slug)) return ['success' => false, 'error' => 'Invalid theme slug.'];
        if (!self::isSafeRelativePath($relative) || strlen($content) > self::MAX_SOURCE_BYTES || str_contains($content, "\0")
            || !mb_check_encoding($content, 'UTF-8')) {
            return ['success' => false, 'error' => 'Source content is invalid or too large.'];
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1) return ['success' => false, 'error' => 'Original source hash is required.'];
        $target = self::resolveThemeFile($slug, $relative);
        if ($target === null) return ['success' => false, 'error' => 'Theme source file was not found.'];

        $lock = self::acquireThemeLock($slug);
        if (!is_resource($lock)) return ['success' => false, 'error' => 'Could not lock the theme source.'];

        $temporary = '';
        try {
            $target = self::resolveThemeFile($slug, $relative);
            if ($target === null) throw new RuntimeException('Theme source changed during save.');
            $currentHash = hash_file('sha256', $target);
            if (!is_string($currentHash) || !hash_equals($expectedHash, $currentHash)) throw new RuntimeException('Theme source changed after it was opened. Reload before saving.');
            $directory = dirname($target);
            $targetStat = stat($target);
            if (!is_array($targetStat)) throw new RuntimeException('Could not inspect source ownership.');
            $temporary = tempnam($directory, '.theme-builder-');
            if (!is_string($temporary) || $temporary === '' || is_link($temporary)) throw new RuntimeException('Could not create an atomic source stage.');
            $temporaryReal = realpath($temporary);
            $directoryReal = realpath($directory);
            if ($temporaryReal === false || $directoryReal === false || dirname($temporaryReal) !== $directoryReal) {
                throw new RuntimeException('Atomic source staging is unavailable in the target directory.');
            }
            $handle = fopen($temporary, 'wb');
            if (!is_resource($handle)) throw new RuntimeException('Could not open the source stage.');
            try {
                if (!flock($handle, LOCK_EX) || fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                    throw new RuntimeException('Could not write the complete source stage.');
                }
                if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Could not sync the source stage.');
            } finally {
                fclose($handle);
            }
            $mode = fileperms($target);
            if ($mode === false || !chmod($temporary, $mode & 0777)) throw new RuntimeException('Could not preserve source permissions.');
            $temporaryStat = stat($temporary);
            if (!is_array($temporaryStat)) throw new RuntimeException('Could not inspect source stage ownership.');
            if ((int)$temporaryStat['uid'] !== (int)$targetStat['uid'] && !@chown($temporary, (int)$targetStat['uid'])) {
                throw new RuntimeException('Could not preserve source ownership.');
            }
            if ((int)$temporaryStat['gid'] !== (int)$targetStat['gid'] && !@chgrp($temporary, (int)$targetStat['gid'])) {
                throw new RuntimeException('Could not preserve source group.');
            }
            if ($lintPhp) self::lintPhpFile($temporary);
            $freshTarget = self::resolveThemeFile($slug, $relative);
            if ($freshTarget === null || !hash_equals($target, $freshTarget)
                || !hash_equals($expectedHash, (string)hash_file('sha256', $freshTarget))) {
                throw new RuntimeException('Theme source changed during validation. Reload before saving.');
            }
            if (!rename($temporary, $target)) throw new RuntimeException('Could not atomically replace the source file.');
            $temporary = '';
            clearstatcache(true, $target);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
            self::invalidateArtifact($slug);
            return ['success' => true, 'sha256' => hash_file('sha256', $target)];
        } catch (Throwable $error) {
            return ['success' => false, 'error' => $error->getMessage()];
        } finally {
            if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
            self::releaseLock($lock);
        }
    }

    private static function validateManifest(array $manifest, string $slug): string
    {
        if (($manifest['folder'] ?? null) !== $slug) return 'Manifest folder must match the draft slug.';
        if (!is_string($manifest['name'] ?? null) || trim($manifest['name']) === '' || mb_strlen($manifest['name']) > 150) return 'Manifest name is invalid.';
        if (!is_string($manifest['version'] ?? null) || preg_match('/\A\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?\z/D', $manifest['version']) !== 1) return 'Manifest version is invalid.';
        if (!is_string($manifest['description'] ?? '') || mb_strlen((string)($manifest['description'] ?? '')) > 5_000) return 'Manifest description is invalid.';
        if (!is_string($manifest['author'] ?? '') || mb_strlen((string)($manifest['author'] ?? '')) > 150) return 'Manifest author is invalid.';
        if (!in_array($manifest['color_mode'] ?? 'both', ['light', 'dark', 'both'], true)) return 'Manifest color mode is invalid.';
        $screenshot = $manifest['screenshot'] ?? 'img.png';
        if (!is_string($screenshot) || ($screenshot !== '' && !self::isSafeRelativePath($screenshot))) return 'Manifest screenshot path is invalid.';
        foreach (['styles' => 'css', 'scripts' => 'js'] as $key => $extension) {
            $entries = $manifest[$key] ?? [];
            if (!is_array($entries) || count($entries) > 50) return 'Manifest asset list is invalid.';
            foreach ($entries as $entry) {
                $source = is_string($entry) ? $entry : (is_array($entry) && is_string($entry['src'] ?? null) ? $entry['src'] : null);
                if (!is_string($source) || !self::isSafeRelativePath($source) || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== $extension) {
                    return 'Manifest asset path is invalid.';
                }
            }
        }
        return '';
    }

    private static function readSourceState(string $path): ?array
    {
        $content = file_get_contents($path);
        if (!is_string($content)) return null;
        return ['content' => $content, 'sha256' => hash('sha256', $content)];
    }

    private static function openRegularFileState(string $path, bool $keepHandle): ?array
    {
        $before = lstat($path);
        if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== 0100000) return null;
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) return null;
        $opened = fstat($handle);
        $after = lstat($path);
        $sameFile = is_array($opened) && is_array($after)
            && (($opened['mode'] ?? 0) & 0170000) === 0100000
            && $before['dev'] === $opened['dev'] && $before['ino'] === $opened['ino']
            && $after['dev'] === $opened['dev'] && $after['ino'] === $opened['ino'];
        if (!$sameFile || (int)$opened['size'] < 1 || (!$keepHandle && (int)$opened['size'] > 1_048_576)) {
            fclose($handle);
            return null;
        }
        $context = hash_init('sha256');
        $content = '';
        $bytes = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 65_536);
            if ($chunk === false) {
                fclose($handle);
                return null;
            }
            if ($chunk === '') continue;
            $bytes += strlen($chunk);
            hash_update($context, $chunk);
            if (!$keepHandle) $content .= $chunk;
        }
        if ($bytes !== (int)$opened['size']) {
            fclose($handle);
            return null;
        }
        $hash = hash_final($context);
        if ($keepHandle) {
            if (fseek($handle, 0) !== 0) {
                fclose($handle);
                return null;
            }
            return ['handle' => $handle, 'size' => $bytes, 'sha256' => $hash];
        }
        fclose($handle);
        return ['content' => $content, 'size' => $bytes, 'sha256' => $hash];
    }

    private static function mergeManifestAssetEntries(mixed $current, mixed $requested): array
    {
        if (!is_array($requested)) return [];
        $existing = [];
        foreach (is_array($current) ? $current : [] as $entry) {
            $source = is_string($entry) ? $entry : (is_array($entry) && is_string($entry['src'] ?? null) ? $entry['src'] : null);
            if (is_string($source)) $existing[$source][] = $entry;
        }
        $merged = [];
        foreach ($requested as $entry) {
            if (is_array($entry)) {
                $merged[] = $entry;
                continue;
            }
            if (!is_string($entry)) continue;
            $source = trim($entry);
            if ($source === '') continue;
            $merged[] = isset($existing[$source]) && $existing[$source] !== [] ? array_shift($existing[$source]) : $source;
        }
        return $merged;
    }

    private static function readManifestPath(string $path): array
    {
        if (!is_file($path) || is_link($path)) return [];
        try {
            $manifest = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            return is_array($manifest) ? $manifest : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function resolveThemeDirectory(string $slug, bool $allowLegacy = false): ?string
    {
        if (!self::isValidSlug($slug) && (!$allowLegacy || !self::isLegacySlug($slug))) return null;
        $base = self::baseDir();
        $candidate = $base . DIRECTORY_SEPARATOR . $slug;
        if (is_link($candidate)) return null;
        $real = realpath($candidate);
        if ($real === false || !is_dir($real) || dirname($real) !== $base) return null;
        return $real;
    }

    private static function resolveThemeFile(string $slug, string $relative): ?string
    {
        if (!self::isSafeRelativePath($relative)) return null;
        $theme = self::resolveThemeDirectory($slug);
        if ($theme === null || self::pathHasSymlink($theme, $relative)) return null;
        $real = realpath($theme . DIRECTORY_SEPARATOR . $relative);
        if ($real === false || !is_file($real) || is_link($real) || !str_starts_with($real, $theme . DIRECTORY_SEPARATOR)) return null;
        return $real;
    }

    private static function isSafeRelativePath(string $relative): bool
    {
        if ($relative === '' || strlen($relative) > 512 || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_starts_with($relative, '/') || preg_match('/\A[A-Za-z]:/', $relative) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $relative) === 1 || !mb_check_encoding($relative, 'UTF-8')) return false;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) return false;
        }
        return true;
    }

    private static function pathHasSymlink(string $root, string $relative): bool
    {
        if (is_link($root)) return true;
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) return true;
        }
        return false;
    }

    private static function copyTree(string $source, string $target): void
    {
        if (!is_dir($source) || is_link($source)) throw new RuntimeException('Theme starter is missing or unsafe.');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new RuntimeException('Theme starter contains a symbolic link.');
            $relative = $iterator->getSubPathName();
            if (!self::isSafeRelativePath($relative)) throw new RuntimeException('Theme starter contains an unsafe path.');
            $destination = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!mkdir($destination, 0770) && !is_dir($destination)) throw new RuntimeException('Could not create a starter directory.');
            } elseif ($item->isFile()) {
                self::writeNewFile($destination, (string)file_get_contents($item->getPathname()), 0660);
            } else {
                throw new RuntimeException('Theme starter contains an unsupported entry.');
            }
        }
    }

    private static function writeNewFile(string $path, string $content, int $mode): void
    {
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) throw new RuntimeException('Could not create a source directory.');
        $handle = fopen($path, 'xb');
        if (!is_resource($handle)) throw new RuntimeException('Could not create a source file.');
        try {
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) throw new RuntimeException('Could not write a source file.');
            if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Could not sync a source file.');
        } finally {
            fclose($handle);
        }
        if (!chmod($path, $mode)) throw new RuntimeException('Could not set source permissions.');
    }

    private static function treeHasOnlyRegularEntries(string $root): bool
    {
        if (!is_dir($root) || is_link($root)) return false;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || (!$item->isDir() && !$item->isFile())) return false;
        }
        return true;
    }

    private static function treeFiles(string $root): array
    {
        if (!self::treeHasOnlyRegularEntries($root)) throw new RuntimeException('Theme tree is unsafe.');
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $relative = substr($item->getPathname(), strlen($root) + 1);
            if (!self::isSafeRelativePath($relative)) throw new RuntimeException('Theme tree contains an unsafe path.');
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private static function treeHash(string $root): string
    {
        return self::hashMap(self::treeHashes($root, self::treeFiles($root)));
    }

    private static function treeHashes(string $root, array $files): array
    {
        $hashes = [];
        foreach ($files as $relative) {
            $hash = hash_file('sha256', $root . '/' . $relative);
            if (!is_string($hash)) throw new RuntimeException('Could not hash theme source.');
            $hashes[$relative] = $hash;
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    private static function zipHashes(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('Could not verify the theme package.');
        $hashes = [];
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $relative = is_array($stat) ? (string)($stat['name'] ?? '') : '';
                if ($relative === '' || str_ends_with($relative, '/')) continue;
                $stream = $zip->getStream($relative);
                if (!is_resource($stream)) throw new RuntimeException('Could not read the theme package.');
                $context = hash_init('sha256');
                while (!feof($stream)) {
                    $chunk = fread($stream, 65_536);
                    if ($chunk === false) {
                        fclose($stream);
                        throw new RuntimeException('Could not read the theme package.');
                    }
                    if ($chunk !== '') hash_update($context, $chunk);
                }
                fclose($stream);
                $hashes[$relative] = hash_final($context);
            }
        } finally {
            $zip->close();
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    private static function hashMap(array $hashes): string
    {
        ksort($hashes, SORT_STRING);
        return hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function lintPhpTree(string $root): void
    {
        foreach (self::treeFiles($root) as $relative) {
            if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') self::lintPhpFile($root . '/' . $relative);
        }
    }

    private static function lintPhpFile(string $path): void
    {
        if (!function_exists('proc_open')) throw new RuntimeException('PHP syntax validation is unavailable.');
        $binary = self::phpCliBinary();
        if ($binary === '') throw new RuntimeException('PHP syntax validation is unavailable.');
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open([$binary, '-l', $path], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start PHP syntax validation.');
        $output = '';
        foreach ($pipes as $pipe) {
            $output .= stream_get_contents($pipe, 16_384) ?: '';
            fclose($pipe);
        }
        $status = proc_close($process);
        if ($status !== 0) {
            $safeOutput = trim(str_replace([$path, basename($path)], 'theme source', $output));
            throw new RuntimeException($safeOutput !== '' ? $safeOutput : 'PHP syntax validation failed.');
        }
    }

    private static function phpCliBinary(): string
    {
        static $resolved = null;
        if (is_string($resolved)) return $resolved;
        $configured = defined('THEME_BUILDER_PHP_CLI') ? (string)THEME_BUILDER_PHP_CLI : '';
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $candidates = array_values(array_unique(array_filter([
            $configured,
            PHP_SAPI === 'cli' ? PHP_BINARY : '',
            PHP_BINDIR . '/php',
            '/usr/bin/php' . $version,
            '/usr/bin/php',
            '/usr/local/bin/php' . $version,
            '/usr/local/bin/php',
        ], static fn(string $candidate): bool => $candidate !== '')));
        foreach ($candidates as $candidate) {
            if (!is_file($candidate) || !is_executable($candidate)) continue;
            $pipes = [];
            $process = proc_open(
                [$candidate, '-r', 'exit(PHP_VERSION_ID === ' . PHP_VERSION_ID . ' ? 0 : 1);'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) continue;
            foreach ($pipes as $pipe) fclose($pipe);
            if (proc_close($process) === 0) return $resolved = $candidate;
        }
        return $resolved = '';
    }

    private static function createPrivateInstallStage(string $slug): string
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($temporaryRoot === false || !is_dir($temporaryRoot) || is_link($temporaryRoot)) return '';
        $rootStat = stat($temporaryRoot);
        if (!is_array($rootStat)) return '';
        $mode = (int)$rootStat['mode'];
        $groupOrWorldWritable = ($mode & 0022) !== 0;
        if ($groupOrWorldWritable && ($mode & 01000) === 0) return '';
        $stage = $temporaryRoot . '/theme-builder-install-' . $slug . '-' . bin2hex(random_bytes(16));
        if (!mkdir($stage, 0700) || is_link($stage)) return '';
        $resolved = realpath($stage);
        if ($resolved === false || dirname($resolved) !== $temporaryRoot) {
            self::removeTree($stage);
            return '';
        }
        return $resolved;
    }

    private static function acquireThemeLock(string $slug)
    {
        if (!self::isValidSlug($slug)) return null;
        $base = self::baseDir();
        $lockDir = $base . '/.locks';
        if ((file_exists($lockDir) && (is_link($lockDir) || !is_dir($lockDir)))
            || (!is_dir($lockDir) && !mkdir($lockDir, 0770) && !is_dir($lockDir))) return null;
        $lockDirReal = realpath($lockDir);
        if ($lockDirReal === false || is_link($lockDir) || dirname($lockDirReal) !== $base) return null;
        $path = $lockDirReal . '/' . hash('sha256', $slug) . '.lock';
        if (is_link($path)) return null;
        if (!file_exists($path)) {
            $created = @fopen($path, 'x');
            if (is_resource($created)) {
                chmod($path, 0660);
                fclose($created);
            }
        }
        $before = lstat($path);
        if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== 0100000) return null;
        $lock = fopen($path, 'r+');
        $opened = is_resource($lock) ? fstat($lock) : false;
        $after = lstat($path);
        $sameFile = is_array($opened) && is_array($after)
            && $before['dev'] === $opened['dev'] && $before['ino'] === $opened['ino']
            && $after['dev'] === $opened['dev'] && $after['ino'] === $opened['ino'];
        if (!is_resource($lock) || !$sameFile || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            return null;
        }
        $lockedPath = lstat($path);
        if (!is_array($lockedPath) || $lockedPath['dev'] !== $opened['dev'] || $lockedPath['ino'] !== $opened['ino']) {
            self::releaseLock($lock);
            return null;
        }
        return $lock;
    }

    private static function releaseLock($lock): void
    {
        if (!is_resource($lock)) return;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private static function writeJsonFile(string $path, array $data): void
    {
        $temporary = tempnam(dirname($path), '.theme-builder-meta-');
        if (!is_string($temporary) || $temporary === '') throw new RuntimeException('Could not stage artifact metadata.');
        try {
            $temporaryReal = realpath($temporary);
            $directoryReal = realpath(dirname($path));
            if ($temporaryReal === false || $directoryReal === false || dirname($temporaryReal) !== $directoryReal) {
                throw new RuntimeException('Atomic artifact metadata staging is unavailable.');
            }
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || !chmod($temporary, 0660) || !rename($temporary, $path)) {
                throw new RuntimeException('Could not publish artifact metadata.');
            }
            $temporary = '';
        } finally {
            if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
        }
    }

    private static function invalidateArtifact(string $slug): void
    {
        if (!self::isValidSlug($slug)) return;
        $base = self::baseDir();
        foreach ([$base . '/' . $slug . '.zip', $base . '/' . $slug . '.zip.json'] as $path) {
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
    }

    private static function removeTree(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) return true;
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) @unlink($item->getPathname());
            else @rmdir($item->getPathname());
        }
        @rmdir($path);
        return !file_exists($path) && !is_link($path);
    }
}
