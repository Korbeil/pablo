<?php
/**
 * @var string                                         $base
 * @var list<array{title: string, pages: list<string>}> $nav
 * @var array<string, array{title: string}>            $pages
 * @var callable                                       $urlOf
 * @var callable                                       $e
 */
$title = 'Page not found';
$description = 'This page does not exist. Search the PABLO docs or start from the documentation home.';
$current = '-';
require __DIR__.'/_header.php';
?>
<div class="docs-layout nav-wide">
  <?php require __DIR__.'/_sidebar.php'; ?>
  <main class="docs-main" id="content">
    <article class="prose">
      <h1>Page not found</h1>
      <p class="lead">This page doesn't exist, or it moved when the docs were reorganised.</p>
      <p><button class="btn btn-primary" type="button" data-search-open>Search the docs</button> or start from the <a href="<?= $urlOf('index') ?>">documentation home</a>.</p>
    </article>
  </main>
  <aside class="docs-toc"></aside>
</div>
<?php require __DIR__.'/_footer.php'; ?>
