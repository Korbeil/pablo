<?php

declare(strict_types=1);

namespace Pablo\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire: a collaborator that can reach the outside world (a process
 * runner, a usage harvester that shells out, an agent launcher) must be a
 * required constructor argument, never defaulted to a real implementation.
 * A default would let a test that forgets one argument silently get a real
 * ProcessRunner — and real CLIs — defeating the FakeAgents/fakes rule.
 *
 * Side-effect-free defaults (Time, NullAnalytics, the env-resolving
 * registries) stay allowed.
 */
final class RequiredCollaboratorsTest extends TestCase
{
    private const FORBIDDEN = [
        // `Type $x = new …` / `Type $x = null` default on an outside-world collaborator (a `= null` builds nothing)
        '/\b(ProcessRunnerInterface|UsageHarvesterInterface|AgentLauncherInterface)\s+\$\w+\s*=\s*new\b/',
        // `$x ?? new ProcessRunner()` and friends in a constructor body
        '/\?\?=?\s*new\s+(ProcessRunner|AgentUsage|OrcaLauncher|HeadlessLauncher|OpenChamberLauncher)\s*\(/',
        // a real runner/harvester as any parameter default
        '/=\s*new\s+(ProcessRunner|AgentUsage)\s*\(/',
    ];

    public function testOutsideWorldCollaboratorsAreNeverDefaulted(): void
    {
        $src = \dirname(__DIR__).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));

        $violations = [];
        $scanned = 0;
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }
            ++$scanned;
            $relative = substr($file->getPathname(), \strlen($src) + 1);
            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                foreach (self::FORBIDDEN as $pattern) {
                    if (1 === preg_match($pattern, $line)) {
                        $violations[] = $relative.':'.($i + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $scanned, 'the scan must reach every src/ subdirectory');
        $this->assertSame([], $violations, "defaulted outside-world collaborators:\n".implode("\n", $violations));
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('requiredRunners')]
    public function testTheRunnerOrHarvesterIsARequiredParameter(string $class, string $param): void
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $this->assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === $param) {
                $this->assertFalse($parameter->isOptional(), "{$class}::\${$param} must be required");
                $this->assertFalse($parameter->allowsNull(), "{$class}::\${$param} must not be nullable");

                return;
            }
        }
        $this->fail("{$class} has no \${$param} parameter");
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function requiredRunners(): iterable
    {
        yield 'AgentUsage' => [\Pablo\Analytics\AgentUsage::class, 'runner'];
        yield 'AgentLauncherFactory' => [\Pablo\Agents\AgentLauncherFactory::class, 'runner'];
        yield 'AbstractAgentLauncher' => [\Pablo\Agents\Ade\AbstractAgentLauncher::class, 'runner'];
        yield 'OrcaLauncher' => [\Pablo\Agents\Ade\Orca\OrcaLauncher::class, 'runner'];
        yield 'HeadlessLauncher' => [\Pablo\Agents\Ade\Headless\HeadlessLauncher::class, 'runner'];
        yield 'OpenChamberLauncher' => [\Pablo\Agents\Ade\OpenChamber\OpenChamberLauncher::class, 'runner'];
        yield 'Poller' => [\Pablo\Poller\Poller::class, 'usage'];
        yield 'WatchAgentCommand' => [\Pablo\Command\Internal\WatchAgentCommand::class, 'usage'];
    }
}
