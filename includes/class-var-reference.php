<?php
declare(strict_types=1);

class VarReference
{
    private const COMMON_VARS = [
        '$pdo'        => ['type' => 'PDO', 'desc' => 'Database connection'],
        '$site'       => ['type' => 'array', 'desc' => 'Site info: $site["title"], $site["url"], $site["description"]'],
        '$context'    => ['type' => 'string', 'desc' => 'Current layout context'],
        '$page_title' => ['type' => 'string', 'desc' => 'Page title for <title> tag'],
    ];

    private const SLOT_VARS = [
        'header' => ['$site' => ['type' => 'array', 'desc' => 'Site info'], '$colorMode' => ['type' => 'string', 'desc' => '"light"|"dark"|"both"'], '$pdo' => ['type' => 'PDO', 'desc' => 'For menu_render()']],
        'footer' => ['$site' => ['type' => 'array', 'desc' => 'Site info'], '$year' => ['type' => 'string', 'desc' => 'Current year']],
        'sidebar' => ['$pdo' => ['type' => 'PDO', 'desc' => 'For widget rendering']],
        'main.homepage' => ['$posts' => ['type' => 'array', 'desc' => 'Latest published posts'], '$featured' => ['type' => 'array?', 'desc' => 'Featured post'], '$site' => ['type' => 'array', 'desc' => 'Site info']],
        'main.search' => ['$posts' => ['type' => 'array', 'desc' => 'Search results'], '$query' => ['type' => 'string', 'desc' => 'Search query']],
        'main.404' => ['$site' => ['type' => 'array', 'desc' => 'Site info']],
        'list.post' => ['$posts' => ['type' => 'array', 'desc' => 'Array of posts'], '$page' => ['type' => 'int', 'desc' => 'Current page'], '$perPage' => ['type' => 'int', 'desc' => 'Per page'], '$total' => ['type' => 'int', 'desc' => 'Total count'], '$pages' => ['type' => 'int', 'desc' => 'Total pages'], '$base' => ['type' => 'string', 'desc' => 'Base URL'], '$pagination' => ['type' => 'string', 'desc' => 'Pagination HTML']],
        'list.page' => ['$pages' => ['type' => 'array', 'desc' => 'Array of pages']],
        'list.category' => ['$category' => ['type' => 'array', 'desc' => 'Category: name, slug, description'], '$posts' => ['type' => 'array', 'desc' => 'Posts in category'], '$pagination' => ['type' => 'string', 'desc' => 'Pagination HTML']],
        'list.archive' => ['$year' => ['type' => 'string', 'desc' => 'Year'], '$month' => ['type' => 'string', 'desc' => 'Month (01-12)'], '$posts' => ['type' => 'array', 'desc' => 'Posts in period'], '$pagination' => ['type' => 'string', 'desc' => 'Pagination HTML']],
        'list.author' => ['$author' => ['type' => 'array', 'desc' => 'Author info'], '$posts' => ['type' => 'array', 'desc' => 'Posts by author'], '$pagination' => ['type' => 'string', 'desc' => 'Pagination HTML']],
        'single.post' => ['$post' => ['type' => 'array', 'desc' => 'Full post object']],
        'single.page' => ['$post' => ['type' => 'array', 'desc' => 'Full page object']],
        'index.category' => ['$categories' => ['type' => 'array', 'desc' => 'All categories with post_count']],
        'index.author' => ['$authors' => ['type' => 'array', 'desc' => 'All authors with post_count']],
    ];

