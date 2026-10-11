<?php
/**
 * Configuration loader.
 *
 * Loads config/config.php, merges it over the built-in defaults (so a config
 * file only has to contain what the user wants to change), clamps numeric
 * values to safe ranges and validates mandatory settings.
 */

declare(strict_types=1);

namespace SuiMonitor;

final class Config
{
    /** Built-in defaults. Every option documented in config.example.php lives here. */
    public const DEFAULTS = [
        'telegram' => [
            'bot_token' => '',
            'chat_id'   => '',
            'api_base'  => 'https://api.telegram.org',
            'proxy'     => '',
        ],
        'servers' => [],
        'display' => [
            'host_name'       => true,
            'ip_address'      => true,
            'panel_version'   => true,
            'core_status'     => true,
            'uptime'          => true,
            'cpu'             => true,
            'ram'             => true,
            'swap'            => true,
            'disk'            => true,
            'disk_io'         => true,
            'app_mem'         => true,
            'threads'         => true,
            'cpu_info'        => true,
            'db_size'         => true,
            'panel_stats'     => true,
            'clients_summary' => true,
            'online_count'    => true,
            'traffic_total'   => true,
            'top_users'       => true,
            'update_time'     => true,
        ],
        'top_users' => [
            'count'       => 5,
            'bar_width'   => 10,
            'sort_by'     => 'usage',
            'min_bytes'   => 0,
            'show_online' => true,
        ],
        'monitor' => [
            'interval'     => 15,
            'http_timeout' => 10,
            'max_retries'  => 2,
            'stats_cache'  => 15,
        ],
        'alerts' => [
            'enabled'                => true,
            'cpu_threshold'          => 85,
            'ram_threshold'          => 85,
            'disk_threshold'         => 90,
            'core_down'              => true,
            'offline'                => true,
            'offline_after_failures' => 3,
            'recovery_notice'        => true,
            'cooldown_seconds'       => 300,
        ],
        'storage' => [
            'dir' => '',
        ],
        'logging' => [
            'level'     => 'info',
            'max_bytes' => 5242880,
            'max_files' => 5,
            'console'   => true,
        ],
    ];

    /**
     * Load, merge, normalise and validate the configuration.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException when the file is missing/invalid (message lists every problem)
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Configuration file not found: {$path}\n"
                . "Copy config/config.example.php to config/config.php and edit it, "
                . "or run the installer: sui-monitor install"
            );
        }

        $user = (static function (string $file) {
            return require $file;
        })($path);

        if (!is_array($user)) {
            throw new \RuntimeException("Configuration file must return an array: {$path}");
        }

        $cfg    = array_replace_recursive(self::DEFAULTS, $user);
        $cfg    = self::normalise($cfg);
        $errors = self::validate($cfg);

        if ($errors) {
            throw new \RuntimeException(
                "Invalid configuration in {$path}:\n  - " . implode("\n  - ", $errors)
            );
        }

        return $cfg;
    }

    // ── Normalisation ──────────────────────────────────────

    /**
     * @param  array<string,mixed> $c
     * @return array<string,mixed>
     */
    private static function normalise(array $c): array
    {
        // Telegram
        $c['telegram']['bot_token'] = trim((string)$c['telegram']['bot_token']);
        $c['telegram']['chat_id']   = trim((string)$c['telegram']['chat_id']);
        $c['telegram']['api_base']  = rtrim(trim((string)$c['telegram']['api_base']), '/')
                                      ?: self::DEFAULTS['telegram']['api_base'];
        $c['telegram']['proxy']     = trim((string)$c['telegram']['proxy']);

        // Servers
        $servers = [];
        foreach ((array)$c['servers'] as $name => $srv) {
            if (!is_array($srv)) {
                continue;
            }
            $servers[(string)$name] = [
                'base_url'   => rtrim(trim((string)($srv['base_url'] ?? '')), '/'),
                'token'      => trim((string)($srv['token'] ?? '')),
                'verify_ssl' => (bool)($srv['verify_ssl'] ?? true),
            ];
        }
        $c['servers'] = $servers;

        // Display toggles are plain booleans
        foreach ($c['display'] as $k => $v) {
            $c['display'][$k] = (bool)$v;
        }

        // Top users
        $tu = &$c['top_users'];
        $tu['count']       = self::clampInt($tu['count'], 1, 20);
        $tu['bar_width']   = self::clampInt($tu['bar_width'], 5, 30);
        $tu['sort_by']     = in_array($tu['sort_by'], ['usage', 'percent', 'name'], true) ? $tu['sort_by'] : 'usage';
        $tu['min_bytes']   = max(0.0, (float)$tu['min_bytes']);
        $tu['show_online'] = (bool)$tu['show_online'];
        unset($tu);

        // Monitor behaviour
        $m = &$c['monitor'];
        $m['interval']     = self::clampInt($m['interval'], 3, 3600);
        $m['http_timeout'] = self::clampInt($m['http_timeout'], 3, 120);
        $m['max_retries']  = self::clampInt($m['max_retries'], 0, 5);
        $m['stats_cache']  = self::clampInt($m['stats_cache'], 0, 3600);
        unset($m);

        // Alerts
        $a = &$c['alerts'];
        $a['enabled']                = (bool)$a['enabled'];
        $a['cpu_threshold']          = self::clampFloat($a['cpu_threshold'], 0, 100);
        $a['ram_threshold']          = self::clampFloat($a['ram_threshold'], 0, 100);
        $a['disk_threshold']         = self::clampFloat($a['disk_threshold'], 0, 100);
        $a['core_down']              = (bool)$a['core_down'];
        $a['offline']                = (bool)$a['offline'];
        $a['offline_after_failures'] = self::clampInt($a['offline_after_failures'], 1, 100);
        $a['recovery_notice']        = (bool)$a['recovery_notice'];
        $a['cooldown_seconds']       = self::clampInt($a['cooldown_seconds'], 30, 86400);
        unset($a);

        // Storage: empty -> <project>/storage ; relative -> relative to project root
        $dir = trim((string)$c['storage']['dir']);
        if ($dir === '') {
            $dir = App::root() . '/storage';
        } elseif ($dir[0] !== '/') {
            $dir = App::root() . '/' . $dir;
        }
        $c['storage']['dir'] = rtrim($dir, '/');

        // Logging
        $l = &$c['logging'];
        $l['level']     = in_array($l['level'], ['debug', 'info', 'warning', 'error'], true) ? $l['level'] : 'info';
        $l['max_bytes'] = max(65536, (int)$l['max_bytes']);
        $l['max_files'] = self::clampInt($l['max_files'], 1, 50);
        $l['console']   = (bool)$l['console'];
        unset($l);

        return $c;
    }

