<?php
$year = date('Y');
$siteTitle = $site['title'] ?? 'My Site';
?>
<footer class="site-footer" role="contentinfo">
  <div class="footer-inner">
    <p class="footer-copy">&copy; <?= $year ?> <?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?>. <?= __('All rights reserved.') ?></p>
  </div>
</footer>
