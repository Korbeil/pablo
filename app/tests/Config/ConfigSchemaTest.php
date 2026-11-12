<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\ConfigIssue;
use Pablo\Config\ConfigSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ConfigSchemaTest extends TestCase
{
    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function texts(array $data, string $scope = ConfigSchema::PROJECT, bool $forLoad = false): array
    {
        return array_map(static fn (ConfigIssue $i): string => $i->severity.' '.$i->text(), ConfigSchema::check($data, $scope, '/x/p.yaml', 'p.yaml', $forLoad));
    }

    public function testTheShippedExamplesHaveNoIssue(): void
    {
        $root = \dirname(__DIR__, 3).'/examples';
        $this->assertSame([], self::texts((array) Yaml::parseFile($root.'/config.yaml'), ConfigSchema::GLOBAL));
        $this->assertSame([], self::texts((array) Yaml::parseFile($root.'/acme-pim.yaml')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typos(): iterable
    {
        yield 'ci.ignore_check' => ["ci:\n  ignore_check: [approval]\n", 'warning p.yaml: ci.ignore_check is not a known key, ignored; did you mean `ci.ignore_checks`?'];
        yield 'testing.failure_signals' => ["testing:\n  failure_signals: qa\n", 'warning p.yaml: testing.failure_signals is not a known key, ignored; did you mean `testing.failure_signal`?'];
        yield 'sync.intervall_minutes' => ["sync:\n  intervall_minutes: 5\n", 'warning p.yaml: sync.intervall_minutes is not a known key, ignored; did you mean `sync.interval_minutes`?'];
        yield 'a top-level key' => ["startup_scrpit: x.sh\n", 'warning p.yaml: startup_scrpit is not a known key, ignored; did you mean `startup_script`?'];
        yield 'nothing close' => ["frobnicate: 1\n", 'warning p.yaml: frobnicate is not a known key, ignored'];
    }

    #[DataProvider('typos')]
    public function testAnUnknownKeyIsAWarningWithASuggestion(string $yaml, string $expected): void
    {
        $this->assertSame([$expected], self::texts((array) Yaml::parse($yaml)));
    }

    public function testAKeyOfTheOtherFileSaysWhereItBelongs(): void
    {
        $this->assertSame(
            ['warning p.yaml: agent_backend is a global-only key, ignored in a project file: set it in ~/.pablo/config.yaml'],
            self::texts(['agent_backend' => 'headless']),
        );
        $this->assertSame(
            ['warning p.yaml: testing.failure_signal is a project-only key, ignored in config.yaml: set it in a project file'],
            self::texts(['testing' => ['failure_signal' => 'qa']], ConfigSchema::GLOBAL),
        );
    }

    public function testLoadingIgnoresUnknownKeys(): void
    {
        $this->assertSame([], self::texts(['ci' => ['ignore_check' => ['x']], 'future_key' => true], forLoad: true));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'interval zero' => [['sync' => ['interval_minutes' => 0]], 'sync.interval_minutes must be a whole number of minutes, at least 1, got 0'];
        yield 'interval text' => [['state_polling' => ['interval_minutes' => 'abc']], "state_polling.interval_minutes must be a whole number of minutes, at least 1, got 'abc'"];
        yield 'interval negative' => [['main_update' => ['interval_minutes' => -5]], 'main_update.interval_minutes must be a whole number of minutes, at least 1, got -5'];
        yield 'strategy' => [['sync' => ['strategy' => 'squash']], "sync.strategy must be one of: rebase, merge, got 'squash'"];
        yield 'boolean' => [['sync' => ['auto_apply' => 'yes']], "sync.auto_apply must be true or false, got 'yes'"];
        yield 'list' => [['ci' => ['ignore_checks' => 'approval']], "ci.ignore_checks must be a list of check names (e.g. [a, b]), got 'approval'"];
        yield 'section' => [['sync' => 'rebase'], "sync must be a mapping, got 'rebase'"];
        yield 'enum' => [['issue_tracker' => ['provider' => 'gitlab']], "issue_tracker.provider must be one of: github, jira, linear, got 'gitlab'"];
        yield 'hook state' => [['hooks' => ['states' => ['red']]], "hooks.states has unknown state 'red'"];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidValues')]
    public function testAnInvalidValueIsAnError(array $data, string $message): void
    {
        $issue = ConfigSchema::loadError($data, ConfigSchema::PROJECT, '/x/p.yaml', 'p.yaml');
        $this->assertNotNull($issue);
        $this->assertTrue($issue->isError());
        $this->assertStringStartsWith('p.yaml: '.$message, $issue->text());
    }

    public function testValidValuesPass(): void
    {
        $this->assertSame([], self::texts([
            'sync' => ['strategy' => 'merge', 'auto_apply' => true, 'interval_minutes' => '15'],
            'state_polling' => ['interval_minutes' => 1],
            'review' => ['bot_whitelist' => []],
            'ci' => ['ignore_checks' => ['approval', 'deploy']],
            'issue_tracker' => ['provider' => 'jira', 'project_key' => 123],
            'hooks' => ['command' => null, 'states' => ['approved'], 'agent_finished' => false],
            'branch_prefix' => null,
        ]));
    }

    public function testGlobalOnlyKeysAreCheckedByValidateButDeferredWhenLoading(): void
    {
        $data = ['dashboard_port' => 99999, 'prune' => ['keep_days' => 0], 'shell_completion' => 'maybe'];

        $this->assertNull(ConfigSchema::loadError($data, ConfigSchema::GLOBAL, '/x/config.yaml', 'config.yaml'));
        $this->assertSame([
            'error config.yaml: dashboard_port must be a port number (1-65535), got 99999',
            'error config.yaml: prune.keep_days must be a whole number of days, at least 1, got 0',
            "error config.yaml: shell_completion must be true or false, got 'maybe'",
        ], array_map(static fn (ConfigIssue $i): string => $i->severity.' '.$i->text(), ConfigSchema::check($data, ConfigSchema::GLOBAL, '/x/config.yaml', 'config.yaml')));
    }

    public function testDefaultModelByTypeOnlyTakesProjectTypes(): void
    {
        $issue = ConfigSchema::loadError(['default_model_by_type' => ['hobby' => 'x']], ConfigSchema::GLOBAL, '/x/config.yaml', 'config.yaml');
        $this->assertNotNull($issue);
        $this->assertSame("config.yaml: default_model_by_type has unknown type 'hobby' (expected one of: work, open-source, personal)", $issue->text());
    }

    public function testClosest(): void
    {
        $this->assertSame('interval_minutes', ConfigSchema::closest('intervall_minutes', ['strategy', 'interval_minutes']));
        $this->assertSame('interval_minutes', ConfigSchema::closest('interval', ['strategy', 'interval_minutes']));
        $this->assertNull(ConfigSchema::closest('colour', ['strategy', 'interval_minutes']));
        $this->assertNull(ConfigSchema::closest('x', []));
    }
}
