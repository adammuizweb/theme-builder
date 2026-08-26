<?php

declare(strict_types=1);

final class ThemeOwnerNavigator
{
    private const MAX_TEMPLATES = 200;
    private const MAX_ASSIGNMENTS = 1000;
    private const MAX_THEME_FILE_RESOURCES = 100;
    private const MAX_TRANSLATION_LOCALES = 20;
    private const CONTENT_BATCH_SIZE = 10;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function relationships(string $folder, array $inspection, string $adminBase): array
    {
        $base = $this->adminBase($adminBase);
        $returnUrl = $base . '/?page=admin/tools/theme-builder/installed&theme=' . rawurlencode($folder);
        $theme = is_array($inspection['theme'] ?? null) ? $inspection['theme'] : [];

        return [
            'templates' => $this->templates($folder, !empty($theme['active']), $base, $returnUrl),
            'theme_files' => $this->themeFiles($folder, $inspection, $base, $returnUrl),
            'sections' => $this->sections($folder, $inspection, $base),
        ];
    }

    private function templates(string $folder, bool $activeTheme, string $base, string $returnUrl): array
    {
        try {
            $builderRoute = $this->ownerRoute('jyavani-builder', 'admin/tools/jyavani-builder');
            $translationRoute = $activeTheme
                ? $this->ownerRoute('content-translation', 'admin/tools/content-translation/theme-section-edit')
                : null;
            $builderAvailable = $builderRoute !== null && function_exists('jvb_layout_statuses');
            $translationAvailable = $translationRoute !== null
                && function_exists('ct_parse_theme_section_composition')
                && function_exists('ct_theme_section_source_resource')
                && function_exists('ct_enabled_locales');
            $localeState = $translationAvailable
                ? $this->translationLocales()
                : ['items' => [], 'truncated' => false];
            $locales = $localeState['items'];

            $stmt = $this->pdo->query(
                "SELECT id, title, slug, type, status, created_by
                 FROM posts
                 WHERE type = 'theme' AND is_deleted = 0
                 ORDER BY title ASC, id ASC
                 LIMIT " . (self::MAX_TEMPLATES + 1)
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $truncated = count($rows) > self::MAX_TEMPLATES;
            if ($truncated) array_pop($rows);

            $slots = [];
            $assignmentStmt = $this->pdo->query(
                'SELECT slot_key, custom_post_id FROM assignments WHERE custom_post_id IS NOT NULL ORDER BY slot_key ASC LIMIT '
                . (self::MAX_ASSIGNMENTS + 1)
            );
            $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($assignments) > self::MAX_ASSIGNMENTS) {
                throw new RuntimeException('Theme Template assignment inventory exceeds the safe navigation limit.');
            }
            foreach ($assignments as $assignment) {
                $postId = (int)($assignment['custom_post_id'] ?? 0);
                $slot = is_string($assignment['slot_key'] ?? null) ? trim($assignment['slot_key']) : '';
                if ($postId > 0 && $slot !== '') $slots[$postId][] = $slot;
            }

            $builderStatuses = [];
            if ($builderAvailable && $rows !== []) {
                try {
                    $builderStatuses = jvb_layout_statuses($this->pdo, array_column($rows, 'id'));
                    if (!is_array($builderStatuses)) $builderStatuses = [];
                } catch (Throwable $error) {
                    $builderAvailable = false;
                    error_log('[theme-builder-owner-navigation] Jy Builder lookup failed: ' . $error->getMessage());
                }
            }

            $translationTemplates = [];
            if ($translationAvailable && $locales !== [] && $rows !== []) {
                $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $boundedContent = $driver === 'mysql'
                    ? 'CASE WHEN OCTET_LENGTH(content) <= 2097152 THEN content ELSE NULL END'
                    : 'CASE WHEN length(CAST(content AS BLOB)) <= 2097152 THEN content ELSE NULL END';
                foreach (array_chunk(array_column($rows, 'id'), self::CONTENT_BATCH_SIZE) as $postIds) {
                    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
                    $contentStmt = $this->pdo->prepare(
                        "SELECT id, type, {$boundedContent} AS content
                         FROM posts
                         WHERE type = 'theme' AND is_deleted = 0 AND id IN ({$placeholders})"
                    );
                    $contentStmt->execute($postIds);
                    while ($contentRow = $contentStmt->fetch(PDO::FETCH_ASSOC)) {
                        try {
                            $content = is_string($contentRow['content'] ?? null) ? $contentRow['content'] : '';
                            $composition = ct_parse_theme_section_composition($content);
                            $resource = $composition !== null
                                ? ct_theme_section_source_resource($this->pdo, $contentRow, false)
                                : null;
                            if (is_array($resource) && (string)($resource['theme_folder'] ?? '') === $folder) {
                                $translationTemplates[(int)$contentRow['id']] = true;
                            }
                        } catch (Throwable $error) {
                            error_log('[theme-builder-owner-navigation] Content Translation lookup failed: ' . $error->getMessage());
                        }
                    }
                    $contentStmt->closeCursor();
                }
            }

            $templates = [];
            foreach ($rows as $row) {
                $postId = (int)($row['id'] ?? 0);
                if ($postId <= 0) continue;
                $record = [
                    'id' => $postId,
                    'title' => (string)($row['title'] ?? ''),
                    'slug' => (string)($row['slug'] ?? ''),
                    'status' => (string)($row['status'] ?? 'draft'),
                    'slots' => array_values(array_unique($slots[$postId] ?? [])),
                    'core_url' => null,
                    'builder' => null,
                    'translations' => [],
                ];

                if ($this->canEditCoreTemplate($row)) {
                    $record['core_url'] = $base . '/?' . http_build_query([
                        'page' => 'admin/themes/edit',
                        'id' => $postId,
                        'return_to' => $returnUrl,
                    ]);
                }

                if ($builderAvailable) {
                    $status = is_string($builderStatuses[$postId] ?? null) ? $builderStatuses[$postId] : 'none';
                    if (in_array($status, ['draft', 'published'], true)) {
                        $record['builder'] = [
                            'status' => $status,
                            'url' => $base . '/?' . http_build_query([
                                'page' => 'admin/tools/jyavani-builder',
                                'view' => 'builder',
                                'post_id' => $postId,
                            ]),
                        ];
                    }
                }

                if (isset($translationTemplates[$postId])) {
                    foreach ($locales as $locale) {
                        $record['translations'][] = [
                            'locale' => $locale,
                            'url' => $base . '/?' . http_build_query([
                                'page' => 'admin/tools/content-translation/theme-section-edit',
                                'post_id' => $postId,
                                'locale' => $locale,
                                'return_to' => $returnUrl,
                            ]),
                        ];
                    }
                }

                $templates[] = $record;
            }

            return [
                'items' => $templates,
                'truncated' => $truncated,
                'active_theme' => $activeTheme,
                'builder_available' => $builderAvailable,
                'translation_available' => $translationAvailable,
                'translation_locales_truncated' => $localeState['truncated'],
                'error' => null,
            ];
        } catch (Throwable $error) {
            error_log('[theme-builder-owner-navigation] Core Theme Template lookup failed: ' . $error->getMessage());
            return [
                'items' => [],
                'truncated' => false,
                'active_theme' => $activeTheme,
                'builder_available' => false,
                'translation_available' => false,
                'translation_locales_truncated' => false,
                'error' => 'Theme Template relationships could not be loaded.',
            ];
        }
    }

