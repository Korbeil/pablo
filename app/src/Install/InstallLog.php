<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Support\PabloPaths;
use Pablo\Support\WriteStatus;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The output of `pablo system:install` (and `system:generate-agents`): step
 * lines, and the files and links it placed, grouped in sections.
 *
 * A created or updated file is printed at once, highlighted; the unchanged
 * ones of a section collapse into one muted line (`= 5 agents, 1 command
 * unchanged`) — each listed with -v — so a re-install that changed nothing
 * says so in a few lines. summary() ends the run with the totals.
 */
final class InstallLog
{
    private const PREFIX = '➜ ';

    private ?string $base = null;

    /** @var array<string, int> kind => unchanged count, in the open section */
    private array $unchanged = [];

    /** The open section's only unchanged path, shown instead of a count of one. */
    private ?string $unchangedPath = null;

    /** @var array<value-of<WriteStatus>, int> */
    private array $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    public function __construct(private readonly OutputInterface $output)
    {
    }

    /** A step line (console markup allowed, as before). */
    public function info(string $line): void
    {
        $this->close();
        $this->output->writeln(self::PREFIX.$line);
    }

    /** A step line only worth showing with -v. */
    public function detail(string $line): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }
        $this->close();
        $this->output->writeln('<fg=gray>'.self::PREFIX.OutputFormatter::escape($line).'</>');
    }

    /**
     * Start a group of files; paths under $base are shown relative to it.
     */
    public function section(string $title, ?string $base = null, ?string $note = null): void
    {
        $this->close();
        $this->base = null !== $base ? rtrim($base, '/') : null;
        $where = array_filter([null !== $base ? PabloPaths::contractTilde($this->base ?? '') : null, $note]);
        $this->output->writeln('');
        $this->output->writeln('<options=bold>'.OutputFormatter::escape($title).'</>'
            .([] !== $where ? ' <fg=gray>('.OutputFormatter::escape(implode(' · ', $where)).')</>' : ''));
    }

    /**
     * One file or link of the open section.
     *
     * @param string $kind what it is, singular ("agent", "link", "unit"), for the collapsed count
     */
    public function file(string $kind, string $path, WriteStatus $status): void
    {
        ++$this->totals[$status->value];
        $shown = OutputFormatter::escape($this->display($path));
        if (WriteStatus::Unchanged === $status) {
            if ($this->output->isVerbose()) {
                $this->output->writeln("  <fg=gray>= unchanged {$shown}</>");

                return;
            }
            $this->unchangedPath = [] === $this->unchanged ? $path : null;
            $this->unchanged[$kind] = ($this->unchanged[$kind] ?? 0) + 1;

            return;
        }
        $this->output->writeln(WriteStatus::Created === $status
            ? "  <info>+ created </info> {$shown}"
            : "  <comment>~ updated </comment> {$shown}");
    }

    /** Print the open section's collapsed unchanged line, if any. */
    public function close(): void
    {
        if ([] === $this->unchanged) {
            return;
        }
        if (null !== $this->unchangedPath && 1 === array_sum($this->unchanged)) {
            $line = '= unchanged '.$this->display($this->unchangedPath);
        } else {
            $counts = [];
            foreach ($this->unchanged as $kind => $count) {
                $counts[] = $count.' '.$kind.(1 === $count ? '' : 's');
            }
            $line = '= '.implode(', ', $counts).' unchanged';
        }
        $this->output->writeln('  <fg=gray>'.OutputFormatter::escape($line).'</>');
        $this->unchanged = [];
        $this->unchangedPath = null;
    }

    /** How many files and links were created or updated so far. */
    public function changed(): int
    {
        return $this->totals['created'] + $this->totals['updated'];
    }

    /** The totals line; nothing when no file was reported. */
    public function summary(): void
    {
        $this->close();
        $total = array_sum($this->totals);
        if (0 === $total) {
            return;
        }
        $this->output->writeln('');
        if (0 === $this->changed()) {
            $this->output->writeln("<fg=gray>Nothing changed: {$total} files and links already up to date.</>");

            return;
        }
        $parts = array_filter([
            $this->totals['created'] > 0 ? "<info>{$this->totals['created']} created</info>" : null,
            $this->totals['updated'] > 0 ? "<comment>{$this->totals['updated']} updated</comment>" : null,
        ]);
        $this->output->writeln("<options=bold>{$this->changed()} changed</> (".implode(', ', $parts).')'
            .($this->totals['unchanged'] > 0 ? ", {$this->totals['unchanged']} unchanged." : '.'));
    }

    private function display(string $path): string
    {
        if (null !== $this->base && str_starts_with($path, $this->base.'/')) {
            return substr($path, \strlen($this->base) + 1);
        }

        return PabloPaths::contractTilde($path);
    }
}
