<?php $pages = $pages ?? []; ?>
<div class="content-area">
  <header class="content-header"><h1><?= __('Pages') ?></h1></header>
  <?php if (!empty($pages)): ?>
    <div class="post-list">
      <?php foreach ($pages as $p): $title = htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8'); $slug = htmlspecialchars($p['slug'] ?? '', ENT_QUOTES, 'UTF-8'); $url = $slug ? "/{$slug}/" : '#'; ?>
        <article class="post-card"><div class="post-card-body"><h2><a href="<?= $url ?>"><?= $title ?></a></h2></div></article>
      <?php endforeach; ?>
    </div>
  <?php else: ?><p class="empty"><?= __('No pages yet.') ?></p><?php endif; ?>
</div>
