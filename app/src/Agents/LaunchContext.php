<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\Task;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;

/**
 * Pre-fetches the deterministic context an auto-launched agent would
 * otherwise spend its first steps gathering (ticket + comments, failing
 * checks + log tails, open review threads) and renders it as a bounded
 * section appended to the launch prompt. Every tool step re-reads the whole
 * context, so handing the agent this upfront saves several full steps.
 *
 * Strictly best-effort: any fetch failure drops that block (or the whole
 * section) and the agent falls back to fetching by itself, as its prompt
 * describes. Read-only — only `gh`/`acli`/`linear` view calls.
 */
final class LaunchContext
{
    /** Hard cap on the whole appended section (~6k tokens). */
    public const MAX_CHARS = 24_000;
    private const BLOCK_CHARS = 12_000;
    private const COMMENT_CHARS = 1_500;
    private const MAX_COMMENTS = 15;
    private const MAX_FAILED_JOBS = 3;
    private const LOG_TAIL_LINES = 60;
    private const TIMEOUT = 45.0;

    private const REVIEW_QUERY = <<<'GRAPHQL'
        query($owner: String!, $repo: String!, $pr: Int!) {
          repository(owner: $owner, name: $repo) {
            pullRequest(number: $pr) {
              reviews(first: 50) {
                pageInfo { hasNextPage }
                nodes { author { login } state body url }
              }
              reviewThreads(first: 100) {
                pageInfo { hasNextPage }
                nodes {
                  isResolved isOutdated path line
                  comments(first: 30) { nodes { author { login } body url } }
                }
              }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly RepoSlug $repoSlug,
    ) {
    }

    /**
     * The launch prompt with the pre-fetched section appended, or $prompt
     * unchanged when the agent has nothing to pre-fetch or every fetch failed.
     */
    public function augment(Agent $agent, Task $task, ProjectConfig $cfg, string $prompt): string
    {
        try {
            $blocks = $this->blocksFor($agent, $task, $cfg);
        } catch (\Throwable) {
            return $prompt;
        }
        $blocks = array_filter($blocks, static fn (string $b): bool => '' !== trim($b));
        if ([] === $blocks) {
            return $prompt;
        }

        $section = "\n\n---\n## Pre-fetched context (PABLO)\n"
            .'Fetched at launch and bounded. Start from it; fetch more only for what '
            ."is missing or marked [truncated].\n\n"
            .implode("\n\n", $blocks);

        return $prompt.self::clip($section, self::MAX_CHARS);
    }

    /**
     * @return list<string>
     */
    private function blocksFor(Agent $agent, Task $task, ProjectConfig $cfg): array
    {
        return match ($agent) {
            Agent::TaskAnalyst, Agent::TaskFeedback => [$this->issueBlock($task, $cfg)],
            Agent::CiAnalyst => null !== $task->prNumber ? [$this->ciBlock($task->prNumber, $cfg)] : [],
            Agent::PrFeedback => null !== $task->prNumber ? [$this->reviewBlock($task->prNumber, $cfg)] : [],
            default => [],
        };
    }

    // ---------------------------------------------------------------- issue ---

    private function issueBlock(Task $task, ProjectConfig $cfg): string
    {
        $issue = $task->issue;
        if (null === $issue) {
            return '';
        }

        return match ($issue->provider) {
            'github' => $this->githubIssue($issue->key, $cfg),
            'jira' => $this->jiraIssue($issue->key),
            'linear' => $this->linearIssue($issue->key),
            default => '',
        };
    }

    private function githubIssue(string $key, ProjectConfig $cfg): string
    {
        $data = $this->json(['gh', 'issue', 'view', $key, '--repo', $this->repoSlug->for($cfg), '--json', 'title,state,labels,body,comments']);
        $comments = [];
        foreach (self::listOf($data['comments'] ?? null) as $c) {
            $comments[] = [self::str($c['author']['login'] ?? null), self::str($c['createdAt'] ?? null), self::str($c['body'] ?? null)];
        }
        $labels = array_map(static fn (array $l): string => self::str($l['name'] ?? null), self::listOf($data['labels'] ?? null));

        return $this->renderIssue("GitHub issue #{$key}", self::str($data['title'] ?? null), [
            'State' => self::str($data['state'] ?? null),
            'Labels' => implode(', ', array_filter($labels)),
        ], self::str($data['body'] ?? null), $comments);
    }

    private function jiraIssue(string $key): string
    {
        $data = $this->json(['acli', 'jira', 'workitem', 'view', $key, '--json', '--fields', 'summary,status,issuetype,priority,labels,description,comment']);
        $fields = \is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $comments = [];
        foreach (self::listOf($fields['comment']['comments'] ?? null) as $c) {
            $author = $c['author'] ?? null;
            $comments[] = [
                self::str(\is_array($author) ? ($author['displayName'] ?? null) : $author),
                self::str($c['created'] ?? null),
                self::adfText($c['body'] ?? null),
            ];
        }

        return $this->renderIssue("Jira {$key}", self::str($fields['summary'] ?? null), [
            'Type' => self::str($fields['issuetype']['name'] ?? null),
            'Status' => self::str($fields['status']['name'] ?? null),
            'Priority' => self::str($fields['priority']['name'] ?? null),
            'Labels' => implode(', ', array_map(self::str(...), \is_array($fields['labels'] ?? null) ? $fields['labels'] : [])),
        ], self::adfText($fields['description'] ?? null), $comments);
    }

    private function linearIssue(string $key): string
    {
        $data = $this->json(['linear', 'issue', 'view', $key, '--json']);
        $rawComments = $data['comments'] ?? null;
        if (\is_array($rawComments) && isset($rawComments['nodes'])) {
            $rawComments = $rawComments['nodes'];
        }
        $comments = [];
        foreach (self::listOf($rawComments) as $c) {
            $user = $c['user'] ?? null;
            $comments[] = [
                self::str(\is_array($user) ? ($user['name'] ?? $user['displayName'] ?? null) : $user),
                self::str($c['createdAt'] ?? null),
                self::str($c['body'] ?? null),
            ];
        }

        return $this->renderIssue("Linear {$key}", self::str($data['title'] ?? null), [
            'State' => self::str($data['state']['name'] ?? null),
        ], self::str($data['description'] ?? null), $comments);
    }

    /**
     * @param array<string, string>                        $meta
     * @param list<array{0: string, 1: string, 2: string}> $comments author, date, body — oldest first
     */
    private function renderIssue(string $heading, string $title, array $meta, string $body, array $comments): string
    {
        if ('' === trim($title) && '' === trim($body) && [] === $comments) {
            return ''; // unexpected payload: let the agent fetch it itself
        }
        $out = "### {$heading}: {$title}\n";
        foreach ($meta as $label => $value) {
            if ('' !== $value) {
                $out .= "- {$label}: {$value}\n";
            }
        }
        $out .= "\n#### Description\n".('' !== trim($body) ? self::clip(trim($body), self::BLOCK_CHARS / 2) : '(empty)')."\n";

        if ([] !== $comments) {
            $total = \count($comments);
            $kept = \array_slice($comments, -self::MAX_COMMENTS);
            $out .= "\n#### Comments ({$total}".($total > \count($kept) ? ', latest '.\count($kept).' shown' : '').", oldest first)\n";
            foreach ($kept as [$author, $date, $text]) {
                $out .= "\n**{$author}** ({$date}):\n".self::clip(trim($text), self::COMMENT_CHARS)."\n";
            }
        }

        return self::clip($out, self::BLOCK_CHARS);
    }

    // ------------------------------------------------------------------- CI ---

    private function ciBlock(int $prNumber, ProjectConfig $cfg): string
    {
        $slug = $this->repoSlug->for($cfg);
        // gh pr checks exits non-zero whenever a check fails — that is the
        // normal case here, so never treat the exit code as an error.
        $checks = self::decode($this->runner->run(
            ['gh', 'pr', 'checks', (string) $prNumber, '--repo', $slug, '--json', 'name,state,bucket,link,workflow'],
            false,
            self::TIMEOUT,
        ));

        $failing = [];
        foreach (self::listOf($checks) as $c) {
            $name = self::str($c['name'] ?? null);
            if (\in_array(self::str($c['bucket'] ?? null), ['fail', 'cancel'], true) && !\in_array($name, $cfg->ciIgnoreChecks, true)) {
                $failing[] = $c;
            }
        }
        if ([] === $failing) {
            return '';
        }

        $out = "### Failing checks on PR #{$prNumber}\n";
        foreach ($failing as $c) {
            $workflow = self::str($c['workflow'] ?? null);
            $out .= '- '.('' !== $workflow ? "{$workflow} / " : '').self::str($c['name'] ?? null)
                .' — '.self::str($c['state'] ?? null).' — '.self::str($c['link'] ?? null)."\n";
        }

        $jobs = 0;
        foreach ($failing as $c) {
            if ($jobs >= self::MAX_FAILED_JOBS) {
                $out .= "\n(further failing jobs not pre-fetched)\n";
                break;
            }
            if (1 !== preg_match('#/actions/runs/(\d+)/job/(\d+)#', self::str($c['link'] ?? null), $m)) {
                continue; // not GitHub Actions (e.g. CircleCI): the agent fetches it itself
            }
            ++$jobs;
            $log = $this->runner->run(['gh', 'run', 'view', $m[1], '--repo', $slug, '--log-failed', '--job', $m[2]], false, self::TIMEOUT);
            $out .= "\n#### Log tail — ".self::str($c['name'] ?? null)." (run {$m[1]}, job {$m[2]})\n```\n".self::logTail($log)."\n```\n";
        }

        return self::clip($out, self::MAX_CHARS - 1_000);
    }

    /**
     * The useful part of a `gh run view --log-failed` dump: the error lines
     * first, then the last lines before the runner's post-job cleanup. Each
     * line loses its "<job>\t<step>\t<timestamp> " prefix and colour codes.
     */
    private static function logTail(string $log): string
    {
        $lines = array_map(
            static function (string $l): string {
                $l = (string) preg_replace(['/^[^\t]*\t[^\t]*\t\S+Z /', '/(\e|\^\[)\[[0-9;]*m/'], '', $l);

                return mb_strlen($l) > 200 ? mb_substr($l, 0, 200).'…' : $l;
            },
            preg_split('/\R/', rtrim($log)) ?: [],
        );
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Post job cleanup.')) {
                $lines = \array_slice($lines, 0, $i);
                break;
            }
        }

        $errors = array_values(array_unique(array_filter(
            $lines,
            static fn (string $l): bool => 1 === preg_match('/##\[error\]|\b(FAIL(ED|URE)?|Fatal|Exception|Error:|AssertionError|failed)\b|✗|✘/', $l),
        )));
        // With error lines found, the tail is only there for surrounding
        // context; without any it is all the agent gets.
        $tail = \array_slice($lines, [] !== $errors ? -20 : -self::LOG_TAIL_LINES);

        $out = '';
        if ([] !== $errors) {
            $out .= "[error lines]\n".implode("\n", \array_slice($errors, 0, 40))."\n\n";
        }
        $out .= (\count($lines) > \count($tail) ? '[last '.\count($tail).' lines]' : '[full log]')."\n".implode("\n", $tail);

        return self::clip($out, 6_000);
    }

