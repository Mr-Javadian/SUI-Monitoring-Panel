# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-11

First public release.

### Added
- Interactive installer / updater / manager (`install.sh`, installed as the `sui-monitor` command) with a setup wizard, automatic Telegram chat detection, panel connection tests, systemd service, and automatic rollback of failed updates.
- Fully documented `config/config.example.php`; every option has a safe default, so a config file only needs the values you want to change.
- Alerts for *server unreachable* (with a *recovered* notice) in addition to CPU / RAM / disk / core-down.
- Optional Telegram proxy (`telegram.proxy`) and custom Bot API base URL (`telegram.api_base`).
- `--check`, `--test-message`, `--validate` and `--once` command-line options.
- Single-instance lock, graceful shutdown on SIGINT/SIGTERM, atomic state-file writes.
- Display toggles for the CPU model / vCPU lines (`display.cpu_info`) and independent control of the client summary vs. online counters.

### Changed
- Project restructured into `bin/`, `src/` (PSR-4, namespace `SuiMonitor`), `config/`, `scripts/`, `systemd/`.
- The dashboard layout is unchanged from the previous private version.
- One `/status` request per refresh (previously two) and one online-users lookup per refresh (previously up to two).
- The "core down" alert is only evaluated when the panel actually reports the core state.

### Fixed
- A temporary Telegram/network error no longer causes a duplicate dashboard message to be posted; a new message is created only when Telegram confirms the old one is gone.
- Unbounded recursion on repeated HTTP 429 responses.
- The bot token can no longer leak into logs through cURL error text.
- Online-user fallback no longer counts the keys of the `/onlines` response as users.

### Removed
- Unused options: `display.cpu_history`, `display.ram_history`, `display.network`, `monitor.history_size` and `telegram.parse_mode` (the dashboard always uses HTML).
