<?php
$posts = $posts ?? [];
$pageTitle = $page_title ?? __('Posts');
$pagination = $pagination ?? '';
?>
<div class="content-area">
  <header class="content-header"><h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1></header>
  <?php if (!empty($posts)): ?>
    <div class="post-list">
      <?php foreach ($posts as $p):
        $title = htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $slug = rawurlencode($p['slug'] ?? '');
        $url = $slug ? "/{$slug}/" : '#';
        $excerpt = htmlspecialchars(mb_strimwidth(safe_strip_tags(html_entity_decode((string)($p['content'] ?? ''), ENT_QUOTES, 'UTF-8')), 0, 200, '…'), ENT_QUOTES, 'UTF-8');
        $date = !empty($p['created_at']) ? date('d M Y', strtotime($p['created_at'])) : '';
        $thumb = $p['display_image'] ?? $p['thumbnail'] ?? '';
      ?>
        <article class="post-card">
          <?php if ($thumb): ?><a href="<?= $url ?>" class="post-card-thumb"><img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $title ?>" loading="lazy"></a><?php endif; ?>
          <div class="post-card-body"><h2><a href="<?= $url ?>"><?= $title ?></a></h2><p class="post-card-meta"><?= $date ?></p><?php if ($excerpt): ?><p class="post-card-excerpt"><?= $excerpt ?></p><?php endif; ?></div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($pagination): ?><nav class="pagination"><?= $pagination ?></nav><?php endif; ?>
  <?php else: ?>
    <p class="empty"><?= __('No posts yet.') ?></p>
  <?php endif; ?>
</div>
