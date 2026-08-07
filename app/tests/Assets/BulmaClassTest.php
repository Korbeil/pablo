<?php

declare(strict_types=1);

namespace Pablo\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire for two Bulma classes that must never appear on a dashboard tag.
 *
 *  - `class="tag is-light"` also matches Bulma 1.x's `.tag.is-light.is-light`
 *    rule — a CSS selector may repeat a class and still match a single
 *    occurrence of it — which wins on specificity and points
 *    --bulma-tag-color-l at --bulma-light-light-invert-l. Bulma never defines
 *    that variable, so the colour declaration is invalid at computed-value
 *    time and the chip inherits the page text colour: white text on a
 *    96%-lightness chip under a dark theme.
 *
 *    Combining is-light with a real colour (`is-link is-light`) is fine —
 *    --bulma-link-light-invert-l and friends do exist — and `.button.is-light`
 *    has no self-collision rule. Only a bare `tag is-light` is broken.
 *
 *  - `is-grey` is not a Bulma class at all (zero occurrences in bulma.min.css)
 *    and silently renders an unstyled tag.
 *
 * Use `pablo-chip` / `pablo-chip-muted` from assets/styles/app.css instead.
 */
final class BulmaClassTest extends TestCase
{
    /** @return list<string> */
    private function sources(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];
        foreach ([$root.'/templates', $root.'/src/Dashboard'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                \assert($file instanceof \SplFileInfo);
                if ($file->isFile() && \in_array($file->getExtension(), ['twig', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    public function testNoBareIsLightOnATag(): void
    {
        $offenders = [];
        foreach ($this->sources() as $path) {
            foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $n => $line) {
                // A tag whose only colour modifier is is-light, in markup or in
                // a PHP class-string returned by TaskView/RebaseLogView.
                if (1 === preg_match('/class="[^"]*\btag\b[^"]*"/', $line) && str_contains($line, 'is-light')
                    && 1 !== preg_match('/is-(primary|link|info|success|warning|danger|dark|black|white)\b/', $line)) {
                    $offenders[] = basename($path).':'.($n + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "bare `tag is-light` renders white-on-white in dark mode; use `pablo-chip`:\n".implode("\n", $offenders));
    }

    public function testNoIsGreyAnywhere(): void
    {
        $offenders = [];
        foreach ($this->sources() as $path) {
            foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $n => $line) {
                if (str_contains($line, 'is-grey')) {
                    $offenders[] = basename($path).':'.($n + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "`is-grey` is not a Bulma class; use `pablo-chip-muted`:\n".implode("\n", $offenders));
    }

    /** The replacements must actually be defined, or we swap one bug for another. */
    public function testReplacementChipClassesAreDefined(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        foreach (['.tag.pablo-chip', '.tag.pablo-chip-muted'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
    }

    /** Guards the diagnosis itself: if upstream ever defines it, revisit this. */
    public function testBulmaStillLacksTheLightLightInvertVariable(): void
    {
        $bulma = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/vendor/bulma/css/bulma.min.css');

        $this->assertStringContainsString('.tag.is-light.is-light', $bulma);
        $this->assertStringNotContainsString('--bulma-light-light-invert-l:', $bulma);
    }
}
