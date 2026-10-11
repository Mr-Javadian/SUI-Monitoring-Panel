<?php
/**
 * Stateless text-formatting helpers used when building the dashboard.
 */

declare(strict_types=1);

namespace SuiMonitor;

final class Formatter
{
    /** Human-readable size, e.g. 1536 -> "1.50 KB". */
    public static function bytes(float $b, int $dec = 2): string
    {
        if ($b <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = 0;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }

        return number_format($b, $dec) . ' ' . $units[$i];
    }

    /** Human-readable throughput, e.g. 2048 -> "2.00 KB/s". */
    public static function rate(?float $bps): string
    {
        if ($bps === null || $bps <= 0) {
            return '0 B/s';
        }

        $units = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
        $i     = 0;
        while ($bps >= 1024 && $i < count($units) - 1) {
            $bps /= 1024;
            $i++;
        }

        return number_format($bps, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }

    /** Unicode progress bar, e.g. bar(50, 10) -> "█████░░░░░". */
    public static function bar(float $percent, int $width = 20): string
    {
        $percent = max(0.0, min(100.0, $percent));
        $filled  = (int)round($percent / 100 * $width);

        return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
    }

    /** Seconds -> "3d 4h 12m 9s". */
    public static function uptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $parts = [];
        if ($d) { $parts[] = "{$d}d"; }
        if ($h) { $parts[] = "{$h}h"; }
        if ($m) { $parts[] = "{$m}m"; }
        $parts[] = "{$s}s";

        return implode(' ', $parts);
    }

    /** Escape text for Telegram's HTML parse mode. */
    public static function esc(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Collapse whitespace and truncate to $max characters (multibyte-safe). */
    public static function shortName(string $name, int $max = 18): string
    {
        $name = (string)preg_replace('/\s+/', ' ', trim($name));

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($name, 'UTF-8') <= $max) {
                return $name;
            }
            return mb_substr($name, 0, $max - 1, 'UTF-8') . '…';
        }

        if (strlen($name) <= $max) {
            return $name;
        }

        return substr($name, 0, $max - 1) . '…';
    }

    /** Visible length of an HTML message (what Telegram counts against its 4096 limit). */
    public static function visibleLength(string $html): int
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    }
}
