<div align="center">

# 📡 SUI-Monitoring-Panel

**Live Telegram dashboards for your [S-UI](https://github.com/alireza0/s-ui) panels.**
One auto-updating message per server — CPU, RAM, disk, traffic, clients, online users — plus instant alerts.

[![Version](https://img.shields.io/badge/version-1.0.0-blue?style=flat-square)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4?style=flat-square&logo=telegram&logoColor=white)](https://core.telegram.org/bots)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)
[![CI](https://img.shields.io/github/actions/workflow/status/Mr-Javadian/SUI-Monitoring-Panel/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/Mr-Javadian/SUI-Monitoring-Panel/actions)

**English** · [فارسی](README.fa.md)

</div>

---

## ✨ Why this project?

Checking several S-UI panels means opening several web UIs. **SUI-Monitoring-Panel** puts everything in one place — your Telegram chat. Each panel gets **one message that edits itself** every few seconds, so you always see the current state without scrolling through spam. When something goes wrong, a separate alert pings you.

| | |
|---|---|
| 🔄 **Self-updating message** | One message per server, edited in place (no chat flood). |
| 🖥️ **Multi-server** | Monitor as many S-UI panels as you like from a single bot. |
| 📊 **Rich dashboard** | CPU / RAM / swap / disk bars, disk I/O speed, uptime, IPs, core status, panel stats. |
| 👥 **Client insight** | Enabled / disabled / expired clients, online connections, unique online users, Top-N clients by traffic with usage bars. |
| 🚨 **Smart alerts** | High CPU / RAM / disk, core down, **server unreachable** (+ recovery notice), with per-alert cooldown. |
| 🧙 **One-command installer** | Interactive wizard: asks for your tokens, tests every connection, sets up a systemd service. |
| ⬆️ **Safe updates** | `sui-monitor update` keeps your config and **rolls back automatically** if the new version fails validation. |
| 🛡️ **Hardened** | Runs as an unprivileged user under a locked-down systemd unit; secrets never reach the logs. |
| 🌍 **Proxy-ready** | Built-in Telegram proxy support for servers where Telegram is blocked. |
| ⚙️ **Fully configurable** | Every line of the dashboard can be switched on/off in a heavily commented config file. |

## 👀 Preview

Exactly what lands in your chat (one message per server, refreshed every 15 s by default):

```text
🖥 S-UI · SERVER MONITORING
━━━━━━━━━━━━━━━━━━━━━━
🖧 Hostname: vps-de-01
📦 S-UI Version: v1.6.3
⚙️ Core: 🟢 Running
⏱️ Uptime: 3d 4h 12m 9s
🌐 NETWORK INTERFACES
├ IPv4 203.0.113.10
├ IPv6 2001:db8::1
└ IPv6 2001:db8::2
━━━━━━━━━━━━━━━━━━━━━━
📊 SYSTEM PERFORMANCE
━━━━━━━━━━━━━━━━━━━━━━
🧠 CPU ███████░░░░░░░░░░░░░ 37.4%
💾 RAM ██████░░░░░░░░░░░░░░ 30.0%
1.20 GB / 4.00 GB
🔁 SWAP █░░░░░░░░░░░░░░░░░░░ 4.9%
100.00 MB / 2.00 GB
💽 DISK ████████░░░░░░░░░░░░ 42.0%
21.00 GB / 50.00 GB
📀 DISK I/O
├ Read Speed:  1.42 MB/s
└ Write Speed: 312.50 KB/s
🧩 Panel Memory: 85.00 MB
🧵 Panel Threads: 17
🖥 CPU Model: Intel(R) Xeon(R) Gold 6130 @ 2.10GHz
🔢 vCPU Cores: 4
🗄 DB Size: 3.00 MB
━━━━━━━━━━━━━━━━━━━━━━
⚙️ S-UI PANEL OVERVIEW
━━━━━━━━━━━━━━━━━━━━━━
⬇️ Total Inbounds: 3
⬆️ Outbounds: 2
⏺️ Endpoints: 0
↔️ Services: 1
━━━━━━━━━━━━━━━━━━━━━━
📈 NETWORK TRAFFIC
━━━━━━━━━━━━━━━━━━━━━━
📤 Upload: 23.00 GB
📥 Download: 124.00 GB
↕️ Total Transferred: 147.00 GB
━━━━━━━━━━━━━━━━━━━━━━
👥 CLIENT OVERVIEW
━━━━━━━━━━━━━━━━━━━━━━
📋 Total Clients: 7
├ 🟢 Enabled: 5
├ 🔴 Disabled: 1
└ ⏳ Expired: 1
🔗 Online Connections: 3
🧑 Unique Online Users: 2
━━━━━━━━━━━━━━━━━━━━━━
🏆 TOP 5 CLIENTS · TRAFFIC USAGE
━━━━━━━━━━━━━━━━━━━━━━
👤1. alice · 🟢 Online
100.00 GB / 200 GB · 50.0%
█████░░░░░
👤2. bob · 🟢 Online
23.00 GB · unlimited
░░░░░░░░░░
👤3. carol-with-a-very… · ⚪️ Offline
10.00 GB / 10 GB · 100.0%
██████████
👤4. erin · ⚪️ Offline
10.00 GB / 100 GB · 10.0%
█░░░░░░░░░
👤5. frank · ⚪️ Offline
4.00 GB · unlimited
░░░░░░░░░░
━━━━━━━━━━━━━━━━━━━━━━
🔄 Last Updated: 2026-10-11 12:00:00
```

## 🚀 Quick install

Run as **root** on the server that will host the bot (it does *not* have to be the same machine as your panels):

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/Mr-Javadian/SUI-Monitoring-Panel/main/install.sh)
```

The wizard will:

1. install PHP 8.1+, git and curl if they are missing (apt / dnf / yum),
2. download the project to `/opt/sui-monitoring-panel`,
3. ask for your **Telegram bot token** and detect your **chat ID** automatically,
4. ask for each panel's **address** and **API token** — and test the connection right away,
5. ask a few basic options (refresh interval, alerts, number of top clients),
6. send a test message, install the `sui-monitor` command and start the systemd service.

> **Before you start** you need:
> 1. A bot token from [@BotFather](https://t.me/BotFather) (`/newbot`) — then press **Start** on your new bot.
> 2. An **API token** from each S-UI panel (create it in the panel's web UI).
> 3. The panel address, e.g. `https://panel.example.com:2095/app/`

### Requirements

| Requirement | Notes |
|---|---|
| Linux with **systemd** | Debian/Ubuntu, RHEL/Alma/Rocky/Fedora (without systemd you can still run it manually) |
| **PHP ≥ 8.1** | with `curl`, `mbstring`, `json` (the installer takes care of it) |
| Outbound HTTPS | to your panels and to `api.telegram.org` (or via `telegram.proxy`) |
| S-UI with the **v2 API** | S-UI exposes it at `<panel-path>apiv2` and authenticates with a `Token` header |

## 🔑 Panel address & API token

- **Address** — scheme + host + port + panel path. The default panel path is `/app/`, so the API lives at `/app/apiv2`.
  You can give the installer just `https://panel.example.com:2095/app/` — it appends `apiv2` for you.
  In `config.php` the full form is used: `https://panel.example.com:2095/app/apiv2`.
- **Token** — create an API token inside the S-UI panel and paste it when asked. The monitor only **reads** data.
- **Self-signed certificate?** Answer *n* to "Verify the TLS certificate?" (`verify_ssl => false`).

## 🛠️ Managing the bot

After installation, the `sui-monitor` command is available system-wide:

| Command | What it does |
|---|---|
| `sui-monitor` | Interactive menu |
| `sui-monitor status` | Service state + configured servers |
| `sui-monitor logs` | Follow the live log |
| `sui-monitor update` | Update to the newest version (config kept, auto-rollback on failure) |
| `sui-monitor add-server` | Add another panel (guided, with connection test) |
| `sui-monitor remove-server` | Remove a panel |
| `sui-monitor reconfigure` | Change the bot token / chat / basic settings |
| `sui-monitor edit` | Edit `config.php`, validate it, restart the service |
| `sui-monitor test` | Validate everything and send a test Telegram message |
| `sui-monitor start \| stop \| restart` | Control the service |
| `sui-monitor uninstall` | Remove everything (optionally keeps a config backup) |

### Updating

```bash
sudo sui-monitor update
```

The updater fetches the latest code, shows what changed, validates the new version against **your** config and restarts the service. If validation fails, it restores the previous version automatically. Your `config/config.php` and saved state are never touched (a timestamped backup of the config is kept, last 3).

## ⚙️ Configuration

Everything lives in **`config/config.php`** (created from [`config/config.example.php`](config/config.example.php)).
Each option is documented in the file itself — purpose, accepted values, default and an example — so you rarely need to look anywhere else.

| Section | What you control |
|---|---|
| `telegram` | Bot token, chat ID, optional proxy / custom Bot API URL |
| `servers` | Your panels: name, API address, token, TLS verification |
| `display` | Switch each dashboard line/section on or off |
| `top_users` | How many clients to rank, bar width, sort order, minimum usage, online badges |
| `monitor` | Refresh interval, HTTP timeout, retries, cache lifetime |
| `alerts` | Master switch, thresholds, core-down, unreachable/recovery, cooldown |
| `storage`, `logging` | Data folder, log level, rotation |

Edit the file and apply it:

```bash
sudo sui-monitor edit          # opens your editor, validates, offers to restart
# or manually:
sudo nano /opt/sui-monitoring-panel/config/config.php
sudo systemctl restart sui-monitor
```

Minimal hand-written config (everything else uses defaults):

```php
<?php
return [
    'telegram' => ['bot_token' => '123456789:AA...', 'chat_id' => -1001234567890],
    'servers'  => [
        'frankfurt' => ['base_url' => 'https://de.example.com:2095/app/apiv2', 'token' => '...'],
        'tokyo'     => ['base_url' => 'https://jp.example.com:2095/app/apiv2', 'token' => '...', 'verify_ssl' => false],
    ],
];
```

## 🚨 Alerts

Alerts are **separate messages** (so your phone actually notifies you), while the dashboard stays quiet.

| Alert | Trigger | Config |
|---|---|---|
| 🔥 High CPU | CPU ≥ threshold | `alerts.cpu_threshold` (default 85 %) |
| 🧠 High RAM | RAM ≥ threshold | `alerts.ram_threshold` (default 85 %) |
| 💿 Low disk | Disk ≥ threshold | `alerts.disk_threshold` (default 90 %) |
| 💀 Core down | sing-box stopped (and not in maintenance) | `alerts.core_down` |
| 📡 Server unreachable | N failed refreshes in a row, then `✅ RECOVERED` when it is back | `alerts.offline`, `offline_after_failures`, `recovery_notice` |

The same alert type for the same server is rate-limited by `alerts.cooldown_seconds` (default 5 min). Set a threshold to `0` to disable that alert only.

## 🧩 Manual installation (no installer)

```bash
sudo apt install -y git php-cli php-curl php-mbstring        # PHP 8.1+ required
git clone https://github.com/Mr-Javadian/SUI-Monitoring-Panel.git
cd SUI-Monitoring-Panel
cp config/config.example.php config/config.php && nano config/config.php
php bin/monitor.php --check          # validate config, test Telegram + every panel
php bin/monitor.php                  # run in the foreground (Ctrl+C to stop)
```

CLI options: `--check` · `--test-message` · `--validate` · `--once` · `--config=FILE` · `--version` · `--help`.

To keep it running, copy [`systemd/sui-monitor.service`](systemd/sui-monitor.service), replace the `@PLACEHOLDERS@`, then `systemctl enable --now sui-monitor`.

## 🩺 Troubleshooting

Run `sudo sui-monitor test` first — it tells you exactly which part fails.

| Symptom | Likely cause & fix |
|---|---|
| `Telegram: Unauthorized` | Wrong bot token. Copy it again from @BotFather. |
| `Could not post to chat` / `chat not found` | Press **Start** on the bot (private chat), or add it to the group/channel as **admin**. |
| Telegram unreachable / timeouts | Telegram is blocked on that server → set `telegram.proxy` (e.g. `socks5h://127.0.0.1:1080`). |
| Panel: `token rejected` / `Invalid token` | Create a new API token in the panel and update the server entry. |
| Panel: `HTTP 404` | Wrong panel path. Default is `/app/apiv2`; use the *Path* from the panel's settings. |
| Panel: cURL SSL error | Self-signed certificate → set `verify_ssl => false` for that server. |
| Dashboard stops updating | `sui-monitor logs`. Telegram limits edit frequency — raise `monitor.interval` when you monitor many servers. |
| Got a second dashboard message | Happens only if you deleted the old one or renamed the server (the name keys the stored message). |
| Service will not start | `journalctl -u sui-monitor -n 50`. Exit code 2 = invalid config (the message names the exact option). |

## 📁 Project structure

```text
SUI-Monitoring-Panel/
├── bin/monitor.php            # CLI entry point (run loop, --check, --once, ...)
├── src/                       # PSR-4 classes, namespace SuiMonitor\
│   ├── Cli.php                #   argument handling, main loop, locking, signals
│   ├── Config.php             #   load + defaults + clamping + validation
│   ├── ServerMonitor.php      #   per-server dashboard rendering, alerts, state
│   ├── SuiApi.php             #   S-UI v2 API client (retries, error reporting)
│   ├── TelegramBot.php        #   Telegram client (429 / not-modified / parse fallback)
│   ├── Formatter.php          #   bytes, rates, bars, uptime, escaping
│   ├── Logger.php             #   leveled, rotating logger
│   └── App.php, autoload.php
├── config/config.example.php  # fully documented configuration template
├── scripts/config-tool.php    # comment-preserving config editor used by the installer
├── systemd/sui-monitor.service
├── install.sh                 # installer / updater / manager  (-> `sui-monitor`)
├── tests/                     # mock S-UI + mock Telegram servers for local testing
├── VERSION · CHANGELOG.md · LICENSE · SECURITY.md · composer.json
└── .github/workflows/ci.yml   # PHP 8.1-8.4 lint + ShellCheck
```

## 🧪 Development

No dependencies, no build step. To try changes without real panels or a real bot:

```bash
php -S 127.0.0.1:18095 tests/mock_sui.php &        # fake S-UI panel   (token: testtoken)
php -S 127.0.0.1:18096 tests/mock_telegram.php &   # fake Telegram API
# config: base_url http://127.0.0.1:18095/app/apiv2, token testtoken,
#         telegram.api_base http://127.0.0.1:18096
php bin/monitor.php --once
```

Code style: PHP 8.1, `declare(strict_types=1)`, PSR-12-ish, English comments. CI lints on PHP 8.1–8.4 and runs ShellCheck.

## 🤝 Contributing

Issues and pull requests are welcome. Please keep the dashboard layout stable (it is part of the project's identity), add a line to the changelog, and avoid committing any real tokens.

## 📄 License

[MIT](LICENSE) © Mr-Javadian

<div align="center">

If this project saves you time, a ⭐ on GitHub is much appreciated.

</div>
