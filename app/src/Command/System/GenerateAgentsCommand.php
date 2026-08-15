<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Config\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateAgentsCommand extends Command
{
    private const TEMPLATE_NAMES = ['task-analyst', 'task-feedback'];

    protected function configure(): void
    {
        $this->setName('system:generate-agents')
            ->setDescription('generate opencode agent .md files from .md.template + project configs')
            ->addOption('agents-dir', null, InputOption::VALUE_REQUIRED, 'agents directory (default: repo opencode/agents)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agentsDir = $input->getOption('agents-dir') ?? \dirname(__DIR__, 4).'/opencode/agents';

        $projectsDir = Config::projectsDir();
        if (!is_dir($projectsDir)) {
            $output->writeln('No projects directory found — generating agents with all providers enabled.');
            $enabledProviders = Config::PROVIDERS;
        } else {
            $enabledProviders = $this->enabledProviders($projectsDir);
        }

        foreach (self::TEMPLATE_NAMES as $name) {
            $templatePath = $agentsDir.'/'.$name.'.md.template';
            $outputPath = $agentsDir.'/'.$name.'.md';

            if (!is_file($templatePath)) {
                $output->writeln("<error>Template not found: {$templatePath}</error>");

                return self::FAILURE;
            }

            $template = file_get_contents($templatePath);
            if (false === $template) {
                $output->writeln("<error>Cannot read template: {$templatePath}</error>");

                return self::FAILURE;
            }

            $section = $this->buildSection($enabledProviders, $name, $agentsDir.'/providers');
            $names = $this->formatProviderNames($enabledProviders);

            $content = str_replace(
                ['{{ISSUE_TRACKER_SECTION}}', '{{ISSUE_TRACKER_NAMES}}'],
                [$section, $names],
                $template,
            );

            $written = @file_put_contents($outputPath, $content);
            if (false === $written) {
                $output->writeln("<error>Cannot write: {$outputPath}</error>");

                return self::FAILURE;
            }

            $output->writeln("Generated {$name}.md with providers: ".implode(', ', $enabledProviders ?: ['(none)']));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function enabledProviders(string $projectsDir): array
    {
        $providers = [];
        foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $path) {
            $data = Config::loadYaml($path);
            if (isset($data['issue_tracker']['provider'])) {
                $providers[] = $data['issue_tracker']['provider'];
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * @param list<string> $providers
     */
    private function formatProviderNames(array $providers): string
    {
        $displayNames = array_map(
            static fn (string $p): string => match ($p) {
                'github' => 'GitHub Issues',
                'jira' => 'Jira',
                'linear' => 'Linear',
                default => $p,
            },
            $providers,
        );

        if ([] === $displayNames) {
            return 'your issue tracker';
        }

        if (1 === \count($displayNames)) {
            return $displayNames[0];
        }

        $last = array_pop($displayNames);

        return implode(', ', $displayNames).', or '.$last;
    }

    /**
     * @param list<string> $providers
     */
    private function buildSection(array $providers, string $agentName, string $providersDir): string
    {
        $items = [];
        $num = 1;

        foreach ($providers as $provider) {
            $file = $providersDir.'/'.$provider.'-'.$agentName.'.md';
            if (is_file($file)) {
                $content = file_get_contents($file);
                if (false !== $content && '' !== trim($content)) {
                    $items[] = $num.'. '.trim($content);
                    ++$num;
                }
            }
        }

        if (\in_array('jira', $providers, true)) {
            $file = $providersDir.'/confluence-'.$agentName.'.md';
            if (is_file($file)) {
                $content = file_get_contents($file);
                if (false !== $content && '' !== trim($content)) {
                    $items[] = $num.'. '.trim($content);
                    ++$num;
                }
            }
        }

        $file = $providersDir.'/fallback-'.$agentName.'.md';
        if (is_file($file)) {
            $content = file_get_contents($file);
            if (false !== $content && '' !== trim($content)) {
                $items[] = $num.'. '.trim($content);
            }
        }

        return implode("\n\n", $items);
    }
}
