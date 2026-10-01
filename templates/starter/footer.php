<?php
$year = date('Y');
$siteTitle = $site['title'] ?? 'My Site';
?>
<footer class="site-footer" role="contentinfo">
  <div class="footer-inner">
    <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'footer', 'copyright')): ?>
      <?= theme_zone_render_position($pdo, 'footer', 'copyright') ?>
    <?php else: ?>
      <p class="footer-copy">&copy; <?= $year ?> <?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?>. <?= __('All rights reserved.') ?></p>
    <?php endif; ?>
  </div>
</footer>