    private const POST_FIELDS = [
        '$post["id"]' => ['type' => 'int', 'desc' => 'Post ID'],
        '$post["title"]' => ['type' => 'string', 'desc' => 'Post title'],
        '$post["slug"]' => ['type' => 'string', 'desc' => 'URL slug'],
        '$post["content"]' => ['type' => 'string', 'desc' => 'Full HTML content'],
        '$post["type"]' => ['type' => 'string', 'desc' => '"article" or "page"'],
        '$post["status"]' => ['type' => 'string', 'desc' => '"published", "draft", "private"'],
        '$post["created_at"]' => ['type' => 'string', 'desc' => 'Publish date'],
        '$post["updated_at"]' => ['type' => 'string', 'desc' => 'Last modified'],
        '$post["thumbnail"]' => ['type' => 'string', 'desc' => 'Thumbnail URL'],
        '$post["display_image"]' => ['type' => 'string', 'desc' => 'Display image URL'],
        '$post["youtube"]' => ['type' => 'string', 'desc' => 'YouTube URL'],
        '$post["author_name"]' => ['type' => 'string', 'desc' => 'Author name'],
        '$post["author_username"]' => ['type' => 'string', 'desc' => 'Author username'],
        '$post["author_email"]' => ['type' => 'string', 'desc' => 'Author email'],
        '$post["author_img"]' => ['type' => 'string', 'desc' => 'Author avatar URL'],
        '$post["category_names"]' => ['type' => 'string', 'desc' => 'Category names (comma-sep)'],
        '$post["category_slugs"]' => ['type' => 'string', 'desc' => 'Category slugs (comma-sep)'],
        '$post["meta"]' => ['type' => 'string', 'desc' => 'JSON meta fields'],
    ];

    private const HELPERS = [
        'htmlspecialchars($str, ENT_QUOTES, "UTF-8")' => 'Escape output — ALWAYS use for user data',
        '__("key")' => 'Translate string (i18n)',
        'safe_strip_tags($html)' => 'Strip HTML tags safely',
        'html_entity_decode($str)' => 'Decode HTML entities before excerpts',
        'mb_strimwidth($str, 0, 200, "…")' => 'Truncate with ellipsis',
        'apply_filters("post_content", $content, $post)' => 'Apply content filters',
        'menu_render($pdo, "primary", [...])' => 'Render dynamic menu',
        'widget("name", [...])' => 'Render sidebar widget',
        'svg_ico("name")' => 'Render Lucide icon',
        'date("d M Y", strtotime($post["created_at"]))' => 'Format date',
    ];

    public static function forSlot(string $slot): array
    {
        return [
            'slot' => $slot,
            'vars' => self::SLOT_VARS[$slot] ?? [],
            'common' => self::COMMON_VARS,
            'post_fields' => in_array($slot, ['single.post', 'single.page'], true) ? self::POST_FIELDS : [],
            'helpers' => self::HELPERS,
        ];
    }

    public static function renderPanel(string $slot): string
    {
        $ref = self::forSlot($slot);
        $html = '<div class="tb-var-ref">';
        if (!empty($ref['vars'])) {
            $html .= '<h4>Slot Variables</h4><ul class="tb-var-list">';
            foreach ($ref['vars'] as $name => $info) {
                $html .= '<li><code>' . htmlspecialchars($name) . '</code> <span class="tb-var-type">' . htmlspecialchars($info['type']) . '</span><br><small>' . htmlspecialchars($info['desc']) . '</small></li>';
            }
            $html .= '</ul>';
        }
        if (!empty($ref['post_fields'])) {
            $html .= '<h4>Post Fields</h4><ul class="tb-var-list">';
            foreach ($ref['post_fields'] as $name => $info) {
                $html .= '<li><code>' . htmlspecialchars($name) . '</code> <span class="tb-var-type">' . htmlspecialchars($info['type']) . '</span><br><small>' . htmlspecialchars($info['desc']) . '</small></li>';
            }
            $html .= '</ul>';
        }
        $html .= '<h4>Common</h4><ul class="tb-var-list">';
        foreach ($ref['common'] as $name => $info) {
            $html .= '<li><code>' . htmlspecialchars($name) . '</code> <span class="tb-var-type">' . htmlspecialchars($info['type']) . '</span><br><small>' . htmlspecialchars($info['desc']) . '</small></li>';
        }
        $html .= '</ul><h4>Helpers</h4><ul class="tb-var-list">';
        foreach ($ref['helpers'] as $code => $desc) {
            $html .= '<li><code>' . htmlspecialchars($code) . '</code><br><small>' . htmlspecialchars($desc) . '</small></li>';
        }
        $html .= '</ul></div>';
        return $html;
    }
}
