#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════════════
#  SUI-Monitoring-Panel — installer / updater / manager
#  https://github.com/Mr-Javadian/SUI-Monitoring-Panel
#
#  One-line install (as root):
#    bash <(curl -fsSL https://raw.githubusercontent.com/Mr-Javadian/SUI-Monitoring-Panel/main/install.sh)
#
#  After installation the same script is available as the `sui-monitor` command:
#    sui-monitor              interactive menu
#    sui-monitor install      (re)install and run the setup wizard
#    sui-monitor update       update to the latest version (config is kept)
#    sui-monitor status       service state + configured servers
#    sui-monitor logs         follow the live log
#    sui-monitor help         all commands
#
#  Environment overrides (rarely needed):
#    SUIMON_REPO    git URL to install from       SUIMON_BRANCH  branch (default: main)
#    SUIMON_DIR     install directory             SUIMON_TG_API  Telegram API base URL
# ══════════════════════════════════════════════════════════════════════════════

set -Eeuo pipefail

# ── Settings ──────────────────────────────────────────────────────────────────
REPO_URL="${SUIMON_REPO:-https://github.com/Mr-Javadian/SUI-Monitoring-Panel.git}"
BRANCH="${SUIMON_BRANCH:-main}"
INSTALL_DIR="${SUIMON_DIR:-/opt/sui-monitoring-panel}"
TG_API="${SUIMON_TG_API:-https://api.telegram.org}"

SERVICE_NAME="sui-monitor"
SERVICE_USER="suimon"
UNIT_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
BIN_LINK="/usr/local/bin/sui-monitor"
CONFIG="${INSTALL_DIR}/config/config.php"

TG_PROXY=""            # set by the wizard when Telegram needs a proxy
HAVE_SYSTEMD=0
[[ -d /run/systemd/system ]] && HAVE_SYSTEMD=1

# ── Pretty output ─────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    C_RED=$'\e[31m'; C_GRN=$'\e[32m'; C_YEL=$'\e[33m'; C_BLU=$'\e[34m'
    C_CYN=$'\e[36m'; C_BLD=$'\e[1m'; C_DIM=$'\e[2m'; C_OFF=$'\e[0m'
else
    C_RED=""; C_GRN=""; C_YEL=""; C_BLU=""; C_CYN=""; C_BLD=""; C_DIM=""; C_OFF=""
fi

info() { printf '%s[i]%s %s\n' "$C_BLU" "$C_OFF" "$*"; }
ok()   { printf '%s[✔]%s %s\n' "$C_GRN" "$C_OFF" "$*"; }
warn() { printf '%s[!]%s %s\n' "$C_YEL" "$C_OFF" "$*" >&2; }
err()  { printf '%s[✘]%s %s\n' "$C_RED" "$C_OFF" "$*" >&2; }
die()  { err "$*"; exit 1; }
step() { printf '\n%s━━ %s%s\n' "$C_CYN$C_BLD" "$*" "$C_OFF"; }

trap 'err "Unexpected error on line ${LINENO}. Nothing more was changed."' ERR

banner() {
    printf '%s' "$C_CYN"
    cat <<'ART'
   ____  _   _ ___   __  __             _ _             _
  / ___|| | | |_ _| |  \/  | ___  _ __ (_) |_ ___  _ __(_)_ __   __ _
  \___ \| | | || |  | |\/| |/ _ \| '_ \| | __/ _ \| '__| | '_ \ / _` |
   ___) | |_| || |  | |  | | (_) | | | | | || (_) | |  | | | | | (_| |
  |____/ \___/|___| |_|  |_|\___/|_| |_|_|\__\___/|_|  |_|_| |_|\__, |
                           P  A  N  E  L                        |___/
ART
    printf '%s' "$C_OFF"
    local v="not installed"
    [[ -f "${INSTALL_DIR}/VERSION" ]] && v="v$(<"${INSTALL_DIR}/VERSION")"
    printf '  %sLive Telegram dashboards for S-UI panels%s  ·  %s\n\n' "$C_DIM" "$C_OFF" "$v"
}

# ── Input helpers (read from the terminal even when the script is piped) ──────
if { true </dev/tty; } 2>/dev/null; then exec 3</dev/tty; else exec 3<&0; fi

# ask "Prompt" [default]  -> echoes the answer
ask() {
    local reply=""
    if [[ -n "${2:-}" ]]; then
        printf '%s %s[%s]%s: ' "$1" "$C_DIM" "$2" "$C_OFF" >&2
    else
        printf '%s: ' "$1" >&2
    fi
    read -r reply <&3 || true
    printf '%s' "${reply:-${2:-}}"
}