    // -------------------------------------------------------------- reviews ---

    private function reviewBlock(int $prNumber, ProjectConfig $cfg): string
    {
        [$owner, $repo] = explode('/', $this->repoSlug->for($cfg), 2) + [1 => ''];
        $data = $this->json([
            'gh', 'api', 'graphql',
            '-F', "owner={$owner}", '-F', "repo={$repo}", '-F', "pr={$prNumber}",
            '-f', 'query='.self::REVIEW_QUERY,
        ]);
        $pr = $data['data']['repository']['pullRequest'] ?? null;
        if (!\is_array($pr)) {
            return '';
        }

        $reviews = array_values(array_filter(
            self::listOf($pr['reviews']['nodes'] ?? null),
            static fn (array $r): bool => '' !== trim(self::str($r['body'] ?? null)),
        ));
        $threads = array_values(array_filter(
            self::listOf($pr['reviewThreads']['nodes'] ?? null),
            static fn (array $t): bool => true !== ($t['isResolved'] ?? false),
        ));
        $conversation = self::listOf($this->json([
            'gh', 'pr', 'view', (string) $prNumber, '--repo', "{$owner}/{$repo}", '--json', 'comments',
        ])['comments'] ?? null);

        $truncated = true === ($pr['reviews']['pageInfo']['hasNextPage'] ?? false)
            || true === ($pr['reviewThreads']['pageInfo']['hasNextPage'] ?? false);

        $out = "### Open review feedback on PR #{$prNumber}\n"
            .\sprintf("%d unresolved threads, %d non-empty review bodies, %d conversation comments%s.\n",
                \count($threads), \count($reviews), \count($conversation), $truncated ? ' [truncated: more pages exist, paginate yourself]' : '');

        if ([] !== $threads) {
            $out .= "\n#### Unresolved threads\n";
            foreach ($threads as $t) {
                $out .= "\n- `".self::str($t['path'] ?? null).':'.self::str($t['line'] ?? null).'`'
                    .(true === ($t['isOutdated'] ?? false) ? ' (outdated)' : '')."\n";
                foreach (self::listOf($t['comments']['nodes'] ?? null) as $c) {
                    $out .= '  - **'.self::str($c['author']['login'] ?? null).'** '.self::str($c['url'] ?? null).":\n    "
                        .str_replace("\n", "\n    ", self::clip(trim(self::str($c['body'] ?? null)), self::COMMENT_CHARS))."\n";
                }
            }
        }
        if ([] !== $reviews) {
            $out .= "\n#### Review bodies\n";
            foreach ($reviews as $r) {
                $out .= "\n- **".self::str($r['author']['login'] ?? null).'** ('.self::str($r['state'] ?? null).') '.self::str($r['url'] ?? null).":\n  "
                    .str_replace("\n", "\n  ", self::clip(trim(self::str($r['body'] ?? null)), self::COMMENT_CHARS))."\n";
            }
        }
        if ([] !== $conversation) {
            $out .= "\n#### Conversation comments\n";
            foreach ($conversation as $c) {
                $out .= "\n- **".self::str($c['author']['login'] ?? null).'** '.self::str($c['url'] ?? null).":\n  "
                    .str_replace("\n", "\n  ", self::clip(trim(self::str($c['body'] ?? null)), self::COMMENT_CHARS))."\n";
            }
        }

        return self::clip($out, self::MAX_CHARS - 1_000);
    }

