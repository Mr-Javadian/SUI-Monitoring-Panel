<?php
/**
 * Command-line front controller.
 *
 *   php bin/monitor.php                 run the monitor loop (default)
 *   php bin/monitor.php --once          refresh every dashboard once, then exit
 *   php bin/monitor.php --validate      validate the config file only (no network)
 *   php bin/monitor.php --check         validate config + test Telegram and every panel
 *   php bin/monitor.php --test-message  like --check, and also send a test message
 *   php bin/monitor.php --config=FILE   use another config file
 *   php bin/monitor.php --version
 */

declare(strict_types=1);

namespace SuiMonitor;

final class Cli
{
    private const EXIT_OK     = 0;
    private const EXIT_FAILED = 1;
    private const EXIT_CONFIG = 2;

    /** Set to false by the signal handlers to stop the main loop gracefully. */
    private static bool $running = true;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $opt = getopt('hv', ['config:', 'once', 'check', 'validate', 'test-message', 'version', 'help']);

        if (isset($opt['h']) || isset($opt['help'])) {
            self::usage();
            return self::EXIT_OK;
        }
        if (isset($opt['v']) || isset($opt['version'])) {
            echo App::NAME . ' v' . App::version() . "\n";
            return self::EXIT_OK;
        }

        $configPath = isset($opt['config']) && is_string($opt['config'])
            ? $opt['config']
            : App::root() . '/config/config.php';

        try {
            $cfg = Config::load($configPath);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[!] " . $e->getMessage() . "\n");
            return self::EXIT_CONFIG;
        }

        if (isset($opt['validate'])) {
            echo "Configuration is valid.\n";
            return self::EXIT_OK;
        }

        if (isset($opt['check']) || isset($opt['test-message'])) {
            return self::check($cfg, isset($opt['test-message']));
        }

        return self::run($cfg, isset($opt['once']));
    }

    // ── check / test mode ──────────────────────────────────

    /** @param array<string,mixed> $cfg */
    private static function check(array $cfg, bool $sendTest): int
    {
        $failed = 0;
        echo App::NAME . ' v' . App::version() . " — connectivity check\n\n";

        $tg = new TelegramBot($cfg['telegram']);
        $me = $tg->getMe();
        if ($me['ok']) {
            $user = is_array($me['result']) ? (string)($me['result']['username'] ?? '?') : '?';
            echo "  [ OK ] Telegram bot token is valid (@{$user})\n";
        } else {
            $failed++;
            echo "  [FAIL] Telegram: {$me['description']}\n";
        }

        if ($sendTest && $me['ok']) {
            $res = $tg->call('sendMessage', [
                'chat_id' => $cfg['telegram']['chat_id'],
                'text'    => '✅ <b>' . App::NAME . '</b> v' . App::version() . " — test message.\nTelegram delivery works.",
                'parse_mode' => 'HTML',
            ]);
            if ($res['ok']) {
                echo "  [ OK ] Test message delivered to chat {$cfg['telegram']['chat_id']}\n";
            } else {
                $failed++;
                echo "  [FAIL] Could not post to chat {$cfg['telegram']['chat_id']}: {$res['description']}\n";
                echo "         (user chats: press Start on the bot first; groups/channels: add the bot as admin)\n";
            }
        }

        foreach ($cfg['servers'] as $name => $srv) {
            $api = new SuiApi((string)$name, $srv, (int)$cfg['monitor']['http_timeout'], 0);
            $t0  = microtime(true);
            $st  = $api->status();
            $ms  = (int)round((microtime(true) - $t0) * 1000);

            if ($st !== null) {
                $ver = is_array($st['sys'] ?? null) ? (string)($st['sys']['appVersion'] ?? '?') : '?';
                echo "  [ OK ] Panel '{$name}' reachable ({$ms} ms, S-UI v{$ver})\n";
            } else {
                $failed++;
                echo "  [FAIL] Panel '{$name}': {$api->lastError} (HTTP " . ($api->lastHttpCode ?? '?') . ")\n";
                if ($api->lastHttpCode === 401 || $api->lastHttpCode === 403
                    || str_contains((string)$api->lastError, 'token')) {
                    echo "         The API token was rejected — create a new one in the panel.\n";
                } elseif ($api->lastHttpCode === 404) {
                    echo "         404: check the base_url path (default panel path: /app/apiv2).\n";
                }
            }
        }

        echo $failed === 0 ? "\nAll checks passed.\n" : "\n{$failed} check(s) failed.\n";

        return $failed === 0 ? self::EXIT_OK : self::EXIT_FAILED;
    }

    // ── main loop ──────────────────────────────────────────

    /** @param array<string,mixed> $cfg */
    private static function run(array $cfg, bool $once): int
    {
        $storage = (string)$cfg['storage']['dir'];

        foreach ([$storage, "{$storage}/logs", "{$storage}/state"] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                fwrite(STDERR, "[!] Cannot create directory: {$dir}\n");
                return self::EXIT_FAILED;
            }
            if (!is_writable($dir)) {
                fwrite(STDERR, "[!] Directory is not writable: {$dir}\n");
                return self::EXIT_FAILED;
            }
        }

        // Only one instance per storage directory; two loops would fight over the same messages.
        $lock = @fopen($storage . '/monitor.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            fwrite(STDERR, "[!] Another instance is already running (lock: {$storage}/monitor.lock)\n");
            return self::EXIT_FAILED;
        }

        Logger::init($cfg);
        self::installSignalHandlers();

        $tg       = new TelegramBot($cfg['telegram']);
        $monitors = [];
        foreach ($cfg['servers'] as $name => $srv) {
            $monitors[(string)$name] = new ServerMonitor((string)$name, $srv, $tg, $cfg);
        }

        $interval = (int)$cfg['monitor']['interval'];
        Logger::info(App::NAME . ' started', [
            'version'  => App::version(),
            'servers'  => implode(',', array_keys($monitors)),
            'interval' => $interval,
        ]);

        do {
            $t0 = microtime(true);

            foreach ($monitors as $name => $monitor) {
                try {
                    $monitor->tick();
                } catch (\Throwable $e) {
                    Logger::error('Monitor tick failed', [
                        'server' => $name,
                        'error'  => $e->getMessage(),
                        'file'   => basename($e->getFile()),
                        'line'   => $e->getLine(),
                    ]);
                }
                if (!self::$running) {
                    break;
                }
            }

            if ($once) {
                break;
            }

            // Sleep in short slices so SIGTERM/SIGINT are honoured promptly.
            $deadline = $t0 + $interval;
            while (self::$running && microtime(true) < $deadline) {
                usleep(200_000);
            }
        } while (self::$running);

        Logger::info(App::NAME . ' stopped');
        flock($lock, LOCK_UN);

        return self::EXIT_OK;
    }

    private static function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = static function (): void {
            self::$running = false;
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    private static function usage(): void
    {
        echo App::NAME . ' v' . App::version() . "\n\n"
           . "Usage: php bin/monitor.php [options]\n\n"
           . "  (no options)       run the monitor loop\n"
           . "  --once             refresh every dashboard once, then exit\n"
           . "  --validate         validate the config file only (no network access)\n"
           . "  --check            validate the config and test Telegram + every panel\n"
           . "  --test-message     like --check, and also post a test message to the chat\n"
           . "  --config=FILE      use FILE instead of config/config.php\n"
           . "  -v, --version      print the version\n"
           . "  -h, --help         show this help\n";
    }
}