# ask_secret "Prompt"  -> echoes the answer (input hidden)
ask_secret() {
    local reply=""
    printf '%s: ' "$1" >&2
    read -rs reply <&3 || true
    printf '\n' >&2
    printf '%s' "$reply"
}

# confirm "Question" [Y|N default]  -> exit status 0 for yes
confirm() {
    local def="${2:-Y}" hint="Y/n" reply
    [[ "$def" == "N" ]] && hint="y/N"
    printf '%s %s[%s]%s: ' "$1" "$C_DIM" "$hint" "$C_OFF" >&2
    read -r reply <&3 || true
    reply="${reply:-$def}"
    [[ "$reply" =~ ^[Yy] ]]
}

pause() { printf '%sPress Enter to continue…%s' "$C_DIM" "$C_OFF" >&2; read -r _ <&3 || true; printf '\n' >&2; }

# ── Environment checks ────────────────────────────────────────────────────────
need_root() { [[ $EUID -eq 0 ]] || die "Please run as root (e.g. 'sudo -i' first, then run this command again)."; }

is_installed() { [[ -d "${INSTALL_DIR}/.git" && -f "${INSTALL_DIR}/bin/monitor.php" ]]; }
need_installed() { is_installed || die "SUI-Monitoring-Panel is not installed in ${INSTALL_DIR}. Run: $0 install"; }

pkg_manager() {
    if   command -v apt-get >/dev/null 2>&1; then echo apt
    elif command -v dnf     >/dev/null 2>&1; then echo dnf
    elif command -v yum     >/dev/null 2>&1; then echo yum
    else echo none; fi
}

php_version_ok() { command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; }

php_extensions_ok() {
    local m; m="$(php -m 2>/dev/null || true)"
    grep -qix 'curl' <<<"$m" && grep -qix 'mbstring' <<<"$m" && grep -qix 'json' <<<"$m"
}

php_ready() { php_version_ok && php_extensions_ok; }

install_dependencies() {
    step "Checking dependencies (PHP 8.1+, curl, mbstring, git)"

    if php_ready && command -v git >/dev/null 2>&1 && command -v curl >/dev/null 2>&1; then
        ok "All dependencies are already installed (PHP $(php -r 'echo PHP_VERSION;'))."
        return
    fi

    local pm; pm="$(pkg_manager)"
    case "$pm" in
        apt)
            export DEBIAN_FRONTEND=noninteractive
            info "Installing packages with apt…"
            apt-get update -qq
            apt-get install -y -qq git curl ca-certificates util-linux php-cli php-curl php-mbstring >/dev/null
            if ! php_version_ok; then
                if [[ -r /etc/os-release ]] && grep -qi '^ID=ubuntu' /etc/os-release; then
                    warn "The distribution's PHP is too old (need 8.1+)."
                    if confirm "Add the trusted 'ppa:ondrej/php' repository and install PHP 8.2?" Y; then
                        apt-get install -y -qq software-properties-common >/dev/null
                        add-apt-repository -y ppa:ondrej/php >/dev/null
                        apt-get update -qq
                        apt-get install -y -qq php8.2-cli php8.2-curl php8.2-mbstring >/dev/null
                        update-alternatives --set php /usr/bin/php8.2 >/dev/null 2>&1 || true
                    fi
                else
                    die "PHP 8.1+ is required but only an older version is available. On Debian, add the Sury repository (https://packages.sury.org/php/) and run this installer again."
                fi
            fi
            ;;
        dnf|yum)
            info "Installing packages with ${pm}…"
            "$pm" install -y -q git curl ca-certificates util-linux php-cli php-common php-mbstring >/dev/null
            ;;
        *)
            die "Unsupported system: no apt, dnf or yum found. Install PHP 8.1+ (curl, mbstring, json), git and curl manually, then run this installer again."
            ;;
    esac

    php_version_ok    || die "PHP 8.1 or newer is required (found: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo none))."
    php_extensions_ok || die "A required PHP extension is missing. Needed: curl, mbstring, json."
    ok "Dependencies ready (PHP $(php -r 'echo PHP_VERSION;'))."
}

# ── System user, code, permissions, systemd ───────────────────────────────────
ensure_service_user() {
    if id "$SERVICE_USER" >/dev/null 2>&1; then
        return
    fi
    info "Creating system user '${SERVICE_USER}' (no login, no home)…"
    local nologin; nologin="$(command -v nologin || echo /usr/sbin/nologin)"
    useradd --system --no-create-home --shell "$nologin" "$SERVICE_USER"
}

