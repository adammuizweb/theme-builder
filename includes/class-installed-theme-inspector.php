<?php

declare(strict_types=1);

/**
 * Read-only adapter for the Core ThemeSourceService inventory.
 *
 * Theme Builder uses the normalized records only for fork selection and
 * cross-owner navigation. Source inspection and every mutation stay in Core.
 */
final class InstalledThemeInspector
{
    private const MAX_DEPENDENCY_SCAN_BYTES = 262144;
    private const MAX_DEPENDENCY_TOTAL_BYTES = 16777216;
    private const MAX_FILES = 1000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function themes(): array
    {
        $rows = $this->pdo->query('SELECT * FROM themes ORDER BY is_active DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
        $themes = [];
        foreach ($rows as $row) {
            $folder = (string)($row['folder_name'] ?? '');
            try {
                $inventory = $this->inventory($folder);
                $files = $this->normalizeFiles($inventory);
                $themes[] = $this->themeSummary($row, count($files), $inventory['theme']);
            } catch (Throwable $error) {
                $themes[] = $this->themeSummary($row, 0, null, $error->getMessage());
            }
        }
        return $themes;
    }

    public function inspect(string $folder): array
    {
        $row = $this->registeredTheme($folder);
        $inventory = $this->inventory($folder);
        $files = $this->normalizeFiles($inventory);
        $categories = [];
        foreach ($files as $file) {
            $category = (string)$file['category'];
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }
        return [
            'theme' => $this->themeSummary($row, count($files), $inventory['theme']),
            'files' => $files,
            'categories' => $categories,
        ];
    }

    public function source(string $folder, string $fileId): ?array
    {
        $this->registeredTheme($folder);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1) return null;
        $source = $this->service()->source($folder, $fileId);
        return is_array($source) ? $source : null;
    }

    public function literalDependencies(string $folder, array $fileIds): array
    {
        if (count($fileIds) > self::MAX_FILES) throw new InvalidArgumentException('Too many source files requested.');
        $files = $this->normalizeFiles($this->inventory($folder));
        $byId = [];
        $byPath = [];
        foreach ($files as $file) {
            $byId[$file['id']] = $file;
            $byPath[$file['path']] = $file;
        }

        $requested = [];
        foreach ($fileIds as $fileId) {
            if (!is_string($fileId) || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1) {
                throw new InvalidArgumentException('Invalid Core source file identity.');
            }
            $requested[$fileId] = true;
        }

        $result = [];
        $scannedBytes = 0;
        foreach (array_keys($requested) as $fileId) {
            $file = $byId[$fileId] ?? null;
            if (!is_array($file)) continue;
            $size = (int)$file['size'];
            $reason = null;
            if ($size > self::MAX_DEPENDENCY_SCAN_BYTES) {
                $reason = 'file_limit';
            } elseif ($scannedBytes + $size > self::MAX_DEPENDENCY_TOTAL_BYTES) {
                $reason = 'aggregate_limit';
            }
            if ($reason !== null) {
                $result[$fileId] = ['scanned' => false, 'reason' => $reason, 'dependencies' => []];
                continue;
            }

            $source = $this->service()->source($folder, $fileId);
            if (!is_array($source) || ($source['id'] ?? null) !== $fileId || ($source['path'] ?? null) !== $file['path']
                || !is_string($source['source'] ?? null) || strlen($source['source']) > self::MAX_DEPENDENCY_SCAN_BYTES
                || !is_string($source['sha256'] ?? null) || !hash_equals(hash('sha256', $source['source']), $source['sha256'])
                || !hash_equals((string)$file['sha256'], $source['sha256'])) {
                throw new RuntimeException('Core source changed during dependency inventory.');
            }
            $scannedBytes += strlen($source['source']);
            $result[$fileId] = [
                'scanned' => true,
                'reason' => null,
                'dependencies' => $this->dependencies($source['source'], (string)$file['path'], $byPath),
            ];
        }
        return $result;
    }

    private function inventory(string $folder): array
    {
        $this->registeredTheme($folder);
        $inventory = $this->service()->inventory($folder);
        if (!is_array($inventory) || !is_array($inventory['theme'] ?? null) || !is_array($inventory['files'] ?? null)
            || !array_is_list($inventory['files'])) {
            throw new RuntimeException('Core returned an invalid installed-theme inventory.');
        }
        $theme = $inventory['theme'];
        if (($theme['id'] ?? null) !== (int)$this->registeredTheme($folder)['id'] || ($theme['folder'] ?? null) !== $folder
            || !is_bool($theme['active'] ?? null) || !is_bool($theme['assigned'] ?? null)
            || !is_bool($theme['store'] ?? null) || !is_bool($theme['system'] ?? null)) {
            throw new RuntimeException('Core returned an invalid installed-theme state.');
        }
        return $inventory;
    }

