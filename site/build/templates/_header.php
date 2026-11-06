<?php
/**
 * @var string   $base
 * @var string   $title
 * @var string   $description
 * @var callable $e
 */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title) ?> · PABLO</title>
  <meta name="description" content="<?= $e($description) ?>">
  <meta property="og:title" content="<?= $e($title) ?> · PABLO">
  <meta property="og:description" content="<?= $e($description) ?>">
  <meta property="og:image" content="https://getpablo.dev/assets/og.png">
  <meta property="og:type" content="article">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="theme-color" content="#2b2b2a">
  <link rel="icon" href="<?= $base ?>assets/logo-mark.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>style.css">
  <link rel="stylesheet" href="<?= $base ?>docs.css">
</head>
<body class="docs-body">

<header class="nav">
  <div class="nav-inner nav-wide">
    <button class="nav-menu" type="button" aria-label="Open the docs menu" aria-controls="docs-sidebar" aria-expanded="false" data-sidebar-toggle>
      <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <a class="brand" href="<?= $base ?>" aria-label="PABLO home"><img src="<?= $base ?>assets/logo-light.svg" alt="PABLO" height="34"></a>
    <nav class="nav-links" aria-label="Site">
      <button class="nav-search" type="button" data-search-open>
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15.5 15.5L20 20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span>Search docs</span><kbd>/</kbd>
      </button>
      <a href="<?= $base ?>docs/" class="nav-docs is-current">Docs</a>
      <a href="https://github.com/Korbeil/pablo" class="nav-gh" aria-label="PABLO on GitHub">
        <svg viewBox="0 0 16 16" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
      </a>
    </nav>
  </div>
</header>