    // ── Validation ─────────────────────────────────────────

    /**
     * @param  array<string,mixed> $c
     * @return list<string> human-readable problems (empty when valid)
     */
    private static function validate(array $c): array
    {
        $e = [];

        $token = $c['telegram']['bot_token'];
        if ($token === '' || self::isPlaceholder($token)) {
            $e[] = "telegram.bot_token is not set (create a bot with @BotFather).";
        } elseif (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            $e[] = "telegram.bot_token does not look like a Telegram bot token (expected 123456789:AA...).";
        }

        $chat = $c['telegram']['chat_id'];
        if ($chat === '' || self::isPlaceholder($chat)) {
            $e[] = "telegram.chat_id is not set (user ID, group ID like -100123..., or @channelusername).";
        } elseif (!preg_match('/^-?\d+$/', $chat) && !preg_match('/^@[A-Za-z0-9_]{4,}$/', $chat)) {
            $e[] = "telegram.chat_id must be a numeric ID (e.g. 123456789 or -1001234567890) or @channelusername.";
        }

        $proxy = $c['telegram']['proxy'];
        if ($proxy !== '' && !preg_match('#^(https?|socks4a?|socks5h?)://\S+$#i', $proxy)) {
            $e[] = "telegram.proxy must look like socks5h://127.0.0.1:1080 or http://127.0.0.1:8080.";
        }

        if (!preg_match('#^https?://\S+$#i', $c['telegram']['api_base'])) {
            $e[] = "telegram.api_base must be an http(s) URL.";
        }

        if (empty($c['servers'])) {
            $e[] = "servers: at least one S-UI server must be configured.";
        }

        foreach ($c['servers'] as $name => $srv) {
            if ($name === '' ) {
                $e[] = "servers: a server has an empty name.";
                continue;
            }
            $url = $srv['base_url'];
            if ($url === '' || self::isPlaceholder($url) || !filter_var($url, FILTER_VALIDATE_URL)
                || !preg_match('#^https?://#i', $url)) {
                $e[] = "servers['{$name}'].base_url must be a valid http(s) URL, e.g. https://panel.example.com:2095/app/apiv2";
            }
            if ($srv['token'] === '' || self::isPlaceholder($srv['token'])) {
                $e[] = "servers['{$name}'].token is not set (create an API token in the S-UI panel).";
            }
        }

        return $e;
    }

    /** True for values that were copied from config.example.php and never edited. */
    private static function isPlaceholder(string $v): bool
    {
        return str_starts_with($v, 'YOUR_') || str_contains($v, 'panel.example.com');
    }

    // ── Small helpers ──────────────────────────────────────

    /** @param mixed $v */
    private static function clampInt($v, int $min, int $max): int
    {
        return max($min, min($max, (int)$v));
    }

    /** @param mixed $v */
    private static function clampFloat($v, float $min, float $max): float
    {
        return max($min, min($max, (float)$v));
    }
}
