<?php

declare(strict_types=1);

namespace Pablo\Site;

use Dom\Element;
use Dom\HTMLDocument;
use Highlight\Highlighter;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the website: the static files of site/ plus one documentation page
 * per site/content/<slug>.html fragment, wrapped in the docs layout.
 *
 * A content fragment is plain HTML starting with its <h1> (the page title)
 * and a first paragraph (its summary, reused for the meta description, the
 * docs home cards and search). The builder then:
 *
 *  - gives every <h2>/<h3> a stable id and a "#" anchor, and lists them in
 *    the page's "On this page" column;
 *  - resolves `page:<slug>[#anchor]` links (validated: a typo fails the
 *    build), `repo:<path>` links (GitHub) and `asset:<path>` links;
 *  - highlights <pre><code class="language-…"> blocks at build time;
 *  - writes search-index.json: one entry per section, for search.js.
 */
final class SiteBuilder
{
    private const REPO_URL = 'https://github.com/Korbeil/pablo';
    private const HIGHLIGHT_ALIASES = ['sh' => 'bash', 'shell' => 'bash', 'console' => 'bash', 'yml' => 'yaml'];

    /** @var array<string, array{slug: string, title: string, description: string, section: string}> */
    private array $pages = [];

    /** @var list<array{title: string, pages: list<string>}> */
    private array $nav = [];

    /** @var array<string, HTMLDocument> */
    private array $documents = [];

    /** @var array<string, list<string>> heading ids per page, to validate `page:x#id` links */
    private array $anchors = [];

    /** @var list<array{p: string, s: string, h: string, u: string, t: string}> */
    private array $index = [];

    private readonly Highlighter $highlighter;

    public function __construct(
        private readonly string $contentDir,
        private readonly string $siteDir,
        private readonly string $outDir,
        private readonly string $base,
    ) {
        $this->highlighter = new Highlighter();
    }

    public function build(): void
    {
        $this->loadNav();
        $this->resetOutput();
        $this->copyDir($this->siteDir, $this->outDir, ['build', 'content']);

        // Two passes: every heading id must exist before links are checked.
        $tocs = [];
        foreach (array_keys($this->pages) as $slug) {
            $tocs[$slug] = $this->prepare($slug);
        }
        foreach (array_keys($this->pages) as $slug) {
            $body = $this->documents[$slug]->body;
            \assert(null !== $body);
            $this->resolveLinks($slug, $body);
            $this->indexPage($slug, $body);
            $this->writePage($slug, $body->innerHTML, $tocs[$slug]);
        }

        $this->writeFile('404.html', $this->render('404.php', [
            'base' => $this->base,
            'nav' => $this->nav,
            'pages' => $this->pages,
            'urlOf' => $this->pageUrl(...),
        ]));
        $this->writeFile('search-index.json', json_encode($this->index, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------- loading

    private function loadNav(): void
    {
        /** @var list<array{section: string, pages: list<string>}> $nav */
        $nav = Yaml::parseFile($this->contentDir.'/nav.yaml');

        // The docs home (content/index.html) has no sidebar section.
        $this->addPage('index', '');
        foreach ($nav as $section) {
            $this->nav[] = ['title' => $section['section'], 'pages' => $section['pages']];
            foreach ($section['pages'] as $slug) {
                $this->addPage($slug, $section['section']);
            }
        }

        foreach (glob($this->contentDir.'/*.html') ?: [] as $file) {
            if (!isset($this->pages[basename($file, '.html')])) {
                throw new \RuntimeException('site/content/'.basename($file).' is not listed in site/content/nav.yaml');
            }
        }
    }

    private function addPage(string $slug, string $section): void
    {
        $file = $this->contentDir.'/'.$slug.'.html';
        if (!is_file($file)) {
            throw new \RuntimeException("site/content/nav.yaml lists {$slug}, but site/content/{$slug}.html does not exist");
        }

        $document = HTMLDocument::createFromString(
            '<!doctype html><html><head><meta charset="utf-8"></head><body>'.file_get_contents($file).'</body></html>',
            \LIBXML_NOERROR,
        );
        $h1 = $document->querySelector('body > h1');
        $lead = $document->querySelector('body > p');
        if (null === $h1 || null === $lead) {
            throw new \RuntimeException("site/content/{$slug}.html must start with an <h1> and a first <p>");
        }

        $this->documents[$slug] = $document;
        $this->pages[$slug] = [
            'slug' => $slug,
            'title' => $this->text($h1),
            'description' => $this->text($lead),
            'section' => $section,
        ];
    }

    // ----------------------------------------------------------- transforms

    /** @return list<array{level: int, id: string, text: string}> */
    private function prepare(string $slug): array
    {
        $document = $this->documents[$slug];
        $toc = [];
        $used = [];

        foreach ($document->querySelectorAll('h2, h3') as $heading) {
            $text = $this->text($heading);
            $id = $heading->getAttribute('id') ?? '';
            if ('' === $id) {
                $id = $base = $this->slugify($text);
                for ($i = 2; isset($used[$id]); ++$i) {
                    $id = $base.'-'.$i;
                }
                $heading->setAttribute('id', $id);
            }
            if (isset($used[$id])) {
                throw new \RuntimeException("site/content/{$slug}.html: duplicate heading id \"{$id}\"");
            }
            $used[$id] = true;

            $anchor = $document->createElement('a');
            $anchor->setAttribute('class', 'anchor');
            $anchor->setAttribute('href', '#'.$id);
            $anchor->setAttribute('aria-label', 'Link to this section');
            $anchor->textContent = '#';
            $heading->append($anchor);

            $toc[] = ['level' => 'H2' === $heading->tagName ? 2 : 3, 'id' => $id, 'text' => $text];
        }
        $this->anchors[$slug] = array_keys($used);

        foreach ($document->querySelectorAll('pre > code') as $code) {
            $this->highlight($document, $code);
        }

        // Wide tables scroll sideways inside their own box; on phones each row
        // stacks into a card, every cell labelled by its column header.
        foreach ($document->querySelectorAll('table') as $table) {
            $this->labelCells($table);
            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'table-wrap');
            $table->replaceWith($wrapper);
            $wrapper->append($table);
        }

        return $toc;
    }

    /**
     * Copies each column header onto its body cells as data-label, so the
     * stacked mobile layout can name them. A cell spanning several columns
     * gets their headers joined ("Linux / macOS").
     */
    private function labelCells(Element $table): void
    {
        $headers = [];
        foreach ($table->querySelectorAll('thead th') as $th) {
            $headers[] = $this->text($th);
        }
        if ([] === $headers) {
            return;
        }

        foreach ($table->querySelectorAll('tbody tr') as $row) {
            $column = 0;
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof Element) {
                    continue;
                }
                $span = max(1, (int) ($cell->getAttribute('colspan') ?? '1'));
                $label = implode(' / ', array_filter(\array_slice($headers, $column, $span), static fn (string $h): bool => '' !== $h));
                if ('TD' === $cell->tagName && '' !== $label) {
                    $cell->setAttribute('data-label', $label);
                }
                $column += $span;
            }
        }
    }

