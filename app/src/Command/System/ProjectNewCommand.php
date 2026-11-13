<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'project:new', description: 'interactively scaffold a new project config in ~/.pablo/projects/')]
final class ProjectNewCommand extends Command
{
    /** The run's console, set by doExecute() for the ask*() helpers. */
    private QuestionHelper $questions;
    private InputInterface $input;
    private OutputInterface $output;

    public function __construct(
        private readonly GitRepoInterface $git,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->questions = new QuestionHelper();
        $this->input = $input;
        $this->output = $output;

        $cwd = (string) getcwd();
        $detectedRepo = $this->detectedRepoRoot($cwd);

        // 1. Repo path
        $repoPath = $this->askRepoPath($detectedRepo);
        $repoDir = basename($repoPath);
        $detectedBranch = $this->detectedPrimaryBranch($repoPath);

        // 2. Project name
        $name = $this->askProjectName($repoDir);

        // 3. Type
        $type = $this->askType();

        // 4. Provider
        $provider = $this->askProvider();

        // 5. Provider-conditional fields
        $site = null;
        $confluenceSpace = null;
        $issueRepo = null;

        if ('jira' === $provider) {
            $site = $this->askOptional('Jira site (e.g. acme.atlassian.net)', 'acme.atlassian.net');
            $confluenceSpace = $this->askOptional('Confluence space (optional)', null);
        } elseif ('linear' === $provider) {
            $confluenceSpace = $this->askOptional('Confluence space (optional)', null);
        } elseif ('github' === $provider) {
            $remoteUrl = $this->git->originUrl($repoPath);
            $defaultIssueRepo = null !== $remoteUrl ? $this->originToSlug($remoteUrl) : null;
            $issueRepo = $this->askOptional('Issue repo (e.g. acme/upstream, optional)', $defaultIssueRepo);
        }

        // 5b. Git sync remote (fork only: sync against the upstream remote)
        // A fork is detected when the issue repo lives on a different slug
        // than the checkout's origin (e.g. origin=me/lib, issues=acme/lib).
        $remoteUrl ??= null;
        $originSlug = null !== $remoteUrl ? $this->originToSlug($remoteUrl) : null;
        $isFork = null !== $issueRepo && null !== $originSlug && $issueRepo !== $originSlug;
        $primaryRemote = $isFork
            ? ($this->askOptional('Primary git remote for sync (e.g. "upstream" when origin is a fork, optional)', 'origin') ?? 'origin')
            : 'origin';

        // 6. Project key
        $projectKey = $this->askProjectKey(strtoupper($name), $provider);

        // 7. Provider identity
        $defaultEmail = $this->git->userEmail($repoPath) ?? '';
        $identity = $this->askIdentity($defaultEmail);

        // 8. Testing flow
        $testingEnabled = $this->ask(new ConfirmationQuestion('[Y/n] Enable the QA/testing flow (needs-testing -> testing-failed)? ', true));
        $failureSignal = null;
        if ($testingEnabled && $this->ask(new ConfirmationQuestion('[y/N] Add a failure signal (for needs-testing -> testing-failed detection)? ', false))) {
            $hint = 'github' === $provider ? 'a GitHub label name' : 'a Jira/Linear status value';
            $failureSignal = $this->askRequired("Failure signal ({$hint})", null);
        }

        // Branch prefix (optional; falls back to ~/.pablo/config.yaml)
        $branchPrefix = $this->askOptional('Branch prefix (prepended to every branch, e.g. "pablo/", optional)', null);

        $yaml = Yaml::dump($this->projectConfig([
            'name' => $name,
            'type' => $type,
            'repo_path' => $repoPath,
            'primary_branch' => $detectedBranch,
            'primary_remote' => $primaryRemote,
            'provider' => $provider,
            'identity' => $identity,
            'project_key' => $projectKey,
            'site' => $site,
            'issue_repo' => $issueRepo,
            'confluence_space' => $confluenceSpace,
            'testing_enabled' => (bool) $testingEnabled,
            'failure_signal' => $failureSignal,
            'branch_prefix' => $branchPrefix,
        ]), 4, 2);

        // 9. Confirm & write
        $output->writeln('');
        $output->writeln('--- YAML preview ---');
        $output->writeln($yaml);
        $output->writeln('--------------------');

        if (!$this->ask(new ConfirmationQuestion('[y/N] Write this config to ~/.pablo/projects/? ', false))) {
            $output->writeln('Aborted.');

            return self::SUCCESS;
        }

        $destDir = $this->projectsLoader->projectsDir();
        Files::ensureDir($destDir);

        // Track the enabled-provider SET across projects: agents must be
        // regenerated whenever it differs after the write, not only when this
        // project's provider is brand new.
        $providersBefore = $this->providerSet($destDir);

        $destPath = $destDir.'/'.$name.'.yaml';
        file_put_contents($destPath, $yaml);

        // Validate it loads
        try {
            $this->projectsLoader->loadProjectFile($destPath);
            $duplicate = $this->projectsLoader->projectErrors($destDir)[$destPath] ?? null;
            if (null !== $duplicate) {
                throw new PabloError($duplicate);
            }
        } catch (PabloError $e) {
            Files::tryRemove($destPath);
            throw new PabloError('Generated config failed validation: '.$e->getMessage());
        }

        $output->writeln("Config written: {$destPath}");

        if ($this->providerSet($destDir) !== $providersBefore) {
            $output->writeln('');
            $output->writeln("This project uses '<comment>{$provider}</comment>' \u{2014} a new issue tracker not seen in your other projects.");
            $output->writeln('Agent templates will be re-generated to include it.');

            $command = $this->getApplication()?->find('system:generate-agents');
            if (null !== $command) {
                $command->run(new ArrayInput([]), $output);
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> unique providers across the projects dir, order-insensitive */
    private function providerSet(string $dir): array
    {
        $providers = [];
        foreach ($this->projectsLoader->loadProjects($dir) as $cfg) {
            $providers[] = $cfg->tracker->provider;
        }

        $unique = array_unique($providers);
        sort($unique);

        return $unique;
    }

    private function askRepoPath(?string $detectedRepo): string
    {
        $default = $detectedRepo ?? '';
        $prompt = 'Repository path (local checkout)';
        if ('' !== $default) {
            $prompt .= ' ['.$default.']';
        }
        $prompt .= ' ';

        $question = new Question($prompt, '' !== $default ? $default : null);
        $question->setNormalizer(static function (?string $v): ?string {
            if (null === $v || '' === $v) {
                return $v;
            }

            return PabloPaths::expandTilde($v);
        });

        $path = (string) $this->ask($question);
        if ('' === $path) {
            throw new PabloError('Repository path is required');
        }

        return rtrim($path, '/');
    }

    private function askProjectName(string $default): string
    {
        $existing = array_keys($this->projectsLoader->loadProjects());
        $question = new Question('Project name ['.$default.'] ', $default);
        $question->setValidator(static function (?string $v) use ($existing): string {
            $v = trim((string) $v);
            if ('' === $v) {
                throw new PabloError('Project name cannot be empty');
            }
            if (1 === preg_match('/\s/', $v)) {
                throw new PabloError('Project name cannot contain spaces');
            }
            if (\in_array($v, $existing, true)) {
                throw new PabloError("Project '{$v}' already exists");
            }

            return $v;
        });

        return (string) $this->ask($question);
    }

    private function askType(): string
    {
        $choices = Config::PROJECT_TYPES;
        $question = new ChoiceQuestion('Project type', $choices, 'work');
        $question->setErrorMessage('Invalid type %s');

        return (string) $this->ask($question);
    }

    private function askProvider(): string
    {
        $choices = Config::PROVIDERS;
        $question = new ChoiceQuestion('Which issue tracker does your project use?', $choices, 'github');
        $question->setErrorMessage('Invalid provider %s');

        return (string) $this->ask($question);
    }

    private function askOptional(string $prompt, ?string $default): ?string
    {
        $display = $prompt;
        $defaultVal = $default ?? '';
        if ('' !== $defaultVal) {
            $display .= ' ['.$defaultVal.']';
        }
        $display .= ' ';

        $question = new Question($display, '' !== $defaultVal ? $defaultVal : null);
        $answer = $this->ask($question);
        if (null === $answer || '' === trim((string) $answer)) {
            return null;
        }

        return trim((string) $answer);
    }

    private function askRequired(string $prompt, ?string $default): string
    {
        $display = $prompt;
        if (null !== $default && '' !== $default) {
            $display .= ' ['.$default.']';
        }
        $display .= ' ';

        $question = new Question($display, $default);
        $question->setValidator(static function (?string $v): string {
            $v = trim((string) $v);
            if ('' === $v) {
                throw new PabloError('This value cannot be empty');
            }

            return $v;
        });

        return (string) $this->ask($question);
    }

    private function askProjectKey(string $default, string $provider): string
    {
        $required = \in_array($provider, ['jira', 'linear'], true);
        $prompt = $required ? 'Project key (issue key prefix)' : 'Project key (branch prefix)';

        return $this->askRequired($prompt, $default);
    }

    private function askIdentity(string $default): string
    {
        return $this->askRequired('Provider identity (email)', $default);
    }

    /**
     * The project file's content, keys in the order the YAML preview shows.
     *
     * @param array{name: string, type: string, repo_path: string, primary_branch: ?string, primary_remote: string, provider: string, identity: string, project_key: string, site: ?string, issue_repo: ?string, confluence_space: ?string, testing_enabled: bool, failure_signal: ?string, branch_prefix: ?string} $answers
     *
     * @return array<string, mixed>
     */
    private function projectConfig(array $answers): array
    {
        $data = [
            'name' => $answers['name'],
            'type' => $answers['type'],
            'repo' => [
                'path' => PabloPaths::contractTilde($answers['repo_path']),
                'primary_branch' => $answers['primary_branch'] ?? 'main',
            ],
            'issue_tracker' => [
                'provider' => $answers['provider'],
                'identity' => $answers['identity'],
                'project_key' => $answers['project_key'],
            ],
        ];

        if (null !== $answers['site']) {
            $data['issue_tracker']['site'] = $answers['site'];
        }
        if (null !== $answers['issue_repo']) {
            $data['issue_tracker']['repo'] = $answers['issue_repo'];
        }
        if (null !== $answers['confluence_space']) {
            $data['confluence'] = ['space' => $answers['confluence_space']];
        }
        if (!$answers['testing_enabled']) {
            $data['testing'] = ['enabled' => false];
        } elseif (null !== $answers['failure_signal']) {
            $data['testing'] = ['failure_signal' => $answers['failure_signal']];
        }
        if (null !== $answers['branch_prefix']) {
            $data['branch_prefix'] = $answers['branch_prefix'];
        }
        if ('origin' !== $answers['primary_remote']) {
            $data['repo']['primary_remote'] = $answers['primary_remote'];
        }

        return $data;
    }

    private function ask(Question $question): mixed
    {
        return $this->questions->ask($this->input, $this->output, $question);
    }

    private function detectedRepoRoot(string $cwd): ?string
    {
        try {
            return $this->git->git($cwd, ['rev-parse', '--show-toplevel']);
        } catch (PabloError) {
            return null;
        }
    }

    private function detectedPrimaryBranch(string $repo): ?string
    {
        try {
            return $this->git->git($repo, ['rev-parse', '--abbrev-ref', 'HEAD']);
        } catch (PabloError) {
            return null;
        }
    }

    private function originToSlug(string $url): string
    {
        $url = (string) preg_replace('#^git@([^:]+):#', 'https://$1/', $url);
        $url = (string) preg_replace('#\.git$#', '', $url);
        $parts = explode('/', $url);
        $count = \count($parts);

        if ($count >= 2) {
            return $parts[$count - 2].'/'.$parts[$count - 1];
        }

        return $url;
    }
}
