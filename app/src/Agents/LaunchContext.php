<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\Task;
use Pablo\Provider\Forge\Gitea\GiteaApi;
use Pablo\Provider\Forge\Gitlab\GitlabApi;
use Pablo\Provider\Forge\RepoRef;
use Pablo\Support\Json;
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
 * describes. Read-only — only `gh`/`glab`/`gt`/`acli`/`linear` view calls.
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
            'gitlab' => $this->gitlabIssue($issue->key, $cfg),
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

    private function gitlabIssue(string $key, ProjectConfig $cfg): string
    {
        $repo = $this->repoSlug->ref($cfg);
        $path = GitlabApi::project($repo->slug).'/issues/'.rawurlencode($key);
        $data = $this->json(GitlabApi::argv($repo->host, $path));
        $comments = [];
        foreach (self::listOf(self::decode($this->runner->run(GitlabApi::argv($repo->host, "{$path}/notes", ['sort' => 'asc', 'order_by' => 'created_at', 'per_page' => 100]), true, self::TIMEOUT))) as $note) {
            if (true === ($note['system'] ?? false)) {
                continue; // label changes, mentions…: not a comment
            }
            $comments[] = [self::str($note['author']['username'] ?? null), self::str($note['created_at'] ?? null), self::str($note['body'] ?? null)];
        }
        $labels = array_map(self::str(...), \is_array($data['labels'] ?? null) ? $data['labels'] : []);

        return $this->renderIssue("GitLab issue #{$key}", self::str($data['title'] ?? null), [
            'State' => self::str($data['state'] ?? null),
            'Labels' => implode(', ', array_filter($labels)),
        ], self::str($data['description'] ?? null), $comments);
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
        $repo = $this->repoSlug->ref($cfg);
        if ($repo->isGitlab()) {
            return $this->gitlabCiBlock($prNumber, $repo, $cfg);
        }
        if ($repo->isGitea()) {
            return $this->giteaCiBlock($prNumber, $repo, $cfg);
        }
        $slug = $repo->slug;
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
            if (\in_array(self::str($c['bucket'] ?? null), ['fail', 'cancel'], true) && !\in_array($name, $cfg->ci->ignoreChecks, true)) {
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

    private const GITLAB_FAILED_JOBS_QUERY = <<<'GRAPHQL'
        query($path: ID!, $iid: String!) {
          project(fullPath: $path) {
            mergeRequest(iid: $iid) {
              headPipeline {
                webPath
                jobs(retried: false, statuses: [FAILED], first: 50) {
                  nodes { id name allowFailure webPath stage { name } }
                }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * GitLab: the head pipeline's failed jobs (allowed failures and
     * ci.ignore_checks left out) and the tail of a few of their logs.
     */
    private function gitlabCiBlock(int $prNumber, RepoRef $repo, ProjectConfig $cfg): string
    {
        $data = $this->json(GitlabApi::graphqlArgv($repo->host, self::GITLAB_FAILED_JOBS_QUERY, ['path' => $repo->slug, 'iid' => (string) $prNumber]));
        $pipeline = $data['data']['project']['mergeRequest']['headPipeline'] ?? null;
        if (!\is_array($pipeline)) {
            return '';
        }
        $failing = [];
        foreach (self::listOf($pipeline['jobs']['nodes'] ?? null) as $job) {
            $name = self::str($job['name'] ?? null);
            if (true !== ($job['allowFailure'] ?? false) && !\in_array($name, $cfg->ci->ignoreChecks, true)) {
                $failing[] = $job;
            }
        }
        if ([] === $failing) {
            return '';
        }

        $out = "### Failing jobs on MR !{$prNumber} (pipeline https://{$repo->host}".self::str($pipeline['webPath'] ?? null).")\n";
        foreach ($failing as $job) {
            $stage = self::str($job['stage']['name'] ?? null);
            $out .= '- '.('' !== $stage ? "{$stage} / " : '').self::str($job['name'] ?? null)
                .' — failed — https://'.$repo->host.self::str($job['webPath'] ?? null)."\n";
        }

        $jobs = 0;
        foreach ($failing as $job) {
            if ($jobs >= self::MAX_FAILED_JOBS) {
                $out .= "\n(further failing jobs not pre-fetched)\n";
                break;
            }
            if (1 !== preg_match('#/(\d+)$#', self::str($job['id'] ?? null), $m)) {
                continue;
            }
            ++$jobs;
            $log = $this->runner->run(GitlabApi::argv($repo->host, GitlabApi::project($repo->slug)."/jobs/{$m[1]}/trace"), false, self::TIMEOUT);
            $out .= "\n#### Log tail — ".self::str($job['name'] ?? null)." (job {$m[1]})\n```\n".self::logTail($log)."\n```\n";
        }

        return self::clip($out, self::MAX_CHARS - 1_000);
    }

    /**
     * Gitea: the head commit's failing statuses (ci.ignore_checks left out)
     * and the tail of a few failed Gitea Actions jobs' logs.
     */
    private function giteaCiBlock(int $prNumber, RepoRef $repo, ProjectConfig $cfg): string
    {
        $path = GiteaApi::repo($repo->slug);
        $sha = self::str($this->giteaGet($repo, "{$path}/pulls/{$prNumber}")['head']['sha'] ?? null);
        if ('' === $sha) {
            return '';
        }
        $status = $this->giteaGet($repo, "{$path}/commits/".rawurlencode($sha).'/status', ['limit' => GiteaApi::PER_PAGE]);
        $failing = [];
        foreach (self::listOf($status['statuses'] ?? null) as $s) {
            $state = self::str($s['status'] ?? $s['state'] ?? null);
            if (\in_array($state, ['failure', 'error'], true) && !\in_array(self::str($s['context'] ?? null), $cfg->ci->ignoreChecks, true)) {
                $failing[] = $s;
            }
        }
        if ([] === $failing) {
            return '';
        }

        $out = "### Failing checks on PR #{$prNumber}\n";
        foreach ($failing as $s) {
            $out .= '- '.self::str($s['context'] ?? null).' — '.self::str($s['status'] ?? $s['state'] ?? null).' — '.self::str($s['target_url'] ?? null)."\n";
        }

        $jobs = 0;
        $runs = self::listOf($this->giteaGet($repo, "{$path}/actions/runs", ['head_sha' => $sha, 'limit' => GiteaApi::PER_PAGE])['workflow_runs'] ?? null);
        foreach ($runs as $run) {
            if ('failure' !== self::str($run['conclusion'] ?? null) || !\is_scalar($run['id'] ?? null)) {
                continue;
            }
            foreach (self::listOf($this->giteaGet($repo, "{$path}/actions/runs/{$run['id']}/jobs", ['limit' => GiteaApi::PER_PAGE])['jobs'] ?? null) as $job) {
                if ('failure' !== self::str($job['conclusion'] ?? null) || !\is_scalar($job['id'] ?? null)) {
                    continue;
                }
                if ($jobs >= self::MAX_FAILED_JOBS) {
                    $out .= "\n(further failing jobs not pre-fetched)\n";
                    break 2;
                }
                ++$jobs;
                $log = $this->runner->run(GiteaApi::argv($repo->host, "{$path}/actions/jobs/{$job['id']}/logs"), false, self::TIMEOUT);
                $out .= "\n#### Log tail — ".self::str($job['name'] ?? null)." (run {$run['id']}, job {$job['id']})\n```\n".self::logTail($log)."\n```\n";
            }
        }

        return self::clip($out, self::MAX_CHARS - 1_000);
    }

    /**
     * One Gitea REST read; [] on Gitea's error body.
     *
     * @param array<string, string|int> $query
     *
     * @return array<mixed>
     */
    private function giteaGet(RepoRef $repo, string $endpoint, array $query = []): array
    {
        $data = $this->json(GiteaApi::argv($repo->host, $endpoint, $query));

        return null === GiteaApi::errorOf($data) ? $data : [];
    }

    /**
     * The useful part of a `gh run view --log-failed` dump or a GitLab job trace: the error lines
     * first, then the last lines before the runner's post-job cleanup. Each
     * line loses its "<job>\t<step>\t<timestamp> " prefix (GitHub), its
     * section markers (GitLab) and colour codes.
     */
    private static function logTail(string $log): string
    {
        $lines = array_map(
            static function (string $l): string {
                $l = (string) preg_replace(['/^[^\t]*\t[^\t]*\t\S+Z /', '/section_(?:start|end):\d+:[^\s\e]*\r?/', '/(\e|\^\[)\[[0-9;]*[mK]/'], '', $l);

                return mb_strlen($l) > 200 ? mb_substr($l, 0, 200).'…' : $l;
            },
            preg_split('/\R/', rtrim($log)) ?: [],
        );
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Post job cleanup.') || str_contains($line, 'Cleaning up project directory and file based variables')) {
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
        $ref = $this->repoSlug->ref($cfg);
        if ($ref->isGitlab()) {
            return $this->gitlabReviewBlock($prNumber, $ref);
        }
        if ($ref->isGitea()) {
            return $this->giteaReviewBlock($prNumber, $ref);
        }
        [$owner, $repo] = explode('/', $ref->slug, 2) + [1 => ''];
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

    /**
     * GitLab: the merge request's unresolved threads and its plain comments
     * (system notes left out), first 100 discussions.
     */
    private function gitlabReviewBlock(int $prNumber, RepoRef $repo): string
    {
        $discussions = self::listOf(self::decode($this->runner->run(
            GitlabApi::argv($repo->host, GitlabApi::project($repo->slug)."/merge_requests/{$prNumber}/discussions", ['per_page' => 100]),
            true,
            self::TIMEOUT,
        )));
        $mrUrl = $repo->prUrl($prNumber);
        $threads = [];
        $conversation = [];
        foreach ($discussions as $discussion) {
            $notes = array_values(array_filter(self::listOf($discussion['notes'] ?? null), static fn (array $n): bool => true !== ($n['system'] ?? false)));
            if ([] === $notes) {
                continue;
            }
            if (true === ($notes[0]['resolvable'] ?? false)) {
                if (true !== ($notes[0]['resolved'] ?? false)) {
                    $threads[] = $notes;
                }
            } else {
                array_push($conversation, ...$notes);
            }
        }
        $line = static fn (array $n): string => '**'.self::str($n['author']['username'] ?? null).'** '.$mrUrl.'#note_'.self::str($n['id'] ?? null);

        $out = "### Open review feedback on MR !{$prNumber}\n"
            .\sprintf("%d unresolved threads, %d comments%s.\n",
                \count($threads), \count($conversation), \count($discussions) >= 100 ? ' [truncated: more pages exist, paginate yourself]' : '');
        if ([] !== $threads) {
            $out .= "\n#### Unresolved threads\n";
            foreach ($threads as $notes) {
                $position = \is_array($notes[0]['position'] ?? null) ? $notes[0]['position'] : [];
                $path = self::str($position['new_path'] ?? $position['old_path'] ?? null);
                $out .= "\n- ".('' !== $path ? '`'.$path.':'.self::str($position['new_line'] ?? $position['old_line'] ?? null).'`' : '(general)')."\n";
                foreach ($notes as $n) {
                    $out .= '  - '.$line($n).":\n    "
                        .str_replace("\n", "\n    ", self::clip(trim(self::str($n['body'] ?? null)), self::COMMENT_CHARS))."\n";
                }
            }
        }
        if ([] !== $conversation) {
            $out .= "\n#### Comments\n";
            foreach ($conversation as $n) {
                $out .= "\n- ".$line($n).":\n  "
                    .str_replace("\n", "\n  ", self::clip(trim(self::str($n['body'] ?? null)), self::COMMENT_CHARS))."\n";
            }
        }
        if ([] === $threads && [] === $conversation) {
            return '';
        }

        return self::clip($out, self::MAX_CHARS - 1_000);
    }

    /**
     * Gitea: the unresolved review comments (grouped by file and line), the
     * non-empty review bodies and the conversation comments, first 50 of each.
     */
    private function giteaReviewBlock(int $prNumber, RepoRef $repo): string
    {
        $path = GiteaApi::repo($repo->slug);
        $reviews = self::listOf($this->giteaGet($repo, "{$path}/pulls/{$prNumber}/reviews", ['limit' => GiteaApi::PER_PAGE]));
        $bodies = [];
        $threads = [];
        foreach ($reviews as $r) {
            if ('PENDING' === self::str($r['state'] ?? null) || true === ($r['dismissed'] ?? false)) {
                continue;
            }
            if ('' !== trim(self::str($r['body'] ?? null))) {
                $bodies[] = $r;
            }
            if ((int) ($r['comments_count'] ?? 0) > 0 && \is_scalar($r['id'] ?? null)) {
                foreach (self::listOf($this->giteaGet($repo, "{$path}/pulls/{$prNumber}/reviews/{$r['id']}/comments")) as $c) {
                    if (!\is_array($c['resolver'] ?? null)) {
                        $threads[self::str($c['path'] ?? null).':'.self::str($c['position'] ?? $c['original_position'] ?? null)][] = $c;
                    }
                }
            }
        }
        $conversation = self::listOf($this->giteaGet($repo, "{$path}/issues/{$prNumber}/comments", ['limit' => GiteaApi::PER_PAGE]));
        if ([] === $threads && [] === $bodies && [] === $conversation) {
            return '';
        }
        $truncated = \count($reviews) >= GiteaApi::PER_PAGE || \count($conversation) >= GiteaApi::PER_PAGE;

        $out = "### Open review feedback on PR #{$prNumber}\n"
            .\sprintf("%d unresolved threads, %d non-empty review bodies, %d conversation comments%s.\n",
                \count($threads), \count($bodies), \count($conversation), $truncated ? ' [truncated: more pages exist, paginate yourself]' : '');
        if ([] !== $threads) {
            $out .= "\n#### Unresolved threads\n";
            foreach ($threads as $where => $comments) {
                $out .= "\n- `{$where}`\n";
                foreach ($comments as $c) {
                    $out .= '  - **'.self::str($c['user']['login'] ?? null).'** '.self::str($c['html_url'] ?? null).":\n    "
                        .str_replace("\n", "\n    ", self::clip(trim(self::str($c['body'] ?? null)), self::COMMENT_CHARS))."\n";
                }
            }
        }
        if ([] !== $bodies) {
            $out .= "\n#### Review bodies\n";
            foreach ($bodies as $r) {
                $out .= "\n- **".self::str($r['user']['login'] ?? null).'** ('.self::str($r['state'] ?? null).') '.self::str($r['html_url'] ?? null).":\n  "
                    .str_replace("\n", "\n  ", self::clip(trim(self::str($r['body'] ?? null)), self::COMMENT_CHARS))."\n";
            }
        }
        if ([] !== $conversation) {
            $out .= "\n#### Conversation comments\n";
            foreach ($conversation as $c) {
                $out .= "\n- **".self::str($c['user']['login'] ?? null).'** '.self::str($c['html_url'] ?? null).":\n  "
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
        $data = Json::tryDecodeArray($out);

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

    private static function clip(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max)."\n[truncated]";
    }
}