    private function highlight(HTMLDocument $document, Element $code): void
    {
        $pre = $code->parentElement;
        \assert(null !== $pre);
        preg_match('/language-([\w-]+)/', $code->getAttribute('class') ?? '', $m);
        $lang = self::HIGHLIGHT_ALIASES[$m[1] ?? ''] ?? ($m[1] ?? '');

        // Content is authored indented inside the HTML; strip the common
        // leading newline so blocks don't start with an empty line.
        $source = rtrim(preg_replace('/\A\n/', '', $code->textContent ?? '') ?? '', "\n ");
        $code->textContent = $source;
        if ('' !== $lang && 'text' !== $lang) {
            try {
                $code->innerHTML = $this->highlighter->highlight($lang, $source)->value;
            } catch (\DomainException) {
                // Unknown language: keep the plain text.
            }
        }
        $code->setAttribute('class', 'hljs language-'.('' !== $lang ? $lang : 'text'));

        $wrapper = $document->createElement('div');
        $wrapper->setAttribute('class', 'code');
        if ('' !== $lang && 'text' !== $lang) {
            $label = $document->createElement('span');
            $label->setAttribute('class', 'code-lang');
            $label->textContent = $lang;
            $wrapper->append($label);
        }
        $pre->replaceWith($wrapper);
        $wrapper->append($pre);
    }

    private function resolveLinks(string $slug, Element $body): void
    {
        foreach ($body->querySelectorAll('a[href]') as $link) {
            $href = (string) $link->getAttribute('href');

            if (str_starts_with($href, 'page:')) {
                [$target, $fragment] = array_pad(explode('#', substr($href, 5), 2), 2, null);
                $target = '' === $target ? 'index' : (string) $target;
                if (!isset($this->pages[$target])) {
                    throw new \RuntimeException("site/content/{$slug}.html links to {$href}: no such page");
                }
                if (null !== $fragment && !\in_array($fragment, $this->anchors[$target], true)) {
                    throw new \RuntimeException("site/content/{$slug}.html links to {$href}: no such section (have: ".implode(', ', $this->anchors[$target]).')');
                }
                $link->setAttribute('href', $this->pageUrl($target).(null !== $fragment ? '#'.$fragment : ''));
            } elseif (str_starts_with($href, 'repo:')) {
                $path = substr($href, 5);
                $kind = str_ends_with($path, '/') ? 'tree' : 'blob';
                $link->setAttribute('href', self::REPO_URL.'/'.$kind.'/main/'.rtrim($path, '/'));
            } elseif (str_starts_with($href, 'asset:')) {
                $link->setAttribute('href', $this->base.substr($href, 6));
            } elseif ('#' === ($href[0] ?? '') && !\in_array(substr($href, 1), $this->anchors[$slug], true) && '#' !== $href) {
                throw new \RuntimeException("site/content/{$slug}.html links to {$href}: no such section");
            }
        }

        foreach ($body->querySelectorAll('img[src^="asset:"]') as $img) {
            $img->setAttribute('src', $this->base.substr((string) $img->getAttribute('src'), 6));
        }
    }

