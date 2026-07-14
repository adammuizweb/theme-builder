<?php
$siteTitle = $site['title'] ?? 'My Site';
$siteDesc  = $site['description'] ?? '';
$baseUrl   = rtrim($site['url'] ?? '/', '/');
$homeUrl   = $baseUrl ?: '/';
?>
<section class="hero">
  <div class="hero-inner">
    <h1><?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($siteDesc !== ''): ?><p><?= htmlspecialchars($siteDesc, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  </div>
</section>
<section class="post-list">
  <h2><?= __('Latest Posts') ?></h2>
  <?php if (!empty($posts) && is_array($posts)): ?>
    <div class="post-grid">
      <?php foreach ($posts as $p): ?>
        <article class="post-card">
          <a href="<?= htmlspecialchars($p['url'] ?? '/' . ($p['slug'] ?? '') . '/', ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!empty($p['thumbnail'])): ?><img src="<?= htmlspecialchars($p['thumbnail'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" loading="lazy"><?php endif; ?>
            <h3><?= htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars(mb_strimwidth(safe_strip_tags(html_entity_decode((string)($p['content'] ?? ''), ENT_QUOTES, 'UTF-8')), 0, 160, '…'), ENT_QUOTES, 'UTF-8') ?></p>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="empty"><?= __('No posts yet.') ?></p>
  <?php endif; ?>
</section>
