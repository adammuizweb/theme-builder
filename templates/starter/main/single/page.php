<?php
if (!isset($post)) { if (isset($page) && (is_array($page) || is_object($page))) $post = (array)$page; }
if (!isset($post) || !is_array($post)) return;
$authorName = !empty($post['author_name']) ? $post['author_name'] : (!empty($post['author_username']) ? $post['author_username'] : (!empty($post['author_email']) ? $post['author_email'] : __('Author')));
$authorUrl = !empty($post['author_username']) ? '/author/' . rawurlencode($post['author_username']) . '/' : null;
$createdTs = !empty($post['created_at']) ? @strtotime($post['created_at']) : null;
$displayCreated = $createdTs ? date('d M Y', $createdTs) : '';
?>
<article class="page-single">
  <header class="post-header">
    <h1 class="post-title"><?= htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="post-meta"><span><?= __('By') ?> <?php if ($authorUrl): ?><a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></a><?php else: ?><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span></div>
  </header>
  <div class="post-body"><?= apply_filters('post_content', (string)($post['content'] ?? ''), $post ?? []) ?></div>
  <footer class="post-footer"><span><?= __('Published:') ?> <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?><?php if ($displayCreated): ?> &mdash; <?= htmlspecialchars($displayCreated, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span></footer>
</article>