    // --------------------------------------------------------------- search

    /**
     * One entry per section — the page intro, then every <h2>/<h3> with the
     * text up to the next heading. Code is included, so a search for a
     * command such as `task:start` lands where it is documented.
     */
    private function indexPage(string $slug, Element $body): void
    {
        $page = $this->pages[$slug];

        $sections = [];
        $current = ['heading' => '', 'id' => '', 'text' => []];
        foreach ($body->childNodes as $child) {
            if (!$child instanceof Element || 'H1' === $child->tagName) {
                continue;
            }
            if (\in_array($child->tagName, ['H2', 'H3'], true)) {
                $sections[] = $current;
                $current = ['heading' => $this->text($child), 'id' => (string) $child->getAttribute('id'), 'text' => []];
                continue;
            }
            $current['text'][] = $this->text($child);
        }
        $sections[] = $current;

        foreach ($sections as $section) {
            $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $section['text'])) ?? '');
            if ('' === $text && '' === $section['heading']) {
                continue;
            }
            $this->index[] = [
                'p' => 'index' === $slug ? 'Documentation' : $page['title'],
                's' => $page['section'],
                'h' => $section['heading'],
                'u' => $this->pageUrl($slug).('' !== $section['id'] ? '#'.$section['id'] : ''),
                't' => mb_substr($text, 0, 2500),
            ];
        }
    }

    // --------------------------------------------------------------- output

    /** @param list<array{level: int, id: string, text: string}> $toc */
    private function writePage(string $slug, string $content, array $toc): void
    {
        [$prev, $next] = $this->neighbours($slug);

        $html = $this->render('page.php', [
            'base' => $this->base,
            'page' => $this->pages[$slug],
            'title' => 'index' === $slug ? 'Documentation' : $this->pages[$slug]['title'],
            'content' => $content,
            'toc' => $toc,
            'nav' => $this->nav,
            'pages' => $this->pages,
            'current' => $slug,
            'prev' => $prev,
            'next' => $next,
            'urlOf' => $this->pageUrl(...),
            'editUrl' => self::REPO_URL.'/edit/main/site/content/'.$slug.'.html',
        ]);

        $this->writeFile(('index' === $slug ? 'docs' : 'docs/'.$slug).'/index.html', $html);
    }

    /** @return array{0: ?array{slug: string, title: string}, 1: ?array{slug: string, title: string}} */
    private function neighbours(string $slug): array
    {
        $order = ['index'];
        foreach ($this->nav as $section) {
            array_push($order, ...$section['pages']);
        }
        $i = (int) array_search($slug, $order, true);
        $at = fn (int $j): ?array => isset($order[$j]) ? ['slug' => $order[$j], 'title' => 'index' === $order[$j] ? 'Documentation home' : $this->pages[$order[$j]]['title']] : null;

        return [$at($i - 1), $at($i + 1)];
    }

    private function pageUrl(string $slug): string
    {
        return $this->base.('index' === $slug ? 'docs/' : 'docs/'.$slug.'/');
    }

    /** @param array<string, mixed> $vars */
    private function render(string $template, array $vars): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        extract($vars);
        ob_start();
        require __DIR__.'/../templates/'.$template;

        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------- utils

    private function text(Element $element): string
    {
        $clone = $element->cloneNode(true);
        \assert($clone instanceof Element);
        foreach ($clone->querySelectorAll('.anchor') as $anchor) {
            $anchor->remove();
        }

        return trim(preg_replace('/\s+/u', ' ', $clone->textContent ?? '') ?? '');
    }

    private function slugify(string $text): string
    {
        $ascii = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '', '-') ?: 'section';
    }

    private function resetOutput(): void
    {
        if (is_dir($this->outDir)) {
            $this->removeDir($this->outDir);
        }
        mkdir($this->outDir, 0o777, true);
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->outDir.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    /** @param list<string> $skip top-level entries not to copy */
    private function copyDir(string $from, string $to, array $skip = []): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0o777, true);
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || \in_array($entry, $skip, true)) {
                continue;
            }
            $source = $from.'/'.$entry;
            is_dir($source) ? $this->copyDir($source, $to.'/'.$entry) : copy($source, $to.'/'.$entry);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
