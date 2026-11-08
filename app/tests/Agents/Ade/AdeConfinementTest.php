<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire: everything specific to one ADE (Orca, OpenChamber, headless)
 * lives under src/Agents/Ade/<Ade>/. Shared code — the runtimes included —
 * reaches ADEs only through AdeRegistry, AdeInterface and the
 * AgentLauncherInterface they hand out: it never names a concrete ADE class
 * nor spells an ADE name as a string. Comments are not code: they may name
 * ADEs freely.
 */
final class AdeConfinementTest extends TestCase
{
    /** Concrete ADE namespaces. */
    private const FORBIDDEN_NAMESPACE = '/^\\\\?Pablo\\\\Agents\\\\Ade\\\\(Orca|OpenChamber|Headless)\\\\/';

    /** ADE names and their CLI binaries, as whole string literals. */
    private const FORBIDDEN_STRINGS = ['orca', 'openchamber', 'headless'];

    public function testSharedCodeNamesNoConcreteAde(): void
    {
        $src = \dirname(__DIR__, 3).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));

        $violations = [];
        $scanned = 0;
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), \strlen($src) + 1);
            if ('php' !== $file->getExtension() || str_starts_with($relative, 'Agents/Ade/')) {
                continue;
            }
            ++$scanned;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!\is_array($token)) {
                    continue;
                }
                [$id, $text, $line] = $token;
                if (\in_array($id, [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true) && 1 === preg_match(self::FORBIDDEN_NAMESPACE, $text)) {
                    $violations[] = "{$relative}:{$line}: {$text}";
                }
                if (\T_CONSTANT_ENCAPSED_STRING === $id && \in_array(strtolower(trim($text, '\'"')), self::FORBIDDEN_STRINGS, true)) {
                    $violations[] = "{$relative}:{$line}: {$text}";
                }
            }
        }

        $this->assertGreaterThan(100, $scanned, 'the scan must reach every src/ subdirectory');
        $this->assertSame([], $violations, "ADE-specific code outside src/Agents/Ade:\n".implode("\n", $violations));
    }
}
