<?php
/**
 * @var string                                         $base
 * @var string                                         $current
 * @var list<array{title: string, pages: list<string>}> $nav
 * @var array<string, array{title: string}>            $pages
 * @var callable                                       $urlOf
 * @var callable                                       $e
 */
?>
<aside class="docs-sidebar" id="docs-sidebar" aria-label="Documentation">
  <a class="sidebar-home<?= 'index' === $current ? ' is-current' : '' ?>" href="<?= $urlOf('index') ?>"<?= 'index' === $current ? ' aria-current="page"' : '' ?>>Documentation home</a>
  <?php foreach ($nav as $section) { ?>
    <p class="sidebar-section"><?= $e($section['title']) ?></p>
    <ul>
      <?php foreach ($section['pages'] as $slug) { ?>
        <li><a href="<?= $urlOf($slug) ?>"<?= $slug === $current ? ' class="is-current" aria-current="page"' : '' ?>><?= $e($pages[$slug]['title']) ?></a></li>
      <?php } ?>
    </ul>
  <?php } ?>
</aside>
