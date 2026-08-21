<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Git\GitRepo;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

final class ProjectNewCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('project:new')
            ->setDescription('interactively scaffold a new project config in ~/.pablo/projects/');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $helper = new QuestionHelper();

        $cwd = (string) getcwd();
        $detectedRepo = $this->detectedRepoRoot($cwd);

        // 1. Repo path
        $repoPath = $this->askRepoPath($helper, $input, $output, $detectedRepo);
        $repoDir = basename($repoPath);
        $detectedBranch = $this->detectedPrimaryBranch($repoPath);

        // 2. Project name
        $projectName = $this->askProjectName($helper, $input, $output, $repoDir);
        $name = $projectName;

        // 3. Type
        $type = $this->askType($helper, $input, $output);

        // 4. Provider
        $provider = $this->askProvider($helper, $input, $output);

        // 5. Provider-conditional fields
        $site = null;
        $confluenceSpace = null;
        $issueRepo = null;

        if ('jira' === $provider) {
            $site = $this->askOptional($helper, $input, $output, 'Jira site (e.g. acme.atlassian.net)', 'acme.atlassian.net');
            $confluenceSpace = $this->askOptional($helper, $input, $output, 'Confluence space (optional)', null);
        } elseif ('linear' === $provider) {
            $confluenceSpace = $this->askOptional($helper, $input, $output, 'Confluence space (optional)', null);
        } elseif ('github' === $provider) {
            $remoteUrl = GitRepo::originUrl($repoPath);
            $defaultIssueRepo = null !== $remoteUrl ? $this->originToSlug($remoteUrl) : null;
            $issueRepo = $this->askOptional($helper, $input, $output, 'Issue repo (e.g. acme/upstream, optional)', $defaultIssueRepo);
        }

        // 6. Project key
        $projectKey = $this->askProjectKey($helper, $input, $output, strtoupper($projectName), $provider);

        // 7. Provider identity
        $defaultEmail = GitRepo::userEmail($repoPath) ?? '';
        $identity = $this->askIdentity($helper, $input, $output, $defaultEmail);

        // 8. Failure signal
        $failureSignal = null;
        if ($helper->ask($input, $output, new ConfirmationQuestion('[y/N] Add a failure signal (for needs-testing -> testing-failed detection)? ', false))) {
            $hint = 'github' === $provider ? 'a GitHub label name' : 'a Jira/Linear status value';
            $failureSignal = $this->askRequired($helper, $input, $output, "Failure signal ({$hint})", null);
        }

        // Build YAML
        $yaml = $this->buildYaml($name, $type, $repoPath, $detectedBranch, $provider, $identity, $projectKey, $site, $issueRepo, $confluenceSpace, $failureSignal);

        // 9. Confirm & write
        $output->writeln('');
        $output->writeln('--- YAML preview ---');
        $output->writeln($yaml);
        $output->writeln('--------------------');

        if (!$helper->ask($input, $output, new ConfirmationQuestion('[y/N] Write this config to ~/.pablo/projects/? ', false))) {
            $output->writeln('Aborted.');

            return self::SUCCESS;
        }

        $destDir = Config::projectsDir();
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0o777, true);
        }

        // Track the enabled-provider SET across projects: agents must be
        // regenerated whenever it differs after the write, not only when this
        // project's provider is brand new.
        $providersBefore = $this->providerSet($destDir);

        $destPath = $destDir.'/'.$name.'.yaml';
        file_put_contents($destPath, $yaml);

        // Validate it loads
        try {
            Config::loadProjects($destDir);
        } catch (PabloError $e) {
            @unlink($destPath);
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
        foreach (Config::loadProjects($dir) as $cfg) {
            $providers[] = $cfg->provider;
        }

        $unique = array_unique($providers);
        sort($unique);

        return $unique;
    }

    private function askRepoPath(QuestionHelper $helper, InputInterface $input, OutputInterface $output, ?string $detectedRepo): string
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
            if (str_starts_with($v, '~/') && false !== ($home = getenv('HOME'))) {
                return $home.substr($v, 1);
            }

            return $v;
        });

        $path = (string) $helper->ask($input, $output, $question);
        if ('' === $path) {
            throw new PabloError('Repository path is required');
        }

        return rtrim($path, '/');
    }

    private function askProjectName(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $default): string
    {
        $existing = array_keys(Config::loadProjects());
        $question = new Question('Project name ['.$default.'] ', $default);
        $question->setValidator(static function (?string $v) use ($existing): string {
            $v = trim((string) $v);
            if ('' === $v) {
                throw new \RuntimeException('Project name cannot be empty');
            }
            if (1 === preg_match('/\s/', $v)) {
                throw new \RuntimeException('Project name cannot contain spaces');
            }
            if (\in_array($v, $existing, true)) {
                throw new \RuntimeException("Project '{$v}' already exists");
            }

            return $v;
        });

        return (string) $helper->ask($input, $output, $question);
    }

    private function askType(QuestionHelper $helper, InputInterface $input, OutputInterface $output): string
    {
        $choices = Config::PROJECT_TYPES;
        $question = new ChoiceQuestion('Project type', $choices, 'work');
        $question->setErrorMessage('Invalid type %s');

        return (string) $helper->ask($input, $output, $question);
    }

    private function askProvider(QuestionHelper $helper, InputInterface $input, OutputInterface $output): string
    {
        $choices = Config::PROVIDERS;
        $question = new ChoiceQuestion('Which issue tracker does your project use?', $choices, 'github');
        $question->setErrorMessage('Invalid provider %s');

        return (string) $helper->ask($input, $output, $question);
    }

    private function askOptional(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt, ?string $default): ?string
    {
        $display = $prompt;
        $defaultVal = $default ?? '';
        if ('' !== $defaultVal) {
            $display .= ' ['.$defaultVal.']';
        }
        $display .= ' ';

        $question = new Question($display, '' !== $defaultVal ? $defaultVal : null);
        $answer = $helper->ask($input, $output, $question);
        if (null === $answer || '' === trim((string) $answer)) {
            return null;
        }

        return trim((string) $answer);
    }

    private function askRequired(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt, ?string $default): string
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
                throw new \RuntimeException('This value cannot be empty');
            }

            return $v;
        });

        return (string) $helper->ask($input, $output, $question);
    }

    private function askProjectKey(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $default, string $provider): string
    {
        $required = \in_array($provider, ['jira', 'linear'], true);
        $prompt = $required ? 'Project key (issue key prefix)' : 'Project key (branch prefix)';

        return $this->askRequired($helper, $input, $output, $prompt, $default);
    }

    private function askIdentity(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $default): string
    {
        return $this->askRequired($helper, $input, $output, 'Provider identity (email)', $default);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStructure(string $name, string $type, string $repoPath, ?string $primaryBranch, string $provider, string $identity, string $projectKey, ?string $site, ?string $issueRepo, ?string $confluenceSpace, ?string $failureSignal): array
    {
        $data = [
            'name' => $name,
            'type' => $type,
            'repo' => [
                'path' => $this->toHomeRelative($repoPath),
                'primary_branch' => $primaryBranch ?? 'main',
            ],
            'issue_tracker' => [
                'provider' => $provider,
                'identity' => $identity,
                'project_key' => $projectKey,
            ],
        ];

        if (null !== $site) {
            $data['issue_tracker']['site'] = $site;
        }
        if (null !== $issueRepo) {
            $data['issue_tracker']['repo'] = $issueRepo;
        }
        if (null !== $confluenceSpace) {
            $data['confluence'] = ['space' => $confluenceSpace];
        }
        if (null !== $failureSignal) {
            $data['testing'] = ['failure_signal' => $failureSignal];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dumpYaml(array $data): string
    {
        return Yaml::dump($data, 4, 2);
    }

    private function buildYaml(string $name, string $type, string $repoPath, ?string $primaryBranch, string $provider, string $identity, string $projectKey, ?string $site, ?string $issueRepo, ?string $confluenceSpace, ?string $failureSignal): string
    {
        $data = $this->buildStructure($name, $type, $repoPath, $primaryBranch, $provider, $identity, $projectKey, $site, $issueRepo, $confluenceSpace, $failureSignal);

        return $this->dumpYaml($data);
    }

    private function toHomeRelative(string $path): string
    {
        $home = getenv('HOME');
        if (false !== $home && '' !== $home && str_starts_with($path, $home)) {
            return '~'.substr($path, \strlen($home));
        }

        return $path;
    }

    private function detectedRepoRoot(string $cwd): ?string
    {
        try {
            return GitRepo::git($cwd, ['rev-parse', '--show-toplevel']);
        } catch (PabloError) {
            return null;
        }
    }

    private function detectedPrimaryBranch(string $repo): ?string
    {
        try {
            return GitRepo::git($repo, ['rev-parse', '--abbrev-ref', 'HEAD']);
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
