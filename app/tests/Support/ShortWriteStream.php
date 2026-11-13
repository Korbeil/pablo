<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

/**
 * Test stream wrapper over a real directory ($root) whose writes only ever
 * store half of what they are given, like a disk filling up mid-write.
 * Register it under a scheme, then address files as "<scheme>://<relative>".
 */
final class ShortWriteStream
{
    public static string $root = '';

    /** @var resource|null set by PHP for stream wrappers */
    public $context;

    /** @var resource|null */
    private $fd;

    public function stream_open(string $path, string $mode): bool
    {
        $fd = fopen(self::real($path), $mode);
        if (false === $fd) {
            return false;
        }
        $this->fd = $fd;

        return true;
    }

    public function stream_write(string $data): int
    {
        \assert(\is_resource($this->fd));

        return (int) fwrite($this->fd, substr($data, 0, intdiv(\strlen($data), 2)));
    }

    public function stream_read(int $count): string|false
    {
        \assert(\is_resource($this->fd));

        return fread($this->fd, max(1, $count));
    }

    public function stream_eof(): bool
    {
        return !\is_resource($this->fd) || feof($this->fd);
    }

    public function stream_close(): void
    {
        if (\is_resource($this->fd)) {
            fclose($this->fd);
        }
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return \is_resource($this->fd) ? fstat($this->fd) : false;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = self::real($path);
        if (!file_exists($real) && !is_link($real)) {
            return false;
        }

        return ($flags & \STREAM_URL_STAT_LINK) ? lstat($real) : stat($real);
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return mkdir(self::real($path), $mode, (bool) ($options & \STREAM_MKDIR_RECURSIVE));
    }

    public function unlink(string $path): bool
    {
        return unlink(self::real($path));
    }

    public function rename(string $from, string $to): bool
    {
        return rename(self::real($from), self::real($to));
    }

    private static function real(string $path): string
    {
        return rtrim(self::$root, '/').'/'.(string) preg_replace('#^[a-z]+://#', '', $path);
    }
}
