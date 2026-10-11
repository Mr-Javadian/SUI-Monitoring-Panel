<?php
/**
 * Leveled logger with size-based rotation.
 *
 * Usage:
 *   Logger::info('message', ['server' => 'x']);
 *   Logger::error('boom');
 *
 * Output goes to the log file and (optionally) to STDOUT, so that
 * `journalctl -u sui-monitor` works when running under systemd.
 */

declare(strict_types=1);

namespace SuiMonitor;

final class Logger
{
    private const LEVELS = [
        'debug'   => 10,
        'info'    => 20,
        'warning' => 30,
        'error'   => 40,
    ];

    private static string $file     = '';
    private static int    $minLevel = 20;
    private static int    $maxBytes = 5242880;
    private static int    $maxFiles = 5;
    private static bool   $console  = true;
    private static bool   $ready    = false;

    /**
     * @param array<string,mixed> $cfg Full, normalised configuration array.
     */
    public static function init(array $cfg): void
    {
        $log = $cfg['logging'] ?? [];

        self::$file     = rtrim((string)($cfg['storage']['dir'] ?? ''), '/') . '/logs/app.log';
        self::$minLevel = self::LEVELS[$log['level'] ?? 'info'] ?? 20;
        self::$maxBytes = (int)($log['max_bytes'] ?? 5242880);
        self::$maxFiles = max(1, (int)($log['max_files'] ?? 5));
        self::$console  = (bool)($log['console'] ?? true);

        $dir = dirname(self::$file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        self::$ready = true;
    }

    /** @param array<string,mixed> $c */
    public static function debug(string $m, array $c = []): void   { self::write('debug',   $m, $c); }
    /** @param array<string,mixed> $c */
    public static function info(string $m, array $c = []): void    { self::write('info',    $m, $c); }
    /** @param array<string,mixed> $c */
    public static function warning(string $m, array $c = []): void { self::write('warning', $m, $c); }
    /** @param array<string,mixed> $c */
    public static function error(string $m, array $c = []): void   { self::write('error',   $m, $c); }

    /** @param array<string,mixed> $ctx */
    private static function write(string $level, string $msg, array $ctx): void
    {
        if (!self::$ready) {
            return;
        }
        if ((self::LEVELS[$level] ?? 20) < self::$minLevel) {
            return;
        }

        $line = sprintf(
            '[%s] [%s] %s%s%s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $msg,
            $ctx ? ' | ' . self::formatContext($ctx) : '',
            PHP_EOL
        );

        if (self::$console && defined('STDOUT')) {
            @fwrite(STDOUT, $line);
        }

        self::rotateIfNeeded();
        @file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $ctx */
    private static function formatContext(array $ctx): string
    {
        $pairs = [];
        foreach ($ctx as $k => $v) {
            if (is_bool($v)) {
                $pairs[] = $k . '=' . ($v ? 'true' : 'false');
            } elseif (is_scalar($v) || $v === null) {
                $pairs[] = $k . '=' . (string)$v;
            } else {
                $pairs[] = $k . '=' . json_encode($v, JSON_UNESCAPED_SLASHES);
            }
        }

        return implode(' ', $pairs);
    }

    private static function rotateIfNeeded(): void
    {
        if (!is_file(self::$file) || filesize(self::$file) < self::$maxBytes) {
            return;
        }

        // Drop the oldest archive, then shift app.log.N-1 -> app.log.N.
        $oldest = self::$file . '.' . self::$maxFiles;
        if (is_file($oldest)) {
            @unlink($oldest);
        }
        for ($i = self::$maxFiles - 1; $i >= 1; $i--) {
            $src = self::$file . '.' . $i;
            if (is_file($src)) {
                @rename($src, self::$file . '.' . ($i + 1));
            }
        }

        @rename(self::$file, self::$file . '.1');
    }
}
