<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Serves the read-only dashboard from PHP's built-in web server.
 *
 * No Docker, no symfony CLI, no extra daemon: `pablo web` and ctrl-c.
 */
final class WebCommand extends Command
{
    public const DEFAULT_PORT = 8321;

    /**
     * The built-in server is single-process by default, which makes a page with
     * two polling live components feel stuck behind its own asset requests.
     */
    public const WORKERS = 4;

    /**
     * Loopback only, and not configurable. The dashboard has no authentication
     * and exposes branch names, issue titles and PR URLs.
     */
    private const HOST = '127.0.0.1';

    protected function configure(): void
    {
        $this->setName('web')
            ->setDescription('serve the read-only dashboard on 127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'port to listen on', (string) self::DEFAULT_PORT)
            ->addOption('open', null, InputOption::VALUE_NONE, 'open the dashboard in your browser');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $port = $this->port($input);
        $url = 'http://'.self::HOST.':'.$port;

        $process = new Process(self::serverCommand($port), env: ['PHP_CLI_SERVER_WORKERS' => (string) self::WORKERS]);
        $process->setTimeout(null);

        $output->writeln("PABLO dashboard → <info>{$url}</info>");
        $output->writeln('<comment>(ctrl-c to stop)</comment>');

        $process->start();

        if ($input->getOption('open')) {
            $this->openBrowser($url);
        }

        // Inherit the server's log lines so ctrl-c behaves like a foreground
        // server; Process forwards SIGINT to the child.
        $process->wait(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    public static function serverCommand(int $port): array
    {
        $public = \dirname(__DIR__, 3).'/public';

        return [
            \PHP_BINARY,
            '-S', self::HOST.':'.$port,
            '-t', $public,
            $public.'/index.php',
        ];
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
