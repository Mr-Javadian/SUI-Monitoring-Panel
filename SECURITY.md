# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Use GitHub's
[private vulnerability reporting](https://github.com/Mr-Javadian/SUI-Monitoring-Panel/security/advisories/new)
instead and include steps to reproduce.

## Handling secrets

- `config/config.php` contains your Telegram bot token and panel API tokens. It is git-ignored and the installer stores it with mode `640` (root + service user only).
- If a token leaks, revoke it immediately: `/revoke` in @BotFather for the bot, and delete the token in the S-UI panel.
- The monitor only performs read requests against your panels.
