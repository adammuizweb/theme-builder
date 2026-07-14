<?php
$year = $year ?? ''; $month = $month ?? ''; $posts = $posts ?? []; $pagination = $pagination ?? '';
$label = $year;
if ($month) { $months = ['', __('January'), __('February'), __('March'), __('April'), __('May'), __('June'), __('July'), __('August'), __('September'), __('October'), __('November'), __('December')]; $label = $months[(int)$month] . ' ' . $year; }
?>
<div class="content-area">
  <header class="content-header"><h1><?= __('Archive:') ?> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h1></header>
  <?php if (!empty($posts)): ?>
    <div class="post-list">
      <?php foreach ($posts as $p): $title = htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8'); $slug = rawurlencode($p['slug'] ?? ''); $url = $slug ? "/{$slug}/" : '#'; $date = !empty($p['created_at']) ? date('d M Y', strtotime($p['created_at'])) : ''; ?>
        <article class="post-card"><div class="post-card-body"><h2><a href="<?= $url ?>"><?= $title ?></a></h2><p class="post-card-meta"><?= $date ?></p></div></article>
      <?php endforeach; ?>
    </div>
    <?php if ($pagination): ?><nav class="pagination"><?= $pagination ?></nav><?php endif; ?>
  <?php else: ?><p class="empty"><?= __('No posts in this period.') ?></p><?php endif; ?>
</div>
