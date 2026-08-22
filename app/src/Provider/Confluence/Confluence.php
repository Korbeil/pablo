<?php

declare(strict_types=1);

namespace Pablo\Provider\Confluence;

use Pablo\Config\ProjectConfig;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Confluence documentation access, backed by the acli CLI.
 *
 * Storage-format body (XHTML with Confluence macros) is returned verbatim;
 * rendering it to Markdown is left to the caller.
 */
final class Confluence
{
    public const CONFLUENCE_CALL_TIMEOUT_S = 30;

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
    }

    public function matchUrl(string $url): ?string
    {
        // Either a cloud wiki URL (with or without a trailing title slug)…
        if (1 === preg_match('#https?://[^/]+/wiki(?:/spaces/[^/]+)?/pages/(?P<id>\d+)#', $url, $m)) {
            return $m['id'];
        }
        // …or a ?pageId=<id> query form.
        if (1 === preg_match('#[?&]pageId=(?P<id>\d+)#', $url, $m)) {
            return $m['id'];
        }

        return null;
    }

    public function fetch(string $pageIdOrUrl, ?ProjectConfig $cfg = null): ConfluencePage
    {
        if ('' === trim($pageIdOrUrl)) {
            throw new PabloError('confluence: no page id or url given');
        }
        $pageId = $pageIdOrUrl;
        if (!ctype_digit($pageId)) {
            $parsed = $this->matchUrl($pageIdOrUrl);
            if (null === $parsed) {
                throw new PabloError('confluence: not a page id or Confluence URL: '.var_export($pageIdOrUrl, true));
            }
            $pageId = $parsed;
        }

        $out = $this->runner->run([
            'acli', 'confluence', 'page', 'view', '--id', $pageId,
            '--json', '--body-format', 'storage',
        ], timeout: self::CONFLUENCE_CALL_TIMEOUT_S);
        $data = json_decode($out, true);
        if (!\is_array($data) || !\array_key_exists('id', $data)) {
            throw new PabloError('confluence: unexpected acli response: '.substr($out, 0, 200));
        }

        $links = $data['_links'] ?? [];
        $base = (string) ($links['base'] ?? '');
        $webui = (string) ($links['webui'] ?? '');
        $url = ('' !== $base || '' !== $webui) ? $base.$webui : '';

        return new ConfluencePage(
            id: (string) $data['id'],
            title: (string) ($data['title'] ?? ''),
            url: $url,
            body: (string) ($data['body']['storage']['value'] ?? ''),
        );
    }

    public function cliName(): string
    {
        return 'acli';
    }

    /** @return list<string> */
    public function authCheckCmd(): array
    {
        return ['acli', 'confluence', 'auth', 'status'];
    }
}
