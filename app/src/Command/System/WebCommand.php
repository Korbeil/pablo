<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\App\Kernel;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Web\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Serves the read-only dashboard with PABLO's own HTTP server
 * (Pablo\Web\Server): no `php -S` — the standalone binary has none — no
 * Docker, no symfony CLI, no extra daemon: `pablo web` and ctrl-c.
 */
#[AsCommand(name: 'web', description: 'serve the read-only dashboard on 127.0.0.1')]
final class WebCommand extends Command
{
    public const DEFAULT_PORT = 8321;

    /**
     * Loopback only, and not configurable. The dashboard has no authentication
     * and exposes branch names, issue titles and PR URLs.
     */
    public const HOST = Server::HOST;

    public function __construct(
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'dev',
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug = true,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'port to listen on', (string) self::DEFAULT_PORT)
            ->addOption('open', null, InputOption::VALUE_NONE, 'open the dashboard in your browser');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $port = $this->port($input);

        $server = $this->server($output);
        $port = $server->listen($port);
        $url = 'http://'.self::HOST.':'.$port;

        $output->writeln("PABLO dashboard → <info>{$url}</info>");
        $output->writeln('<comment>(ctrl-c to stop)</comment>');

        if ($input->getOption('open')) {
            $this->openBrowser($url);
        }

        $server->run();

        return self::SUCCESS;
    }

    /**
     * Each worker boots its own kernel, in the same env as this command, after
     * the fork; this command's own container has already compiled (or loaded)
     * the cached container they will reuse.
     */
    public function server(OutputInterface $output): Server
    {
        $environment = $this->environment;
        $debug = $this->debug;

        return new Server(
            static fn (): Kernel => new Kernel($environment, $debug),
            self::publicDir(),
            Server::WORKERS,
            static function (string $line) use ($output): void {
                $output->writeln('['.date('D M j H:i:s Y').'] '.$line);
            },
        );
    }

    /** app/public: the compiled assets (inside the binary, or in a checkout). */
    public static function publicDir(): string
    {
        return \dirname(__DIR__, 3).'/public';
    }

    private function port(InputInterface $input): int
    {
        $raw = (string) $input->getOption('port');
        if (1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > 65535) {
            throw new PabloError("invalid --port {$raw}: expected a number between 1 and 65535");
        }

        return (int) $raw;
    }

    private function openBrowser(string $url): void
    {
        $opener = 'Darwin' === \PHP_OS_FAMILY ? 'open' : 'xdg-open';
        try {
            (new Process([$opener, $url]))->run();
        } catch (\Throwable) {
            // A dashboard that is up but unopened is not a failure.
        }
    }
}
