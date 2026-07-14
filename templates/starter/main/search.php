<?php
$query = htmlspecialchars($_GET['s'] ?? '', ENT_QUOTES, 'UTF-8');
$posts = $posts ?? [];
$count = count($posts);
?>
<div class="content-area">
  <header class="content-header"><h1><?= __('Search:') ?> "<?= $query ?>"</h1><p class="content-meta"><?= sprintf(__('%d results found'), $count) ?></p></header>
  <?php if ($count > 0): ?>
    <div class="post-list">
      <?php foreach ($posts as $p): $title = htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8'); $slug = htmlspecialchars($p['slug'] ?? '', ENT_QUOTES, 'UTF-8'); $url = $slug ? "/{$slug}/" : '#'; $excerpt = htmlspecialchars(mb_strimwidth(safe_strip_tags(html_entity_decode((string)($p['content'] ?? ''), ENT_QUOTES, 'UTF-8')), 0, 200, '…'), ENT_QUOTES, 'UTF-8'); ?>
        <article class="post-card"><div class="post-card-body"><h2><a href="<?= $url ?>"><?= $title ?></a></h2><?php if ($excerpt): ?><p class="post-card-excerpt"><?= $excerpt ?></p><?php endif; ?></div></article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state"><p><?= __('No results found for') ?> "<?= $query ?>".</p><a href="/" class="btn-back"><?= __('Back to Home') ?></a></div>
  <?php endif; ?>
</div>
