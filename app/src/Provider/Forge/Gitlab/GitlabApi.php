<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitlab;

use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * `glab api` calls against one GitLab host: REST v4 endpoints and GraphQL.
 *
 * Every call names its host (`--hostname`) and its project by full path
 * (`projects/group%2Fsub%2Fproject`), so nothing depends on the directory
 * glab runs from nor on glab's default host. REST lists are paged by hand
 * (`page`/`per_page`) rather than with `--paginate`, whose output is one
 * JSON document per page.
 */
final class GitlabApi
{
    public const TIMEOUT_S = 20;
    public const PER_PAGE = 100;

    /** Upper bound on the pages getAll() reads (100 items each). */
    public const MAX_PAGES = 20;

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
    }

    /** The REST path of a project, by full path ("group/sub/project"). */
    public static function project(string $slug): string
    {
        return 'projects/'.rawurlencode($slug);
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return list<string>
     */
    public static function argv(string $host, string $endpoint, array $query = [], string $method = 'GET'): array
    {
        $argv = ['glab', 'api', '--hostname', $host];
        if ('GET' !== $method) {
            array_push($argv, '--method', $method);
        }
        $argv[] = $endpoint.([] === $query ? '' : '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986));

        return $argv;
    }

    /**
     * One GraphQL request: string variables go as raw fields (-f), the
     * others as typed fields (-F), as glab expects.
     *
     * @param array<string, string|int|bool|null> $variables null ones are left out
     *
     * @return list<string>
     */
    public static function graphqlArgv(string $host, string $query, array $variables = []): array
    {
        $argv = ['glab', 'api', '--hostname', $host, 'graphql', '-f', 'query='.$query];
        foreach ($variables as $name => $value) {
            if (null === $value) {
                continue;
            }
            if (\is_string($value)) {
                array_push($argv, '-f', "{$name}={$value}");
            } else {
                array_push($argv, '-F', $name.'='.(\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
            }
        }

        return $argv;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<mixed>
     */
    public function get(string $host, string $endpoint, array $query = []): array
    {
        $out = $this->runner->run(self::argv($host, $endpoint, $query), timeout: self::TIMEOUT_S);

        return Json::decodeArray($out, "glab api {$endpoint} output");
    }

    /**
     * Every item of a REST list, page by page, in the order GitLab sorts them.
     *
     * @param array<string, string|int> $query
     *
     * @return list<array<mixed>>
     */
    public function getAll(string $host, string $endpoint, array $query = [], int $maxPages = self::MAX_PAGES): array
    {
        $all = [];
        for ($page = 1; $page <= $maxPages; ++$page) {
            $out = $this->runner->run(self::argv($host, $endpoint, $query + ['per_page' => self::PER_PAGE, 'page' => $page]), timeout: self::TIMEOUT_S);
            $items = array_values(array_filter(Json::decodeList($out, "glab api {$endpoint} output"), \is_array(...)));
            array_push($all, ...$items);
            if (\count($items) < self::PER_PAGE) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<mixed>
     */
    public function post(string $host, string $endpoint, array $query = []): array
    {
        $out = $this->runner->run(self::argv($host, $endpoint, $query, 'POST'), timeout: self::TIMEOUT_S);

        return Json::tryDecodeArray($out) ?? [];
    }

    /**
     * The `data` of a GraphQL answer. Throws when GitLab answered with errors
     * and no data at all.
     *
     * @param array<string, string|int|bool|null> $variables
     *
     * @return array<mixed>
     */
    public function graphql(string $host, string $query, array $variables = []): array
    {
        $out = $this->runner->run(self::graphqlArgv($host, $query, $variables), timeout: self::TIMEOUT_S);
        $response = Json::decodeArray($out, 'glab api graphql output');
        $data = $response['data'] ?? null;
        if (!\is_array($data)) {
            throw new PabloError('glab api graphql: '.self::errorMessage($response));
        }

        return $data;
    }

    /** @param array<mixed> $response */
    public static function errorMessage(array $response): string
    {
        $messages = [];
        foreach (\is_array($response['errors'] ?? null) ? $response['errors'] : [] as $error) {
            $message = \is_array($error) ? ($error['message'] ?? null) : $error;
            if (\is_string($message) && '' !== $message) {
                $messages[] = $message;
            }
        }

        return [] === $messages ? 'no data in the answer' : implode('; ', $messages);
    }
}
