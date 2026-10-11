<?php
/**
 * Application-level constants.
 */

declare(strict_types=1);

namespace SuiMonitor;

final class App
{
    public const NAME       = 'SUI-Monitoring-Panel';
    public const REPOSITORY = 'https://github.com/Mr-Javadian/SUI-Monitoring-Panel';

    /** Absolute path of the project root (the directory that contains src/). */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** Current version, read from the VERSION file (single source of truth). */
    public static function version(): string
    {
        $file = self::root() . '/VERSION';
        $v    = is_file($file) ? trim((string)@file_get_contents($file)) : '';

        return $v !== '' ? $v : 'unknown';
    }
}