    private function sections(string $folder, array $inspection, string $base): array
    {
        $files = is_array($inspection['files'] ?? null) ? $inspection['files'] : [];
        $inspector = new InstalledThemeInspector($this->pdo);
        $wrapperIds = [];
        foreach ($files as $file) {
            if (is_array($file) && ($file['category'] ?? null) === 'section-wrapper'
                && is_string($file['id'] ?? null)) $wrapperIds[] = $file['id'];
        }
        try {
            $dependencyMap = $inspector->literalDependencies($folder, $wrapperIds);
        } catch (Throwable $error) {
            $dependencyMap = [];
            error_log('[theme-builder-owner-navigation] Theme Section dependency lookup failed: ' . $error->getMessage());
        }
        $items = [];

        foreach ($files as $file) {
            if (!is_array($file) || ($file['category'] ?? null) !== 'section-wrapper') continue;
            $fileId = is_string($file['id'] ?? null) ? $file['id'] : '';
            $record = [
                'path' => (string)($file['path'] ?? ''),
                'url' => $this->sourceUrl($base, $folder, $fileId),
                'dependencies' => [],
                'scanned' => false,
                'scan_reason' => null,
                'error' => null,
            ];

            try {
                $dependencyState = $dependencyMap[$fileId] ?? null;
                if (!is_array($dependencyState)) throw new RuntimeException('Theme Section wrapper source is unavailable.');
                $record['scanned'] = !empty($dependencyState['scanned']);
                $record['scan_reason'] = is_string($dependencyState['reason'] ?? null) ? $dependencyState['reason'] : null;
                foreach ((array)($dependencyState['dependencies'] ?? []) as $dependency) {
                    if (!is_array($dependency) || !is_string($dependency['id'] ?? null)) continue;
                    $record['dependencies'][] = [
                        'path' => (string)($dependency['path'] ?? ''),
                        'category' => (string)($dependency['category_label'] ?? ''),
                        'url' => $this->sourceUrl($base, $folder, $dependency['id']),
                    ];
                }
            } catch (Throwable $error) {
                $record['error'] = 'Dependency inventory is unavailable.';
                error_log('[theme-builder-owner-navigation] Theme Section dependency lookup failed: ' . $error->getMessage());
            }
            $items[] = $record;
        }

        return ['items' => $items];
    }

