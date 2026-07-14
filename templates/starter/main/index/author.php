<?php $authors = $authors ?? []; ?>
<div class="content-area">
  <header class="content-header"><h1><?= __('Authors') ?></h1></header>
  <?php if (!empty($authors)): ?>
    <div class="post-list">
      <?php foreach ($authors as $a): $name = htmlspecialchars($a['display_name'] ?? $a['username'] ?? '', ENT_QUOTES, 'UTF-8'); $ident = htmlspecialchars($a['username'] ?? $a['id'] ?? '', ENT_QUOTES, 'UTF-8'); $count = (int)($a['post_count'] ?? 0); $url = $ident ? "/author/{$ident}/" : '#'; ?>
        <article class="post-card"><div class="post-card-body"><h2><a href="<?= $url ?>"><?= $name ?></a></h2><p class="post-card-meta"><?= sprintf(__('%d posts'), $count) ?></p></div></article>
      <?php endforeach; ?>
    </div>
  <?php else: ?><p class="empty"><?= __('No authors yet.') ?></p><?php endif; ?>
</div>