fetch_code() {
    step "Downloading SUI-Monitoring-Panel (${BRANCH})"
    if [[ -d "${INSTALL_DIR}/.git" ]]; then
        info "Existing installation found, refreshing it…"
        git -C "$INSTALL_DIR" fetch --quiet --depth 1 origin "$BRANCH"
        git -C "$INSTALL_DIR" reset --quiet --hard FETCH_HEAD
    else
        if [[ -e "$INSTALL_DIR" && -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]]; then
            die "${INSTALL_DIR} exists and is not empty. Remove it or set SUIMON_DIR to another path."
        fi
        mkdir -p "$(dirname "$INSTALL_DIR")"
        git clone --quiet --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
    fi
    ok "Code ready: v$(<"${INSTALL_DIR}/VERSION")"
}

apply_permissions() {
    chown -R root:root "$INSTALL_DIR"
    chmod 755 "$INSTALL_DIR/bin/monitor.php" "$INSTALL_DIR/install.sh"
    mkdir -p "${INSTALL_DIR}/storage"
    chown -R "${SERVICE_USER}:${SERVICE_USER}" "${INSTALL_DIR}/storage"
    chmod 750 "${INSTALL_DIR}/storage"
    if [[ -f "$CONFIG" ]]; then
        # Contains secrets: readable by root and the service user only.
        chown root:"$SERVICE_USER" "$CONFIG"
        chmod 640 "$CONFIG"
    fi
}

install_command_link() {
    ln -sf "${INSTALL_DIR}/install.sh" "$BIN_LINK"
    ok "Management command installed: ${C_BLD}sui-monitor${C_OFF}"
}

write_unit() {
    [[ $HAVE_SYSTEMD -eq 1 ]] || return 0
    local php_bin; php_bin="$(command -v php)"
    sed -e "s|@INSTALL_DIR@|${INSTALL_DIR}|g" \
        -e "s|@PHP_BIN@|${php_bin}|g" \
        -e "s|@USER@|${SERVICE_USER}|g" \
        "${INSTALL_DIR}/systemd/sui-monitor.service" > "$UNIT_FILE"
    systemctl daemon-reload
}

# Run a command as the unprivileged service user.
as_service_user() {
    if command -v runuser >/dev/null 2>&1; then
        runuser -u "$SERVICE_USER" -- "$@"
    else
        su -s /bin/sh "$SERVICE_USER" -c "$(printf '%q ' "$@")"
    fi
}

service_cmd() {   # service_cmd start|stop|restart|enable|disable
    if [[ $HAVE_SYSTEMD -eq 1 ]]; then
        systemctl "$1" "$SERVICE_NAME"
    else
        warn "systemd not available — run manually: php ${INSTALL_DIR}/bin/monitor.php"
    fi
}

# ── config-tool wrapper ───────────────────────────────────────────────────────
ct() { php "${INSTALL_DIR}/scripts/config-tool.php" --file="$CONFIG" "$@"; }

# ── Telegram helpers ──────────────────────────────────────────────────────────
tg_get_me() {   # tg_get_me TOKEN -> prints response body
    local args=(-sS -m 15)
    [[ -n "$TG_PROXY" ]] && args+=(--proxy "$TG_PROXY")
    curl "${args[@]}" "${TG_API}/bot${1}/getMe" 2>&1 || true
}