    private function themeFiles(string $folder, array $inspection, string $base, string $returnUrl): array
    {
        try {
            $route = $this->ownerRoute('content-translation', 'admin/tools/content-translation/theme-file-edit');
            $available = $route !== null
                && function_exists('ct_theme_file_resources')
                && function_exists('ct_theme_file_translation_statuses')
                && function_exists('ct_enabled_locales');
            if (!$available) {
                return [
                    'items' => [],
                    'available' => false,
                    'locales_truncated' => false,
                    'error' => null,
                ];
            }

            $resources = ct_theme_file_resources($this->pdo, $folder);
            if (!is_array($resources)) throw new RuntimeException('Theme File resource inventory is invalid.');
            if (count($resources) > self::MAX_THEME_FILE_RESOURCES) {
                throw new RuntimeException('Theme File resource inventory exceeds the safe navigation limit.');
            }

            $validResources = [];
            foreach ($resources as $resource) {
                if (!is_array($resource) || !is_string($resource['theme_folder'] ?? null)
                    || !hash_equals($folder, $resource['theme_folder'])) continue;
                $slot = is_string($resource['slot_key'] ?? null) ? trim($resource['slot_key']) : '';
                $id = is_string($resource['id'] ?? null) ? $resource['id'] : '';
                if ($slot === '' || strlen($slot) > 150 || !hash_equals($folder . ':' . $slot, $id)) continue;
                $validResources[$id] = $resource;
            }

            $localeState = $this->translationLocales();
            $statuses = $validResources !== [] && $localeState['items'] !== []
                ? ct_theme_file_translation_statuses($this->pdo, array_values($validResources))
                : [];
            if (!is_array($statuses)) $statuses = [];

            $sourceFiles = [];
            foreach ((array)($inspection['files'] ?? []) as $file) {
                if (!is_array($file) || ($file['category'] ?? null) !== 'slot'
                    || !is_string($file['slot'] ?? null) || !is_string($file['id'] ?? null)) continue;
                $sourceFiles[$file['slot']] = $file;
            }

            $items = [];
            foreach ($validResources as $id => $resource) {
                $slot = (string)$resource['slot_key'];
                $source = $sourceFiles[$slot] ?? null;
                $translations = [];
                foreach ($localeState['items'] as $locale) {
                    $status = $statuses[$id][$locale] ?? null;
                    $translations[] = [
                        'locale' => $locale,
                        'status' => in_array($status, ['draft', 'published', 'incomplete'], true) ? $status : null,
                        'url' => $base . '/?' . http_build_query([
                            'page' => 'admin/tools/content-translation/theme-file-edit',
                            'theme_folder' => $folder,
                            'slot_key' => $slot,
                            'locale' => $locale,
                            'return_to' => $returnUrl,
                        ]),
                    ];
                }
                $items[] = [
                    'id' => $id,
                    'label' => (string)($resource['label'] ?? $slot),
                    'slot' => $slot,
                    'field_count' => count((array)($resource['fields'] ?? [])),
                    'source_path' => is_array($source) ? (string)($source['path'] ?? '') : null,
                    'source_file_id' => is_array($source) ? (string)($source['id'] ?? '') : null,
                    'source_url' => is_array($source) ? $this->sourceUrl($base, $folder, (string)$source['id']) : null,
                    'translations' => $translations,
                ];
            }

            return [
                'items' => $items,
                'available' => true,
                'locales_truncated' => $localeState['truncated'],
                'error' => null,
            ];
        } catch (Throwable $error) {
            error_log('[theme-builder-owner-navigation] Theme File lookup failed: ' . $error->getMessage());
            return [
                'items' => [],
                'available' => false,
                'locales_truncated' => false,
                'error' => 'Theme File translation relationships could not be loaded.',
            ];
        }
    }

