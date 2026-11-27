<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitea;

use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Calls to the Gitea CLI (`gt`, the successor of the archived tea) against
 * one Gitea host: `gt api` for REST (/api/v1), and gt's own commands where
 * they do the job.
 *
 * Every call names its instance (`--host <host>`, which gt matches against
 * the accounts it is logged in to) and its repository by path
 * (`repos/<owner>/<repo>/…`, `--repo owner/repo`), so nothing depends on the
 * directory gt runs from nor on gt's default instance; `--no-input` keeps it
 * from ever prompting. A non-2xx answer fails the command (its body still
 * printed): a read run with check: false checks for Gitea's error body
 * (errorOf()).
 */
final class GiteaApi
{
    public const TIMEOUT_S = 20;

    /** Gitea's default (and usual maximum) page size, MAX_RESPONSE_ITEMS. */
    public const PER_PAGE = 50;

    /** Upper bound on the pages getAll() reads (50 items each). */
    public const MAX_PAGES = 20;

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
    }

    /** The REST path of a repository ("owner/repo"). */
    public static function repo(string $slug): string
    {
        return 'repos/'.implode('/', array_map(rawurlencode(...), explode('/', $slug, 2)));
    }

    /**
     * A `gt api` call. $fields become string fields (-f), sent as the JSON
     * body of a non-GET request.
     *
     * @param array<string, string|int> $query
     * @param array<string, string>     $fields
     *
     * @return list<string>
     */
    public static function argv(string $host, string $endpoint, array $query = [], string $method = 'GET', array $fields = []): array
    {
        $argv = ['gt', 'api', '--host', $host, '--no-input'];
        if ('GET' !== $method) {
            array_push($argv, '--method', $method);
        }
        foreach ($fields as $name => $value) {
            array_push($argv, '--raw-field', "{$name}={$value}");
        }
        $argv[] = $endpoint.([] === $query ? '' : '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986));

        return $argv;
    }

    /**
     * A gt command on one repository of the host, such as
     * ['pr', 'ready', '12'].
     *
     * @param list<string> $command
     *
     * @return list<string>
     */
    public static function commandArgv(string $host, string $slug, array $command): array
    {
        return ['gt', ...$command, '--host', $host, '--repo', $slug, '--no-input'];
    }

    /**
     * Gitea's error message when $response is an error body (an object with
     * a message and none of a resource's identifiers), else null.
     */
    public static function errorOf(mixed $response): ?string
    {
        if (!\is_array($response) || (array_is_list($response) && [] !== $response)) {
            return null;
        }
        $message = $response['message'] ?? null;
        if (!\is_string($message) || isset($response['id']) || isset($response['number'])) {
            return null;
        }

        return '' === $message ? 'error' : $message;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<mixed>
     */
    public function get(string $host, string $endpoint, array $query = []): array
    {
        return self::decode($this->runner->run(self::argv($host, $endpoint, $query), timeout: self::TIMEOUT_S), $endpoint);
    }

    /**
     * Every item of a REST list, page by page, in the order Gitea sorts them.
     * $key names the list inside an object answer (actions runs and jobs).
     *
     * @param array<string, string|int> $query
     *
     * @return list<array<mixed>>
     */
    public function getAll(string $host, string $endpoint, array $query = [], int $maxPages = self::MAX_PAGES, ?string $key = null): array
    {
        $all = [];
        for ($page = 1; $page <= $maxPages; ++$page) {
            $data = $this->get($host, $endpoint, $query + ['limit' => self::PER_PAGE, 'page' => $page]);
            $items = self::items($data, $key);
            array_push($all, ...$items);
            if (\count($items) < self::PER_PAGE) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<mixed>
     */
    public function send(string $host, string $method, string $endpoint, array $fields = []): array
    {
        $out = $this->runner->run(self::argv($host, $endpoint, [], $method, $fields), timeout: self::TIMEOUT_S);
        if ('' === trim($out)) {
            return []; // 204 No Content
        }

        return self::decode($out, $endpoint);
    }

    /** Runs a gt command on one repository of the host; returns its output. */
    public function command(string $host, string $slug, string ...$command): string
    {
        return $this->runner->run(self::commandArgv($host, $slug, array_values($command)), timeout: self::TIMEOUT_S);
    }

    /**
     * A gt api answer, decoded; throws on Gitea's error body.
     *
     * @return array<mixed>
     */
    public static function decode(string $out, string $endpoint): array
    {
        $data = Json::decodeArray($out, "gt api {$endpoint} output");
        $error = self::errorOf($data);
        if (null !== $error) {
            throw new PabloError("gt api {$endpoint}: {$error}");
        }

        return $data;
    }

    /**
     * The array items of a list answer ($key: the list inside an object).
     *
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function items(array $data, ?string $key = null): array
    {
        $list = null === $key ? $data : ($data[$key] ?? []);

        return \is_array($list) ? array_values(array_filter($list, \is_array(...))) : [];
    }
}
