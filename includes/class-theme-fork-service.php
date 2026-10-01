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
            $coreLocks = $this->acquireCoreLocks([$sourceFolder, $targetFolder]);
            if (!function_exists('package_publication_recovery_paths')) {
                throw new RuntimeException('Core package publication recovery checks are unavailable.');
            }
            $recoveryPaths = package_publication_recovery_paths($target);
            if ($recoveryPaths !== []) {
                throw new RuntimeException('A prior theme publication recovery artifact requires manual resolution. Inspect and restore or archive it before retrying: ' . basename($recoveryPaths[0]));
            }
            $this->assertCoreInventoryAvailable($sourceFolder);
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
            if ($registeredThemeId === (int)$sourceRow['id'] || !empty($targetRow['is_active']) || !empty($targetRow['is_system'])
                || (string)($targetRow['store_url'] ?? '') !== '' || (string)($targetRow['store_slug'] ?? '') !== '') {
                throw new RuntimeException('Fork registration identity is unsafe.');
            }

            $metadata = [
                'schema' => 1,
                'folder' => $targetFolder,
                'theme_id' => $registeredThemeId,
                'created_at' => gmdate('c'),
                'created_by' => $actorId,
                'source' => [
                    'folder' => $sourceFolder,
                    'theme_id' => (int)$sourceRow['id'],
                    'version' => (string)($manifest['version'] ?? ''),
                    'tree_sha256' => $this->snapshotDigest($sourceSnapshot),
                ],
                'fork_tree_sha256' => $this->snapshotDigest($stageSnapshot),
                'root_identity' => ['dev' => (int)$promotedIdentity['dev'], 'ino' => (int)$promotedIdentity['ino']],
            ];
            $metadataPath = $this->metadataPath($targetFolder);
            if (file_exists($metadataPath) || is_link($metadataPath)) throw new RuntimeException('Fork metadata already exists.');
            $this->writeJsonAtomic($metadataPath, $metadata, 0660);
            $this->pdo->commit();

            return ['success' => true, 'folder' => $targetFolder, 'theme_id' => $registeredThemeId, 'active' => false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($registered && $registeredThemeId > 0) {
                try {
                    $delete = $this->pdo->prepare('DELETE FROM themes WHERE id = ? AND folder_name = ? AND is_active = 0 AND is_system = 0');
                    $delete->execute([$registeredThemeId, $targetFolder]);
                    $verify = $this->pdo->prepare('SELECT COUNT(*) FROM themes WHERE id = ? AND folder_name = ?');
                    $verify->execute([$registeredThemeId, $targetFolder]);
                    $databaseRollbackComplete = (int)$verify->fetchColumn() === 0;
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
            $this->assertManagedRootIdentity($root, $metadata);
            $manifest = $this->readManifest($root, $folder);
            if (isset($manifest['store']) || (string)($row['store_url'] ?? '') !== ''
                || (string)($row['store_slug'] ?? '') !== '' || !empty($row['is_system'])) return $state;

            $state['managed'] = true;
            $state['metadata'] = $metadata;
            if (!empty($row['is_active'])) {
                $state['reason'] = 'This managed fork is active. Deactivate it before editing or restoring source.';
                return $state;
            }
            if ($this->assignmentCount((int)$row['id']) > 0) {
                $state['reason'] = 'This managed fork is assigned to a live slot. Remove its assignments before editing or restoring source.';
                return $state;
            }
            $state['editable'] = true;
            $state['reason'] = '';
            return $state;
        } catch (Throwable) {
            return $state;
        }
    }

    public function managedEditPolicy(string $folder): ?array
    {
        if (!ThemeWorkspace::isValidSlug($folder)) return null;
        $metadataPath = $this->metadataPath($folder);
        if (!file_exists($metadataPath) && !is_link($metadataPath)) return null;

        $state = $this->forkState($folder);
        if (!$state['managed']) {
            return ['allowed' => false, 'message' => 'Theme Builder could not verify managed-fork provenance. Editing is blocked.'];
        }
        return ['allowed' => (bool)$state['editable'], 'message' => (string)$state['reason']];
    }

    public function hasManagedForkMarker(string $folder): bool
    {
        if (!ThemeWorkspace::isValidSlug($folder)) return false;
        $configured = defined('THEME_BUILDER_WORKSPACE') ? (string)THEME_BUILDER_WORKSPACE : '';
        $base = $configured !== '' ? $configured : dirname(__DIR__, 3) . '/cfg/var/theme-builder';
        if (!file_exists($base) && !is_link($base)) return false;
        if (is_link($base) || !is_dir($base)) throw new RuntimeException('Theme Builder provenance storage is unsafe.');
        $baseReal = realpath($base);
        if ($baseReal === false) throw new RuntimeException('Theme Builder provenance storage is unavailable.');

        $directory = $baseReal . '/.installed-forks';
        if (!file_exists($directory) && !is_link($directory)) return false;
        if (is_link($directory) || !is_dir($directory) || dirname((string)realpath($directory)) !== $baseReal) {
            throw new RuntimeException('Theme Builder provenance storage is unsafe.');
        }
        $path = $directory . '/' . hash('sha256', $folder) . '.json';
        return file_exists($path) || is_link($path);
    }

    public function deleteFork(string $folder): array
    {
        if (!ThemeWorkspace::isValidSlug($folder)) return ['success' => false, 'error' => 'Invalid managed fork folder.'];
        $coreLocks = [];
        $locks = [];
        $root = '';
        $quarantine = '';
        $rootIdentity = null;
        $committed = false;
        try {
            $coreLocks = $this->acquireCoreLocks([$folder]);
            $locks = $this->acquireLocks(['installed:' . $folder]);
            $root = $this->themeRoot($folder);
            if (function_exists('package_publication_recovery_paths') && package_publication_recovery_paths($root) !== []) {
                throw new RuntimeException('A prior theme publication recovery artifact requires manual resolution before deletion.');
            }

            $metadata = $this->readMetadata($folder);
            if ($metadata === null) throw new RuntimeException('Only a Theme Builder managed fork can be deleted here.');
            $rootIdentity = @lstat($root);
            if (!is_array($rootIdentity)) throw new RuntimeException('Managed fork root is unavailable.');
            $this->assertManagedRootIdentity($root, $metadata);
            $this->scanTree($root);

            $row = $this->lockThemeDatabaseState($folder);
            $themeId = (int)$row['id'];
            if ((int)($metadata['theme_id'] ?? 0) !== $themeId || !empty($row['is_active']) || !empty($row['is_system'])
                || trim((string)($row['store_url'] ?? '')) !== '' || trim((string)($row['store_slug'] ?? '')) !== ''
                || $this->assignmentCount($themeId) > 0) {
                throw new RuntimeException('Only an inactive, unassigned Theme Builder managed fork can be deleted.');
            }
            $this->assertManagedRootIdentity($root, $metadata);

            $quarantine = $this->themesRoot() . '/.theme-builder-delete-' . $folder . '-' . bin2hex(random_bytes(10));
            if (!rename($root, $quarantine)) throw new RuntimeException('Could not quarantine the managed fork before deletion.');
            $this->syncDirectory($this->themesRoot());
            $quarantineIdentity = @lstat($quarantine);
            if (!is_array($quarantineIdentity) || !$this->sameDirectory($rootIdentity, $quarantineIdentity)) {
                throw new RuntimeException('Managed fork identity changed during deletion.');
            }

            $zones = $this->pdo->prepare('DELETE FROM theme_zone_items WHERE theme_folder = ?');
            $zones->execute([$folder]);
            $delete = $this->pdo->prepare('DELETE FROM themes WHERE id = ? AND folder_name = ? AND is_active = 0 AND is_system = 0 AND store_url = ? AND store_slug = ?');
            $delete->execute([$themeId, $folder, '', '']);
            if ($delete->rowCount() !== 1) throw new RuntimeException('Managed fork registration changed before deletion.');
            $this->pdo->commit();
            $committed = true;

            $warnings = [];
            $metadataPath = $this->metadataPath($folder);
            if (is_link($metadataPath) || !is_file($metadataPath) || !@unlink($metadataPath)) {
                $warnings[] = 'Managed fork metadata could not be removed.';
            }
            if (!$this->removeOwnedTree($quarantine)) {
                $warnings[] = 'The quarantined theme files could not be removed and require administrator cleanup.';
            }
            $result = ['success' => true, 'folder' => $folder];
            if ($warnings !== []) $result['warning'] = implode(' ', $warnings);
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (!$committed && $quarantine !== '' && is_array($rootIdentity) && !file_exists($root) && !is_link($root)) {
                $quarantineIdentity = @lstat($quarantine);
                if (is_array($quarantineIdentity) && $this->sameDirectory($rootIdentity, $quarantineIdentity)) {
                    @rename($quarantine, $root);
                    $this->syncDirectory($this->themesRoot());
                }
            }
            return ['success' => false, 'error' => $this->safeError($error)];
        } finally {
            $this->releaseLocks($locks);
            $this->releaseCoreLocks($coreLocks);
        }
    }

    private function assertCoreInventoryAvailable(string $folder): void
    {
        if (!function_exists('theme_source_service')) {
            throw new RuntimeException('Core ThemeSourceService is unavailable; Theme Builder requires Jyavani Core 2.3.164 or newer.');
        }
        $service = theme_source_service($this->pdo);
        if (!$service instanceof ThemeSourceService) {
            throw new RuntimeException('Core ThemeSourceService inventory is unavailable.');
        }
        $inventory = $service->inventory($folder);
        if (!is_array($inventory) || !is_array($inventory['theme'] ?? null) || !is_array($inventory['files'] ?? null)
            || !array_is_list($inventory['files'])) {
            throw new RuntimeException('Core returned an invalid installed-theme inventory.');
        }
    }

    private function registeredTheme(string $folder): array
    {
        if (!$this->validInstalledFolder($folder)) throw new InvalidArgumentException('Invalid source theme folder.');
        $stmt = $this->pdo->prepare('SELECT * FROM themes WHERE folder_name = ? LIMIT 1');
        $stmt->execute([$folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['folder_name'] ?? null) || !hash_equals($folder, $row['folder_name'])) {
            throw new RuntimeException('Source theme is not registered with this exact folder identity.');
        }
        return $row;
    }

    private function validInstalledFolder(string $folder): bool
    {
        return strlen($folder) <= 128 && preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) === 1
            && !in_array($folder, ['.', '..'], true);
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
        if (!$row || !is_string($row['folder_name'] ?? null) || !hash_equals($folder, $row['folder_name'])) {
            throw new RuntimeException('Theme registration identity is unavailable.');
        }
        $assignments = $this->pdo->prepare('SELECT id FROM assignments WHERE theme_id = ? ORDER BY id' . $suffix);
        $assignments->execute([(int)$row['id']]);
        $assignments->fetchAll(PDO::FETCH_COLUMN);
        return $row;
    }

    private function assertManagedRootIdentity(string $root, array $metadata): void
    {
        $stat = @lstat($root);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000)
            || (int)($metadata['root_identity']['dev'] ?? -1) !== (int)$stat['dev']
            || (int)($metadata['root_identity']['ino'] ?? -1) !== (int)$stat['ino']) {
            throw new RuntimeException('Managed fork root identity changed.');
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
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (++$count > self::MAX_ENTRIES) throw new RuntimeException('Theme tree contains too many entries.');
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
            if ($size > self::MAX_FILE_BYTES || ($total += $size) > self::MAX_TREE_BYTES) throw new RuntimeException('Theme tree exceeds the size limit.');
            $state = $this->hashRegularFile($path, $root, self::MAX_FILE_BYTES);
            $entries[$relative] = ['type' => 'file', 'size' => $state['size'], 'sha256' => $state['sha256'],
                'dev' => (int)$stat['dev'], 'ino' => (int)$stat['ino'], 'mtime' => (int)$stat['mtime']];
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
        $this->writeFileExclusive($temporary, $encoded, 0664);
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
        if (array_keys($source['entries']) !== array_keys($fork['entries'])) throw new RuntimeException('Fork tree is incomplete.');
        foreach ($source['entries'] as $relative => $entry) {
            $copy = $fork['entries'][$relative];
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
                if (($bytes += strlen($chunk)) > $maxBytes) throw new RuntimeException('Theme file is too large.');
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
        if (!function_exists('theme_lifecycle_lock_keys') || !function_exists('theme_operation_acquire')
            || !function_exists('theme_operation_release')) {
            throw new RuntimeException('Core theme operation locking is unavailable.');
        }
        $keys = theme_lifecycle_lock_keys(array_values(array_unique($folders)));
        if (!is_array($keys) || $keys === []) throw new RuntimeException('Core theme lifecycle lock keys are unavailable.');
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return theme_operation_acquire($keys);
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
        if (preg_match('/\A\.(?:locks|installed-forks)\z/D', $name) !== 1) throw new RuntimeException('Private storage identity is invalid.');
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
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new RuntimeException('Fork staging changed before publication.');
            if ($entry->isDir()) {
                if (!chmod($entry->getPathname(), 0755)) throw new RuntimeException('Could not publish fork directory permissions.');
            } elseif ($entry->isFile()) {
                if (!chmod($entry->getPathname(), 0644)) throw new RuntimeException('Could not publish fork file permissions.');
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
        foreach ($this->scanTree($root)['entries'] as $relative => $entry) {
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
        $candidates = array_values(array_unique(array_filter([$configured, PHP_SAPI === 'cli' ? PHP_BINARY : '', PHP_BINDIR . '/php',
            '/usr/bin/php' . $version, '/usr/bin/php', '/usr/local/bin/php' . $version, '/usr/local/bin/php'])));
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
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                if ($entry->isLink() || $entry->isFile()) @unlink($entry->getPathname());
                else @rmdir($entry->getPathname());
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
        }
        if (defined('VIEWS_BASE')) $roots[] = realpath((string)VIEWS_BASE);
        foreach (array_filter($roots) as $root) $message = str_replace((string)$root, 'theme storage', $message);
        return $message !== '' ? $message : 'Theme fork operation failed.';
    }
}
