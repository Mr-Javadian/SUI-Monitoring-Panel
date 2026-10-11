#!/usr/bin/env php
<?php
/**
 * SUI-Monitoring-Panel — entry point.
 *
 * Run `php bin/monitor.php --help` for the available options.
 * In production this file is started by systemd (see systemd/sui-monitor.service).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "[!] PHP 8.1 or newer is required (found " . PHP_VERSION . ").\n");
    exit(1);
}

foreach (['curl', 'json', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[!] Required PHP extension is missing: {$ext}\n");
        exit(1);
    }
}

require __DIR__ . '/../src/autoload.php';

exit(SuiMonitor\Cli::main($argv));
