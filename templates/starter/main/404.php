<?php $homeUrl = rtrim($site['url'] ?? '/', '/'); ?>
<div class="empty-state">
  <h1><?= __('Page Not Found') ?></h1>
  <p><?= __('Sorry, the page you are looking for does not exist or has been moved.') ?></p>
  <a href="<?= htmlspecialchars($homeUrl) ?>" class="btn-back"><?= __('Back to Home') ?></a>
</div>
