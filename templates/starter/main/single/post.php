<?php
if (!isset($post) || !is_array($post)) return;
$authorName = !empty($post['author_name']) ? $post['author_name'] : (!empty($post['author_username']) ? $post['author_username'] : (!empty($post['author_email']) ? $post['author_email'] : __('Author')));
$authorUrl = !empty($post['author_username']) ? '/author/' . rawurlencode($post['author_username']) . '/' : null;
$categories = [];
if (!empty($post['category_names']) && !empty($post['category_slugs'])) {
    $catNames = explode(', ', (string)$post['category_names']);
    $catSlugs = explode(', ', (string)$post['category_slugs']);
    foreach ($catNames as $i => $name) { if (isset($catSlugs[$i])) $categories[] = ['name' => $name, 'slug' => $catSlugs[$i]]; }
}
$wordCount = str_word_count(safe_strip_tags(html_entity_decode((string)($post['content'] ?? ''), ENT_QUOTES, 'UTF-8')));
$readTime = max(1, (int)ceil($wordCount / 200));
$datePublished = !empty($post['created_at']) ? date('c', strtotime((string)$post['created_at'])) : null;
$thumbUrl = !empty($post['display_image']) ? $post['display_image'] : (!empty($post['thumbnail']) ? $post['thumbnail'] : '');
$siteTitleJson = $site['title'] ?? 'My Site';
?>
<?php if ($datePublished): ?>
<script type="application/ld+json">
<?= json_encode(['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'] ?? '', 'image' => $thumbUrl ?: null, 'author' => ['@type' => 'Person', 'name' => $authorName, 'url' => $authorUrl], 'publisher' => ['@type' => 'Organization', 'name' => $siteTitleJson], 'datePublished' => $datePublished, 'description' => mb_strimwidth(safe_strip_tags(html_entity_decode((string)($post['content'] ?? ''), ENT_QUOTES, 'UTF-8')), 0, 160, '...')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<?php endif; ?>
<article class="post-single">
  <header class="post-header">
    <?php if (!empty($categories)): ?><div class="post-categories"><?php foreach ($categories as $cat): ?><a href="/category/<?= rawurlencode($cat['slug']) ?>/" class="cat-badge"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?></div><?php endif; ?>
    <h1 class="post-title"><?= htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="post-meta">
      <span><?= __('Written by') ?> <?php if ($authorUrl): ?><a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></a><?php else: ?><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
      <?php if ($datePublished): ?><span><time datetime="<?= htmlspecialchars($datePublished, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('d M Y', strtotime((string)$post['created_at'])), ENT_QUOTES, 'UTF-8') ?></time></span><?php endif; ?>
      <span><?= sprintf(__('%d min read'), $readTime) ?></span>
    </div>
  </header>
  <?php if ($thumbUrl): ?><figure class="post-thumbnail"><img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" loading="lazy"></figure><?php endif; ?>
  <div class="post-body"><?= apply_filters('post_content', (string)($post['content'] ?? ''), $post ?? []) ?></div>
  <footer class="post-footer"><a href="/" class="btn-back">&larr; <?= __('Home') ?></a></footer>
</article>
