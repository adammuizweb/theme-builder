<?php
declare(strict_types=1);

class PreviewRenderer
{
    public static function dummyContext(string $slot): array
    {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $dummyPost = [
            'id' => 1, 'title' => 'Sample Post — Getting Started', 'slug' => 'sample-post',
            'content' => '<p>This is a <strong>sample post</strong> with formatted content. It shows <a href="#">links</a>, headings, and other elements.</p><h2>A Subheading</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p><ul><li>Item one</li><li>Item two</li></ul><blockquote>A blockquote.</blockquote>',
            'type' => 'article', 'status' => 'published',
            'created_at' => '2026-07-10 14:30:00', 'updated_at' => '2026-07-12 09:15:00',
            'thumbnail' => '', 'display_image' => '', 'youtube' => '',
            'author_name' => 'Adam Muiz', 'author_username' => 'admin', 'author_email' => 'admin@example.com', 'author_img' => '',
            'category_names' => 'Technology, Programming', 'category_slugs' => 'technology, programming',
            'meta' => '', 'display_image_target_url' => '', 'display_image_target_attribute' => '',
        ];

        $dummyPosts = [
            $dummyPost,
            array_merge($dummyPost, ['id' => 2, 'title' => 'Second Post', 'slug' => 'second-post', 'created_at' => '2026-07-08 10:00:00']),
            array_merge($dummyPost, ['id' => 3, 'title' => 'Third Post', 'slug' => 'third-post', 'created_at' => '2026-07-05 16:45:00']),
        ];

        $ctx = [
            'pdo' => $GLOBALS['pdo'] ?? null,
            'site' => ['title' => 'My Site', 'url' => $baseUrl, 'description' => 'A Jyavani CMS site'],
            'context' => 'preview', 'page_title' => 'Preview',
            'base_url' => $baseUrl, 'homeUrl' => $baseUrl,
        ];

        switch ($slot) {
            case 'main.homepage': $ctx['posts'] = $dummyPosts; $ctx['featured'] = $dummyPost; break;
            case 'main.search': $ctx['posts'] = $dummyPosts; $ctx['query'] = 'theme'; $_GET['s'] = 'theme'; break;
            case 'list.post': $ctx['posts'] = $dummyPosts; $ctx['results'] = $dummyPosts; $ctx['page'] = 1; $ctx['perPage'] = 10; $ctx['total'] = 3; $ctx['pages'] = 1; $ctx['totalPages'] = 1; $ctx['base'] = '/artikel/'; $ctx['pagination'] = ''; $ctx['page_title'] = 'Articles'; break;
            case 'list.page': $ctx['pages'] = $dummyPosts; break;
            case 'list.category': $ctx['category'] = ['name' => 'Technology', 'slug' => 'technology', 'description' => 'Tech posts.']; $ctx['posts'] = $dummyPosts; $ctx['pagination'] = ''; break;
            case 'list.archive': $ctx['year'] = '2026'; $ctx['month'] = '07'; $ctx['posts'] = $dummyPosts; $ctx['pagination'] = ''; break;
            case 'list.author': $ctx['author'] = ['display_name' => 'Adam Muiz', 'username' => 'admin']; $ctx['posts'] = $dummyPosts; $ctx['pagination'] = ''; break;
            case 'single.post': $ctx['post'] = $dummyPost; break;
            case 'single.page': $ctx['post'] = array_merge($dummyPost, ['type' => 'page', 'title' => 'About Us']); $ctx['page'] = $ctx['post']; break;
            case 'index.category': $ctx['categories'] = [['name' => 'Technology', 'slug' => 'technology', 'post_count' => 5], ['name' => 'Programming', 'slug' => 'programming', 'post_count' => 3]]; break;
            case 'index.author': $ctx['authors'] = [['display_name' => 'Adam Muiz', 'username' => 'admin', 'post_count' => 10], ['display_name' => 'Jane Doe', 'username' => 'jane', 'post_count' => 3]]; break;
        }
        return $ctx;
    }

    public static function render(string $themeDir, string $slot): string
    {
        $file = ThemeWorkspace::slotFiles()[$slot] ?? null;
        if (!$file || !is_file($themeDir . '/' . $file)) return '<p>File not found for slot: ' . htmlspecialchars($slot) . '</p>';
        $ctx = self::dummyContext($slot);
        $manifest = is_file($themeDir . '/theme.json') ? (json_decode((string)file_get_contents($themeDir . '/theme.json'), true) ?: []) : [];

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview</title>';
        foreach ($manifest['styles'] ?? [] as $css) {
            if (is_file($themeDir . '/' . $css)) $html .= '<style>' . file_get_contents($themeDir . '/' . $css) . '</style>';
        }
        $html .= '</head><body>';
        $html .= self::renderTemplate($themeDir . '/' . $file, $ctx);
        foreach ($manifest['scripts'] ?? [] as $js) {
            if (is_file($themeDir . '/' . $js)) $html .= '<script>' . file_get_contents($themeDir . '/' . $js) . '</script>';
        }
        $html .= '</body></html>';
        return $html;
    }

    public static function renderFullPage(string $themeDir, string $slot): string
    {
        $ctx = self::dummyContext($slot);
        $manifest = is_file($themeDir . '/theme.json') ? (json_decode((string)file_get_contents($themeDir . '/theme.json'), true) ?: []) : [];

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($ctx['site']['title']) . '</title>';
        foreach ($manifest['styles'] ?? [] as $css) {
            if (is_file($themeDir . '/' . $css)) $html .= '<style>' . file_get_contents($themeDir . '/' . $css) . '</style>';
        }
        $html .= '</head><body>';
        if (is_file($themeDir . '/header.php')) $html .= self::renderTemplate($themeDir . '/header.php', $ctx);
        $mainFile = ThemeWorkspace::slotFiles()[$slot] ?? null;
        if ($mainFile && is_file($themeDir . '/' . $mainFile)) {
            $html .= '<main>' . self::renderTemplate($themeDir . '/' . $mainFile, $ctx) . '</main>';
        }
        if (is_file($themeDir . '/footer.php')) $html .= self::renderTemplate($themeDir . '/footer.php', $ctx);
        foreach ($manifest['scripts'] ?? [] as $js) {
            if (is_file($themeDir . '/' . $js)) $html .= '<script>' . file_get_contents($themeDir . '/' . $js) . '</script>';
        }
        $html .= '</body></html>';
        return $html;
    }

    private static function renderTemplate(string $path, array $ctx): string
    {
        $GLOBALS['pdo'] = $ctx['pdo'] ?? null;
        $__ctx = $ctx;
        ob_start();
        try {
            extract($__ctx, EXTR_SKIP);
            include $path;
        } catch (Throwable $e) {
            ob_end_clean();
            return '<div style="padding:1rem;background:#fee;border:1px solid #f00;color:#900">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        return (string)ob_get_clean();
    }
}
