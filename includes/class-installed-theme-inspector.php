<?php

final class InstalledThemeInspector
{
    private const MAX_TREE_ENTRIES = 10000;
    private const MAX_PHP_FILES = 1000;
    private const MAX_SOURCE_BYTES = 5242880;
    private const MAX_DEPENDENCY_SCAN_BYTES = 262144;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function themes(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM themes ORDER BY is_active DESC, name ASC');
        $themes = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $root = $this->themeRoot((string)($row['folder_name'] ?? ''));
                $inventory = $this->inventory($root, (string)$row['folder_name']);
                $themes[] = $this->themeSummary($row, $root, count($inventory));
            } catch (Throwable $e) {
                $themes[] = $this->themeSummary($row, null, 0, $e->getMessage());
            }
        }

        return $themes;
    }

    public function inspect(string $folder): array
    {
        $row = $this->registeredTheme($folder);
        $root = $this->themeRoot($folder);
        $files = $this->inventory($root, $folder);

        $categories = [];
        foreach ($files as $file) {
            $key = (string)$file['category'];
            $categories[$key] = ($categories[$key] ?? 0) + 1;
        }

        return [
            'theme' => $this->themeSummary($row, $root, count($files)),
            'files' => $files,
            'slots' => $this->slotStatus($root, $folder, $files),
            'categories' => $categories,
        ];
    }

    public function source(string $folder, string $fileId): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $fileId)) return null;

        $row = $this->registeredTheme($folder);
        $root = $this->themeRoot($folder);
        $rootStat = @lstat($root);
        if (!is_array($rootStat) || (($rootStat['mode'] & 0170000) !== 0040000)) {
            throw new RuntimeException('Theme root identity is unavailable.');
        }
        $files = $this->inventory($root, $folder);
        $byPath = [];

        foreach ($files as $index => $file) {
            $byPath[$file['path']] = $index;
        }

        foreach ($files as $file) {
            if (!hash_equals((string)$file['id'], $fileId)) continue;

            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$file['path']);
            $state = $this->readRegularFile($path, $root, self::MAX_SOURCE_BYTES);
            $file['dependencies_scanned'] = strlen($state['content']) <= self::MAX_DEPENDENCY_SCAN_BYTES;
            $file['dependencies'] = $file['dependencies_scanned']
                ? $this->dependencies($state['content'], (string)$file['path'], $files, $byPath)
                : [];
            $file['size'] = $state['size'];
            $file['lines'] = substr_count($state['content'], "\n") + 1;
            $file['sha256'] = $state['sha256'];
            $file['modified_at'] = $state['modified_at'];
            $file['mode'] = $state['mode'];
            $file['owner'] = $state['owner'];
            $file['group'] = $state['group'];
            $file['target_token'] = hash('sha256', implode("\0", [
                'theme-builder-target-v1',
                (string)($row['id'] ?? 0),
                $folder,
                (string)$rootStat['dev'],
                (string)$rootStat['ino'],
                (string)$file['path'],
                (string)$state['dev'],
                (string)$state['ino'],
            ]));
            $file['utf8'] = preg_match('//u', $state['content']) === 1;
            $file['source'] = $state['content'];
            return $file;
        }

        return null;
    }

    private function registeredTheme(string $folder): array
    {
        $this->assertFolder($folder);
        $stmt = $this->pdo->prepare('SELECT * FROM themes WHERE folder_name = ? LIMIT 1');
        $stmt->execute([$folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !is_string($row['folder_name'] ?? null)
            || !hash_equals($folder, $row['folder_name'])) {
            throw new RuntimeException('Theme is not registered with this exact folder identity.');
        }
        return $row;
    }

    private function themeRoot(string $folder): string
    {
        $this->assertFolder($folder);
        if (!defined('VIEWS_BASE')) throw new RuntimeException('Theme root is unavailable.');

        $base = realpath((string)VIEWS_BASE);
        if ($base === false || !is_dir($base)) throw new RuntimeException('Theme root is unavailable.');

        $candidate = $base . DIRECTORY_SEPARATOR . $folder;
        if (is_link($candidate)) throw new RuntimeException('Theme root cannot be a symlink.');

        $root = realpath($candidate);
        if ($root === false || !is_dir($root) || dirname($root) !== $base) {
            throw new RuntimeException('Theme directory was not found.');
        }

        return $root;
    }

    private function assertFolder(string $folder): void
    {
        if (strlen($folder) > 128 || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) !== 1
            || in_array($folder, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid theme folder.');
        }
    }

    private function inventory(string $root, string $folder): array
    {
        $files = [];
        $entries = 0;
        $flags = FilesystemIterator::SKIP_DOTS;
        $directory = new RecursiveDirectoryIterator($root, $flags);
        $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $entry) {
            $entries++;
            if ($entries > self::MAX_TREE_ENTRIES) throw new RuntimeException('Theme tree is too large to inspect.');

            $path = $entry->getPathname();
            $stat = @lstat($path);
            if ($stat === false || is_link($path)) throw new RuntimeException('Theme tree contains an unsafe entry.');

            $type = $stat['mode'] & 0170000;
            if ($type !== 0040000 && $type !== 0100000) {
                throw new RuntimeException('Theme tree contains a special filesystem entry.');
            }
            if ($type === 0040000 && !is_readable($path)) {
                throw new RuntimeException('Theme tree contains an unreadable directory.');
            }

            $real = realpath($path);
            if ($real === false || !$this->isWithin($root, $real)) {
                throw new RuntimeException('Theme tree escapes its root.');
            }

            if ($type !== 0100000 || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') continue;
            if (count($files) >= self::MAX_PHP_FILES) throw new RuntimeException('Theme contains too many PHP files to inspect.');

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
            $classification = $this->classify($relative);
            $record = [
                'id' => hash('sha256', $folder . "\0" . $relative),
                'path' => $relative,
                'name' => basename($relative),
                'directory' => dirname($relative) === '.' ? '' : dirname($relative),
                'category' => $classification['category'],
                'category_label' => $classification['label'],
                'slot' => $classification['slot'],
                'size' => (int)$stat['size'],
                'modified_at' => (int)$stat['mtime'],
                'mode' => sprintf('%04o', $stat['mode'] & 07777),
                'owner' => $this->ownerName((int)$stat['uid']),
                'group' => $this->groupName((int)$stat['gid']),
                'writable' => is_writable($real) && is_writable(dirname($real)),
            ];

            $files[] = $record;
        }

        usort($files, static fn(array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));
        return $files;
    }

    private function readRegularFile(string $path, string $root, int $maxBytes): array
    {
        $before = @lstat($path);
        if ($before === false || is_link($path) || (($before['mode'] & 0170000) !== 0100000)) {
            throw new RuntimeException('Source is not a regular file.');
        }
        if ((int)$before['size'] > $maxBytes) throw new RuntimeException('PHP source exceeds the inspection limit.');

        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('PHP source could not be opened.');

        try {
            $opened = fstat($handle);
            if ($opened === false || !$this->sameFile($before, $opened)) {
                throw new RuntimeException('PHP source changed while it was opened.');
            }

            $content = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) throw new RuntimeException('PHP source could not be read.');
                $content .= $chunk;
                if (strlen($content) > $maxBytes) throw new RuntimeException('PHP source exceeds the inspection limit.');
            }

            $afterRead = fstat($handle);
        } finally {
            fclose($handle);
        }

        clearstatcache(true, $path);
        $after = @lstat($path);
        $real = realpath($path);
        if (
            $afterRead === false || $after === false || $real === false || is_link($path)
            || !$this->sameFile($opened, $afterRead) || !$this->sameFile($afterRead, $after)
            || !$this->isWithin($root, $real)
        ) {
            throw new RuntimeException('PHP source changed during inspection.');
        }

        return [
            'content' => $content,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'modified_at' => (int)$after['mtime'],
            'mode' => sprintf('%04o', $after['mode'] & 07777),
            'owner' => $this->ownerName((int)$after['uid']),
            'group' => $this->groupName((int)$after['gid']),
            'dev' => (int)$after['dev'],
            'ino' => (int)$after['ino'],
        ];
    }

    private function sameFile(array $a, array $b): bool
    {
        return (int)$a['dev'] === (int)$b['dev']
            && (int)$a['ino'] === (int)$b['ino']
            && (int)$a['size'] === (int)$b['size']
            && (int)$a['mtime'] === (int)$b['mtime']
            && (($b['mode'] & 0170000) === 0100000);
    }

    private function isWithin(string $root, string $path): bool
    {
        return $path === $root || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0;
    }

    private function classify(string $path): array
    {
        $slot = array_search($path, ThemeWorkspace::slotFiles(), true);
        if ($slot !== false) return ['category' => 'slot', 'label' => 'Canonical Slot', 'slot' => $slot];

        if (str_starts_with($path, 'partials/shortcodes/section/')) {
            return ['category' => 'section-wrapper', 'label' => 'Theme Section Wrapper', 'slot' => null];
        }
        if (str_starts_with($path, 'main/sections/')) {
            return ['category' => 'section', 'label' => 'Theme Section', 'slot' => null];
        }
        if (str_starts_with($path, 'partials/shortcodes/')) {
            return ['category' => 'shortcode', 'label' => 'Shortcode Partial', 'slot' => null];
        }
        if (str_starts_with($path, 'partials/')) {
            return ['category' => 'partial', 'label' => 'Partial', 'slot' => null];
        }
        if (str_starts_with($path, 'helpers/')) {
            return ['category' => 'helper', 'label' => 'Helper', 'slot' => null];
        }
        if (str_starts_with($path, 'main/')) {
            return ['category' => 'main-view', 'label' => 'Main View', 'slot' => null];
        }

        return ['category' => 'php', 'label' => 'PHP', 'slot' => null];
    }

    private function dependencies(string $source, string $sourcePath, array $files, array $byPath): array
    {
        if (strlen($source) > self::MAX_DEPENDENCY_SCAN_BYTES) return [];

        $dependencies = [];
        $tokens = token_get_all($source);
        $requireTokens = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            if (!is_array($tokens[$index]) || !in_array($tokens[$index][0], $requireTokens, true)) continue;

            $cursor = $this->nextMeaningfulToken($tokens, $index + 1);
            if (($tokens[$cursor] ?? null) === '(') $cursor = $this->nextMeaningfulToken($tokens, $cursor + 1);
            if (!is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== T_DIR) continue;

            $cursor = $this->nextMeaningfulToken($tokens, $cursor + 1);
            if (($tokens[$cursor] ?? null) !== '.') continue;
            $cursor = $this->nextMeaningfulToken($tokens, $cursor + 1);
            if (!is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== T_CONSTANT_ENCAPSED_STRING) continue;

            $literal = $this->decodeStringLiteral((string)$tokens[$cursor][1]);
            if ($literal === null) continue;
            $relative = $this->normalizeDependency(dirname($sourcePath), $literal);
            if ($relative === null || !isset($byPath[$relative])) continue;

            $target = $files[$byPath[$relative]];
            $dependencies[$target['id']] = [
                'id' => $target['id'],
                'path' => $target['path'],
                'category_label' => $target['category_label'],
            ];
        }

        return array_values($dependencies);
    }

    private function nextMeaningfulToken(array $tokens, int $index): int
    {
        $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        while (isset($tokens[$index]) && is_array($tokens[$index]) && in_array($tokens[$index][0], $ignored, true)) {
            $index++;
        }
        return $index;
    }

    private function decodeStringLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2) return null;
        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[strlen($literal) - 1] !== $quote) return null;

        $value = substr($literal, 1, -1);
        if ($quote === "'") return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        return stripcslashes($value);
    }

    private function normalizeDependency(string $directory, string $suffix): ?string
    {
        if (str_contains($suffix, "\0") || str_contains($suffix, '$')) return null;
        $suffix = ltrim(str_replace('\\', '/', trim($suffix)), '/');
        if ($suffix === '') return null;

        $parts = $directory === '.' ? [] : explode('/', $directory);
        foreach (explode('/', $suffix) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if (!$parts) return null;
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        $path = implode('/', $parts);
        return str_ends_with(strtolower($path), '.php') ? $path : null;
    }

    private function slotStatus(string $root, string $folder, array $files): array
    {
        $physical = [];
        foreach ($files as $file) $physical[$file['path']] = $file;

        $defaultRoot = null;
        $defaultFolder = defined('DEFAULT_THEME_FOLDER') ? (string)DEFAULT_THEME_FOLDER : 'default';
        if ($folder !== $defaultFolder) {
            try {
                $defaultRoot = $this->themeRoot($defaultFolder);
            } catch (Throwable $e) {
                $defaultRoot = null;
            }
        }

        $slots = [];
        foreach (ThemeWorkspace::slotFiles() as $slot => $path) {
            $status = 'missing';
            $fileId = null;
            if (isset($physical[$path])) {
                $status = 'physical';
                $fileId = $physical[$path]['id'];
            } elseif ($defaultRoot !== null && $this->safeRegularPath($defaultRoot, $path)) {
                $status = 'inherited';
            }

            $slots[] = ['slot' => $slot, 'path' => $path, 'status' => $status, 'file_id' => $fileId];
        }

        return $slots;
    }

    private function safeRegularPath(string $root, string $relative): bool
    {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_link($path)) return false;
        $stat = @lstat($path);
        $real = realpath($path);
        return $stat !== false && (($stat['mode'] & 0170000) === 0100000)
            && $real !== false && $this->isWithin($root, $real);
    }

    private function themeSummary(array $row, ?string $root, int $phpFiles, ?string $error = null): array
    {
        $manifest = [];
        if ($root !== null) {
            $manifestPath = $root . DIRECTORY_SEPARATOR . 'theme.json';
            if ($this->safeRegularPath($root, 'theme.json')) {
                try {
                    $state = $this->readRegularFile($manifestPath, $root, 1048576);
                    $decoded = json_decode($state['content'], true);
                    if (is_array($decoded)) {
                        if (array_key_exists('folder', $decoded)
                            && (!is_string($decoded['folder']) || !hash_equals((string)$row['folder_name'], $decoded['folder']))) {
                            throw new RuntimeException('Theme manifest folder identity is invalid.');
                        }
                        $decoded['folder'] = (string)$row['folder_name'];
                        $manifest = $decoded;
                    }
                } catch (Throwable $e) {
                    if (str_contains($e->getMessage(), 'folder identity')) throw $e;
                    // Database metadata remains available when a manifest is unreadable.
                }
            }
        }

        $manifestStoreUrl = is_string($manifest['store']['url'] ?? null) ? trim($manifest['store']['url']) : '';
        $manifestStoreSlug = is_string($manifest['store']['slug'] ?? null) ? trim($manifest['store']['slug']) : '';
        $databaseStoreUrl = trim((string)($row['store_url'] ?? ''));
        $databaseStoreSlug = trim((string)($row['store_slug'] ?? ''));
        $storeUrl = $manifestStoreUrl !== '' ? $manifestStoreUrl : $databaseStoreUrl;
        $storeSlug = $manifestStoreSlug !== '' ? $manifestStoreSlug : $databaseStoreSlug;
        return [
            'id' => (int)($row['id'] ?? 0),
            'folder' => (string)($row['folder_name'] ?? ''),
            'name' => (string)($manifest['name'] ?? $row['name'] ?? $row['folder_name'] ?? ''),
            'description' => (string)($manifest['description'] ?? $row['description'] ?? ''),
            'version' => (string)($manifest['version'] ?? $row['version'] ?? ''),
            'author' => (string)($manifest['author'] ?? $row['author'] ?? ''),
            'active' => !empty($row['is_active']),
            'system' => !empty($row['is_system']),
            'store' => $storeUrl !== '' || $storeSlug !== '',
            'store_url' => $storeUrl,
            'store_slug' => $storeSlug,
            'php_files' => $phpFiles,
            'inspectable' => $error === null,
            'error' => $error,
        ];
    }

    private function ownerName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $owner = posix_getpwuid($uid);
            if (is_array($owner) && isset($owner['name'])) return (string)$owner['name'];
        }
        return (string)$uid;
    }

    private function groupName(int $gid): string
    {
        if (function_exists('posix_getgrgid')) {
            $group = posix_getgrgid($gid);
            if (is_array($group) && isset($group['name'])) return (string)$group['name'];
        }
        return (string)$gid;
    }
}