    // -------------------------------------------------------------- helpers ---

    /**
     * @param list<string> $argv
     *
     * @return array<mixed>
     */
    private function json(array $argv): array
    {
        return self::decode($this->runner->run($argv, true, self::TIMEOUT));
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $out): array
    {
        $data = json_decode($out, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * The array entries of $value (non-arrays dropped).
     *
     * @return list<array<mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_array(...)));
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Plain text of an Atlassian Document Format node (Jira descriptions and
     * comments); plain strings pass through.
     */
    private static function adfText(mixed $node): string
    {
        if (\is_string($node)) {
            return $node;
        }
        if (!\is_array($node)) {
            return '';
        }
        $type = self::str($node['type'] ?? null);
        if ('text' === $type) {
            return self::str($node['text'] ?? null);
        }
        if ('mention' === $type || 'emoji' === $type) {
            return self::str($node['attrs']['text'] ?? null);
        }
        if ('hardBreak' === $type) {
            return "\n";
        }
        $text = '';
        foreach (self::listOf($node['content'] ?? null) as $child) {
            $text .= self::adfText($child);
        }
        if ('listItem' === $type) {
            return '- '.trim($text)."\n";
        }
        if (\in_array($type, ['paragraph', 'heading', 'codeBlock', 'blockquote', 'rule'], true)) {
            return $text."\n";
        }

        return $text;
    }

    private static function clip(string $text, int $max, bool $keepTail = false): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return $keepTail
            ? "[truncated]\n".mb_substr($text, -$max)
            : mb_substr($text, 0, $max)."\n[truncated]";
    }
}
