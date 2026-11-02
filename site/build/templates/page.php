<?php
/**
 * @var string                                                                                     $base
 * @var string                                                                                     $title
 * @var string                                                                                     $content
 * @var string                                                                                     $current
 * @var string                                                                                     $editUrl
 * @var array{title: string, description: string, section: string, file: string}                  $page
 * @var list<array{level: int, id: string, text: string}>                                          $toc
 * @var list<array{title: string, pages: list<string>}>                                            $nav
 * @var array<string, array{title: string, description: string}>                                  $pages
 * @var ?array{slug: string, title: string}                                                        $prev
 * @var ?array{slug: string, title: string}                                                        $next
 * @var callable                                                                                   $urlOf
 * @var callable                                                                                   $e
 */
$description = $page['description'];
require __DIR__.'/_header.php';
?>
<div class="docs-layout nav-wide">
  <?php require __DIR__.'/_sidebar.php'; ?>

  <main class="docs-main" id="content">
    <?php if ('' !== $page['section']) { ?>
      <p class="tag docs-section"><?= $e($page['section']) ?></p>
    <?php } ?>
    <article class="prose">
      <?= $content ?>
    </article>

    <?php if ('index' === $current) { ?>
      <div class="docs-map">
        <?php foreach ($nav as $section) { ?>
          <section class="docs-map-section">
            <h2><?= $e($section['title']) ?></h2>
            <?php foreach ($section['pages'] as $slug) { ?>
              <a class="docs-card" href="<?= $urlOf($slug) ?>">
                <span class="docs-card-title"><?= $e($pages[$slug]['title']) ?></span>
                <span class="docs-card-desc"><?= $e($pages[$slug]['description']) ?></span>
              </a>
            <?php } ?>
          </section>
        <?php } ?>
      </div>
    <?php } ?>

    <nav class="docs-pager" aria-label="Previous and next pages">
      <?php if (null !== $prev) { ?>
        <a class="pager-prev" href="<?= $urlOf($prev['slug']) ?>"><span>Previous</span><?= $e($prev['title']) ?></a>
      <?php } else { ?><span></span><?php } ?>
      <?php if (null !== $next) { ?>
        <a class="pager-next" href="<?= $urlOf($next['slug']) ?>"><span>Next</span><?= $e($next['title']) ?></a>
      <?php } ?>
    </nav>
    <p class="docs-edit"><a href="<?= $e($editUrl) ?>">Edit this page on GitHub</a></p>
  </main>

  <aside class="docs-toc" aria-label="On this page">
    <?php if ([] !== $toc) { ?>
      <p class="toc-title">On this page</p>
      <ul>
        <?php foreach ($toc as $item) { ?>
          <li class="toc-l<?= $item['level'] ?>"><a href="#<?= $e($item['id']) ?>"><?= $e($item['text']) ?></a></li>
        <?php } ?>
      </ul>
    <?php } ?>
  </aside>
</div>
<?php require __DIR__.'/_footer.php'; ?>