# ── Wizard: Telegram ──────────────────────────────────────────────────────────
wizard_telegram() {
    step "Telegram bot"
    cat <<EOT
  1. Open Telegram, talk to ${C_BLD}@BotFather${C_OFF}, send /newbot and copy the token it gives you.
  2. Press ${C_BLD}Start${C_OFF} on your new bot (or add it to your group/channel as admin).

EOT
    local token="" body

    while true; do
        token="$(ask_secret 'Bot token (input hidden)')"
        if [[ ! "$token" =~ ^[0-9]{5,}:[A-Za-z0-9_-]{20,}$ ]]; then
            warn "That does not look like a bot token (expected 123456789:AA…). Try again."
            continue
        fi
        info "Verifying the token with Telegram…"
        body="$(tg_get_me "$token")"
        if grep -q '"ok":true' <<<"$body"; then
            ok "Token accepted: @$(grep -o '"username":"[^"]*"' <<<"$body" | head -1 | cut -d'"' -f4)"
            break
        fi
        warn "Telegram did not accept it: ${body:0:140}"
        if [[ -z "$TG_PROXY" ]] && confirm "Is Telegram blocked on this server (do you need a proxy)?" N; then
            TG_PROXY="$(ask 'Proxy URL (e.g. socks5h://127.0.0.1:1080)')"
            continue
        fi
        confirm "Try another token?" Y || die "Cancelled."
    done

    ct set telegram bot_token string "$token"
    if [[ -n "$TG_PROXY" ]]; then
        ct set telegram proxy string "$TG_PROXY"
    fi
    if [[ "$TG_API" != "https://api.telegram.org" ]]; then
        ct set telegram api_base string "$TG_API"
    fi

    # ── chat id ──
    local chat=""
    echo
    echo "  Where should the dashboards be posted?"
    echo "    1) Detect automatically (recommended)"
    echo "    2) Enter the chat ID / @channel manually"
    local choice; choice="$(ask 'Choose' 1)"

    if [[ "$choice" == "1" ]]; then
        local tries=0 found
        while [[ -z "$chat" && $tries -lt 3 ]]; do
            tries=$((tries + 1))
            echo
            info "Now send ${C_BLD}/start${C_OFF} to your bot (or write any message in the group/channel where it is a member), then press Enter."
            pause
            found="$(php "${INSTALL_DIR}/scripts/config-tool.php" detect-chat "$token" "$TG_API" "$TG_PROXY" 2>&1 || true)"
            if [[ -z "$found" || "$found" == config-tool:* ]]; then
                warn "No chats found yet. ${found}"
                continue
            fi
            local -a ids=() lines=()
            while IFS='|' read -r cid ctype ctitle; do
                [[ -z "$cid" ]] && continue
                ids+=("$cid"); lines+=("${ctype}: ${ctitle:-<no name>}  (id ${cid})")
            done <<<"$found"
            local i
            for i in "${!ids[@]}"; do printf '    %d) %s\n' "$((i + 1))" "${lines[$i]}"; done
            local pick; pick="$(ask 'Use which chat?' 1)"
            if [[ "$pick" =~ ^[0-9]+$ ]] && (( pick >= 1 && pick <= ${#ids[@]} )); then
                chat="${ids[$((pick - 1))]}"
            fi
        done
        [[ -z "$chat" ]] && warn "Could not detect a chat automatically — please enter it manually."
    fi

    while [[ -z "$chat" ]]; do
        chat="$(ask 'Chat ID (e.g. 123456789, -1001234567890) or @channelusername')"
        if [[ ! "$chat" =~ ^-?[0-9]+$ && ! "$chat" =~ ^@[A-Za-z0-9_]{4,}$ ]]; then
            warn "Invalid chat ID."; chat=""
        fi
    done
    ct set telegram chat_id auto "$chat"
    ok "Chat saved: ${chat}"
}

# ── Wizard: servers ───────────────────────────────────────────────────────────
# Turn whatever the user typed into the API base URL.
normalize_panel_url() {
    local u="$1"
    u="${u//[[:space:]]/}"
    while [[ "$u" == */ ]]; do u="${u%/}"; done
    local rest="${u#*://}"
    if [[ "$rest" != */* ]]; then
        u="${u}/app/apiv2"          # only host:port given -> default panel path
    elif [[ "$u" != */apiv2 ]]; then
        u="${u}/apiv2"              # panel path given -> add the API suffix
    fi
    printf '%s' "$u"
}

PANEL_ERR=""
test_panel() {   # test_panel URL TOKEN VERIFY(1|0)
    local args=(-sS -m 12 -w '\n%{http_code}' -H "Token: $2")
    [[ "$3" == "0" ]] && args+=(-k)
    local out code body
    if ! out="$(curl "${args[@]}" "$1/status?r=cpu" 2>&1)"; then
        PANEL_ERR="$out"; return 1
    fi
    code="${out##*$'\n'}"; body="${out%$'\n'*}"
    if [[ "$code" == "200" ]] && grep -q '"success":true' <<<"$body"; then
        return 0
    fi
    case "$code" in
        200)     if grep -q '"success":false' <<<"$body"; then
                     PANEL_ERR="the panel refused the request: $(grep -o '"msg":"[^"]*"' <<<"$body" | head -1 | cut -d'"' -f4) (check the API token)"
                 else
                     PANEL_ERR="HTTP 200 but the response is not the S-UI API (wrong panel path?)"
                 fi ;;
        401|403) PANEL_ERR="HTTP ${code}: token rejected" ;;
        404)     PANEL_ERR="HTTP 404: wrong panel path (default is /app/apiv2)" ;;
        *)       PANEL_ERR="HTTP ${code}: ${body:0:100}" ;;
    esac
    return 1
}

# add_one_server  -> 0 when a server was added
add_one_server() {
    local default_name="${1:-server-1}" name url token verify=1

    while true; do
        name="$(ask 'Name for this server (shown in the dashboard/alerts)' "$default_name")"
        [[ "$name" =~ ^[A-Za-z0-9._\ -]+$ ]] && break
        warn "Use letters, digits, space, dot, dash or underscore only."
    done

    while true; do
        url="$(ask 'Panel address, e.g. https://panel.example.com:2095/app/')"
        if [[ ! "$url" =~ ^https?:// ]]; then warn "The address must start with http:// or https://"; continue; fi
        url="$(normalize_panel_url "$url")"
        info "API address: ${C_BLD}${url}${C_OFF}"
        break
    done

    token="$(ask_secret 'API token from the panel (input hidden)')"
    [[ -n "$token" ]] || { warn "Empty token — skipped."; return 1; }

    if [[ "$url" == https://* ]]; then
        confirm "Verify the TLS certificate? (answer 'n' for self-signed certificates)" Y || verify=0
    fi

    info "Testing the connection…"
    if test_panel "$url" "$token" "$verify"; then
        ok "Panel '${name}' answered."
    else
        warn "Connection test failed: ${PANEL_ERR}"
        confirm "Save this server anyway?" N || return 1
    fi

    if ! ct add-server "$name" "$url" "$token" "$verify"; then
        warn "Could not save '${name}'."
        return 1
    fi
    ok "Server '${name}' saved."
}

wizard_servers() {   # wizard_servers fresh|append
    step "S-UI panels"
    cat <<EOT
  You need an ${C_BLD}API token${C_OFF} from each panel (create one in the S-UI web UI) and its address,
  e.g. ${C_DIM}https://panel.example.com:2095/app/${C_OFF}  (default panel path is /app/).

EOT
    local n=1
    if [[ "${1:-fresh}" == "fresh" ]]; then
        ct reset-servers
    else
        n=$(( $(ct list-servers | wc -l) + 1 ))
    fi
    while true; do
        if add_one_server "server-${n}"; then n=$((n + 1)); fi
        if ! confirm "Add another server?" N; then break; fi
    done
    local count; count="$(ct list-servers | wc -l)"
    [[ "$count" -ge 1 ]] || die "At least one server is required."
    ok "${count} server(s) configured."
}

# ── Wizard: options ───────────────────────────────────────────────────────────
wizard_options() {
    step "Basic settings"
    local v

    while true; do
        v="$(ask 'Refresh interval in seconds (3-3600; 15 is good for up to 3-4 servers)' 15)"
        [[ "$v" =~ ^[0-9]+$ ]] && (( v >= 3 && v <= 3600 )) && break
        warn "Enter a number between 3 and 3600."
    done
    ct set monitor interval int "$v"

    while true; do
        v="$(ask 'How many top clients to list (1-20)' 5)"
        [[ "$v" =~ ^[0-9]+$ ]] && (( v >= 1 && v <= 20 )) && break
        warn "Enter a number between 1 and 20."
    done
    ct set top_users count int "$v"

    if confirm "Send alerts (high CPU/RAM/disk, core down, server unreachable)?" Y; then
        ct set alerts enabled bool true
        if confirm "Customize alert thresholds? (defaults: CPU 85%, RAM 85%, disk 90%)" N; then
            local key def
            for key in cpu ram disk; do
                def=85; [[ "$key" == "disk" ]] && def=90
                while true; do
                    v="$(ask "  ${key^^} threshold in % (0 = disable this alert)" "$def")"
                    [[ "$v" =~ ^[0-9]+$ ]] && (( v <= 100 )) && break
                    warn "Enter a number between 0 and 100."
                done
                ct set alerts "${key}_threshold" float "$v"
            done
        fi
    else
        ct set alerts enabled bool false
    fi
    ok "Settings saved. (Every other option can be tuned in ${CONFIG})"
}

# ── Verification ──────────────────────────────────────────────────────────────
verify_setup() {
    step "Verifying the configuration"
    apply_permissions
    if ! as_service_user php "${INSTALL_DIR}/bin/monitor.php" --test-message; then
        warn "Some checks failed (see above)."
        confirm "Continue anyway?" N || die "Stopped. Fix the problem with '${0##*/} reconfigure' or '${0##*/} add-server', then try again."
    fi
}

# ══════════════════════════════════════════════════════════════════════════════
#  Commands
# ══════════════════════════════════════════════════════════════════════════════

cmd_install() {
    need_root
    banner

    local keep_config=0
    if [[ -f "$CONFIG" ]]; then
        warn "An existing configuration was found: ${CONFIG}"
        if confirm "Keep it (reinstall the code only)?" Y; then keep_config=1; fi
    fi

    install_dependencies
    ensure_service_user
    fetch_code

    if [[ $keep_config -eq 0 ]]; then
        [[ -f "$CONFIG" ]] && cp -p "$CONFIG" "${CONFIG}.bak-$(date +%Y%m%d-%H%M%S)"
        cp "${INSTALL_DIR}/config/config.example.php" "$CONFIG"
        chmod 600 "$CONFIG"
        wizard_telegram
        wizard_servers fresh
        wizard_options
    fi

    verify_setup
    write_unit
    install_command_link

    if [[ $HAVE_SYSTEMD -eq 1 ]]; then
        step "Starting the service"
        systemctl enable "$SERVICE_NAME" >/dev/null 2>&1
        systemctl restart "$SERVICE_NAME"
        sleep 2
        if systemctl is-active --quiet "$SERVICE_NAME"; then
            ok "Service is running."
        else
            warn "The service did not start. Inspect: journalctl -u ${SERVICE_NAME} -n 50"
        fi
    fi

    cat <<EOT

${C_GRN}${C_BLD}Installation complete!${C_OFF}  Your dashboards should appear in Telegram within seconds.

  ${C_BLD}sui-monitor${C_OFF}             management menu
  ${C_BLD}sui-monitor status${C_OFF}      service state
  ${C_BLD}sui-monitor logs${C_OFF}        live log
  ${C_BLD}sui-monitor update${C_OFF}      update to the newest version
  Config file:           ${CONFIG}   (edit, then: sui-monitor restart)

EOT
}

cmd_update() {
    need_root
    need_installed

    # The updater replaces this very script on disk; re-run from a temporary copy first.
    if [[ -z "${SUIMON_REEXEC:-}" ]]; then
        local tmp; tmp="$(mktemp /tmp/suimon-update.XXXXXX)"
        cp "$0" "$tmp"
        SUIMON_REEXEC=1 exec bash "$tmp" update "$@"
    fi
    trap 'rm -f "$0"' EXIT

    local force=0 assume_yes=0 a
    for a in "$@"; do
        case "$a" in --force) force=1 ;; -y|--yes) assume_yes=1 ;; esac
    done

    banner
    step "Checking for updates (branch ${BRANCH})"
    local current prev remote remote_ver
    current="$(<"${INSTALL_DIR}/VERSION")"
    prev="$(git -C "$INSTALL_DIR" rev-parse HEAD)"

    git -C "$INSTALL_DIR" fetch --quiet --depth 1 origin "$BRANCH" || die "Cannot reach the repository (${REPO_URL})."
    remote="$(git -C "$INSTALL_DIR" rev-parse FETCH_HEAD)"
    remote_ver="$(git -C "$INSTALL_DIR" show FETCH_HEAD:VERSION 2>/dev/null || echo '?')"

    if [[ "$prev" == "$remote" && $force -eq 0 ]]; then
        ok "Already up to date (v${current})."
        return 0
    fi
    info "Installed: v${current}   →   Available: v${remote_ver//$'\n'/}"

    if [[ -n "$(git -C "$INSTALL_DIR" status --porcelain --untracked-files=no)" ]]; then
        warn "Local modifications to tracked project files were found; they will be overwritten."
        warn "(Your config/config.php and storage/ are NOT affected.)"
        [[ $assume_yes -eq 1 ]] || confirm "Continue?" Y || die "Update cancelled."
    fi

    local backup=""
    if [[ -f "$CONFIG" ]]; then
        backup="${CONFIG}.bak-$(date +%Y%m%d-%H%M%S)"
        cp -p "$CONFIG" "$backup"
        info "Config backup: ${backup}"
        # Keep only the three most recent backups (they contain secrets).
        # shellcheck disable=SC2012
        ls -1t "${CONFIG}".bak-* 2>/dev/null | tail -n +4 | xargs -r rm -f
    fi

    step "Applying update"
    git -C "$INSTALL_DIR" reset --quiet --hard FETCH_HEAD

    local failed=0
    install_dependencies || failed=1
    if [[ $failed -eq 0 ]]; then
        local f
        while IFS= read -r f; do
            php -l "$f" >/dev/null 2>&1 || { err "Syntax error in ${f}"; failed=1; }
        done < <(find "${INSTALL_DIR}/src" "${INSTALL_DIR}/bin" "${INSTALL_DIR}/scripts" -name '*.php')
    fi
    if [[ $failed -eq 0 && -f "$CONFIG" ]]; then
        apply_permissions
        as_service_user php "${INSTALL_DIR}/bin/monitor.php" --validate >/dev/null || failed=1
    fi

    if [[ $failed -ne 0 ]]; then
        err "The new version failed validation — rolling back."
        git -C "$INSTALL_DIR" reset --quiet --hard "$prev"
        apply_permissions
        write_unit
        [[ $HAVE_SYSTEMD -eq 1 ]] && systemctl restart "$SERVICE_NAME" || true
        die "Rolled back to v${current}. Your configuration was not modified."
    fi

    apply_permissions
    write_unit
    install_command_link
    if [[ $HAVE_SYSTEMD -eq 1 ]]; then
        systemctl enable "$SERVICE_NAME" >/dev/null 2>&1 || true
        systemctl restart "$SERVICE_NAME"
    fi

    ok "Updated to v$(<"${INSTALL_DIR}/VERSION")."
    if [[ -f "${INSTALL_DIR}/CHANGELOG.md" ]]; then
        echo; echo "${C_BLD}What's new:${C_OFF}"
        awk '/^## \[/{n++} n==1{print "  " $0} n>1{exit}' "${INSTALL_DIR}/CHANGELOG.md"
    fi
}

cmd_reconfigure() {
    need_root; need_installed
    [[ -f "$CONFIG" ]] || cp "${INSTALL_DIR}/config/config.example.php" "$CONFIG"
    wizard_telegram
    wizard_options
    verify_setup
    service_cmd restart
    ok "Done."
}

cmd_add_server() {
    need_root; need_installed
    wizard_servers append
    apply_permissions
    service_cmd restart
}

cmd_remove_server() {
    need_root; need_installed
    local -a names=()
    mapfile -t names < <(ct list-servers)
    [[ ${#names[@]} -gt 1 ]] || die "Only one server is configured; at least one is required."
    echo "Configured servers:"
    local i
    for i in "${!names[@]}"; do printf '  %d) %s\n' "$((i + 1))" "${names[$i]}"; done
    local pick; pick="$(ask 'Remove which one? (number, empty = cancel)')"
    [[ "$pick" =~ ^[0-9]+$ ]] && (( pick >= 1 && pick <= ${#names[@]} )) || { info "Cancelled."; return 0; }
    local name="${names[$((pick - 1))]}"
    confirm "Remove '${name}'? (its Telegram message stays in the chat; delete it manually)" N || return 0
    ct remove-server "$name"
    apply_permissions
    service_cmd restart
    ok "Removed '${name}'."
}

cmd_edit() {
    need_root; need_installed
    "${EDITOR:-$(command -v nano || command -v vi)}" "$CONFIG"
    apply_permissions
    if as_service_user php "${INSTALL_DIR}/bin/monitor.php" --validate; then
        confirm "Restart the service to apply the changes?" Y && service_cmd restart
    else
        err "The configuration is invalid — the service was NOT restarted. Run 'sui-monitor edit' to fix it."
    fi
}

cmd_status() {
    need_installed
    echo "${C_BLD}SUI-Monitoring-Panel${C_OFF} v$(<"${INSTALL_DIR}/VERSION")   (${INSTALL_DIR})"
    if [[ $HAVE_SYSTEMD -eq 1 ]]; then
        local state; state="$(systemctl is-active "$SERVICE_NAME" 2>/dev/null || true)"
        local en;    en="$(systemctl is-enabled "$SERVICE_NAME" 2>/dev/null || true)"
        echo "Service : ${state:-unknown} (${en:-not enabled})"
    fi
    if [[ -f "$CONFIG" ]]; then
        echo "Servers :"
        ct list-servers | sed 's/^/  - /'
    fi
    [[ $HAVE_SYSTEMD -eq 1 ]] && systemctl --no-pager --lines=5 status "$SERVICE_NAME" 2>/dev/null | sed -n '1,12p' || true
}

cmd_logs() {
    need_installed
    if [[ $HAVE_SYSTEMD -eq 1 ]] && command -v journalctl >/dev/null 2>&1; then
        exec journalctl -u "$SERVICE_NAME" -n 100 -f --no-pager
    fi
    exec tail -n 100 -f "${INSTALL_DIR}/storage/logs/app.log"
}

cmd_test() {
    need_root; need_installed
    as_service_user php "${INSTALL_DIR}/bin/monitor.php" --test-message
}

cmd_uninstall() {
    need_root
    is_installed || die "Nothing to uninstall (${INSTALL_DIR} not found)."
    warn "This stops the service and removes ${INSTALL_DIR}."
    confirm "Continue?" N || { info "Cancelled."; return 0; }

    if [[ $HAVE_SYSTEMD -eq 1 ]]; then
        systemctl disable --now "$SERVICE_NAME" >/dev/null 2>&1 || true
        rm -f "$UNIT_FILE"
        systemctl daemon-reload
    fi

    if [[ -f "$CONFIG" ]] && ! confirm "Also delete your configuration (tokens)?" N; then
        local keep
        keep="/root/sui-monitoring-panel.config.$(date +%Y%m%d-%H%M%S).php"
        cp -p "$CONFIG" "$keep"
        info "Configuration saved to ${keep}"
    fi

    rm -f "$BIN_LINK"
    rm -rf "$INSTALL_DIR"
    if id "$SERVICE_USER" >/dev/null 2>&1 && confirm "Remove the system user '${SERVICE_USER}'?" Y; then
        userdel "$SERVICE_USER" 2>/dev/null || true
    fi
    ok "Uninstalled. (The Telegram dashboard messages remain in your chat; delete them manually if you wish.)"
}

cmd_version() {
    if is_installed; then
        echo "SUI-Monitoring-Panel v$(<"${INSTALL_DIR}/VERSION")"
    else
        echo "SUI-Monitoring-Panel is not installed (installer script, branch ${BRANCH})"
    fi
}

cmd_help() {
    cat <<EOT
${C_BLD}sui-monitor${C_OFF} — SUI-Monitoring-Panel manager

  install            install (or reinstall) and run the setup wizard
  update [--force]   update to the latest version; config and state are kept,
                     and a failed update is rolled back automatically
  reconfigure        change the Telegram bot / chat / basic settings
  add-server         add another S-UI panel
  remove-server      remove a panel
  edit               open the config file in your editor, validate, restart
  test               validate everything and send a test Telegram message
  status             service state and configured servers
  logs               follow the live log
  start|stop|restart control the service
  uninstall          remove the service and files
  version            print the installed version
  menu               interactive menu (default when run without arguments)
EOT
}

cmd_menu() {
    while true; do
        clear 2>/dev/null || true
        banner
        if is_installed; then
            echo "   1) Update"
            echo "   2) Status"
            echo "   3) Live logs"
            echo "   4) Restart service"
            echo "   5) Add a server"
            echo "   6) Remove a server"
            echo "   7) Reconfigure Telegram / settings"
            echo "   8) Edit the config file"
            echo "   9) Send a test message"
            echo "  10) Reinstall"
            echo "  11) Uninstall"
        else
            echo "   1) Install"
        fi
        echo "   0) Exit"
        echo
        local c; c="$(ask 'Choose')"
        if is_installed; then
            case "$c" in
                1) cmd_update ;;      2) cmd_status ;;        3) cmd_logs ;;
                4) service_cmd restart ;; 5) cmd_add_server ;; 6) cmd_remove_server ;;
                7) cmd_reconfigure ;; 8) cmd_edit ;;          9) cmd_test ;;
                10) cmd_install ;;    11) cmd_uninstall ;;    0) exit 0 ;;
                *) warn "Unknown option." ;;
            esac
        else
            case "$c" in 1) cmd_install ;; 0) exit 0 ;; *) warn "Unknown option." ;; esac
        fi
        pause
    done
}

# ── Dispatcher ────────────────────────────────────────────────────────────────
main() {
    local cmd="${1:-menu}"
    shift || true
    case "$cmd" in
        install)              cmd_install "$@" ;;
        update|upgrade)       cmd_update "$@" ;;
        reconfigure|config)   cmd_reconfigure ;;
        add-server)           cmd_add_server ;;
        remove-server)        cmd_remove_server ;;
        edit)                 cmd_edit ;;
        test)                 cmd_test ;;
        status)               cmd_status ;;
        logs|log)             cmd_logs ;;
        start|stop|restart)   need_root; need_installed; service_cmd "$cmd"; ok "${cmd}: done." ;;
        uninstall|remove)     cmd_uninstall ;;
        version|-v|--version) cmd_version ;;
        menu)                 need_root; cmd_menu ;;
        help|-h|--help)       cmd_help ;;
        *)                    err "Unknown command: ${cmd}"; cmd_help; exit 64 ;;
    esac
}

main "$@"
