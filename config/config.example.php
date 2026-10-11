<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║                    SUI-Monitoring-Panel — Configuration                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * HOW TO USE THIS FILE
 * --------------------
 *   1. The installer (`sui-monitor install`) creates config/config.php for you
 *      and fills in the essentials. To configure by hand instead:
 *          cp config/config.example.php config/config.php
 *   2. Edit config/config.php (NOT this example file).
 *   3. Validate it:        php bin/monitor.php --check
 *   4. Apply the changes:  sudo systemctl restart sui-monitor
 *
 * GOOD TO KNOW
 * ------------
 *   - Every option except the REQUIRED ones has a sensible default. If you
 *     delete a line, the default (shown in its comment) is used.
 *   - true / false are written WITHOUT quotes. Strings use 'single quotes'.
 *   - config/config.php holds secrets (bot token, API tokens). It is listed in
 *     .gitignore — NEVER commit it or share it publicly.
 *   - Lines starting with // (or the blocks like this one) are comments and are ignored.
 */

return [

    // ═════════════════════════════════════════════════════════════════════════
    //  1. TELEGRAM                                                  [REQUIRED]
    // ═════════════════════════════════════════════════════════════════════════
    'telegram' => [

        // Bot token issued by @BotFather (open Telegram → @BotFather → /newbot).
        //   Type    : string
        //   Example : '123456789:AAHkJ3x-EXAMPLE_TOKEN_abcdefghijklmn'
        'bot_token' => 'YOUR_BOT_TOKEN',

        // Where the dashboards are posted. One of:
        //   • your own user ID           → 123456789      (press Start on the bot first)
        //   • a group / supergroup ID    → -1001234567890 (add the bot to the group)
        //   • a channel                  → '@mychannel' or -1001234567890
        //                                  (the bot must be an ADMIN with "Post/Edit messages")
        // Tip: the installer can detect your chat ID automatically.
        //   Type    : int or string
        'chat_id'   => 'YOUR_CHAT_ID',

        // Base URL of the Telegram Bot API. Change this only if you run your own
        // Bot API server or a reverse proxy in front of Telegram.
        //   Default : 'https://api.telegram.org'
        'api_base'  => 'https://api.telegram.org',

        // Route Telegram traffic through a proxy. Useful on servers where
        // api.telegram.org is blocked. Leave '' for a direct connection.
        //   Allowed : 'http://host:port'  |  'socks5h://host:port'  |  'socks5h://user:pass@host:port'
        //   Default : ''
        'proxy'     => '',
    ],


    // ═════════════════════════════════════════════════════════════════════════
    //  2. S-UI PANELS                                               [REQUIRED]
    // ═════════════════════════════════════════════════════════════════════════
    // One entry per panel you want to monitor. Each panel gets its OWN Telegram
    // message that is edited in place on every refresh.
    //
    //   'a-name-you-choose' => [
    //       'base_url'   => API address of the panel (see below),
    //       'token'      => API token created inside the panel,
    //       'verify_ssl' => true | false,
    //   ],
    //
    // base_url
    //   Format  : <scheme>://<host>:<port><panel-path>apiv2      (no trailing slash)
    //   Default panel path is /app/, so a default install looks like:
    //       'http://203.0.113.10:2095/app/apiv2'
    //       'https://panel.example.com:2095/app/apiv2'
    //   If you changed the panel "Path" in Settings, use that instead of /app/.
    //
    // token
    //   Create it in the panel (API tokens / token management) and paste it here.
    //   The monitor only READS data, so an ordinary token is enough.
    //
    // verify_ssl
    //   true  (recommended) — reject invalid/expired HTTPS certificates.
    //   false               — accept self-signed certificates or plain IP + HTTPS.
    //   Has no effect for http:// URLs. Default: true
    //
    // The name (array key) is shown in alerts and used to remember each message.
    // Renaming a server makes the bot post a new message for it.
    'servers' => [
        // >>> SERVERS-BEGIN (the installer manages the block between these markers)
        'server-1' => [
            'base_url'   => 'https://panel.example.com:2095/app/apiv2',
            'token'      => 'YOUR_API_TOKEN',
            'verify_ssl' => true,
        ],
        // <<< SERVERS-END
    ],


    // ═════════════════════════════════════════════════════════════════════════
    //  3. WHAT THE DASHBOARD SHOWS
    // ═════════════════════════════════════════════════════════════════════════
    // true = show the line/section, false = hide it. Everything defaults to true.
    // Sections with nothing left to show disappear on their own.
    'display' => [

        // ── Header & network ─────────────────────────────────────────────────
        'host_name'       => true,   // Server hostname
        'panel_version'   => true,   // S-UI version (e.g. v1.6.3)
        'core_status'     => true,   // sing-box core: Running / Stopped / maintenance
        'uptime'          => true,   // Time since the server booted
        'ip_address'      => true,   // "NETWORK INTERFACES": public IPv4 / IPv6 addresses

        // ── System performance ───────────────────────────────────────────────
        'cpu'             => true,   // CPU usage bar
        'ram'             => true,   // RAM usage bar + used/total
        'swap'            => true,   // SWAP bar (only shown if the server has swap)
        'disk'            => true,   // Disk usage bar + used/total
        'disk_io'         => true,   // Disk read/write speed (per second)
        'app_mem'         => true,   // Memory used by the S-UI process itself
        'threads'         => true,   // Thread count of the S-UI process
        'cpu_info'        => true,   // CPU model + number of vCPU cores
        'db_size'         => true,   // Size of the panel database

        // ── Panel statistics ─────────────────────────────────────────────────
        'panel_stats'     => true,   // Number of inbounds / outbounds / endpoints / services
        'traffic_total'   => true,   // Total upload / download / transferred (all clients)
        'clients_summary' => true,   // Total / enabled / disabled / expired clients
        'online_count'    => true,   // Online connections + unique online users
        'top_users'       => true,   // "TOP N CLIENTS" ranking (see section 4)

        // ── Footer ───────────────────────────────────────────────────────────
        'update_time'     => true,   // "Last Updated" timestamp
    ],


    // ═════════════════════════════════════════════════════════════════════════
    //  4. TOP CLIENTS RANKING  (only used when display.top_users = true)
    // ═════════════════════════════════════════════════════════════════════════
    'top_users' => [

        // How many clients to list.
        //   Allowed : 1 – 20          Default : 5
        // (Telegram messages are limited to 4096 characters; if the dashboard
        //  gets too long the ranking is dropped automatically.)
        'count'       => 5,

        // Width of each client's usage bar, in characters.
        //   Allowed : 5 – 30          Default : 10
        'bar_width'   => 10,

        // How to order the ranking.
        //   'usage'   → most traffic used first
        //   'percent' → highest % of the client's quota used first
        //   'name'    → alphabetical
        //   Default : 'usage'
        'sort_by'     => 'usage',

        // Ignore clients that used less than this many BYTES. 0 = list everyone.
        //   Examples: 1073741824 = 1 GB,   104857600 = 100 MB
        //   Default : 0
        'min_bytes'   => 0,

        // Show 🟢 Online / ⚪️ Offline next to each client.
        //   Default : true
        'show_online' => true,
    ],


    // ═════════════════════════════════════════════════════════════════════════
    //  5. MONITOR BEHAVIOUR
    // ═════════════════════════════════════════════════════════════════════════
    'monitor' => [

        // Seconds between dashboard refreshes. Telegram limits how often a chat
        // can be edited, so keep (servers × 60 / interval) well under ~20 edits
        // per minute. 15 s is fine for up to 3–4 servers; use 30+ for more.
        //   Allowed : 3 – 3600        Default : 15
        'interval'     => 15,

        // Seconds to wait for a panel to answer one API request before giving up.
        //   Allowed : 3 – 120         Default : 10
        'http_timeout' => 10,

        // Extra attempts after a network error or HTTP 5xx from a panel.
        //   Allowed : 0 – 5           Default : 2
        'max_retries'  => 2,

        // Seconds to reuse client/inbound/outbound/endpoint/service lists before
        // asking the panel again. Lower = fresher numbers, more panel load.
        // 0 = fetch on every refresh.
        //   Allowed : 0 – 3600        Default : 15
        'stats_cache'  => 15,
    ],


    // ═════════════════════════════════════════════════════════════════════════
    //  6. ALERTS  (separate Telegram messages, in addition to the dashboard)
    // ═════════════════════════════════════════════════════════════════════════
    'alerts' => [

        // Master switch. false = never send alert messages.
        //   Default : true
        'enabled'                => true,

        // Alert when usage reaches this PERCENT. Use 0 to disable that alert only.
        //   Allowed : 0 – 100
        'cpu_threshold'          => 85,   // Default: 85
        'ram_threshold'          => 85,   // Default: 85
        'disk_threshold'         => 90,   // Default: 90

        // Alert when the sing-box core is stopped (and not in maintenance mode).
        //   Default : true
        'core_down'              => true,

        // Alert when a panel cannot be reached at all (server down, wrong token,
        // network problem...). Sent once, then a "RECOVERED" message follows.
        //   Default : true
        'offline'                => true,

        // How many refreshes in a row must fail before the offline alert is sent.
        // Avoids false alarms from one-off network blips.
        //   Allowed : 1 – 100         Default : 3
        'offline_after_failures' => 3,

        // Send a "RECOVERED" message when a panel that triggered an offline alert
        // answers again.
        //   Default : true
        'recovery_notice'        => true,

        // Minimum seconds between two alerts of the SAME kind for the same server.
        //   Allowed : 30 – 86400      Default : 300 (5 minutes)
        'cooldown_seconds'       => 300,
    ],


    // ═════════════════════════════════════════════════════════════════════════
    //  7. STORAGE & LOGGING  (usually left unchanged)
    // ═════════════════════════════════════════════════════════════════════════
    'storage' => [

        // Folder for logs, saved Telegram message IDs and the lock file.
        // '' = the "storage" folder inside the project. Relative paths are
        // resolved from the project root. Must be writable by the service user.
        //   Default : ''
        'dir' => '',
    ],

    'logging' => [

        // Minimum severity written to the log.
        //   'debug'   → everything (very verbose, for troubleshooting)
        //   'info'    → normal operation
        //   'warning' → problems only
        //   'error'   → failures only
        //   Default : 'info'
        'level'     => 'info',

        // Rotate app.log when it grows beyond this many bytes.
        //   Default : 5242880 (5 MB)
        'max_bytes' => 5242880,

        // How many rotated files to keep (app.log.1 … app.log.N).
        //   Allowed : 1 – 50          Default : 5
        'max_files' => 5,

        // Also print log lines to the console / systemd journal
        // (`journalctl -u sui-monitor -f`).
        //   Default : true
        'console'   => true,
    ],

];
