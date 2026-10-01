<?php

declare(strict_types=1);

final class ThemeBuilderLegacyHistory
{
    private const MAX_ENTRIES = 5000;
    private const MAX_DEPTH = 8;
    private const MAX_PATH_BYTES = 512;
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_TOTAL_BYTES = 134217728;

    public function buildExport(int $actorId): array
    {
        if ($actorId < 1 || !class_exists('ZipArchive')) {
            throw new RuntimeException('Protected legacy history export is unavailable.');
        }

        $base = $this->baseDirectory();
        $exportDirectory = $this->exportDirectory($base);
        $this->cleanupAbandonedExports($exportDirectory);
        $temporary = $exportDirectory . '/legacy-history-' . bin2hex(random_bytes(16)) . '.zip';
        $expected = [];
        $entries = 0;
        $bytes = 0;
        $files = [];

        try {
            $created = $this->createProtectedExport($temporary);
            $zip = new ZipArchive();
            $oldUmask = umask(0077);
            try {
                $opened = $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            } finally {
                umask($oldUmask);
            }
            if ($opened !== true) {
                throw new RuntimeException('Could not create the protected legacy history export.');
            }
            try {
                foreach (['.baselines' => 'baselines', '.revisions' => 'revisions'] as $directory => $archiveRoot) {
                    $root = $base . '/' . $directory;
                    if (!file_exists($root) && !is_link($root)) continue;
                    $this->assertLegacyRoot($root, $base);
                    $rootStat = @lstat($root);
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $entry) {
                        if (++$entries > self::MAX_ENTRIES) throw new RuntimeException('Legacy history contains too many entries.');
                        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
                        $this->assertRelativePath($relative);
                        if (substr_count($relative, '/') + 1 > self::MAX_DEPTH) {
                            throw new RuntimeException('Legacy history nesting is too deep.');
                        }
                        $path = $entry->getPathname();
                        $stat = @lstat($path);
                        if (!is_array($rootStat) || !is_array($stat) || is_link($path) || (($stat['mode'] & 0002) !== 0)
                            || (int)$stat['uid'] !== (int)$rootStat['uid']) {
                            throw new RuntimeException('Legacy history contains an unsafe entry.');
                        }
                        $type = $stat['mode'] & 0170000;
                        $real = realpath($path);
                        if ($real === false || !$this->isWithin($root, $real)) {
                            throw new RuntimeException('Legacy history escapes private storage.');
                        }
                        if ($type === 0040000) {
                            if (!is_readable($real)) throw new RuntimeException('Legacy history contains an unreadable directory.');
                            continue;
                        }
                        if ($type !== 0100000 || (int)$stat['nlink'] !== 1 || !is_readable($real)) {
                            throw new RuntimeException('Legacy history contains a special or unreadable file.');
                        }

                        $state = $this->readRegularFile($real, $root);
                        $bytes += $state['size'];
                        if ($bytes > self::MAX_TOTAL_BYTES) throw new RuntimeException('Legacy history exceeds the export size limit.');
                        $archiveName = $archiveRoot . '/' . $relative;
                        $caseKey = strtolower($archiveName);
                        if (isset($expected[$caseKey])) throw new RuntimeException('Legacy history contains colliding archive paths.');
                        if (!$zip->addFromString($archiveName, $state['content'])) {
                            throw new RuntimeException('Could not add verified legacy history to the export.');
                        }
                        $expected[$caseKey] = ['name' => $archiveName, 'size' => $state['size'], 'sha256' => $state['sha256']];
                        $files[] = ['path' => $archiveName, 'size' => $state['size'], 'sha256' => $state['sha256']];
                    }
                }

                $manifest = json_encode([
                    'schema' => 1,
                    'scope' => 'theme_builder_legacy_history',
                    'exported_at' => gmdate('c'),
                    'exported_by' => $actorId,
                    'source' => 'cfg/var/theme-builder/.baselines and .revisions',
                    'files' => $files,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
                if (strlen($manifest) > self::MAX_FILE_BYTES || $bytes + strlen($manifest) > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('Legacy history metadata exceeds the export size limit.');
                }
                if (!$zip->addFromString('legacy-history.json', $manifest)) {
                    throw new RuntimeException('Could not add protected export metadata.');
                }
                $expected['legacy-history.json'] = [
                    'name' => 'legacy-history.json',
                    'size' => strlen($manifest),
                    'sha256' => hash('sha256', $manifest),
                ];
            } finally {
                if (!$zip->close()) throw new RuntimeException('Could not finalize the protected legacy history export.');
            }

            if (!chmod($temporary, 0600)) throw new RuntimeException('Could not protect the legacy history export.');
            clearstatcache(true, $temporary);
            $closed = @lstat($temporary);
            if (!is_array($closed) || (($closed['mode'] & 0170000) !== 0100000) || (($closed['mode'] & 0777) !== 0600)
                || (int)$closed['nlink'] !== 1 || (int)$closed['size'] < 1 || (int)$closed['size'] > self::MAX_TOTAL_BYTES
                || (int)$created['uid'] !== (int)$closed['uid']) {
                throw new RuntimeException('Protected legacy history export identity changed during creation.');
            }
            $this->verifyArchive($temporary, $expected);
            $result = $this->describeExport($temporary) + [
                'download_name' => 'theme-builder-legacy-history-' . gmdate('Ymd-His') . '.zip',
            ];
            $temporary = '';
            return $result;
        } finally {
            if ($temporary !== '' && is_file($temporary) && !is_link($temporary)) @unlink($temporary);
        }
    }

    public function openExport(array $descriptor)
    {
        $path = $this->validatedExportPath($descriptor);
        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        if (!is_array($before) || !is_array($opened) || !$this->sameFile($before, $opened)
            || !$this->matchesDescriptor($opened, $descriptor)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Protected legacy history export changed before download.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                fclose($handle);
                throw new RuntimeException('Could not verify the legacy history export.');
            }
            if ($chunk === '') continue;
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
        }
        $afterRead = fstat($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        $sha256 = hash_final($hash);
        if (!is_array($afterRead) || !is_array($after) || !$this->sameFile($opened, $afterRead)
            || !$this->sameFile($afterRead, $after) || $bytes !== (int)$descriptor['size']
            || !hash_equals((string)$descriptor['sha256'], $sha256)) {
            fclose($handle);
            throw new RuntimeException('Protected legacy history export verification failed.');
        }
        rewind($handle);
        return $handle;
    }

    public function cleanupExport(array $descriptor): bool
    {
        $handle = null;
        try {
            $path = $this->validatedExportPath($descriptor);
            $before = @lstat($path);
            $handle = @fopen($path, 'rb');
            $opened = is_resource($handle) ? fstat($handle) : false;
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($before) || !is_array($opened) || !is_array($after)
                || !$this->sameFile($before, $opened) || !$this->sameFile($opened, $after)
                || !$this->matchesDescriptor($opened, $descriptor)) return false;
            return @unlink($path) && !file_exists($path) && !is_link($path);
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($handle)) fclose($handle);
        }
    }

    private function validatedExportPath(array $descriptor): string
    {
        if (!is_string($descriptor['path'] ?? null) || !is_int($descriptor['size'] ?? null)
            || $descriptor['size'] < 0 || $descriptor['size'] > self::MAX_TOTAL_BYTES
            || !is_string($descriptor['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $descriptor['sha256']) !== 1
            || !is_int($descriptor['dev'] ?? null) || $descriptor['dev'] < 0
            || !is_int($descriptor['ino'] ?? null) || $descriptor['ino'] < 1
            || !is_int($descriptor['uid'] ?? null) || $descriptor['uid'] < 0) {
            throw new InvalidArgumentException('Invalid legacy history export descriptor.');
        }
        $directory = $this->exportDirectory($this->baseDirectory());
        $path = $descriptor['path'];
        if (dirname($path) !== $directory || preg_match('/\Alegacy-history-[a-f0-9]{32}\.zip\z/D', basename($path)) !== 1
            || is_link($path)) {
            throw new RuntimeException('Legacy history export path is unsafe.');
        }
        $stat = @lstat($path);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000) || ($stat['mode'] & 0077) !== 0
            || (int)$stat['nlink'] !== 1 || !$this->matchesDescriptor($stat, $descriptor)) {
            throw new RuntimeException('Legacy history export identity is unsafe.');
        }
        return $path;
    }

    private function createProtectedExport(string $path): array
    {
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($handle)) throw new RuntimeException('Could not create the protected legacy history export.');
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || (($opened['mode'] & 0170000) !== 0100000) || (($opened['mode'] & 0777) !== 0600)
                || (int)$opened['nlink'] !== 1 || (int)$opened['size'] !== 0) {
                throw new RuntimeException('Could not protect the legacy history export.');
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Could not sync the protected legacy history export.');
            }
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || !$this->sameFile($opened, $stat)) {
            throw new RuntimeException('Protected legacy history export identity changed during creation.');
        }
        return $stat;
    }

    private function describeExport(string $path): array
    {
        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        if (!is_array($before) || !is_array($opened) || !$this->sameFile($before, $opened)
            || (($opened['mode'] & 0777) !== 0600) || (int)$opened['nlink'] !== 1
            || (int)$opened['size'] < 1 || (int)$opened['size'] > self::MAX_TOTAL_BYTES) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Protected legacy history export verification failed.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) throw new RuntimeException('Could not verify the legacy history export.');
                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
            $afterRead = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($afterRead) || !is_array($after) || !$this->sameFile($opened, $afterRead)
            || !$this->sameFile($afterRead, $after) || $bytes !== (int)$opened['size']) {
            throw new RuntimeException('Protected legacy history export changed during verification.');
        }
        return [
            'path' => $path,
            'size' => $bytes,
            'sha256' => hash_final($hash),
            'dev' => (int)$opened['dev'],
            'ino' => (int)$opened['ino'],
            'uid' => (int)$opened['uid'],
        ];
    }

    private function matchesDescriptor(array $stat, array $descriptor): bool
    {
        return (int)$stat['dev'] === (int)$descriptor['dev'] && (int)$stat['ino'] === (int)$descriptor['ino']
            && (int)$stat['uid'] === (int)$descriptor['uid'] && (int)$stat['size'] === (int)$descriptor['size'];
    }

    private function assertLegacyRoot(string $root, string $base): void
    {
        $stat = @lstat($root);
        $real = realpath($root);
        $baseStat = @lstat($base);
        if (!is_array($stat) || !is_array($baseStat) || is_link($root) || (($stat['mode'] & 0170000) !== 0040000)
            || (($stat['mode'] & 0002) !== 0) || (int)$stat['uid'] !== (int)$baseStat['uid']
            || $real === false || dirname($real) !== $base) {
            throw new RuntimeException('Legacy history storage is unsafe.');
        }
    }

    private function readRegularFile(string $path, string $root): array
    {
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || (($before['mode'] & 0170000) !== 0100000)
            || (int)$before['nlink'] !== 1 || (int)$before['size'] > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Legacy history file is unsafe or too large.');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new RuntimeException('Legacy history file is unreadable.');
        $content = '';
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !$this->sameFile($before, $opened)) throw new RuntimeException('Legacy history file changed while opening.');
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) throw new RuntimeException('Legacy history file could not be read.');
                $content .= $chunk;
                if (strlen($content) > self::MAX_FILE_BYTES) throw new RuntimeException('Legacy history file is too large.');
            }
            $afterRead = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        $real = realpath($path);
        if (!is_array($afterRead) || !is_array($after) || $real === false || !$this->isWithin($root, $real)
            || !$this->sameFile($opened, $afterRead) || !$this->sameFile($afterRead, $after)) {
            throw new RuntimeException('Legacy history file changed during reading.');
        }
        return ['content' => $content, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
    }

    private function verifyArchive(string $path, array $expected): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles !== count($expected)) {
            throw new RuntimeException('Legacy history archive verification failed.');
        }
        try {
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || !isset($expected[strtolower($name)]) || isset($seen[strtolower($name)])) {
                    throw new RuntimeException('Legacy history archive contains an unexpected entry.');
                }
                $record = $expected[strtolower($name)];
                if (!hash_equals($record['name'], $name)) throw new RuntimeException('Legacy history archive path changed.');
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) throw new RuntimeException('Legacy history archive entry is unreadable.');
                $hash = hash_init('sha256');
                $bytes = 0;
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) { fclose($stream); throw new RuntimeException('Legacy history archive entry is unreadable.'); }
                    $bytes += strlen($chunk);
                    hash_update($hash, $chunk);
                }
                fclose($stream);
                if ($bytes !== $record['size'] || !hash_equals($record['sha256'], hash_final($hash))) {
                    throw new RuntimeException('Legacy history archive entry verification failed.');
                }
                $seen[strtolower($name)] = true;
            }
        } finally {
            $zip->close();
        }
    }

    private function baseDirectory(): string
    {
        $base = ThemeWorkspace::baseDir();
        $real = realpath($base);
        $stat = @lstat($base);
        if ($real === false || !is_array($stat) || is_link($base) || (($stat['mode'] & 0170000) !== 0040000)
            || (($stat['mode'] & 0002) !== 0)) {
            throw new RuntimeException('Theme Builder private storage is unsafe.');
        }
        $publicRoots = [];
        if (defined('VIEWS_BASE')) $publicRoots[] = realpath((string)VIEWS_BASE);
        if (defined('PUBLIC_PATH')) $publicRoots[] = realpath((string)PUBLIC_PATH);
        foreach (array_filter($publicRoots) as $publicRoot) {
            if ($this->isWithin((string)$publicRoot, $real)) {
                throw new RuntimeException('Legacy history export storage cannot be public.');
            }
        }
        return $real;
    }

    private function exportDirectory(string $base): string
    {
        $path = $base . '/.legacy-exports';
        if ((file_exists($path) && (is_link($path) || !is_dir($path))) || (!is_dir($path) && !mkdir($path, 0700))) {
            throw new RuntimeException('Protected legacy export storage is unavailable.');
        }
        chmod($path, 0700);
        $real = realpath($path);
        $stat = @lstat($path);
        if ($real === false || dirname($real) !== $base || !is_array($stat) || is_link($path)
            || (($stat['mode'] & 0170000) !== 0040000) || (($stat['mode'] & 0777) !== 0700)) {
            throw new RuntimeException('Protected legacy export storage is unsafe.');
        }
        return $real;
    }

    private function cleanupAbandonedExports(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $directory . '/' . $name;
            $stat = @lstat($path);
            if (preg_match('/\Alegacy-history-[a-f0-9]{32}\.zip\z/D', $name) !== 1 || !is_array($stat)
                || is_link($path) || (($stat['mode'] & 0170000) !== 0100000) || ($stat['mode'] & 0077) !== 0
                || (int)$stat['nlink'] !== 1) {
                throw new RuntimeException('Protected legacy export storage contains an unsafe entry.');
            }
            if ((int)$stat['mtime'] < time() - 3600 && !@unlink($path)) {
                throw new RuntimeException('An abandoned legacy history export could not be removed.');
            }
        }
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || !mb_check_encoding($path, 'UTF-8')) {
            throw new RuntimeException('Legacy history contains an unsafe path.');
        }
        foreach (explode('/', $path) as $part) if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Legacy history contains an unsafe path.');
        }
    }

    private function sameFile(array $a, array $b): bool
    {
        return (int)$a['dev'] === (int)$b['dev'] && (int)$a['ino'] === (int)$b['ino']
            && (int)$a['size'] === (int)$b['size'] && (int)$a['mtime'] === (int)$b['mtime']
            && (($b['mode'] & 0170000) === 0100000);
    }

    private function isWithin(string $root, string $path): bool
    {
        return $path === $root || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0;
    }
}
