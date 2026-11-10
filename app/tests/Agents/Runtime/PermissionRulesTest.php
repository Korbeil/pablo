<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\Runtime\PermissionRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermissionRulesTest extends TestCase
{
    private const KNOWN = ['read', 'edit', 'shell'];

    public function testSplitsActionsAndShellPatternsDeduplicatedInRuleOrder(): void
    {
        $rules = PermissionRules::fromFrontmatter(['permissions' => [
            ['action' => 'shell', 'effect' => 'allow'],
            ['action' => 'read', 'resource' => '*', 'effect' => 'allow'],
            ['action' => 'shell', 'resource' => 'git push*', 'effect' => 'deny'],
            ['action' => 'shell', 'resource' => 'git status*', 'effect' => 'allow'],
            ['action' => 'edit', 'effect' => 'deny'],
            ['action' => 'read', 'effect' => 'allow'],
            ['action' => 'shell', 'resource' => 'git push*', 'effect' => 'deny'],
        ]], 'a', self::KNOWN, 'Test');

        $this->assertSame(['shell', 'read'], $rules->allowedActions);
        $this->assertSame(['edit'], $rules->deniedActions);
        $this->assertSame(['git status*'], $rules->allowedShell);
        $this->assertSame(['git push*'], $rules->deniedShell);
    }

    public function testADenyWinsWhateverTheRuleOrder(): void
    {
        $rules = PermissionRules::fromFrontmatter(['permissions' => [
            ['action' => 'edit', 'effect' => 'deny'],
            ['action' => 'edit', 'effect' => 'allow'],
            ['action' => 'read', 'effect' => 'allow'],
        ]], 'a', self::KNOWN, 'Test');

        $this->assertSame(['read'], $rules->offeredActions());
    }

    public function testNoPermissionsIsEmpty(): void
    {
        $rules = PermissionRules::fromFrontmatter([], 'a', self::KNOWN, 'Test');

        $this->assertSame([], $rules->offeredActions());
        $this->assertSame([], $rules->deniedShell);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalid(): iterable
    {
        yield 'not a list' => ['read', 'a: permissions must be a list'];
        yield 'not a mapping' => [['read'], 'a: permission #0 is not a mapping'];
        yield 'unknown action' => [[['action' => 'fly', 'effect' => 'allow']], "a: permission action 'fly' has no Test equivalent"];
        yield 'bad effect' => [[['action' => 'read', 'effect' => 'ask']], "a: permission effect 'ask' is not allow/deny"];
        yield 'pattern on non-shell' => [[['action' => 'read', 'resource' => 'src/*', 'effect' => 'allow']], "a: only shell permissions may target a pattern ('read' → 'src/*')"];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidRules(mixed $permissions, string $message): void
    {
        $this->expectException(AgentTemplateError::class);
        $this->expectExceptionMessage($message);

        PermissionRules::fromFrontmatter(['permissions' => $permissions], 'a', self::KNOWN, 'Test');
    }
}
