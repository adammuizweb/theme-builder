<?php
$siteTitle = $site['title'] ?? 'My Site';
$baseUrl   = rtrim($site['url'] ?? '/', '/');
$homeUrl   = $baseUrl ?: '/';
$searchQuery = $_GET['s'] ?? '';
$colorMode = function_exists('get_theme_color_mode') ? get_theme_color_mode() : 'both';
?>
<header class="site-header">
  <div class="header-inner">
    <a href="<?= htmlspecialchars($homeUrl) ?>" class="brand"><span class="site-title"><?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?></span></a>
    <button id="hamburger" class="hamburger" aria-label="<?= __('Menu') ?>"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    <nav id="navbar" class="navbar">
      <div class="mobile-head">
        <span class="mobile-title"><?= __('Menu') ?></span>
        <button id="closeMenu" class="close-btn" aria-label="<?= __('Close Menu') ?>"><svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      </div>
      <?php if (function_exists('menu_render')) { echo menu_render($pdo, 'primary', ['menu_class' => 'menu', 'submenu_class' => 'submenu', 'depth' => 0]); } ?>
      <div class="controls">
        <?php if ($colorMode === 'both'): ?>
        <select id="themeSelect" class="ctrl-item"><option value="light"><?= __('Light') ?></option><option value="dark"><?= __('Dark') ?></option></select>
        <?php endif; ?>
        <form method="get" action="<?= htmlspecialchars($homeUrl) ?>"><input type="search" name="s" class="ctrl-item" placeholder="<?= __('Search...') ?>" value="<?= htmlspecialchars($searchQuery) ?>"></form>
      </div>
    </nav>
  </div>
</header>
