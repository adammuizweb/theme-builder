<?php

declare(strict_types=1);

final class ThemeForkService
{
    private const MAX_ENTRIES = 5000;
    private const MAX_DEPTH = 32;
    private const MAX_PATH_BYTES = 512;
    private const MAX_FILE_BYTES = 67108864;
    private const MAX_TREE_BYTES = 268435456;
    private const MAX_MANIFEST_BYTES = 1048576;
    private const MAX_SOURCE_BYTES = 5242880;
    private const MAX_BASELINE_BYTES = 67108864;
    private const MAX_BASELINE_FILES = 1000;
    private const MAX_REVISIONS_PER_FILE = 200;
    private const MAX_REVISION_BYTES_PER_FILE = 268435456;
    private const MAX_EXPORT_ENTRIES = 5000;
    private const MAX_EXPORT_BYTES = 134217728;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function fork(string $sourceFolder, string $targetFolder, string $name, string $title, int $actorId): array
    {
        if (!ThemeWorkspace::isValidSlug($targetFolder)) {
            return ['success' => false, 'error' => 'Use a new lowercase folder containing only letters, numbers, hyphens, and underscores.'];
        }
        $name = trim($name);
        $title = trim($title);
        if ($name === '' || mb_strlen($name) > 150 || $title === '' || mb_strlen($title) > 150) {
            return ['success' => false, 'error' => 'Fork name or title is invalid.'];
        }
        if ($actorId < 1) return ['success' => false, 'error' => 'Site Owner identity is unavailable.'];

        $coreLocks = [];
        $locks = [];
        $stage = '';
        $target = '';
        $metadataPath = '';
        $promoted = false;
        $registered = false;
        $registeredThemeId = 0;
        $promotedIdentity = null;
        $databaseRollbackComplete = true;

        try {
            $base = $this->themesRoot();
            $target = $base . DIRECTORY_SEPARATOR . $targetFolder;
            $coreLocks = $this->acquireCoreLocks(['0-theme-lifecycle', $sourceFolder, $targetFolder]);
            if (!function_exists('package_publication_recovery_paths')) {
                throw new RuntimeException('Core package publication recovery checks are unavailable.');
            }
            $recoveryPaths = package_publication_recovery_paths($target);
            if ($recoveryPaths !== []) {
                throw new RuntimeException('A prior theme publication recovery artifact requires manual resolution. Inspect and restore or archive it before retrying: ' . basename($recoveryPaths[0]));
            }
            $locks = $this->acquireLocks(['installed:' . $sourceFolder, 'installed:' . $targetFolder]);
            $sourceRow = $this->registeredTheme($sourceFolder);
            $sourceRoot = $this->themeRoot($sourceFolder);
            $this->assertTargetAvailable($targetFolder, $target);

            $sourceSnapshot = $this->scanTree($sourceRoot);
            if (!isset($sourceSnapshot['entries']['theme.json']) || $sourceSnapshot['entries']['theme.json']['type'] !== 'file') {
                throw new RuntimeException('Source theme manifest is missing.');
            }

            $stage = $base . '/.theme-builder-fork-' . $targetFolder . '-' . bin2hex(random_bytes(12));
            if (!mkdir($stage, 0700) || is_link($stage)) throw new RuntimeException('Could not create fork staging.');
            chmod($stage, 0700);
            $stageReal = realpath($stage);
            if ($stageReal === false || dirname($stageReal) !== $base) throw new RuntimeException('Fork staging escaped the theme root.');
            $stage = $stageReal;

            $this->copySnapshot($sourceRoot, $stage, $sourceSnapshot['entries']);
            $manifest = $this->transformManifest($stage, $sourceFolder, $targetFolder, $name, $title);
            $this->lintPhpTree($stage);

            if ($this->snapshotComparable($sourceSnapshot) !== $this->snapshotComparable($this->scanTree($sourceRoot))) {
                throw new RuntimeException('Source theme changed during fork creation.');
            }
            $stageSnapshot = $this->scanTree($stage);
            $this->assertForkSnapshot($sourceSnapshot, $stageSnapshot);
            $this->assertTargetAvailable($targetFolder, $target);
            $this->applyPublishedModes($stage);

            $stageIdentity = @lstat($stage);
            if (!is_array($stageIdentity) || (($stageIdentity['mode'] & 0170000) !== 0040000)) {
                throw new RuntimeException('Fork staging identity is unsafe before publication.');
            }
            if (!rename($stage, $target)) throw new RuntimeException('Could not publish the forked theme.');
            $stage = '';
            $promoted = true;
            $promotedIdentity = @lstat($target);
            if (!is_array($promotedIdentity) || !$this->sameDirectory($stageIdentity, $promotedIdentity) || !chmod($target, 0755)) {
                throw new RuntimeException('Could not establish the published fork identity.');
            }
            $this->syncDirectory($base);

            $this->pdo->beginTransaction();
            if (!function_exists('register_theme_in_db') || !register_theme_in_db($this->pdo, $targetFolder, $manifest, false)) {
                throw new RuntimeException('Core could not register the forked theme.');
            }
            $registered = true;
            $targetRow = $this->registeredTheme($targetFolder);
            $registeredThemeId = (int)$targetRow['id'];
            if ((int)$targetRow['id'] === (int)$sourceRow['id'] || !empty($targetRow['is_active']) || !empty($targetRow['is_system'])
                || (string)($targetRow['store_url'] ?? '') !== '' || (string)($targetRow['store_slug'] ?? '') !== '') {
                throw new RuntimeException('Fork registration identity is unsafe.');
            }

            $metadata = [
                'schema' => 1,
                'folder' => $targetFolder,
                'theme_id' => (int)$targetRow['id'],
                'created_at' => gmdate('c'),
                'created_by' => $actorId,
                'source' => [
                    'folder' => $sourceFolder,
                    'theme_id' => (int)$sourceRow['id'],
                    'version' => (string)($manifest['version'] ?? ''),
                    'tree_sha256' => $this->snapshotDigest($sourceSnapshot),
                ],
                'fork_tree_sha256' => $this->snapshotDigest($stageSnapshot),
                'root_identity' => [
                    'dev' => (int)$promotedIdentity['dev'],
                    'ino' => (int)$promotedIdentity['ino'],
                ],
            ];
            $metadataPath = $this->metadataPath($targetFolder);
            if (file_exists($metadataPath) || is_link($metadataPath)) throw new RuntimeException('Fork metadata already exists.');
            $this->writeJsonAtomic($metadataPath, $metadata, 0660);
            $this->pdo->commit();

            return [
                'success' => true,
                'folder' => $targetFolder,
                'theme_id' => (int)$targetRow['id'],
                'active' => false,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($registered && $registeredThemeId > 0) {
                try {
                    $delete = $this->pdo->prepare('DELETE FROM themes WHERE id = ? AND folder_name = ? AND is_active = 0 AND is_system = 0');
                    $delete->execute([$registeredThemeId, $targetFolder]);
                    $verify = $this->pdo->prepare('SELECT folder_name FROM themes WHERE id = ? AND folder_name = ?');
                    $verify->execute([$registeredThemeId, $targetFolder]);
                    $remaining = $verify->fetchAll(PDO::FETCH_COLUMN);
                    $databaseRollbackComplete = true;
                    foreach ($remaining as $remainingFolder) {
                        if (!is_string($remainingFolder) || !hash_equals($targetFolder, $remainingFolder)) {
                            $databaseRollbackComplete = false;
                            break;
                        }
                        $databaseRollbackComplete = false;
                    }
                } catch (Throwable) {
                    $databaseRollbackComplete = false;
                }
            }
            if ($metadataPath !== '' && is_file($metadataPath) && !is_link($metadataPath)) @unlink($metadataPath);
            $rollbackComplete = !$promoted || $target === '' || $this->quarantineAndRemove($target, $targetFolder, $promotedIdentity);
            if ($stage !== '') $this->removeOwnedTree($stage);
            $message = $this->safeError($error);
            if (!$rollbackComplete) $message .= ' Automatic filesystem rollback was incomplete; administrator cleanup is required.';
            if (!$databaseRollbackComplete) $message .= ' Automatic registry rollback was incomplete; administrator cleanup is required.';
            return ['success' => false, 'error' => $message];
        } finally {
            $this->releaseLocks($locks);
            $this->releaseCoreLocks($coreLocks);
        }
    }

    public function forkState(string $folder): array
    {
        $state = ['managed' => false, 'editable' => false, 'reason' => 'Only inactive Theme Builder forks can be edited.', 'metadata' => null];
        if (!ThemeWorkspace::isValidSlug($folder)) return $state;

        try {
            $metadata = $this->readMetadata($folder);
            if ($metadata === null) return $state;
            $row = $this->registeredTheme($folder);
            if ((int)($metadata['theme_id'] ?? 0) !== (int)$row['id']) return $state;
            $root = $this->themeRoot($folder);
            $rootStat = @lstat($root);
            if (!is_array($rootStat) || (int)($metadata['root_identity']['dev'] ?? -1) !== (int)$rootStat['dev']
                || (int)($metadata['root_identity']['ino'] ?? -1) !== (int)$rootStat['ino']) return $state;
            $manifest = $this->readManifest($root, $folder);
            $managed = !isset($manifest['store'])
                && (string)($row['store_url'] ?? '') === ''
                && (string)($row['store_slug'] ?? '') === ''
                && empty($row['is_system']);
            if (!$managed) return $state;

            $state['managed'] = true;
            $state['metadata'] = $metadata;
            if (!empty($row['is_active'])) {
                $state['reason'] = 'This managed fork is active. Phase 2 editing is limited to inactive forks.';
                return $state;
            }
            if ($this->assignmentCount((int)$row['id']) > 0) {
                $state['reason'] = 'This managed fork is assigned to a live slot. Remove its assignments before editing.';
                return $state;
            }
            $state['editable'] = true;
            $state['reason'] = '';
            return $state;
        } catch (Throwable) {
            return $state;
        }
    }

    public function savePhp(string $folder, string $fileId, string $content, string $expectedHash, int $actorId, string $note = ''): array
    {
        return $this->replacePhp($folder, $fileId, $content, $expectedHash, $actorId, $note, false, '', [], 'save', null);
    }

    public function saveDirectPhp(string $folder, string $fileId, string $targetToken, string $content, string $expectedHash,
        int $actorId, string $note = '', array $acknowledgements = []): array
    {
        return $this->replacePhp($folder, $fileId, $content, $expectedHash, $actorId, $note, true, $targetToken,
            $acknowledgements, 'save', null);
    }

    public function directEditState(string $folder): array
    {
        $state = [
            'editable' => false,
            'reason' => 'This installed theme is unavailable for direct editing.',
            'active' => false,
            'store' => false,
            'system' => false,
            'assigned' => false,
        ];
        try {
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $manifest = $this->readManifest($root, $folder);
            $state['active'] = !empty($row['is_active']);
            $state['system'] = !empty($row['is_system'])
                || $folder === (defined('DEFAULT_THEME_FOLDER') ? (string)DEFAULT_THEME_FOLDER : 'default');
            $manifestStoreUrl = is_string($manifest['store']['url'] ?? null) ? trim($manifest['store']['url']) : '';
            $manifestStoreSlug = is_string($manifest['store']['slug'] ?? null) ? trim($manifest['store']['slug']) : '';
            $state['store'] = trim((string)($row['store_url'] ?? '')) !== ''
                || trim((string)($row['store_slug'] ?? '')) !== '' || $manifestStoreUrl !== '' || $manifestStoreSlug !== '';
            $state['assigned'] = $this->assignmentCount((int)$row['id']) > 0;
            if ($state['system']) {
                $state['reason'] = 'System and default themes are read-only. Fork this theme before editing.';
                return $state;
            }
            $state['editable'] = true;
            $state['reason'] = '';
        } catch (Throwable $error) {
            $state['reason'] = $this->safeError($error);
        }
        return $state;
    }

    public function dirtyState(string $folder): array
    {
        try {
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $rootStat = $this->directoryIdentity($root);
            $baseline = $this->readBaseline($folder, (int)$row['id'], $rootStat);
            $current = $this->phpSnapshot($root, $folder);
            if ($baseline === null) {
                $files = [];
                foreach ($current as $path => $record) {
                    $files[$path] = [
                        'status' => 'untracked',
                        'baseline_sha256' => null,
                        'current_sha256' => (string)$record['sha256'],
                    ];
                }
                return [
                    'tracked' => false,
                    'locally_modified' => false,
                    'changed_count' => 0,
                    'counts' => ['untracked' => count($files)],
                    'files' => $files,
                    'registered_theme_id' => (int)$row['id'],
                    'root_identity' => ['dev' => (string)$rootStat['dev'], 'ino' => (string)$rootStat['ino']],
                    'current_version' => (string)($row['version'] ?? ''),
                    'store_url' => (string)($row['store_url'] ?? ''),
                    'store_slug' => (string)($row['store_slug'] ?? ''),
                ];
            }
            $files = [];
            $counts = ['clean' => 0, 'modified' => 0, 'added' => 0, 'deleted' => 0];
            foreach ($baseline['files'] as $path => $original) {
                if (!isset($current[$path])) {
                    $status = 'deleted';
                    $currentHash = null;
                } else {
                    $currentHash = (string)$current[$path]['sha256'];
                    $status = hash_equals((string)$original['sha256'], $currentHash) ? 'clean' : 'modified';
                }
                $counts[$status]++;
                $files[$path] = [
                    'status' => $status,
                    'baseline_sha256' => (string)$original['sha256'],
                    'current_sha256' => $currentHash,
                ];
            }
            foreach ($current as $path => $record) {
                if (isset($baseline['files'][$path])) continue;
                $counts['added']++;
                $files[$path] = ['status' => 'added', 'baseline_sha256' => null, 'current_sha256' => (string)$record['sha256']];
            }
            ksort($files, SORT_STRING);
            $changed = $counts['modified'] + $counts['added'] + $counts['deleted'];
            $baselineVersion = (string)($baseline['installed']['version'] ?? '');
            $currentVersion = (string)($row['version'] ?? '');
            $upstreamChanged = $changed > 0 && $baselineVersion !== '' && $currentVersion !== '' && $baselineVersion !== $currentVersion;
            return [
                'tracked' => true,
                'baseline_id' => (string)$baseline['baseline_id'],
                'locally_modified' => $changed > 0,
                'changed_count' => $changed,
                'upstream_changed' => $upstreamChanged,
                'baseline_version' => $baselineVersion,
                'current_version' => $currentVersion,
                'counts' => $counts,
                'files' => $files,
                'registered_theme_id' => (int)$row['id'],
                'root_identity' => ['dev' => (string)$rootStat['dev'], 'ino' => (string)$rootStat['ino']],
                'store_url' => (string)($row['store_url'] ?? ''),
                'store_slug' => (string)($row['store_slug'] ?? ''),
            ];
        } catch (Throwable $error) {
            return ['tracked' => false, 'locally_modified' => false, 'changed_count' => 0, 'counts' => [], 'files' => [], 'error' => $this->safeError($error)];
        }
    }

    public function refreshBaseline(string $folder, string $origin, int $actorId): array
    {
        if (!in_array($origin, ['core_install', 'core_update'], true)) {
            throw new InvalidArgumentException('Invalid baseline refresh origin.');
        }
        if ($actorId < 0) throw new InvalidArgumentException('Invalid baseline actor identity.');

        $locks = [];
        try {
            // Core invokes completion callbacks while its generic operation lock is still held.
            $locks = $this->acquireLocks(['installed:' . $folder]);
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $rootIdentity = $this->directoryIdentity($root);
            $first = $this->phpSnapshot($root, $folder);
            $second = $this->phpSnapshot($root, $folder);
            if ($first !== $second) throw new RuntimeException('Theme PHP source changed during baseline refresh.');

            $baseline = $this->makeBaseline($folder, $row, $rootIdentity, $second, $actorId, $origin);
            $path = $this->baselinePath($folder, (int)$row['id'], $rootIdentity);
            if (file_exists($path) || is_link($path)) {
                $existingStat = @lstat($path);
                if (!is_array($existingStat) || is_link($path) || (($existingStat['mode'] & 0170000) !== 0100000)) {
                    throw new RuntimeException('Existing theme baseline is unsafe.');
                }
            }
            $this->writeJsonAtomic($path, $baseline, 0660);
            $verified = $this->readBaseline($folder, (int)$row['id'], $rootIdentity);
            if ($verified === null || !hash_equals((string)$baseline['baseline_id'], (string)($verified['baseline_id'] ?? ''))
                || $verified !== $baseline) {
                throw new RuntimeException('Refreshed theme baseline verification failed.');
            }
            return $verified;
        } finally {
            $this->releaseLocks($locks);
        }
    }

    public function buildPhpSourceExport(string $folder, int $actorId): array
    {
        if (!$this->validInstalledFolder($folder) || $actorId < 1) {
            throw new InvalidArgumentException('Invalid theme source export request.');
        }
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP support is unavailable.');

        $coreLocks = [];
        $locks = [];
        $temporary = '';
        try {
            $coreLocks = $this->acquireCoreLocks(['0-theme-lifecycle', $folder]);
            $locks = $this->acquireLocks(['installed:' . $folder]);
            $this->cleanupAbandonedExports();
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $rootIdentity = $this->directoryIdentity($root);
            $exportLimits = ['entries' => 0, 'bytes' => 0, 'names' => []];
            $first = $this->phpSnapshot($root, $folder);
            $second = $this->phpSnapshot($root, $folder);
            if ($first !== $second) {
                throw new RuntimeException('Theme PHP source changed during export capture.');
            }

            $baseline = $this->readBaseline($folder, (int)$row['id'], $rootIdentity);
            $dirty = $this->dirtyStateFromSnapshots($row, $rootIdentity, $baseline, $second);
            $entries = $this->currentExportFiles($root, $second, $exportLimits);
            $revisionExport = $this->exportRevisionFiles($folder, (int)$row['id'], $rootIdentity, $exportLimits);
            foreach ($revisionExport['files'] as $name => $record) $entries[$name] = ['file' => $record];
            $export = [
                'schema' => 1,
                'scope' => 'physical_php_source',
                'exported_at' => gmdate('c'),
                'exported_by' => $actorId,
                'theme' => [
                    'folder' => $folder,
                    'registered_id' => (int)$row['id'],
                    'root_identity' => ['dev' => (string)$rootIdentity['dev'], 'ino' => (string)$rootIdentity['ino']],
                    'version' => (string)($row['version'] ?? ''),
                    'store_url' => (string)($row['store_url'] ?? ''),
                    'store_slug' => (string)($row['store_slug'] ?? ''),
                ],
                'dirty' => $dirty,
                'current_php_files' => [],
                'revision_count' => $revisionExport['count'],
            ];
            foreach ($second as $relative => $record) {
                $export['current_php_files'][$relative] = ['size' => $record['size'], 'sha256' => $record['sha256']];
            }
            $exportJson = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $this->reserveExportEntry('export.json', strlen($exportJson), true, $exportLimits);
            $entries['export.json'] = ['content' => $exportJson];
            if ($baseline !== null) {
                $baselineJson = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
                $this->reserveExportEntry('baseline.json', strlen($baselineJson), true, $exportLimits);
                $entries['baseline.json'] = ['content' => $baselineJson];
            }
            ksort($entries, SORT_STRING);

            $exportDir = $this->privateDirectory('.exports');
            $temporary = $exportDir . '/theme-source-' . bin2hex(random_bytes(16)) . '.zip';
            $this->writeFileExclusive($temporary, '', 0600);
            $zip = new ZipArchive();
            $zipOpen = false;
            $expected = [];
            try {
                if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Could not create the private source export.');
                }
                $zipOpen = true;
                foreach ($entries as $name => $entry) {
                    if (isset($entry['file'])) {
                        $this->assertExportSourceFile($entry['file']);
                        if (!$zip->addFile($entry['file']['path'], $name)) {
                            throw new RuntimeException('Could not add a verified source file to the private export.');
                        }
                        $expected[$name] = ['size' => $entry['file']['size'], 'sha256' => $entry['file']['sha256']];
                        continue;
                    }
                    $content = $entry['content'];
                    if (!$zip->addFromString($name, $content)) {
                        throw new RuntimeException('Could not add protected metadata to the private export.');
                    }
                    $expected[$name] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
                }
                if (!$zip->close()) throw new RuntimeException('Could not finalize the private source export.');
                $zipOpen = false;
            } finally {
                if ($zipOpen) @$zip->close();
            }
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Could not finalize the private source export.');
            }
            foreach ($entries as $entry) {
                if (isset($entry['file'])) $this->assertExportSourceFile($entry['file']);
            }
            $this->assertDirectoryIdentity($root, $rootIdentity);
            if ($first !== $this->phpSnapshot($root, $folder)) {
                throw new RuntimeException('Theme PHP source changed while finalizing the export.');
            }
            $this->verifyExportArchive($temporary, $expected);
            $stat = @lstat($temporary);
            $sha256 = @hash_file('sha256', $temporary);
            if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000) || (($stat['mode'] & 0077) !== 0)
                || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
                throw new RuntimeException('Private source export verification failed.');
            }
            $result = [
                'path' => $temporary,
                'size' => (int)$stat['size'],
                'sha256' => $sha256,
                'download_name' => $folder . '-php-source-' . gmdate('Ymd-His') . '.zip',
            ];
            $temporary = '';
            return $result;
        } finally {
            if ($temporary !== '' && !$this->cleanupPhpSourceExport($temporary)) {
                error_log('[theme-builder-source-export] Could not remove a failed private source export.');
            }
            $this->releaseLocks($locks);
            $this->releaseCoreLocks($coreLocks);
        }
    }

    public function cleanupPhpSourceExport(string $path): bool
    {
        try {
            $directory = $this->privateDirectory('.exports');
            $real = realpath($path);
            if ($real !== false && dirname($real) === $directory
                && preg_match('/\Atheme-source-[a-f0-9]{32}\.zip\z/D', basename($real)) === 1) {
                $directoryStat = @lstat($directory);
                if (!is_array($directoryStat)) return false;
                $verified = $this->openVerifiedExport($path, $directory, $directoryStat);
                try {
                    if (!@unlink($path)) return false;
                } finally {
                    fclose($verified['handle']);
                }
                clearstatcache(true, $path);
                return !file_exists($path) && !is_link($path);
            }
        } catch (Throwable) {
            // Cleanup must not hide the streaming result or its original failure.
        }
        return false;
    }

    public function revisions(string $folder, string $fileId, int $limit = 25): array
    {
        if (!$this->validInstalledFolder($folder) || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1) return [];
        $limit = max(1, min(20, $limit));
        try {
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $rootStat = $this->directoryIdentity($root);
            $source = (new InstalledThemeInspector($this->pdo))->source($folder, $fileId);
            if ($source === null) return [];
            $fileDir = $this->privateDirectory('.revisions') . '/' . (int)$row['id'] . '/' . $fileId;
            if (!is_dir($fileDir) || is_link($fileDir)) return [];
            $records = [];
            foreach (scandir($fileDir, SCANDIR_SORT_DESCENDING) ?: [] as $revisionId) {
                if ($revisionId === '.' || $revisionId === '..') continue;
                if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1) {
                    throw new RuntimeException('Source revision storage contains a malformed revision name.');
                }
                $record = $this->readRevision($fileDir . '/' . $revisionId, $folder, (int)$row['id'], $fileId,
                    (string)$source['path'], $rootStat, true);
                if ($record === null) continue;
                unset($record['source']);
                if (count($records) < $limit) $records[] = $record;
            }
            return $records;
        } catch (Throwable) {
            return [];
        }
    }

    public function restoreDirectPhp(string $folder, string $fileId, string $targetToken, string $revisionId,
        string $expectedHash, int $actorId, string $note = '', array $acknowledgements = []): array
    {
        return $this->restorePhp($folder, $fileId, $targetToken, $revisionId, $expectedHash, $actorId, $note, true, $acknowledgements);
    }

    public function restoreManagedPhp(string $folder, string $fileId, string $revisionId, string $expectedHash,
        int $actorId, string $note = ''): array
    {
        return $this->restorePhp($folder, $fileId, '', $revisionId, $expectedHash, $actorId, $note, false, []);
    }

    private function restorePhp(string $folder, string $fileId, string $targetToken, string $revisionId,
        string $expectedHash, int $actorId, string $note, bool $direct, array $acknowledgements): array
    {
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1) {
            return ['success' => false, 'code' => 'invalid_request', 'error' => 'Invalid source revision request.'];
        }
        try {
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $rootStat = $this->directoryIdentity($root);
            $source = (new InstalledThemeInspector($this->pdo))->source($folder, $fileId);
            if ($source === null) throw new RuntimeException('Source revision target is unavailable.');
            $revisionDir = $this->privateDirectory('.revisions') . '/' . (int)$row['id'] . '/' . $fileId . '/' . $revisionId;
            $revision = $this->readRevision($revisionDir, $folder, (int)$row['id'], $fileId, (string)$source['path'], $rootStat, true);
            if ($revision === null) throw new RuntimeException('Source revision is unavailable or does not match this theme installation.');
            return $this->replacePhp($folder, $fileId, (string)$revision['source'], $expectedHash, $actorId, $note,
                $direct, $targetToken, $acknowledgements, 'restore', $revisionId);
        } catch (Throwable $error) {
            return ['success' => false, 'code' => 'revision_not_found', 'error' => $this->safeError($error)];
        }
    }

    private function replacePhp(string $folder, string $fileId, string $content, string $expectedHash, int $actorId,
        string $note, bool $direct, string $targetToken, array $acknowledgements, string $operation, ?string $restoredFrom): array
    {
        if (!$this->validInstalledFolder($folder) || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1 || $actorId < 1) {
            return ['success' => false, 'code' => 'invalid_request', 'error' => 'Invalid installed source request.'];
        }
        if ($direct && (preg_match('/\A[a-f0-9]{64}\z/D', $targetToken) !== 1 || empty($acknowledgements['direct']))) {
            return ['success' => false, 'code' => 'confirmation_required', 'error' => 'Direct source editing requires explicit confirmation.'];
        }
        if (strlen($content) > self::MAX_SOURCE_BYTES || str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) {
            return ['success' => false, 'error' => 'Source content is invalid or too large.'];
        }
        if (mb_strlen($note) > 500) return ['success' => false, 'error' => 'Change note is too long.'];

        $coreLocks = [];
        $locks = [];
        $temporary = '';
        $revision = null;
        $replacementComplete = false;
        $resultHash = '';
        $revisionId = '';
        $newTargetToken = '';
        try {
            $coreLocks = $this->acquireCoreLocks(['0-theme-lifecycle', $folder]);
            $locks = $this->acquireLocks(['installed:' . $folder]);
            $lockedRow = $this->lockThemeDatabaseState($folder);
            $editState = $direct ? $this->directEditState($folder) : $this->forkState($folder);
            if (!$editState['editable']) throw new RuntimeException('Theme editability changed. Reload before saving.');
            $this->assertRiskAcknowledgements($editState, $acknowledgements, $direct);
            if (!$direct && (int)$lockedRow['id'] !== (int)$editState['metadata']['theme_id']) throw new RuntimeException('Fork registration identity changed.');

            $inspector = new InstalledThemeInspector($this->pdo);
            $source = $inspector->source($folder, $fileId);
            if ($source === null || !hash_equals($expectedHash, (string)$source['sha256'])) {
                throw new RuntimeException('Theme source changed after it was opened. Reload before saving.');
            }
            if ($direct && !hash_equals($targetToken, (string)($source['target_token'] ?? ''))) {
                throw new RuntimeException('Theme source identity changed after it was opened. Reload before saving.');
            }
            $relative = (string)$source['path'];
            $root = $this->themeRoot($folder);
            $rootIdentity = $this->directoryIdentity($root);
            $target = $this->resolveRegularPath($root, $relative);
            $current = $this->readRegularContent($target, $root, self::MAX_SOURCE_BYTES);
            if (!hash_equals($expectedHash, $current['sha256'])) throw new RuntimeException('Theme source changed during validation. Reload before saving.');

            $targetStat = @lstat($target);
            if (!is_array($targetStat)) throw new RuntimeException('Could not inspect source ownership.');
            $targetDirectory = dirname($target);
            $directoryBefore = @lstat($targetDirectory);
            $directoryReal = realpath($targetDirectory);
            if (!is_array($directoryBefore) || $directoryReal === false || (($directoryBefore['mode'] & 0170000) !== 0040000)) {
                throw new RuntimeException('Source directory is unsafe.');
            }
            $temporary = $targetDirectory . '/.theme-builder-save-' . bin2hex(random_bytes(12));
            $handle = @fopen($temporary, 'xb');
            if (!is_resource($handle)) throw new RuntimeException('Could not create an atomic source stage.');
            try {
                $temporaryReal = realpath($temporary);
                $directoryAfter = @lstat($targetDirectory);
                if ($temporaryReal === false || dirname($temporaryReal) !== $directoryReal || !is_array($directoryAfter)
                    || !$this->sameDirectory($directoryBefore, $directoryAfter)) throw new RuntimeException('Source directory changed during staging.');
                $this->writeAll($handle, $content);
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Could not sync the source stage.');
                $opened = fstat($handle);
                if (!is_array($opened) || (($opened['mode'] & 0170000) !== 0100000)) throw new RuntimeException('Source staging is unsafe.');
            } finally {
                fclose($handle);
            }

            $mode = ($targetStat['mode'] & 0777) & 0775;
            if (($mode & 0600) !== 0600) $mode |= 0600;
            if (!chmod($temporary, $mode)) throw new RuntimeException('Could not preserve safe source permissions.');
            $temporaryStat = @stat($temporary);
            if (!is_array($temporaryStat)) throw new RuntimeException('Could not inspect source staging.');
            if ((int)$temporaryStat['uid'] !== (int)$targetStat['uid'] && !@chown($temporary, (int)$targetStat['uid'])) {
                throw new RuntimeException('Could not preserve source ownership.');
            }
            if ((int)$temporaryStat['gid'] !== (int)$targetStat['gid'] && !@chgrp($temporary, (int)$targetStat['gid'])) {
                throw new RuntimeException('Could not preserve source group.');
            }
            $this->lintPhpFile($temporary);

            $resultHash = hash('sha256', $content);
            if (hash_equals($expectedHash, $resultHash)) {
                @unlink($temporary);
                $temporary = '';
                $this->pdo->commit();
                return ['success' => true, 'unchanged' => true, 'sha256' => $resultHash, 'target_token' => (string)($source['target_token'] ?? '')];
            }
            $baseline = $direct ? $this->ensureBaseline($folder, $lockedRow, $root, $rootIdentity, $actorId) : null;
            $metadata = $direct ? ['theme_id' => (int)$lockedRow['id'], 'source' => ['version' => (string)($lockedRow['version'] ?? '')]] : $editState['metadata'];
            $revisionId = $this->createRevision(
                $folder,
                (int)$metadata['theme_id'],
                $fileId,
                $relative,
                $current['content'],
                $expectedHash,
                $resultHash,
                $targetStat,
                $actorId,
                trim($note),
                (string)($metadata['source']['version'] ?? ''),
                $rootIdentity,
                $operation,
                $restoredFrom,
                is_array($baseline) ? (string)$baseline['baseline_id'] : ''
            );
            $revision = ['theme_id' => (int)$metadata['theme_id'], 'file_id' => $fileId, 'revision_id' => $revisionId];

            $freshTarget = $this->resolveRegularPath($root, $relative);
            $fresh = $this->readRegularContent($freshTarget, $root, self::MAX_SOURCE_BYTES);
            $freshTargetStat = @lstat($freshTarget);
            if (!hash_equals($target, $freshTarget) || !hash_equals($expectedHash, $fresh['sha256'])) {
                throw new RuntimeException('Theme source changed before replacement. Reload before saving.');
            }
            if (!is_array($freshTargetStat) || (int)$freshTargetStat['dev'] !== (int)$targetStat['dev']
                || (int)$freshTargetStat['ino'] !== (int)$targetStat['ino']) {
                throw new RuntimeException('Theme source identity changed before replacement. Reload before saving.');
            }
            if ($direct) {
                $this->assertDirectoryIdentity($root, $rootIdentity);
                $this->assertLockedDirectEditable($folder, (int)$metadata['theme_id'], $acknowledgements);
            } else {
                $this->assertManagedRootIdentity($root, $metadata);
                $this->assertLockedEditable($folder, (int)$metadata['theme_id']);
            }
            if (!rename($temporary, $target)) throw new RuntimeException('Could not atomically replace the source file.');
            $temporary = '';
            $replacementComplete = true;
            $this->syncDirectory(dirname($target));
            clearstatcache(true, $target);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
            if ($direct) {
                $newTargetStat = @lstat($target);
                if (is_array($newTargetStat)) {
                    $newTargetToken = $this->targetToken((int)$lockedRow['id'], $folder, $rootIdentity, $relative, $newTargetStat);
                }
            }
            try {
                $this->pdo->commit();
            } catch (Throwable $commitError) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $verified = $this->readRegularContent($target, $root, self::MAX_SOURCE_BYTES);
                if (!hash_equals($resultHash, $verified['sha256'])) throw $commitError;
                $commitResult = [
                    'success' => true,
                    'sha256' => $resultHash,
                    'revision_id' => $revisionId,
                    'warning' => 'Source was saved and verified, but database lock release reported an error.',
                ];
                if ($direct && $newTargetToken !== '') $commitResult['target_token'] = $newTargetToken;
                if ($direct && $newTargetToken === '') $commitResult['reload_required'] = true;
                return $commitResult;
            }

            $result = ['success' => true, 'sha256' => $resultHash, 'revision_id' => $revisionId];
            if ($direct) {
                $result['target_token'] = $newTargetToken;
                if ($newTargetToken === '') {
                    unset($result['target_token']);
                    $result['warning'] = 'Source was saved, but its new physical identity could not be returned. Reload before editing again.';
                    $result['reload_required'] = true;
                }
                $result['dirty'] = $this->dirtyState($folder);
                $result['warnings'] = array_values(array_filter([
                    (!empty($editState['active']) || !empty($editState['assigned'])) ? 'live_theme' : null,
                    !empty($editState['store']) ? 'store_managed' : null,
                ]));
                if ($restoredFrom !== null) $result['restored_from_revision_id'] = $restoredFrom;
            }
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (!$replacementComplete && $revision !== null) $this->removeRevision($revision['theme_id'], $revision['file_id'], $revision['revision_id']);
            $message = $this->safeError($error);
            $code = str_contains($message, 'Reload') ? 'stale_source'
                : (str_contains($message, 'read-only') ? 'system_theme_read_only'
                : (str_contains($message, 'confirmation') ? 'confirmation_required' : 'source_save_failed'));
            return ['success' => false, 'code' => $code, 'error' => $message, 'reload_required' => $code === 'stale_source'];
        } finally {
            if ($temporary !== '' && is_file($temporary) && !is_link($temporary)) @unlink($temporary);
            $this->releaseLocks($locks);
            $this->releaseCoreLocks($coreLocks);
        }
    }

    private function validInstalledFolder(string $folder): bool
    {
        return strlen($folder) <= 128
            && preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) === 1
            && !in_array($folder, ['.', '..'], true);
    }

    private function assertRiskAcknowledgements(array $state, array $acknowledgements, bool $direct): void
    {
        if (!$direct) return;
        if (empty($acknowledgements['direct'])) throw new RuntimeException('Direct source editing requires explicit confirmation.');
        if ((!empty($state['active']) || !empty($state['assigned'])) && empty($acknowledgements['active'])) {
            throw new RuntimeException('Live assigned-theme source editing requires explicit confirmation.');
        }
        if (!empty($state['store']) && empty($acknowledgements['store'])) {
            throw new RuntimeException('Store-managed source editing requires explicit confirmation.');
        }
    }

    private function directoryIdentity(string $path): array
    {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || (($stat['mode'] & 0170000) !== 0040000)) {
            throw new RuntimeException('Theme root identity is unsafe.');
        }
        return ['dev' => (int)$stat['dev'], 'ino' => (int)$stat['ino']];
    }

    private function assertDirectoryIdentity(string $path, array $identity): void
    {
        $current = $this->directoryIdentity($path);
        if ((int)$identity['dev'] !== $current['dev'] || (int)$identity['ino'] !== $current['ino']) {
            throw new RuntimeException('Theme root identity changed. Reload before saving.');
        }
    }

    private function targetToken(int $themeId, string $folder, array $rootIdentity, string $relative, array $fileIdentity): string
    {
        return hash('sha256', implode("\0", [
            'theme-builder-target-v1',
            (string)$themeId,
            $folder,
            (string)$rootIdentity['dev'],
            (string)$rootIdentity['ino'],
            $relative,
            (string)$fileIdentity['dev'],
            (string)$fileIdentity['ino'],
        ]));
    }

    private function baselinePath(string $folder, int $themeId, array $rootIdentity): string
    {
        $key = hash('sha256', implode("\0", [$folder, (string)$themeId, (string)$rootIdentity['dev'], (string)$rootIdentity['ino']]));
        return $this->privateDirectory('.baselines') . '/' . $key . '.json';
    }

    private function readBaseline(string $folder, int $themeId, array $rootIdentity): ?array
    {
        $path = $this->baselinePath($folder, $themeId, $rootIdentity);
        if (!file_exists($path)) return null;
        if (is_link($path) || !is_file($path)) throw new RuntimeException('Theme baseline is unsafe.');
        $state = $this->readRegularContent($path, dirname($path), self::MAX_MANIFEST_BYTES);
        $this->assertNoDuplicateJsonObjectKeys($state['content']);
        $baseline = json_decode($state['content'], true, 64, JSON_THROW_ON_ERROR);
        $this->validateBaseline($baseline, $folder, $themeId, $rootIdentity);
        return $baseline;
    }

    private function validateBaseline(mixed $baseline, string $folder, int $themeId, array $rootIdentity): void
    {
        if (!is_array($baseline)) throw new RuntimeException('Theme baseline schema is invalid.');
        $this->assertExactKeys($baseline,
            ['schema', 'baseline_id', 'theme', 'installed', 'scope', 'origin', 'captured_at', 'captured_by', 'files'],
            'Theme baseline');
        if ($baseline['schema'] !== 1 || !is_string($baseline['baseline_id'])
            || preg_match('/\A[a-f0-9]{32}\z/D', $baseline['baseline_id']) !== 1
            || $baseline['scope'] !== 'physical_php'
            || !in_array($baseline['origin'], ['core_install', 'core_update', 'pre_first_direct_edit'], true)
            || !is_int($baseline['captured_by']) || $baseline['captured_by'] < 0
            || !$this->validBaselineTimestamp($baseline['captured_at'])) {
            throw new RuntimeException('Theme baseline schema is invalid.');
        }

        if (!is_array($baseline['theme'])) throw new RuntimeException('Theme baseline identity is invalid.');
        $this->assertExactKeys($baseline['theme'], ['folder', 'registered_id', 'root_identity'], 'Theme baseline identity');
        if (!is_string($baseline['theme']['folder']) || !hash_equals($folder, $baseline['theme']['folder'])
            || !is_int($baseline['theme']['registered_id']) || $baseline['theme']['registered_id'] !== $themeId
            || !is_array($baseline['theme']['root_identity'])) {
            throw new RuntimeException('Theme baseline identity is invalid.');
        }
        $storedRoot = $baseline['theme']['root_identity'];
        $this->assertExactKeys($storedRoot, ['dev', 'ino'], 'Theme baseline root identity');
        if (!$this->validIdentityDecimal($storedRoot['dev']) || !$this->validIdentityDecimal($storedRoot['ino'])
            || !hash_equals((string)$rootIdentity['dev'], $storedRoot['dev'])
            || !hash_equals((string)$rootIdentity['ino'], $storedRoot['ino'])) {
            throw new RuntimeException('Theme baseline root identity is invalid.');
        }

        if (!is_array($baseline['installed'])) throw new RuntimeException('Theme baseline installed metadata is invalid.');
        $this->assertExactKeys($baseline['installed'], ['version', 'store_url', 'store_slug'], 'Theme baseline installed metadata');
        if (!$this->validBaselineString($baseline['installed']['version'], 255)
            || !$this->validBaselineString($baseline['installed']['store_url'], 2048)
            || !$this->validBaselineString($baseline['installed']['store_slug'], 128)) {
            throw new RuntimeException('Theme baseline installed metadata is invalid.');
        }

        if (!is_array($baseline['files']) || count($baseline['files']) > self::MAX_BASELINE_FILES) {
            throw new RuntimeException('Theme baseline file count is invalid.');
        }
        $totalBytes = 0;
        $fileIds = [];
        $pathSemantics = [];
        foreach ($baseline['files'] as $relative => $record) {
            if (!is_string($relative) || !is_array($record)) throw new RuntimeException('Theme baseline file identity is invalid.');
            $this->assertRelativePath($relative);
            if (substr_count($relative, '/') + 1 > self::MAX_DEPTH
                || !str_ends_with(strtolower($relative), '.php')) {
                throw new RuntimeException('Theme baseline contains an unsafe PHP path.');
            }
            $semanticPath = strtolower($relative);
            if (isset($pathSemantics[$semanticPath])) throw new RuntimeException('Theme baseline contains colliding PHP paths.');
            $pathSemantics[$semanticPath] = true;

            $this->assertExactKeys($record, ['file_id', 'sha256', 'size'], 'Theme baseline file');
            $derivedId = hash('sha256', $folder . "\0" . $relative);
            if (!is_string($record['file_id']) || preg_match('/\A[a-f0-9]{64}\z/D', $record['file_id']) !== 1
                || !hash_equals($derivedId, $record['file_id']) || isset($fileIds[$record['file_id']])
                || !is_string($record['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $record['sha256']) !== 1
                || !is_int($record['size']) || $record['size'] < 0 || $record['size'] > self::MAX_SOURCE_BYTES) {
                throw new RuntimeException('Theme baseline file metadata is invalid.');
            }
            $fileIds[$record['file_id']] = true;
            $totalBytes += $record['size'];
            if ($totalBytes > self::MAX_BASELINE_BYTES) throw new RuntimeException('Theme baseline exceeds the total size limit.');
        }
    }

    private function assertExactKeys(array $value, array $expected, string $label): void
    {
        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (!is_string($key)) throw new RuntimeException($label . ' keys are invalid.');
        }
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) throw new RuntimeException($label . ' keys are invalid.');
    }

    private function validIdentityDecimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?:0|[1-9]\d*)\z/D', $value) === 1
            && (strlen($value) < strlen((string)PHP_INT_MAX)
                || (strlen($value) === strlen((string)PHP_INT_MAX) && strcmp($value, (string)PHP_INT_MAX) <= 0));
    }

    private function validBaselineString(mixed $value, int $maxBytes): bool
    {
        return is_string($value) && strlen($value) <= $maxBytes && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function validBaselineTimestamp(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D', $value) !== 1) return false;
        $timestamp = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
        return $timestamp instanceof DateTimeImmutable && $timestamp->format(DateTimeInterface::ATOM) === $value
            && $timestamp->getTimestamp() >= 0 && $timestamp->getTimestamp() <= time() + 300;
    }

    private function assertNoDuplicateJsonObjectKeys(string $json): void
    {
        $offset = 0;
        $this->scanJsonValue($json, $offset, 0);
        $this->skipJsonWhitespace($json, $offset);
        if ($offset !== strlen($json)) throw new RuntimeException('Theme baseline JSON is invalid.');
    }

    private function scanJsonValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > 64) throw new RuntimeException('Theme baseline JSON nesting is invalid.');
        $this->skipJsonWhitespace($json, $offset);
        $character = $json[$offset] ?? '';
        if ($character === '{') {
            $offset++;
            $seen = [];
            $this->skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') === '}') { $offset++; return; }
            while (true) {
                $this->skipJsonWhitespace($json, $offset);
                $key = $this->scanJsonString($json, $offset);
                if (isset($seen[$key])) throw new RuntimeException('Theme baseline JSON contains duplicate object keys.');
                $seen[$key] = true;
                $this->skipJsonWhitespace($json, $offset);
                if (($json[$offset] ?? '') !== ':') throw new RuntimeException('Theme baseline JSON is invalid.');
                $offset++;
                $this->scanJsonValue($json, $offset, $depth + 1);
                $this->skipJsonWhitespace($json, $offset);
                $separator = $json[$offset] ?? '';
                if ($separator === '}') { $offset++; return; }
                if ($separator !== ',') throw new RuntimeException('Theme baseline JSON is invalid.');
                $offset++;
            }
        }
        if ($character === '[') {
            $offset++;
            $this->skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') === ']') { $offset++; return; }
            while (true) {
                $this->scanJsonValue($json, $offset, $depth + 1);
                $this->skipJsonWhitespace($json, $offset);
                $separator = $json[$offset] ?? '';
                if ($separator === ']') { $offset++; return; }
                if ($separator !== ',') throw new RuntimeException('Theme baseline JSON is invalid.');
                $offset++;
            }
        }
        if ($character === '"') { $this->scanJsonString($json, $offset); return; }
        if (substr_compare($json, 'true', $offset, 4) === 0) { $offset += 4; return; }
        if (substr_compare($json, 'false', $offset, 5) === 0) { $offset += 5; return; }
        if (substr_compare($json, 'null', $offset, 4) === 0) { $offset += 4; return; }
        if (preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $json, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            return;
        }
        throw new RuntimeException('Theme baseline JSON is invalid.');
    }

    private function scanJsonString(string $json, int &$offset): string
    {
        if (($json[$offset] ?? '') !== '"') throw new RuntimeException('Theme baseline JSON is invalid.');
        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            $character = $json[$offset++];
            if ($character === '"') {
                $decoded = json_decode(substr($json, $start, $offset - $start), true, 2, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) throw new RuntimeException('Theme baseline JSON key is invalid.');
                return $decoded;
            }
            if ($character === '\\') {
                if ($offset >= $length) throw new RuntimeException('Theme baseline JSON is invalid.');
                $offset++;
            }
        }
        throw new RuntimeException('Theme baseline JSON is invalid.');
    }

    private function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) $offset++;
    }

    private function ensureBaseline(string $folder, array $row, string $root, array $rootIdentity, int $actorId): array
    {
        $existing = $this->readBaseline($folder, (int)$row['id'], $rootIdentity);
        if ($existing !== null) return $existing;
        $first = $this->phpSnapshot($root, $folder);
        $second = $this->phpSnapshot($root, $folder);
        if ($first !== $second) throw new RuntimeException('Theme source changed during baseline capture. Reload before saving.');
        $baseline = $this->makeBaseline($folder, $row, $rootIdentity, $second, $actorId, 'pre_first_direct_edit');
        $this->validateBaseline($baseline, $folder, (int)$row['id'], $rootIdentity);
        $path = $this->baselinePath($folder, (int)$row['id'], $rootIdentity);
        if (file_exists($path) || is_link($path)) throw new RuntimeException('Theme baseline publication conflicted. Reload before saving.');
        $this->writeJsonAtomic($path, $baseline, 0660);
        $verified = $this->readBaseline($folder, (int)$row['id'], $rootIdentity);
        if ($verified === null || !hash_equals((string)$baseline['baseline_id'], (string)($verified['baseline_id'] ?? ''))
            || $verified !== $baseline) {
            throw new RuntimeException('Initial theme baseline verification failed.');
        }
        return $verified;
    }

    private function makeBaseline(string $folder, array $row, array $rootIdentity, array $snapshot, int $actorId, string $origin): array
    {
        $files = [];
        foreach ($snapshot as $relative => $record) {
            $files[$relative] = [
                'file_id' => hash('sha256', $folder . "\0" . $relative),
                'sha256' => (string)$record['sha256'],
                'size' => (int)$record['size'],
            ];
        }
        $baseline = [
            'schema' => 1,
            'baseline_id' => bin2hex(random_bytes(16)),
            'theme' => [
                'folder' => $folder,
                'registered_id' => (int)$row['id'],
                'root_identity' => ['dev' => (string)$rootIdentity['dev'], 'ino' => (string)$rootIdentity['ino']],
            ],
            'installed' => [
                'version' => (string)($row['version'] ?? ''),
                'store_url' => (string)($row['store_url'] ?? ''),
                'store_slug' => (string)($row['store_slug'] ?? ''),
            ],
            'scope' => 'physical_php',
            'origin' => $origin,
            'captured_at' => gmdate('c'),
            'captured_by' => $actorId,
            'files' => $files,
        ];
        return $baseline;
    }

    private function dirtyStateFromSnapshots(array $row, array $rootIdentity, ?array $baseline, array $current): array
    {
        if ($baseline === null) {
            $files = [];
            foreach ($current as $path => $record) {
                $files[$path] = ['status' => 'untracked', 'baseline_sha256' => null, 'current_sha256' => (string)$record['sha256']];
            }
            return [
                'tracked' => false,
                'locally_modified' => false,
                'changed_count' => 0,
                'counts' => ['untracked' => count($files)],
                'files' => $files,
                'registered_theme_id' => (int)$row['id'],
                'root_identity' => ['dev' => (string)$rootIdentity['dev'], 'ino' => (string)$rootIdentity['ino']],
                'current_version' => (string)($row['version'] ?? ''),
                'store_url' => (string)($row['store_url'] ?? ''),
                'store_slug' => (string)($row['store_slug'] ?? ''),
            ];
        }
        $files = [];
        $counts = ['clean' => 0, 'modified' => 0, 'added' => 0, 'deleted' => 0];
        foreach ($baseline['files'] as $path => $original) {
            $currentHash = isset($current[$path]) ? (string)$current[$path]['sha256'] : null;
            $status = $currentHash === null ? 'deleted'
                : (hash_equals((string)$original['sha256'], $currentHash) ? 'clean' : 'modified');
            $counts[$status]++;
            $files[$path] = [
                'status' => $status,
                'baseline_sha256' => (string)$original['sha256'],
                'current_sha256' => $currentHash,
            ];
        }
        foreach ($current as $path => $record) {
            if (isset($baseline['files'][$path])) continue;
            $counts['added']++;
            $files[$path] = ['status' => 'added', 'baseline_sha256' => null, 'current_sha256' => (string)$record['sha256']];
        }
        ksort($files, SORT_STRING);
        $changed = $counts['modified'] + $counts['added'] + $counts['deleted'];
        $baselineVersion = (string)($baseline['installed']['version'] ?? '');
        $currentVersion = (string)($row['version'] ?? '');
        return [
            'tracked' => true,
            'baseline_id' => (string)$baseline['baseline_id'],
            'locally_modified' => $changed > 0,
            'changed_count' => $changed,
            'upstream_changed' => $changed > 0 && $baselineVersion !== '' && $currentVersion !== '' && $baselineVersion !== $currentVersion,
            'baseline_version' => $baselineVersion,
            'current_version' => $currentVersion,
            'counts' => $counts,
            'files' => $files,
            'registered_theme_id' => (int)$row['id'],
            'root_identity' => ['dev' => (string)$rootIdentity['dev'], 'ino' => (string)$rootIdentity['ino']],
            'store_url' => (string)($row['store_url'] ?? ''),
            'store_slug' => (string)($row['store_slug'] ?? ''),
        ];
    }

    private function phpSnapshot(string $root, string $folder): array
    {
        $records = [];
        $entries = 0;
        $phpFiles = 0;
        $totalBytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if (++$entries > self::MAX_ENTRIES) throw new RuntimeException('Theme tree contains too many entries.');
            $path = $entry->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
            $this->assertRelativePath($relative);
            if (substr_count($relative, '/') + 1 > self::MAX_DEPTH) throw new RuntimeException('Theme tree nesting is too deep.');
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path)) throw new RuntimeException('Theme tree contains an unsafe entry.');
            $type = $stat['mode'] & 0170000;
            if ($type === 0040000) {
                if (!is_readable($path)) throw new RuntimeException('Theme tree contains an unreadable directory.');
                continue;
            }
            if ($type !== 0100000) throw new RuntimeException('Theme tree contains an unsupported entry.');
            if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'php') continue;
            if ((int)$stat['size'] > self::MAX_SOURCE_BYTES) throw new RuntimeException('Theme PHP source exceeds the baseline limit.');
            if (++$phpFiles > self::MAX_BASELINE_FILES) throw new RuntimeException('Theme contains too many PHP files for baseline tracking.');
            $totalBytes += (int)$stat['size'];
            if ($totalBytes > self::MAX_BASELINE_BYTES) throw new RuntimeException('Theme PHP baseline exceeds the total size limit.');
            $state = $this->hashRegularFile($path, $root, self::MAX_SOURCE_BYTES);
            $records[$relative] = ['size' => $state['size'], 'sha256' => $state['sha256']];
        }
        ksort($records, SORT_STRING);
        return $records;
    }

    private function currentExportFiles(string $root, array $snapshot, array &$exportLimits): array
    {
        $files = [];
        foreach ($snapshot as $relative => $expected) {
            $name = 'current-php/' . $relative;
            $this->reserveExportEntry($name, (int)$expected['size'], false, $exportLimits);
            $files[$name] = ['file' => $this->captureExportSourceFile(
                $name,
                $root,
                $relative,
                self::MAX_SOURCE_BYTES,
                (int)$expected['size'],
                (string)$expected['sha256']
            )];
        }
        return $files;
    }

    private function exportRevisionFiles(string $folder, int $themeId, array $rootIdentity, array &$exportLimits): array
    {
        $revisionRoot = $this->privateDirectory('.revisions');
        $themeDir = $revisionRoot . '/' . $themeId;
        if (!file_exists($themeDir)) return ['files' => [], 'count' => 0];
        if (is_link($themeDir) || !is_dir($themeDir)) throw new RuntimeException('Theme revision storage is unsafe.');

        $files = [];
        $count = 0;
        foreach (scandir($themeDir, SCANDIR_SORT_ASCENDING) ?: [] as $fileId) {
            if ($fileId === '.' || $fileId === '..') continue;
            if (preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1) {
                throw new RuntimeException('Theme revision storage contains a malformed file identity.');
            }
            $fileDir = $themeDir . '/' . $fileId;
            if (is_link($fileDir) || !is_dir($fileDir)) throw new RuntimeException('Theme revision storage is unsafe.');
            $revisionCount = 0;
            foreach (scandir($fileDir, SCANDIR_SORT_ASCENDING) ?: [] as $revisionId) {
                if ($revisionId === '.' || $revisionId === '..') continue;
                $revisionCount++;
                if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1) {
                    throw new RuntimeException('Theme revision storage contains a malformed revision identity.');
                }
                $revisionDir = $fileDir . '/' . $revisionId;
                if (is_link($revisionDir) || !is_dir($revisionDir)) throw new RuntimeException('Theme revision storage is unsafe.');
                $prefix = 'revisions/' . $fileId . '/' . $revisionId . '/';
                $metadataStat = @lstat($revisionDir . '/revision.json');
                $sourceStat = @lstat($revisionDir . '/source.php');
                if (!is_array($metadataStat) || !is_array($sourceStat) || is_link($revisionDir . '/revision.json')
                    || is_link($revisionDir . '/source.php') || (($metadataStat['mode'] & 0170000) !== 0100000)
                    || (($sourceStat['mode'] & 0170000) !== 0100000) || (int)$metadataStat['size'] < 0
                    || (int)$sourceStat['size'] < 0) {
                    throw new RuntimeException('Source revision storage contains an unsafe entry.');
                }
                $verified = $this->validateRevisionRecord($revisionDir, $folder, $themeId, $fileId, null, $rootIdentity, false);
                if ((int)$verified['metadata_size'] !== (int)$metadataStat['size']
                    || (int)$verified['source_size'] !== (int)$sourceStat['size']) {
                    throw new RuntimeException('Source revision changed during export loading.');
                }
                if (!$verified['current_root']) continue;
                $metadataName = $prefix . 'revision.json';
                $sourceName = $prefix . 'source.php';
                $this->reserveExportEntry($metadataName, (int)$verified['metadata_size'], true, $exportLimits);
                $this->reserveExportEntry($sourceName, (int)$verified['source_size'], false, $exportLimits);
                $files[$metadataName] = $this->captureExportSourceFile($metadataName, $revisionDir, 'revision.json',
                    self::MAX_MANIFEST_BYTES, (int)$verified['metadata_size'], (string)$verified['metadata_sha256']);
                $files[$sourceName] = $this->captureExportSourceFile($sourceName, $revisionDir, 'source.php',
                    self::MAX_SOURCE_BYTES, (int)$verified['source_size'], (string)$verified['source_sha256']);
                $count++;
            }
            if ($revisionCount === 0) throw new RuntimeException('Theme revision storage contains an empty file identity.');
        }
        return ['files' => $files, 'count' => $count];
    }

    private function reserveExportEntry(string $name, int $bytes, bool $metadata, array &$limits): void
    {
        $this->assertRelativePath($name);
        if ($bytes < 0 || ($metadata ? $bytes > self::MAX_MANIFEST_BYTES : $bytes > self::MAX_SOURCE_BYTES)) {
            throw new RuntimeException($metadata
                ? 'Theme source export metadata is too large.'
                : 'Theme source export contains an oversized entry.');
        }
        if (isset($limits['names'][$name])) throw new RuntimeException('Theme source export contains a duplicate entry.');
        if ((int)$limits['entries'] >= self::MAX_EXPORT_ENTRIES) {
            throw new RuntimeException('Theme source export contains too many entries.');
        }
        if ((int)$limits['bytes'] > self::MAX_EXPORT_BYTES - $bytes) {
            throw new RuntimeException('Theme source export exceeds the total size limit.');
        }
        $limits['names'][$name] = true;
        $limits['entries']++;
        $limits['bytes'] += $bytes;
    }

    private function captureExportSourceFile(string $name, string $root, string $relative, int $maxBytes,
        int $expectedSize, string $expectedHash): array
    {
        $rootIdentity = $this->directoryIdentity($root);
        $path = $this->resolveRegularPath($root, $relative);
        $before = @lstat($path);
        $state = $this->hashRegularFile($path, $root, $maxBytes);
        clearstatcache(true, $path);
        $after = @lstat($path);
        $this->assertDirectoryIdentity($root, $rootIdentity);
        if (!is_array($before) || !is_array($after) || !$this->sameFile($before, $after)
            || $state['size'] !== $expectedSize || !hash_equals($expectedHash, $state['sha256'])) {
            throw new RuntimeException('Theme source export file changed during verification.');
        }
        return [
            'name' => $name,
            'root' => $root,
            'relative' => $relative,
            'path' => $path,
            'max_bytes' => $maxBytes,
            'size' => $expectedSize,
            'sha256' => $expectedHash,
            'root_identity' => $rootIdentity,
            'identity' => [
                'dev' => (int)$after['dev'],
                'ino' => (int)$after['ino'],
                'size' => (int)$after['size'],
                'mtime' => (int)$after['mtime'],
                'mode' => (int)$after['mode'],
            ],
        ];
    }

    private function assertExportSourceFile(array $expected): void
    {
        $current = $this->captureExportSourceFile(
            (string)$expected['name'],
            (string)$expected['root'],
            (string)$expected['relative'],
            (int)$expected['max_bytes'],
            (int)$expected['size'],
            (string)$expected['sha256']
        );
        if ((string)$current['path'] !== (string)$expected['path']
            || $current['root_identity'] !== $expected['root_identity']
            || $current['identity'] !== $expected['identity']) {
            throw new RuntimeException('Theme source export file identity changed.');
        }
    }

    private function verifyExportArchive(string $path, array $expected): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles !== count($expected)) {
            throw new RuntimeException('Theme source export archive could not be reopened.');
        }
        try {
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || isset($seen[$name]) || !array_key_exists($name, $expected)) {
                    throw new RuntimeException('Theme source export contains an unexpected entry.');
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) throw new RuntimeException('Theme source export entry could not be streamed.');
                $context = hash_init('sha256');
                $bytes = 0;
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) throw new RuntimeException('Theme source export entry could not be read.');
                        if ($chunk === '') continue;
                        $bytes += strlen($chunk);
                        if ($bytes > (int)$expected[$name]['size']) {
                            throw new RuntimeException('Theme source export entry verification failed.');
                        }
                        hash_update($context, $chunk);
                    }
                } finally {
                    fclose($stream);
                }
                if ($bytes !== (int)$expected[$name]['size']
                    || !hash_equals((string)$expected[$name]['sha256'], hash_final($context))) {
                    throw new RuntimeException('Theme source export entry verification failed.');
                }
                $seen[$name] = true;
            }
            if (count($seen) !== count($expected)) throw new RuntimeException('Theme source export is incomplete.');
        } finally {
            $zip->close();
        }
    }

    private function cleanupAbandonedExports(): void
    {
        $directory = $this->privateDirectory('.exports');
        $directoryStat = @lstat($directory);
        if (!is_array($directoryStat) || (($directoryStat['mode'] & 0170000) !== 0040000) || is_link($directory)) {
            throw new RuntimeException('Private source export storage is unsafe.');
        }
        $cutoff = time() - 3600;
        foreach (scandir($directory, SCANDIR_SORT_ASCENDING) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            if (preg_match('/\Atheme-source-[a-f0-9]{32}\.zip\z/D', $name) !== 1) {
                throw new RuntimeException('Private source export storage contains an unexpected entry.');
            }
            $path = $directory . '/' . $name;
            $verified = $this->openVerifiedExport($path, $directory, $directoryStat);
            try {
                if ((int)$verified['stat']['mtime'] >= $cutoff) continue;
                if (!@unlink($path)) throw new RuntimeException('Could not remove an abandoned private source export.');
            } finally {
                fclose($verified['handle']);
            }
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) throw new RuntimeException('Abandoned private source export cleanup could not be verified.');
        }
    }

    private function openVerifiedExport(string $path, string $directory, array $directoryStat): array
    {
        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        clearstatcache(true, $path);
        $after = @lstat($path);
        $real = realpath($path);
        if (!is_array($before) || !is_resource($handle) || !is_array($opened) || !is_array($after)
            || is_link($path) || !$this->sameFile($before, $opened) || !$this->sameFile($opened, $after)
            || (($opened['mode'] & 0777) !== 0600) || (int)($opened['nlink'] ?? 0) !== 1
            || (int)$opened['uid'] !== (int)$directoryStat['uid'] || $real === false || dirname($real) !== $directory) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Private source export storage contains an unsafe entry.');
        }
        return ['handle' => $handle, 'stat' => $opened];
    }

    private function readRevision(string $revisionDir, string $folder, int $themeId, string $fileId, string $relative,
        array $rootIdentity, bool $includeSource): ?array
    {
        $record = $this->validateRevisionRecord($revisionDir, $folder, $themeId, $fileId, $relative, $rootIdentity);
        if (!$record['current_root']) return null;
        $metadata = $record['metadata'];
        if ($includeSource) $metadata['source'] = $record['source'];
        return $metadata;
    }

    private function validateRevisionRecord(string $revisionDir, string $folder, int $themeId, string $fileId,
        ?string $expectedRelative, array $rootIdentity, bool $includeSource = true): array
    {
        $revisionId = basename($revisionDir);
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1
            || is_link($revisionDir) || !is_dir($revisionDir)) {
            throw new RuntimeException('Source revision identity is unsafe.');
        }
        $children = array_values(array_filter(scandir($revisionDir, SCANDIR_SORT_ASCENDING) ?: [],
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..'));
        if ($children !== ['revision.json', 'source.php']) {
            throw new RuntimeException('Source revision storage contains an incomplete or unexpected entry.');
        }
        $metadataPath = $revisionDir . '/revision.json';
        $sourcePath = $revisionDir . '/source.php';
        if (is_link($metadataPath) || is_link($sourcePath) || !is_file($metadataPath) || !is_file($sourcePath)) {
            throw new RuntimeException('Source revision storage contains an unsafe entry.');
        }
        $metadataState = $this->readRegularContent($metadataPath, $revisionDir, self::MAX_MANIFEST_BYTES);
        $sourceState = $includeSource
            ? $this->readRegularContent($sourcePath, $revisionDir, self::MAX_SOURCE_BYTES)
            : $this->hashRegularFile($sourcePath, $revisionDir, self::MAX_SOURCE_BYTES);
        $metadata = json_decode($metadataState['content'], true, 32, JSON_THROW_ON_ERROR);
        $relative = is_array($metadata) && is_string($metadata['relative_path'] ?? null) ? $metadata['relative_path'] : '';
        $operation = is_array($metadata) && array_key_exists('operation', $metadata) ? $metadata['operation'] : 'save';
        $restoredFrom = is_array($metadata) && array_key_exists('restored_from_revision_id', $metadata)
            ? $metadata['restored_from_revision_id'] : null;
        if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 1
            || ($metadata['revision_id'] ?? null) !== $revisionId
            || ($metadata['theme_folder'] ?? null) !== $folder || ($metadata['theme_id'] ?? null) !== $themeId
            || ($metadata['file_id'] ?? null) !== $fileId || ($expectedRelative !== null && $relative !== $expectedRelative)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string)($metadata['previous_sha256'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string)($metadata['result_sha256'] ?? '')) !== 1
            || !hash_equals((string)$metadata['previous_sha256'], $sourceState['sha256'])
            || !is_string($operation) || !in_array($operation, ['save', 'restore'], true)
            || ($restoredFrom !== null && (!is_string($restoredFrom)
                || preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $restoredFrom) !== 1))) {
            throw new RuntimeException('Source revision metadata identity is invalid.');
        }
        $this->assertRelativePath($relative);
        if (!hash_equals($fileId, hash('sha256', $folder . "\0" . $relative))) {
            throw new RuntimeException('Source revision path identity is invalid.');
        }

        $currentRoot = true;
        if (array_key_exists('root_identity', $metadata)) {
            $storedRoot = $metadata['root_identity'];
            if (!is_array($storedRoot)
                || preg_match('/\A\d+\z/D', (string)($storedRoot['dev'] ?? '')) !== 1
                || preg_match('/\A\d+\z/D', (string)($storedRoot['ino'] ?? '')) !== 1) {
                throw new RuntimeException('Source revision root identity is invalid.');
            }
            $currentRoot = (string)$storedRoot['dev'] === (string)$rootIdentity['dev']
                && (string)$storedRoot['ino'] === (string)$rootIdentity['ino'];
        } elseif (!$this->forkState($folder)['managed']) {
            throw new RuntimeException('Legacy source revision is not bound to a managed fork.');
        }

        $metadata['operation'] = $operation;
        $metadata['restored_from_revision_id'] = $restoredFrom;
        $result = [
            'current_root' => $currentRoot,
            'metadata' => $metadata,
            'metadata_raw' => $metadataState['content'],
            'metadata_size' => $metadataState['size'],
            'metadata_sha256' => $metadataState['sha256'],
            'source_size' => $sourceState['size'],
            'source_sha256' => $sourceState['sha256'],
        ];
        if ($includeSource) $result['source'] = $sourceState['content'];
        return $result;
    }

    private function registeredTheme(string $folder): array
    {
        if (!$this->validInstalledFolder($folder)) {
            throw new InvalidArgumentException('Invalid source theme folder.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM themes WHERE folder_name = ? LIMIT 1');
        $stmt->execute([$folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['folder_name'] ?? null)
            || !hash_equals($folder, $row['folder_name'])) {
            throw new RuntimeException('Source theme is not registered with this exact folder identity.');
        }
        return $row;
    }

    private function assignmentCount(int $themeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM assignments WHERE theme_id = ?');
        $stmt->execute([$themeId]);
        return (int)$stmt->fetchColumn();
    }

    private function lockThemeDatabaseState(string $folder): array
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('A database operation is already active.');
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $this->pdo->prepare('SELECT * FROM themes WHERE folder_name = ? LIMIT 1' . $suffix);
        $stmt->execute([$folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['folder_name'] ?? null)
            || !hash_equals($folder, $row['folder_name'])) {
            throw new RuntimeException('Theme registration identity is unavailable.');
        }
        $assignments = $this->pdo->prepare('SELECT id FROM assignments WHERE theme_id = ? ORDER BY id' . $suffix);
        $assignments->execute([(int)$row['id']]);
        $assignments->fetchAll(PDO::FETCH_COLUMN);
        return $row;
    }

    private function assertLockedDirectEditable(string $folder, int $themeId, array $acknowledgements): void
    {
        $stmt = $this->pdo->prepare('SELECT folder_name, is_active, is_system, store_url, store_slug FROM themes WHERE id = ? AND folder_name = ? LIMIT 1');
        $stmt->execute([$themeId, $folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $default = defined('DEFAULT_THEME_FOLDER') ? (string)DEFAULT_THEME_FOLDER : 'default';
        if (!$row || !is_string($row['folder_name'] ?? null) || !hash_equals($folder, $row['folder_name'])
            || !empty($row['is_system']) || $folder === $default) {
            throw new RuntimeException('Theme became read-only before replacement. Reload before saving.');
        }
        if ((!empty($row['is_active']) || $this->assignmentCount($themeId) > 0) && empty($acknowledgements['active'])) {
            throw new RuntimeException('Theme became live before replacement; active source confirmation is required.');
        }
        if ((trim((string)($row['store_url'] ?? '')) !== '' || trim((string)($row['store_slug'] ?? '')) !== '')
            && empty($acknowledgements['store'])) {
            throw new RuntimeException('Theme became Store-managed before replacement; Store confirmation is required.');
        }
    }

    private function assertLockedEditable(string $folder, int $themeId): void
    {
        $stmt = $this->pdo->prepare('SELECT folder_name, is_active, is_system, store_url, store_slug FROM themes WHERE id = ? AND folder_name = ? LIMIT 1');
        $stmt->execute([$themeId, $folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['folder_name'] ?? null) || !hash_equals($folder, $row['folder_name'])
            || !empty($row['is_active']) || !empty($row['is_system']) || (string)$row['store_url'] !== ''
            || (string)$row['store_slug'] !== '' || $this->assignmentCount($themeId) > 0) {
            throw new RuntimeException('Fork became live or changed identity before replacement. Reload before saving.');
        }
    }

    private function assertManagedRootIdentity(string $root, array $metadata): void
    {
        $stat = @lstat($root);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000)
            || (int)($metadata['root_identity']['dev'] ?? -1) !== (int)$stat['dev']
            || (int)($metadata['root_identity']['ino'] ?? -1) !== (int)$stat['ino']) {
            throw new RuntimeException('Managed fork root changed before replacement. Reload before saving.');
        }
    }

    private function themesRoot(): string
    {
        if (!defined('VIEWS_BASE')) throw new RuntimeException('Theme root is unavailable.');
        $base = realpath((string)VIEWS_BASE);
        if ($base === false || !is_dir($base) || is_link((string)VIEWS_BASE)) throw new RuntimeException('Theme root is unavailable.');
        return $base;
    }

    private function themeRoot(string $folder): string
    {
        $base = $this->themesRoot();
        $candidate = $base . DIRECTORY_SEPARATOR . $folder;
        if (is_link($candidate)) throw new RuntimeException('Theme root cannot be a symlink.');
        $root = realpath($candidate);
        if ($root === false || !is_dir($root) || dirname($root) !== $base) throw new RuntimeException('Theme directory was not found.');
        return $root;
    }

    private function assertTargetAvailable(string $folder, string $target): void
    {
        if ($folder === (defined('DEFAULT_THEME_FOLDER') ? (string)DEFAULT_THEME_FOLDER : 'default')) {
            throw new RuntimeException('The Core fallback theme name cannot be used for a fork.');
        }
        if (file_exists($target) || is_link($target)) throw new RuntimeException('Fork target already exists.');
        $stmt = $this->pdo->prepare('SELECT id, folder_name FROM themes WHERE folder_name = ? LIMIT 1');
        $stmt->execute([$folder]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!is_string($existing['folder_name'] ?? null) || !hash_equals($folder, $existing['folder_name'])) {
                throw new RuntimeException('Fork target conflicts with a differently cased registration.');
            }
            throw new RuntimeException('Fork target is already registered.');
        }
        $metadata = $this->metadataPath($folder);
        if (file_exists($metadata) || is_link($metadata)) throw new RuntimeException('Fork target metadata already exists.');
    }

    private function scanTree(string $root): array
    {
        if (!is_dir($root) || is_link($root)) throw new RuntimeException('Theme tree is unsafe.');
        $entries = [];
        $count = 0;
        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $count++;
            if ($count > self::MAX_ENTRIES) throw new RuntimeException('Theme tree contains too many entries.');
            $path = $entry->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
            $this->assertRelativePath($relative);
            if (substr_count($relative, '/') + 1 > self::MAX_DEPTH) throw new RuntimeException('Theme tree nesting is too deep.');
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path)) throw new RuntimeException('Theme tree contains an unsafe entry.');
            $type = $stat['mode'] & 0170000;
            $real = realpath($path);
            if ($real === false || !$this->isWithin($root, $real)) throw new RuntimeException('Theme tree escapes its root.');

            if ($type === 0040000) {
                if (!is_readable($path)) throw new RuntimeException('Theme tree contains an unreadable directory.');
                $entries[$relative] = ['type' => 'dir'];
                continue;
            }
            if ($type !== 0100000 || !is_readable($path)) throw new RuntimeException('Theme tree contains an unsupported entry.');
            $size = (int)$stat['size'];
            if ($size > self::MAX_FILE_BYTES) throw new RuntimeException('Theme tree contains an oversized file.');
            $total += $size;
            if ($total > self::MAX_TREE_BYTES) throw new RuntimeException('Theme tree exceeds the total size limit.');
            $state = $this->hashRegularFile($path, $root, self::MAX_FILE_BYTES);
            $entries[$relative] = [
                'type' => 'file',
                'size' => $state['size'],
                'sha256' => $state['sha256'],
                'dev' => (int)$stat['dev'],
                'ino' => (int)$stat['ino'],
                'mtime' => (int)$stat['mtime'],
            ];
        }
        ksort($entries, SORT_STRING);
        return ['entries' => $entries, 'total_bytes' => $total];
    }

    private function copySnapshot(string $sourceRoot, string $stage, array $entries): void
    {
        foreach ($entries as $relative => $entry) {
            $destination = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($entry['type'] === 'dir') {
                if (!mkdir($destination, 0700) && !is_dir($destination)) throw new RuntimeException('Could not create a fork directory.');
                chmod($destination, 0700);
                continue;
            }
            $source = $this->resolveRegularPath($sourceRoot, $relative);
            $before = @lstat($source);
            if (!is_array($before) || (int)$before['dev'] !== (int)$entry['dev'] || (int)$before['ino'] !== (int)$entry['ino']) {
                throw new RuntimeException('Source theme changed before copying.');
            }
            $input = @fopen($source, 'rb');
            $output = @fopen($destination, 'xb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Could not open a fork file stream.');
            }
            $context = hash_init('sha256');
            $bytes = 0;
            try {
                $opened = fstat($input);
                if (!is_array($opened) || !$this->sameFile($before, $opened)) throw new RuntimeException('Source file changed while opening.');
                while (!feof($input)) {
                    $chunk = fread($input, 65536);
                    if ($chunk === false) throw new RuntimeException('Could not read a complete source file.');
                    if ($chunk === '') continue;
                    $this->writeAll($output, $chunk);
                    hash_update($context, $chunk);
                    $bytes += strlen($chunk);
                }
                if (!fflush($output) || (function_exists('fsync') && !fsync($output))) throw new RuntimeException('Could not sync a fork file.');
                $afterRead = fstat($input);
            } finally {
                fclose($input);
                fclose($output);
            }
            clearstatcache(true, $source);
            $after = @lstat($source);
            $hash = hash_final($context);
            if (!is_array($afterRead) || !is_array($after) || !$this->sameFile($opened, $afterRead) || !$this->sameFile($afterRead, $after)
                || $bytes !== (int)$entry['size'] || !hash_equals((string)$entry['sha256'], $hash)
                || !hash_equals($hash, (string)hash_file('sha256', $destination))) {
                throw new RuntimeException('Fork file verification failed.');
            }
            if (!chmod($destination, 0600)) throw new RuntimeException('Could not set private staging permissions.');
        }
    }

    private function transformManifest(string $stage, string $sourceFolder, string $targetFolder, string $name, string $title): array
    {
        $path = $this->resolveRegularPath($stage, 'theme.json');
        $state = $this->readRegularContent($path, $stage, self::MAX_MANIFEST_BYTES);
        $this->assertJsonNumbersPreservable($state['content']);
        $object = json_decode($state['content'], false, 64, JSON_THROW_ON_ERROR);
        if (!$object instanceof stdClass) throw new RuntimeException('Source theme manifest must be a JSON object.');
        if (property_exists($object, 'folder') && (!is_string($object->folder) || $object->folder !== $sourceFolder)) {
            throw new RuntimeException('Source manifest folder does not match its physical theme.');
        }
        if (!is_string($object->version ?? null) || preg_match('/\A\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?\z/D', $object->version) !== 1) {
            throw new RuntimeException('Source theme version is invalid.');
        }
        $object->folder = $targetFolder;
        $object->name = $name;
        $object->title = $title;
        $object->is_active = false;
        unset($object->store, $object->store_url, $object->store_slug);
        $encoded = json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = dirname($path) . '/.theme-builder-manifest-' . bin2hex(random_bytes(10));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) throw new RuntimeException('Could not stage the fork manifest.');
        try {
            $this->writeAll($handle, $encoded);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Could not sync the fork manifest.');
        } finally {
            fclose($handle);
        }
        chmod($temporary, 0664);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the fork manifest.');
        }
        $manifest = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['folder'] ?? null) !== $targetFolder || isset($manifest['store']) || !empty($manifest['is_active'])) {
            throw new RuntimeException('Fork manifest transformation failed.');
        }
        return $manifest;
    }

    private function assertForkSnapshot(array $source, array $fork): void
    {
        $sourceEntries = $source['entries'];
        $forkEntries = $fork['entries'];
        if (array_keys($sourceEntries) !== array_keys($forkEntries)) throw new RuntimeException('Fork tree is incomplete.');
        foreach ($sourceEntries as $relative => $entry) {
            $copy = $forkEntries[$relative];
            if ($entry['type'] !== $copy['type']) throw new RuntimeException('Fork tree type verification failed.');
            if ($entry['type'] === 'file' && $relative !== 'theme.json'
                && ((int)$entry['size'] !== (int)$copy['size'] || !hash_equals((string)$entry['sha256'], (string)$copy['sha256']))) {
                throw new RuntimeException('Fork tree hash verification failed.');
            }
        }
    }

    private function snapshotComparable(array $snapshot): array
    {
        $result = [];
        foreach ($snapshot['entries'] as $relative => $entry) {
            $result[$relative] = $entry['type'] === 'file'
                ? ['type' => 'file', 'size' => $entry['size'], 'sha256' => $entry['sha256']]
                : ['type' => 'dir'];
        }
        return $result;
    }

    private function snapshotDigest(array $snapshot): string
    {
        return hash('sha256', json_encode($this->snapshotComparable($snapshot), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function metadataPath(string $folder): string
    {
        return $this->privateDirectory('.installed-forks') . '/' . hash('sha256', $folder) . '.json';
    }

    private function readMetadata(string $folder): ?array
    {
        $path = $this->metadataPath($folder);
        if (!is_file($path) || is_link($path)) return null;
        $stat = @lstat($path);
        $directoryStat = @stat(dirname($path));
        if (!is_array($stat) || !is_array($directoryStat) || (int)$stat['uid'] !== (int)$directoryStat['uid'] || (($stat['mode'] & 0002) !== 0)) return null;
        $state = $this->readRegularContent($path, dirname($path), self::MAX_MANIFEST_BYTES);
        $metadata = json_decode($state['content'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 1 || ($metadata['folder'] ?? null) !== $folder) return null;
        return $metadata;
    }

    private function createRevision(string $folder, int $themeId, string $fileId, string $relative, string $previous,
        string $previousHash, string $resultHash, array $targetStat, int $actorId, string $note, string $sourceVersion,
        ?array $rootIdentity = null, string $operation = 'save', ?string $restoredFrom = null, string $baselineId = ''): string
    {
        $revisionRoot = $this->privateDirectory('.revisions');
        $themeDir = $this->ensurePrivateChild($revisionRoot, (string)$themeId);
        $fileDir = $this->ensurePrivateChild($themeDir, $fileId);
        $this->assertRevisionCapacity($fileDir, strlen($previous));
        $revisionId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
        $revisionDir = $fileDir . '/' . $revisionId;
        if (!mkdir($revisionDir, 0770) || is_link($revisionDir)) throw new RuntimeException('Could not create a source revision.');
        chmod($revisionDir, 0770);
        try {
            $sourcePath = $revisionDir . '/source.php';
            $this->writeFileExclusive($sourcePath, $previous, 0660);
            if (!hash_equals($previousHash, (string)hash_file('sha256', $sourcePath))) throw new RuntimeException('Source revision verification failed.');
            $metadata = [
                'schema' => 1,
                'revision_id' => $revisionId,
                'theme_folder' => $folder,
                'theme_id' => $themeId,
                'file_id' => $fileId,
                'relative_path' => $relative,
                'previous_sha256' => $previousHash,
                'result_sha256' => $resultHash,
                'actor_user_id' => $actorId,
                'created_at' => gmdate('c'),
                'owner' => (int)$targetStat['uid'],
                'group' => (int)$targetStat['gid'],
                'mode' => sprintf('%04o', $targetStat['mode'] & 07777),
                'source_version' => $sourceVersion,
                'change_note' => $note,
                'operation' => $operation,
                'restored_from_revision_id' => $restoredFrom,
                'baseline_id' => $baselineId,
            ];
            if ($rootIdentity !== null) {
                $metadata['root_identity'] = ['dev' => (string)$rootIdentity['dev'], 'ino' => (string)$rootIdentity['ino']];
            }
            $this->writeJsonAtomic($revisionDir . '/revision.json', $metadata, 0660);
            $this->syncDirectory($revisionDir);
            $this->syncDirectory($fileDir);
            return $revisionId;
        } catch (Throwable $error) {
            $this->removeOwnedTree($revisionDir);
            throw $error;
        }
    }

    private function removeRevision(int $themeId, string $fileId, string $revisionId): void
    {
        if ($themeId < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1
            || preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1) return;
        try {
            $root = $this->privateDirectory('.revisions');
            $path = $root . '/' . $themeId . '/' . $fileId . '/' . $revisionId;
            $real = realpath($path);
            if ($real !== false && $this->isWithin($root, $real)) $this->removeOwnedTree($real);
        } catch (Throwable) {
            // A failed save must not hide its original error if revision cleanup fails.
        }
    }

    private function assertRevisionCapacity(string $fileDir, int $incomingBytes): void
    {
        $count = 0;
        $bytes = 0;
        foreach (scandir($fileDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $fileDir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) throw new RuntimeException('Source revision storage contains an unsafe entry.');
            $source = $path . '/source.php';
            if (is_link($source) || !is_file($source)) throw new RuntimeException('Source revision storage contains an incomplete entry.');
            $stat = @lstat($source);
            if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)) throw new RuntimeException('Source revision storage contains an unsafe file.');
            $count++;
            $bytes += (int)$stat['size'];
            if ($count >= self::MAX_REVISIONS_PER_FILE || $bytes + $incomingBytes > self::MAX_REVISION_BYTES_PER_FILE) {
                throw new RuntimeException('Source revision retention limit was reached. Export or remove old revisions before saving.');
            }
        }
    }

    private function readManifest(string $root, string $folder): array
    {
        $path = $this->resolveRegularPath($root, 'theme.json');
        $state = $this->readRegularContent($path, $root, self::MAX_MANIFEST_BYTES);
        $manifest = json_decode($state['content'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || (array_key_exists('folder', $manifest)
            && (!is_string($manifest['folder']) || !hash_equals($folder, $manifest['folder'])))) {
            throw new RuntimeException('Fork manifest identity is invalid.');
        }
        $manifest['folder'] = $folder;
        return $manifest;
    }

    private function resolveRegularPath(string $root, string $relative): string
    {
        $this->assertRelativePath($relative);
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) throw new RuntimeException('Theme path contains a symlink.');
        }
        $real = realpath($path);
        $stat = @lstat($path);
        if ($real === false || !is_array($stat) || (($stat['mode'] & 0170000) !== 0100000) || !$this->isWithin($root, $real)) {
            throw new RuntimeException('Theme source file was not found.');
        }
        return $real;
    }

    private function hashRegularFile(string $path, string $root, int $maxBytes): array
    {
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || (($before['mode'] & 0170000) !== 0100000) || (int)$before['size'] > $maxBytes) {
            throw new RuntimeException('Theme file is unsafe or too large.');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new RuntimeException('Could not open a theme file.');
        $context = hash_init('sha256');
        $bytes = 0;
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !$this->sameFile($before, $opened)) throw new RuntimeException('Theme file changed while opening.');
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) throw new RuntimeException('Could not read a complete theme file.');
                if ($chunk === '') continue;
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) throw new RuntimeException('Theme file is too large.');
                hash_update($context, $chunk);
            }
            $afterRead = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        $real = realpath($path);
        if (!is_array($afterRead) || !is_array($after) || $real === false || is_link($path)
            || !$this->sameFile($opened, $afterRead) || !$this->sameFile($afterRead, $after) || !$this->isWithin($root, $real)) {
            throw new RuntimeException('Theme file changed during inspection.');
        }
        return ['size' => $bytes, 'sha256' => hash_final($context)];
    }

    private function readRegularContent(string $path, string $root, int $maxBytes): array
    {
        $state = $this->hashRegularFile($path, $root, $maxBytes);
        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        if (!is_array($before) || !is_resource($handle)) throw new RuntimeException('Could not open source content.');
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !$this->sameFile($before, $opened)) throw new RuntimeException('Source content changed while opening.');
            $content = stream_get_contents($handle, $maxBytes + 1);
            $afterRead = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_string($content) || strlen($content) > $maxBytes || !is_array($afterRead) || !is_array($after)
            || !$this->sameFile($opened, $afterRead) || !$this->sameFile($afterRead, $after)
            || !hash_equals($state['sha256'], hash('sha256', $content))) {
            throw new RuntimeException('Source content changed during reading.');
        }
        return ['content' => $content, 'size' => strlen($content), 'sha256' => $state['sha256']];
    }

    private function assertRelativePath(string $relative): void
    {
        if ($relative === '' || strlen($relative) > self::MAX_PATH_BYTES || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_starts_with($relative, '/') || preg_match('/\A[A-Za-z]:/', $relative) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $relative) === 1 || !mb_check_encoding($relative, 'UTF-8')) {
            throw new RuntimeException('Theme tree contains an unsafe path.');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') throw new RuntimeException('Theme tree contains an unsafe path.');
        }
    }

    private function sameFile(array $a, array $b): bool
    {
        return (int)$a['dev'] === (int)$b['dev'] && (int)$a['ino'] === (int)$b['ino']
            && (int)$a['size'] === (int)$b['size'] && (int)$a['mtime'] === (int)$b['mtime']
            && (($b['mode'] & 0170000) === 0100000);
    }

    private function sameDirectory(array $a, array $b): bool
    {
        return (int)$a['dev'] === (int)$b['dev'] && (int)$a['ino'] === (int)$b['ino']
            && (($a['mode'] & 0170000) === 0040000) && (($b['mode'] & 0170000) === 0040000);
    }

    private function isWithin(string $root, string $path): bool
    {
        return $path === $root || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0;
    }

    private function acquireCoreLocks(array $folders): array
    {
        if (!function_exists('theme_operation_acquire') || !function_exists('theme_operation_release')) {
            throw new RuntimeException('Core theme operation locking is unavailable.');
        }
        $folders = array_values(array_unique($folders));
        sort($folders, SORT_STRING);
        return theme_operation_acquire($folders);
    }

    private function releaseCoreLocks(array $locks): void
    {
        if ($locks === []) return;
        if (!function_exists('theme_operation_release')) {
            error_log('[theme-builder-core-lock] Core theme operation release is unavailable.');
            return;
        }
        theme_operation_release($locks);
    }

    private function acquireLocks(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        $lockDir = $this->privateDirectory('.locks');
        $locks = [];
        try {
            foreach ($keys as $key) {
                $path = $lockDir . '/' . hash('sha256', $key) . '.lock';
                if (is_link($path)) throw new RuntimeException('Theme lock is unsafe.');
                if (!file_exists($path)) {
                    $created = @fopen($path, 'x');
                    if (is_resource($created)) {
                        chmod($path, 0660);
                        fclose($created);
                    }
                }
                $before = @lstat($path);
                $lock = @fopen($path, 'r+');
                $opened = is_resource($lock) ? fstat($lock) : false;
                $after = @lstat($path);
                if (!is_array($before) || !is_array($opened) || !is_array($after) || (($opened['mode'] & 0170000) !== 0100000)
                    || (int)$before['dev'] !== (int)$opened['dev'] || (int)$before['ino'] !== (int)$opened['ino']
                    || (int)$after['dev'] !== (int)$opened['dev'] || (int)$after['ino'] !== (int)$opened['ino']
                    || !flock($lock, LOCK_EX)) {
                    if (is_resource($lock)) fclose($lock);
                    throw new RuntimeException('Could not lock the theme operation.');
                }
                $locked = @lstat($path);
                if (!is_array($locked) || (int)$locked['dev'] !== (int)$opened['dev'] || (int)$locked['ino'] !== (int)$opened['ino']) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    throw new RuntimeException('Theme lock changed during acquisition.');
                }
                $locks[] = $lock;
            }
            return $locks;
        } catch (Throwable $error) {
            $this->releaseLocks($locks);
            throw $error;
        }
    }

    private function releaseLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            if (!is_resource($lock)) continue;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function privateDirectory(string $name): string
    {
        $base = ThemeWorkspace::baseDir();
        $baseReal = realpath($base);
        $baseStat = @lstat($base);
        if ($baseReal === false || !is_array($baseStat) || (($baseStat['mode'] & 0002) !== 0)) {
            throw new RuntimeException('Private storage permissions are unsafe.');
        }
        $publicRoots = [];
        if (defined('VIEWS_BASE')) $publicRoots[] = realpath((string)VIEWS_BASE);
        if (defined('PUBLIC_PATH')) $publicRoots[] = realpath((string)PUBLIC_PATH);
        foreach (array_filter($publicRoots) as $publicRoot) {
            if ($this->isWithin((string)$publicRoot, $baseReal)) throw new RuntimeException('Private storage cannot be web-accessible.');
        }
        return $this->ensurePrivateChild($baseReal, $name);
    }

    private function ensurePrivateChild(string $parent, string $name): string
    {
        if (preg_match('/\A(?:[a-z0-9][a-z0-9_-]{0,99}|\.(?:locks|installed-forks|revisions|baselines|exports))\z/D', $name) !== 1) throw new RuntimeException('Private storage identity is invalid.');
        $parentReal = realpath($parent);
        if ($parentReal === false || !is_dir($parentReal) || is_link($parent)) throw new RuntimeException('Private storage is unavailable.');
        $path = $parentReal . '/' . $name;
        if ((file_exists($path) && (is_link($path) || !is_dir($path))) || (!is_dir($path) && !mkdir($path, 0770))) {
            throw new RuntimeException('Private storage is unavailable.');
        }
        chmod($path, 0770);
        $real = realpath($path);
        if ($real === false || dirname($real) !== $parentReal || is_link($path)) throw new RuntimeException('Private storage escaped its root.');
        return $real;
    }

    private function writeJsonAtomic(string $path, array $data, int $mode): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = dirname($path) . '/.theme-builder-json-' . bin2hex(random_bytes(10));
        try {
            $this->writeFileExclusive($temporary, $encoded, $mode);
            if (!rename($temporary, $path)) throw new RuntimeException('Could not publish protected metadata.');
            $temporary = '';
            $this->syncDirectory(dirname($path));
        } finally {
            if ($temporary !== '' && is_file($temporary) && !is_link($temporary)) @unlink($temporary);
        }
    }

    private function applyPublishedModes(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new RuntimeException('Fork staging changed before publication.');
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                if (!chmod($path, 0755)) throw new RuntimeException('Could not publish fork directory permissions.');
            } elseif ($entry->isFile()) {
                if (!chmod($path, 0644)) throw new RuntimeException('Could not publish fork file permissions.');
            } else {
                throw new RuntimeException('Fork staging contains an unsupported entry.');
            }
        }
    }

    private function assertJsonNumbersPreservable(string $json): void
    {
        $length = strlen($json);
        $inString = false;
        $escaped = false;
        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];
            if ($inString) {
                if ($escaped) $escaped = false;
                elseif ($char === '\\') $escaped = true;
                elseif ($char === '"') $inString = false;
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if (($char === '-' && isset($json[$index + 1]) && ctype_digit($json[$index + 1])) || ctype_digit($char)) {
                if (preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $json, $match, 0, $index) !== 1) continue;
                $number = $match[0];
                if (!str_contains($number, '.') && stripos($number, 'e') === false && strlen(ltrim($number, '-')) >= 19) {
                    throw new RuntimeException('Theme manifest contains an integer too large to preserve safely.');
                }
                if (str_contains($number, '.') || stripos($number, 'e') !== false) {
                    $digits = ltrim((string)preg_replace('/\D/', '', preg_replace('/[eE].*\z/', '', $number)), '0');
                    if (strlen($digits) > 15) throw new RuntimeException('Theme manifest contains a number too precise to preserve safely.');
                }
                $index += strlen($number) - 1;
            }
        }
    }

    private function syncDirectory(string $directory): void
    {
        if (!function_exists('fsync')) return;
        $handle = @fopen($directory, 'r');
        if (!is_resource($handle)) return;
        @fsync($handle);
        fclose($handle);
    }

    private function writeFileExclusive(string $path, string $content, int $mode): void
    {
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) throw new RuntimeException('Could not create a protected file.');
        try {
            $this->writeAll($handle, $content);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Could not sync a protected file.');
        } finally {
            fclose($handle);
        }
        if (!chmod($path, $mode)) throw new RuntimeException('Could not protect a private file.');
    }

    private function writeAll($handle, string $content): void
    {
        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written < 1) throw new RuntimeException('Could not write complete file bytes.');
            $offset += $written;
        }
    }

    private function lintPhpTree(string $root): void
    {
        $snapshot = $this->scanTree($root);
        foreach ($snapshot['entries'] as $relative => $entry) {
            if ($entry['type'] === 'file' && strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') {
                $this->lintPhpFile($this->resolveRegularPath($root, $relative));
            }
        }
    }

    private function lintPhpFile(string $path): void
    {
        if (!function_exists('proc_open')) throw new RuntimeException('PHP syntax validation is unavailable.');
        $binary = $this->phpCliBinary();
        if ($binary === '') throw new RuntimeException('PHP syntax validation is unavailable.');
        $pipes = [];
        $process = proc_open([$binary, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start PHP syntax validation.');
        $output = '';
        foreach ($pipes as $pipe) {
            $output .= stream_get_contents($pipe, 16384) ?: '';
            fclose($pipe);
        }
        if (proc_close($process) !== 0) {
            $safe = trim(str_replace([$path, basename($path)], 'theme source', $output));
            throw new RuntimeException($safe !== '' ? $safe : 'PHP syntax validation failed.');
        }
    }

    private function phpCliBinary(): string
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
            $process = proc_open([$candidate, '-r', 'exit(PHP_VERSION_ID === ' . PHP_VERSION_ID . ' ? 0 : 1);'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) continue;
            foreach ($pipes as $pipe) fclose($pipe);
            if (proc_close($process) === 0) return $resolved = $candidate;
        }
        return $resolved = '';
    }

    private function quarantineAndRemove(string $target, string $folder, ?array $expectedIdentity): bool
    {
        if (!file_exists($target) || is_link($target) || !is_array($expectedIdentity)) return false;
        $base = $this->themesRoot();
        $real = realpath($target);
        $current = @lstat($target);
        if ($real === false || !is_array($current) || dirname($real) !== $base || basename($real) !== $folder
            || (int)$current['dev'] !== (int)$expectedIdentity['dev'] || (int)$current['ino'] !== (int)$expectedIdentity['ino']) return false;
        $quarantine = $base . '/.theme-builder-rollback-' . $folder . '-' . bin2hex(random_bytes(10));
        if (!rename($real, $quarantine)) return false;
        $this->syncDirectory($base);
        return $this->removeOwnedTree($quarantine);
    }

    private function removeOwnedTree(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) return true;
        if (is_link($path) || is_file($path)) return @unlink($path);
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entryPath = $entry->getPathname();
                if ($entry->isLink() || $entry->isFile()) @unlink($entryPath);
                else @rmdir($entryPath);
            }
            @rmdir($path);
        } catch (Throwable) {
            return false;
        }
        return !file_exists($path) && !is_link($path);
    }

    private function safeError(Throwable $error): string
    {
        $message = $error->getMessage();
        $roots = [];
        try {
            $roots[] = realpath(ThemeWorkspace::baseDir());
        } catch (Throwable) {
            // Preserve the original operation error when private storage itself is unsafe.
        }
        if (defined('VIEWS_BASE')) $roots[] = realpath((string)VIEWS_BASE);
        foreach (array_filter($roots) as $root) {
            $message = str_replace((string)$root, 'theme storage', $message);
        }
        return $message !== '' ? $message : 'Theme fork operation failed.';
    }
}
