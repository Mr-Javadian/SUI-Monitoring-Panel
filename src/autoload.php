<?php
/**
 * Minimal PSR-4 autoloader for the SuiMonitor\ namespace.
 *
 * The project has no runtime dependencies, so Composer is optional:
 * this file is all that is needed to load the classes in src/.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'SuiMonitor\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
