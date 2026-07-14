<?php
$category = $category ?? [];
$posts = $posts ?? [];
$pagination = $pagination ?? '';
$catName = htmlspecialchars($category['name'] ?? $page_title ?? __('Category'), ENT_QUOTES, 'UTF-8');
$catDesc = htmlspecialchars($category['description'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<div class="content-area">
  <header class="content-header"><h1><?= $catName ?></h1><?php if ($catDesc): ?><p class="content-meta"><?= $catDesc ?></p><?php endif; ?></header>
  <?php if (!empty($posts)): ?>
    <div class="post-list">
      <?php foreach ($posts as $p): $title = htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8'); $slug = rawurlencode($p['slug'] ?? ''); $url = $slug ? "/{$slug}/" : '#'; $date = !empty($p['created_at']) ? date('d M Y', strtotime($p['created_at'])) : ''; $thumb = $p['display_image'] ?? $p['thumbnail'] ?? ''; ?>
        <article class="post-card"><?php if ($thumb): ?><a href="<?= $url ?>" class="post-card-thumb"><img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $title ?>" loading="lazy"></a><?php endif; ?><div class="post-card-body"><h2><a href="<?= $url ?>"><?= $title ?></a></h2><p class="post-card-meta"><?= $date ?></p></div></article>
      <?php endforeach; ?>
    </div>
    <?php if ($pagination): ?><nav class="pagination"><?= $pagination ?></nav><?php endif; ?>
  <?php else: ?><p class="empty"><?= __('No posts in this category yet.') ?></p><?php endif; ?>
</div>
