<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\Config;
use Pablo\Config\ConfigIssue;
use Pablo\Config\ConfigValidator;
use Pablo\Config\GlobalConfig;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ConfigValidatorTest extends TestCase
{
    use UsesGlobalConfig;

    public const DEFAULTS = <<<'YAML'
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
default_model: opencode-go/glm-5.3-flash
pr_description_locale: en

YAML;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-validate-'.uniqid();
        mkdir($this->tmp.'/projects', 0o777, true);
        mkdir($this->tmp.'/repo/.git', 0o777, true);
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/projects');
        $this->writeGlobalConfig(self::DEFAULTS);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        (new Filesystem())->remove($this->tmp);
    }

    public static function project(string $name, string $repo, string $extra = ''): string
    {
        return <<<YAML
            name: {$name}
            type: work
            repo:
              path: {$repo}
              primary_branch: main
            issue_tracker:
              provider: github
              identity: octocat
              project_key: WK

            YAML.$extra;
    }

    private function write(string $file, string $yaml): string
    {
        $path = $this->tmp.'/projects/'.$file;
        file_put_contents($path, $yaml);

        return $path;
    }

    /** @return list<string> */
    private function texts(): array
    {
        $report = (new ConfigValidator(new GlobalConfig(), new Config(new GlobalConfig())))->validate();

        return array_map(static fn (ConfigIssue $i): string => $i->severity.' '.$i->text(), $report->issues);
    }

    public function testAValidSetupHasNoIssue(): void
    {
        $this->write('wk.yaml', self::project('wk', $this->tmp.'/repo'));
        $report = (new ConfigValidator(new GlobalConfig(), new Config(new GlobalConfig())))->validate();

        $this->assertSame([], $report->issues);
        $this->assertSame([$this->configPath, $this->tmp.'/projects/wk.yaml'], $report->files);
        $this->assertNull($report->globalError);
    }

    public function testEveryProblemOfEveryFileIsReported(): void
    {
        $this->writeGlobalConfig(self::DEFAULTS."dashbord_port: 8400\n");
        $this->write('a.yaml', self::project('a', $this->tmp.'/repo', "ci:\n  ignore_check: [x]\nsync:\n  interval_minutes: 0\n"));
        $this->write('b.yaml', "name: [broken\n");

        $texts = $this->texts();

        $this->assertContains('warning config.yaml: dashbord_port is not a known key, ignored; did you mean `dashboard_port`?', $texts);
        $this->assertContains('warning a.yaml: ci.ignore_check is not a known key, ignored; did you mean `ci.ignore_checks`?', $texts);
        $this->assertContains('error a.yaml: sync.interval_minutes must be a whole number of minutes, at least 1, got 0', $texts);
        $this->assertCount(4, $texts);
        $this->assertStringStartsWith('error b.yaml: invalid YAML', $texts[3]);
    }

    public function testWhatLoadingRefusesIsAnError(): void
    {
        $this->write('a.yaml', "name: a\ntype: work\n");

        $this->assertSame(["error a.yaml: missing required key 'repo.path'"], $this->texts());
    }

    public function testPathsAreChecked(): void
    {
        $this->write('gone.yaml', self::project('gone', $this->tmp.'/nowhere', "startup_script: {$this->tmp}/setup.sh\nworktrees_root: {$this->tmp}/missing/wt\n"));
        mkdir($this->tmp.'/plain');
        $this->write('plain.yaml', self::project('plain', $this->tmp.'/plain', "startup_script: bin/setup.sh\n"));

        $this->assertSame([
            "error gone.yaml: repo.path is not a directory: {$this->tmp}/nowhere",
            "error gone.yaml: startup_script file not found: {$this->tmp}/setup.sh",
            "error gone.yaml: worktrees_root parent directory not found: {$this->tmp}/missing",
            "error plain.yaml: repo.path is not a git repository (no .git): {$this->tmp}/plain",
        ], $this->texts());
    }

    public function testDuplicateNamesAreErrorsOnBothFiles(): void
    {
        $this->write('a.yaml', self::project('same', $this->tmp.'/repo'));
        $this->write('b.yaml', self::project('same', $this->tmp.'/repo'));

        $this->assertSame([
            "error a.yaml: name duplicate project name 'same' (also in b.yaml): both files are skipped",
            "error b.yaml: name duplicate project name 'same' (also in a.yaml): both files are skipped",
        ], $this->texts());
    }

    public function testABrokenGlobalConfigIsReportedOnceAndStopsProjectLoading(): void
    {
        $this->writeGlobalConfig(str_replace('interval_minutes: 10', 'interval_minutes: abc', self::DEFAULTS));
        $this->write('a.yaml', self::project('a', $this->tmp.'/repo'));
        $report = (new ConfigValidator(new GlobalConfig(), new Config(new GlobalConfig())))->validate();

        $this->assertSame(["config.yaml: state_polling.interval_minutes must be a whole number of minutes, at least 1, got 'abc'"], array_map(static fn (ConfigIssue $i): string => $i->text(), $report->issues));
        $this->assertSame("config.yaml: state_polling.interval_minutes must be a whole number of minutes, at least 1, got 'abc'", $report->globalError);
    }

    public function testAMissingGlobalConfigIsAWarning(): void
    {
        $this->unsetGlobalConfig();
        putenv('PABLO_CONFIG='.$this->tmp.'/absent.yaml');
        try {
            $this->assertSame(['warning config.yaml: not found: projects get no global defaults, each must set every key itself'], $this->texts());
        } finally {
            putenv('PABLO_CONFIG');
        }
    }
}