    private function ownerRoute(string $plugin, string $route): ?array
    {
        if (!function_exists('plugin_resolve_route') || !function_exists('plugin_route_is_allowed')) return null;
        try {
            $resolved = plugin_resolve_route($route);
            return is_array($resolved)
                && ($resolved['plugin'] ?? null) === $plugin
                && ($resolved['route'] ?? null) === $route
                && plugin_route_is_allowed($this->pdo, $resolved)
                    ? $resolved
                    : null;
        } catch (Throwable $error) {
            error_log('[theme-builder-owner-navigation] Owner route lookup failed: ' . $error->getMessage());
            return null;
        }
    }

    private function canEditCoreTemplate(array $post): bool
    {
        if (!function_exists('current_user_can')) return false;
        try {
            return current_user_can($this->pdo, 'core.theme_content.update', [
                'owner_id' => (int)($post['created_by'] ?? 0),
            ]);
        } catch (Throwable $error) {
            return false;
        }
    }

    private function translationLocales(): array
    {
        try {
            $locales = ct_enabled_locales($this->pdo);
        } catch (Throwable $error) {
            return ['items' => [], 'truncated' => false];
        }
        $valid = [];
        foreach (is_array($locales) ? $locales : [] as $locale) {
            if (!is_string($locale) || strlen($locale) > 16
                || preg_match('/\A[a-zA-Z0-9]+(?:[-_][a-zA-Z0-9]+)*\z/D', $locale) !== 1) continue;
            $valid[$locale] = true;
        }
        $items = array_keys($valid);
        $truncated = count($items) > self::MAX_TRANSLATION_LOCALES;
        return [
            'items' => array_slice($items, 0, self::MAX_TRANSLATION_LOCALES),
            'truncated' => $truncated,
        ];
    }

    private function sourceUrl(string $base, string $folder, string $fileId): string
    {
        return $base . '/?page=admin/tools/theme-builder/installed&theme=' . rawurlencode($folder)
            . '&file=' . rawurlencode($fileId);
    }

    private function adminBase(string $base): string
    {
        $base = rtrim($base, '/');
        if ($base === '' || !str_starts_with($base, '/') || str_starts_with($base, '//')) {
            throw new InvalidArgumentException('Admin base path is invalid.');
        }
        return $base;
    }
}
