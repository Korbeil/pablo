<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire: everything specific to one agent runtime lives under
 * src/Agents/Runtime/<Runtime>/. Shared code reaches runtimes only through
 * AgentRuntimeRegistry and the interfaces — it never names a concrete
 * runtime class nor spells a runtime or CLI name as a string. Comments are
 * not code: they may name runtimes freely.
 */
final class RuntimeConfinementTest extends TestCase
{
    /** Concrete runtime namespaces. */
    private const FORBIDDEN_NAMESPACE = '/^\\\\?Pablo\\\\Agents\\\\Runtime\\\\(OpenCode|ClaudeCode|Copilot)\\\\/';

    /** Runtime names and their CLI binaries, as whole string literals. */
    private const FORBIDDEN_STRINGS = ['opencode', 'claude-code', 'claude', 'copilot'];

    public function testSharedCodeNamesNoConcreteRuntime(): void
    {
        $src = \dirname(__DIR__, 3).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));

        $violations = [];
        $scanned = 0;
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), \strlen($src) + 1);
            if ('php' !== $file->getExtension() || str_starts_with($relative, 'Agents/Runtime/')) {
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
        $this->assertSame([], $violations, "runtime-specific code outside src/Agents/Runtime:\n".implode("\n", $violations));
    }
}
