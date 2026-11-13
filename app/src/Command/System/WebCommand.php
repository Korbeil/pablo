<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Pablo\Web\PhpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Serves the dashboard from PHP's built-in web server — in the
 * standalone binary, from the PHP CLI it carries (see PhpServer).
 *
 * No Docker, no symfony CLI, no extra daemon: `pablo web` and ctrl-c.
 */
#[AsCommand(name: 'web', description: 'serve the dashboard on 127.0.0.1')]
final class WebCommand extends Command
{
    public const DEFAULT_PORT = 8321;

    public const HOST = PhpServer::HOST;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'dev',
        private readonly GlobalConfig $globalConfig = new GlobalConfig(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'port to listen on (default: dashboard_port in ~/.pablo/config.yaml, else '.self::DEFAULT_PORT.')')
            ->addOption('open', null, InputOption::VALUE_NONE, 'open the dashboard in your browser');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $port = $this->port($input);
        $url = 'http://'.self::HOST.':'.$port;
        // Checked up front: the server itself fails closed on a bad value
        // (loopback only), which would look like a mysterious 400.
        $trustedHosts = $this->globalConfig->dashboardTrustedHosts();

        $process = new Process((new PhpServer())->command($port), env: PhpServer::environment($this->environment));
        $process->setTimeout(null);

        $output->writeln("PABLO dashboard → <info>{$url}</info>");
        if ([] !== $trustedHosts) {
            $output->writeln('also answering requests for: '.implode(', ', $trustedHosts));
        }
        $output->writeln('<comment>(ctrl-c to stop)</comment>');

        $process->start();

        // A service stop (systemd, launchd) signals this process: pass it on,
        // so `php -S` and its workers stop with it.
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $forward = static function (int $signal) use ($process): void {
                if ($process->isRunning()) {
                    $process->signal($signal);
                }
            };
            pcntl_signal(\SIGTERM, $forward);
            pcntl_signal(\SIGINT, $forward);
        }

        if ($input->getOption('open')) {
            $this->openBrowser($url);
        }

        // Inherit the server's log lines so ctrl-c behaves like a foreground server.
        $process->wait(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return self::SUCCESS;
    }

    /** --port, else dashboard_port (what the service runs with), else 8321. */
    private function port(InputInterface $input): int
    {
        $raw = $input->getOption('port');
        if (null === $raw) {
            return $this->globalConfig->dashboardPort(self::DEFAULT_PORT);
        }
        if (!GlobalConfig::isPort($raw)) {
            throw new PabloError('invalid --port '.(string) $raw.': expected a number between 1 and 65535');
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
