<?php

declare(strict_types=1);

namespace Pablo\Web;

/**
 * The Host names the dashboard answers: the loopback names, always, plus the
 * user's own `dashboard_trusted_hosts` (exact names, never patterns).
 *
 * The dashboard listens on 127.0.0.1 only (PhpServer), but a web page whose
 * domain name re-resolves to 127.0.0.1 (DNS rebinding) would become
 * same-origin and could drive its write actions. Its requests carry that
 * foreign Host header, so anything not listed here gets a 400. Extra names
 * are for reaching it through something that keeps the 127.0.0.1 listener,
 * e.g. a reverse proxy, an SSH tunnel or a hosts-file alias.
 *
 * Symfony strips the port before matching and wraps each pattern as
 * {pattern}i, hence the anchors.
 */
final class TrustedHosts
{
    public const LOOPBACK_PATTERN = '^(?:127\.0\.0\.1|localhost)$';

    /**
     * @param list<string> $extra normalized host names (see normalize())
     *
     * @return list<string> patterns for Request::setTrustedHosts()
     */
    public static function patterns(array $extra = []): array
    {
        $patterns = [self::LOOPBACK_PATTERN];
        if ([] !== $extra) {
            $patterns[] = '^(?:'.implode('|', array_map(static fn (string $h): string => preg_quote($h, '{'), $extra)).')$';
        }

        return $patterns;
    }

    /**
     * A host name or IP literal as Symfony's Request::getHost() returns it
     * (lowercase, IPv6 in brackets), or null when $host is not one: no
     * scheme, port, path or wildcard.
     */
    public static function normalize(string $host): ?string
    {
        $host = strtolower(trim($host));
        $bare = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if (false !== filter_var($bare, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return '['.$bare.']';
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return $host;
        }
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (\strlen($host) <= 253 && 1 === preg_match('/^'.$label.'(?:\.'.$label.')*$/', $host)) {
            return $host;
        }

        return null;
    }
}
