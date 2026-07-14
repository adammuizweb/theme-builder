<?php
$author = $author ?? []; $posts = $posts ?? []; $pagination = $pagination ?? '';
$authorName = htmlspecialchars($author['display_name'] ?? $author['username'] ?? $page_title ?? __('Author'), ENT_QUOTES, 'UTF-8');
?>
<div class="content-area">
  <header class="content-header"><h1><?= __('Posts by') ?> <?= $authorName ?></h1></header>
  <?php if (!empty($posts)): ?>
    <div class="post-list">
      <?php foreach ($posts as $p): $title = htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8'); $slug = rawurlencode($p['slug'] ?? ''); $url = $slug ? "/{$slug}/" : '#'; $date = !empty($p['created_at']) ? date('d M Y', strtotime($p['created_at'])) : ''; ?>
        <article class="post-card"><div class="post-card-body"><h2><a href="<?= $url ?>"><?= $title ?></a></h2><p class="post-card-meta"><?= $date ?></p></div></article>
      <?php endforeach; ?>
    </div>
    <?php if ($pagination): ?><nav class="pagination"><?= $pagination ?></nav><?php endif; ?>
  <?php else: ?><p class="empty"><?= __('No posts from this author yet.') ?></p><?php endif; ?>
</div>