    private function service(): ThemeSourceService
    {
        if (!function_exists('theme_source_service')) {
            throw new RuntimeException('Core ThemeSourceService is unavailable; Theme Builder requires Jyavani Core 2.3.164 or newer.');
        }
        $service = theme_source_service($this->pdo);
        if (!$service instanceof ThemeSourceService) {
            throw new RuntimeException('Core ThemeSourceService contract is unavailable.');
        }
        return $service;
    }

    private function normalizeFiles(array $inventory): array
    {
        $records = $inventory['files'];
        if (count($records) > self::MAX_FILES) throw new RuntimeException('Core returned too many installed-theme files.');

        $files = [];
        foreach ($records as $record) {
            if (!is_array($record)) throw new RuntimeException('Core returned an invalid installed-theme file record.');
            $id = $record['id'] ?? null;
            $path = $record['path'] ?? null;
            if (!is_string($id) || preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1
                || !is_string($path) || !$this->validRelativePhpPath($path)
                || !is_int($record['size'] ?? null) || $record['size'] < 0 || $record['size'] > 5242880
                || !is_string($record['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $record['sha256']) !== 1) {
                throw new RuntimeException('Core returned an invalid installed-theme file identity.');
            }
            $classification = $this->classify($path);
            $files[] = array_merge($record, [
                'id' => $id,
                'path' => $path,
                'name' => basename($path),
                'directory' => dirname($path) === '.' ? '' : dirname($path),
                'category' => $classification['category'],
                'category_label' => $classification['label'],
                'slot' => $classification['slot'],
            ]);
        }
        usort($files, static fn(array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));
        return $files;
    }

    private function registeredTheme(string $folder): array
    {
        $this->assertFolder($folder);
        $stmt = $this->pdo->prepare('SELECT * FROM themes WHERE folder_name = ? LIMIT 1');
        $stmt->execute([$folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['folder_name'] ?? null) || !hash_equals($folder, $row['folder_name'])) {
            throw new RuntimeException('Theme is not registered with this exact folder identity.');
        }
        return $row;
    }

    private function assertFolder(string $folder): void
    {
        if (strlen($folder) > 128 || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) !== 1
            || in_array($folder, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid theme folder.');
        }
    }

    private function validRelativePhpPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 512 || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || !str_ends_with(strtolower($path), '.php')) return false;
        foreach (explode('/', $path) as $part) if ($part === '' || $part === '.' || $part === '..') return false;
        return true;
    }

    private function classify(string $path): array
    {
        $slot = array_search($path, ThemeWorkspace::slotFiles(), true);
        if ($slot !== false) return ['category' => 'slot', 'label' => 'Canonical Slot', 'slot' => $slot];
        if (str_starts_with($path, 'partials/shortcodes/section/')) return ['category' => 'section-wrapper', 'label' => 'Theme Section Wrapper', 'slot' => null];
        if (str_starts_with($path, 'main/sections/')) return ['category' => 'section', 'label' => 'Theme Section', 'slot' => null];
        if (str_starts_with($path, 'partials/shortcodes/')) return ['category' => 'shortcode', 'label' => 'Shortcode Partial', 'slot' => null];
        if (str_starts_with($path, 'partials/')) return ['category' => 'partial', 'label' => 'Partial', 'slot' => null];
        if (str_starts_with($path, 'helpers/')) return ['category' => 'helper', 'label' => 'Helper', 'slot' => null];
        if (str_starts_with($path, 'main/')) return ['category' => 'main-view', 'label' => 'Main View', 'slot' => null];
        return ['category' => 'php', 'label' => 'PHP', 'slot' => null];
    }

    private function dependencies(string $source, string $sourcePath, array $byPath): array
    {
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
            $target = $relative !== null ? ($byPath[$relative] ?? null) : null;
            if (!is_array($target)) continue;
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
        while (isset($tokens[$index]) && is_array($tokens[$index])
            && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $index++;
        return $index;
    }

    private function decodeStringLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2) return null;
        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[strlen($literal) - 1] !== $quote) return null;
        $value = substr($literal, 1, -1);
        return $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
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
                if ($parts === []) return null;
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $path = implode('/', $parts);
        return str_ends_with(strtolower($path), '.php') ? $path : null;
    }

    private function themeSummary(array $row, int $phpFiles, ?array $coreTheme = null, ?string $error = null): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'folder' => (string)($row['folder_name'] ?? ''),
            'name' => (string)($row['name'] ?? $row['folder_name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'version' => (string)($row['version'] ?? ''),
            'author' => (string)($row['author'] ?? ''),
            'active' => $coreTheme !== null ? $coreTheme['active'] : !empty($row['is_active']),
            'assigned' => $coreTheme !== null ? $coreTheme['assigned'] : false,
            'system' => $coreTheme !== null ? $coreTheme['system'] : !empty($row['is_system']),
            'store' => $coreTheme !== null ? $coreTheme['store'] : (trim((string)($row['store_url'] ?? '')) !== '' || trim((string)($row['store_slug'] ?? '')) !== ''),
            'php_files' => $phpFiles,
            'inspectable' => $error === null,
            'error' => $error,
        ];
    }
}
