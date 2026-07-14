<?php $categories = $categories ?? []; ?>
<div class="content-area">
  <header class="content-header"><h1><?= __('Categories') ?></h1></header>
  <?php if (!empty($categories)): ?>
    <div class="post-list">
      <?php foreach ($categories as $cat): $name = htmlspecialchars($cat['name'] ?? '', ENT_QUOTES, 'UTF-8'); $slug = htmlspecialchars($cat['slug'] ?? '', ENT_QUOTES, 'UTF-8'); $count = (int)($cat['post_count'] ?? $cat['count'] ?? 0); $url = $slug ? "/category/{$slug}/" : '#'; ?>
        <article class="post-card"><div class="post-card-body"><h2><a href="<?= $url ?>"><?= $name ?></a></h2><p class="post-card-meta"><?= sprintf(__('%d posts'), $count) ?></p></div></article>
      <?php endforeach; ?>
    </div>
  <?php else: ?><p class="empty"><?= __('No categories yet.') ?></p><?php endif; ?>
</div>
