<?php
/**
 * Per-server monitor.
 *
 * One instance per configured S-UI server. Each instance:
 *   - owns a single Telegram message and edits it in place on every tick
 *   - persists its own state (message_id, alert cooldowns, rate counters)
 *   - renders the dashboard entirely from live panel API data
 *   - caches slow-changing endpoints (clients, inbounds, ...) for `stats_cache` seconds
 *   - turns cumulative counters (disk I/O) into per-second rates
 *   - raises Telegram alerts (CPU / RAM / disk / core down / server unreachable)
 */

declare(strict_types=1);

namespace SuiMonitor;

final class ServerMonitor
{
    private const SEP = '━━━━━━━━━━━━━━━━━━━━━━';

    /** Telegram rejects messages longer than this many visible characters. */
    private const TELEGRAM_LIMIT = 4096;

    private string      $name;
    private SuiApi      $api;
    private TelegramBot $tg;

    /** @var array<string,mixed> */
    private array $cfg;
    /** @var array<string,mixed> */
    private array $state;
    private string $stateFile;

    /** @var array<string,array{at:int,data:mixed}> TTL cache shared across ticks. */
    private array $cache = [];
    /** @var array<string,mixed> Memo that lives for a single tick only. */
    private array $tickCache = [];

    /**
     * @param array{base_url:string,token:string,verify_ssl:bool} $serverCfg
     * @param array<string,mixed> $cfg Full, normalised configuration.
     */
    public function __construct(string $name, array $serverCfg, TelegramBot $tg, array $cfg)
    {
        $this->name = $name;
        $this->tg   = $tg;
        $this->cfg  = $cfg;

        $this->api = new SuiApi(
            $name,
            $serverCfg,
            (int)$cfg['monitor']['http_timeout'],
            (int)$cfg['monitor']['max_retries']
        );

        // The hash keeps file names unique even if two server names only differ by special characters.
        $dir  = $cfg['storage']['dir'] . '/state';
        $safe = (string)preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
        $hash = substr(md5($name), 0, 8);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->stateFile = "{$dir}/{$safe}_{$hash}.json";
        $this->state     = $this->loadState();
    }

    public function getName(): string
    {
        return $this->name;
    }

    // ═══════════════════════════════════════════════════════
    //  Tick (called by the main loop)
    // ═══════════════════════════════════════════════════════

    /** Fetch fresh data, evaluate alerts, and refresh the Telegram message. */
    public function tick(): void
    {
        $this->tickCache = [];

        $st = $this->api->status();

        if ($st === null) {
            $this->onUnreachable();
            $text = $this->renderOffline();
        } else {
            $this->onReachable();
            $this->checkAlerts($st);
            $text = $this->render($st);
        }

        $this->publish($text);
        $this->saveState();
    }

    /** Edit the existing message, or (re)create it when it does not exist. */
    private function publish(string $text): void
    {
        $mid = $this->state['message_id'] ?? null;

        if ($mid) {
            $outcome = $this->tg->edit((int)$mid, $text);

            if ($outcome === TelegramBot::EDIT_OK) {
                return;
            }
            if ($outcome === TelegramBot::EDIT_FAILED) {
                // Transient problem (network, rate limit...). Keep the id and try again next tick.
                Logger::warning('Edit failed, will retry next tick', ['server' => $this->name, 'mid' => $mid]);
                return;
            }

            Logger::warning('Message is gone, creating a new one', ['server' => $this->name, 'mid' => $mid]);
        }

        $newMid = $this->tg->send($text);
        if ($newMid !== null) {
            $this->state['message_id'] = $newMid;
            Logger::info($mid ? 'Recreated message' : 'Created message', ['server' => $this->name, 'mid' => $newMid]);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  Rendering — the message layout
    // ═══════════════════════════════════════════════════════

    /** Message shown while the panel API cannot be reached. */
    private function renderOffline(): string
    {
        $err  = $this->api->lastError ?? 'unknown';
        $code = $this->api->lastHttpCode ?? '?';

        return "⚠️ <b>" . Formatter::esc($this->name) . " — offline</b>\n"
             . "└ <i>HTTP: {$code} | " . Formatter::esc($err) . "</i>\n"
             . "└ <i>" . date('Y-m-d H:i:s') . "</i>";
    }

    /**
     * Build the full dashboard text from a /status payload.
     *
     * @param array<string,mixed> $st
     */
    public function render(array $st): string
    {
        $d   = $this->cfg['display'];
        $sys = is_array($st['sys'] ?? null) ? $st['sys'] : [];
        $sbd = is_array($st['sbd'] ?? null) ? $st['sbd'] : [];
        $mem = is_array($st['mem'] ?? null) ? $st['mem'] : ['current' => 0, 'total' => 1];
        $swp = is_array($st['swp'] ?? null) ? $st['swp'] : ['current' => 0, 'total' => 0];
        $dsk = is_array($st['dsk'] ?? null) ? $st['dsk'] : ['current' => 0, 'total' => 1];
        $dio = is_array($st['dio'] ?? null) ? $st['dio'] : [];
        $db  = is_array($st['db']  ?? null) ? $st['db']  : [];
        $cpu = (float)($st['cpu'] ?? 0);

        // Each block is [key, lines]; blocks after the first are separated by SEP.
        $blocks = [
            ['header', array_merge(
                $this->renderHeader($sys, $sbd, $d),
                $this->renderNetworkInterfaces($sys, $d)
            )],
        ];

        $sections = [
            'performance' => $this->renderPerformance($d, $cpu, $mem, $swp, $dsk, $dio, $sys, $db),
            'panel'       => $this->renderPanelOverview($d),
            'traffic'     => $this->renderTraffic($d),
            'clients'     => $this->renderClients($d),
            'top_users'   => $this->renderTopUsers($d),
        ];
        foreach ($sections as $key => $lines) {
            if (!empty($lines)) {
                $blocks[] = [$key, $lines];
            }
        }

        if ($d['update_time']) {
            $blocks[] = ['footer', ["🔄 <b>Last Updated:</b> " . date('Y-m-d H:i:s')]];
        }

        return $this->assemble($blocks);
    }

    /**
     * Join blocks with separators and, if the result would exceed Telegram's
     * limit, drop the least important blocks until it fits.
     *
     * @param list<array{0:string,1:list<string>}> $blocks
     */
    private function assemble(array $blocks): string
    {
        $join = static function (array $blocks): string {
            $out = [];
            foreach ($blocks as $i => [, $lines]) {
                if ($i > 0) {
                    $out[] = self::SEP;
                }
                foreach ($lines as $line) {
                    $out[] = $line;
                }
            }
            return implode("\n", $out);
        };

        $text = $join($blocks);

        foreach (['top_users', 'clients', 'traffic', 'panel'] as $drop) {
            if (Formatter::visibleLength($text) <= self::TELEGRAM_LIMIT) {
                break;
            }
            Logger::warning('Message too long, dropping section', ['server' => $this->name, 'section' => $drop]);
            $blocks = array_values(array_filter($blocks, static fn(array $b): bool => $b[0] !== $drop));
            $text   = $join($blocks);
        }

        return $text;
    }

    // ── Section renderers ──────────────────────────────────

    /**
     * @param  array<string,mixed> $sys
     * @param  array<string,mixed> $sbd
     * @param  array<string,bool>  $d
     * @return list<string>
     */
    private function renderHeader(array $sys, array $sbd, array $d): array
    {
        $L   = [];
        $L[] = "🖥 <b>S-UI · SERVER MONITORING</b>";
        $L[] = self::SEP;

        if ($d['host_name'] && !empty($sys['hostName'])) {
            $L[] = "🖧 <b>Hostname:</b> " . Formatter::esc((string)$sys['hostName']);
        }

        if ($d['panel_version'] && !empty($sys['appVersion'])) {
            $L[] = "📦 <b>S-UI Version:</b> v" . Formatter::esc((string)$sys['appVersion']);
        }

        if ($d['core_status']) {
            $running     = !empty($sbd['running']);
            $maintenance = !empty($sbd['maintenance']);
            $icon        = $running ? '🟢' : '🔴';
            $label       = $running ? 'Running' : 'Stopped';
            if ($maintenance) {
                $label .= ' (maintenance)';
            }
            $L[] = "⚙️ <b>Core:</b> {$icon} {$label}";
        }

        if ($d['uptime'] && !empty($sys['bootTime'])) {
            $L[] = "⏱️ <b>Uptime:</b> " . Formatter::uptime(time() - (int)$sys['bootTime']);
        }

        return $L;
    }

    /**
     * @param  array<string,mixed> $sys
     * @param  array<string,bool>  $d
     * @return list<string>
     */
    private function renderNetworkInterfaces(array $sys, array $d): array
    {
        if (!$d['ip_address']) {
            return [];
        }

        $ipv4 = is_array($sys['ipv4'] ?? null) ? $sys['ipv4'] : [];
        $ipv6 = is_array($sys['ipv6'] ?? null) ? $sys['ipv6'] : [];

        if (empty($ipv4) && empty($ipv6)) {
            return [];
        }

        $rows = [];
        foreach ($ipv4 as $ip) {
            $rows[] = "IPv4 <code>" . Formatter::esc((string)$ip) . "</code>";
        }
        foreach ($ipv6 as $ip) {
            $rows[] = "IPv6 <code>" . Formatter::esc((string)$ip) . "</code>";
        }

        $L    = ["🌐 <b>NETWORK INTERFACES</b>"];
        $last = count($rows) - 1;
        foreach ($rows as $i => $row) {
            $L[] = ($i === $last ? '└' : '├') . " {$row}";
        }

        return $L;
    }

    /**
     * @param  array<string,bool>  $d
     * @param  array<string,mixed> $mem
     * @param  array<string,mixed> $swp
     * @param  array<string,mixed> $dsk
     * @param  array<string,mixed> $dio
     * @param  array<string,mixed> $sys
     * @param  array<string,mixed> $db
     * @return list<string>
     */
    private function renderPerformance(
        array $d,
        float $cpu,
        array $mem,
        array $swp,
        array $dsk,
        array $dio,
        array $sys,
        array $db
    ): array {
        $memCur = (float)($mem['current'] ?? 0);
        $memTot = (float)($mem['total']   ?? 1);
        $dskCur = (float)($dsk['current'] ?? 0);
        $dskTot = (float)($dsk['total']   ?? 1);
        $swpCur = (float)($swp['current'] ?? 0);
        $swpTot = (float)($swp['total']   ?? 0);

        $memPct = $memTot > 0 ? $memCur / $memTot * 100 : 0.0;
        $dskPct = $dskTot > 0 ? $dskCur / $dskTot * 100 : 0.0;
        $swpPct = $swpTot > 0 ? $swpCur / $swpTot * 100 : 0.0;

        $L = [];

        if ($d['cpu']) {
            $L[] = "🧠 CPU " . Formatter::bar($cpu, 20) . " <b>" . number_format($cpu, 1) . "%</b>";
        }

        if ($d['ram']) {
            $L[] = "💾 RAM " . Formatter::bar($memPct, 20) . " <b>" . number_format($memPct, 1) . "%</b>";
            $L[] = "<i>" . Formatter::bytes($memCur) . " / " . Formatter::bytes($memTot) . "</i>";
        }

        if ($d['swap'] && $swpTot > 0) {
            $L[] = "🔁 SWAP " . Formatter::bar($swpPct, 20) . " <b>" . number_format($swpPct, 1) . "%</b>";
            $L[] = "<i>" . Formatter::bytes($swpCur) . " / " . Formatter::bytes($swpTot) . "</i>";
        }

        if ($d['disk']) {
            $L[] = "💽 DISK " . Formatter::bar($dskPct, 20) . " <b>" . number_format($dskPct, 1) . "%</b>";
            $L[] = "<i>" . Formatter::bytes($dskCur) . " / " . Formatter::bytes($dskTot) . "</i>";
        }

        // Disk I/O: the panel reports cumulative counters, shown here as per-second rates.
        if ($d['disk_io'] && !empty($dio)) {
            $rRate = isset($dio['read'])  ? $this->rate('dio_read',  (float)$dio['read'])  : null;
            $wRate = isset($dio['write']) ? $this->rate('dio_write', (float)$dio['write']) : null;

            $L[] = "📀 <b>DISK I/O</b>";
            $L[] = "├ Read Speed:  <b>" . Formatter::rate($rRate) . "</b>";
            $L[] = "└ Write Speed: <b>" . Formatter::rate($wRate) . "</b>";
        }

        if ($d['app_mem'] && isset($sys['appMem'])) {
            $L[] = "🧩 <b>Panel Memory:</b> " . Formatter::bytes((float)$sys['appMem']);
        }
        if ($d['threads'] && isset($sys['appThreads'])) {
            $L[] = "🧵 <b>Panel Threads:</b> " . (int)$sys['appThreads'];
        }

        if ($d['cpu_info']) {
            if (!empty($sys['cpuType'])) {
                $model = (string)preg_replace('/\s*Processor\s*/i', ' ', (string)$sys['cpuType']);
                $model = trim((string)preg_replace('/\s+/', ' ', $model));
                $L[]   = "🖥 <b>CPU Model:</b> " . Formatter::esc($model);
            }
            if (!empty($sys['cpuCount'])) {
                $L[] = "🔢 <b>vCPU Cores:</b> " . (int)$sys['cpuCount'];
            }
        }

        if ($d['db_size'] && !empty($db)) {
            $size = $db['size'] ?? $db['bytes'] ?? $db['dbSize'] ?? null;
            if ($size !== null) {
                $L[] = "🗄 <b>DB Size:</b> " . Formatter::bytes((float)$size);
            }
        }

        // Nothing enabled -> no section at all (avoids an empty "SYSTEM PERFORMANCE" header).
        if (empty($L)) {
            return [];
        }

        return array_merge(["📊 <b>SYSTEM PERFORMANCE</b>", self::SEP], $L);
    }

    /**
     * @param  array<string,bool> $d
     * @return list<string>
     */
    private function renderPanelOverview(array $d): array
    {
        if (!$d['panel_stats']) {
            return [];
        }

        $inbounds  = $this->extractList($this->fetchCached('inbounds',  fn() => $this->api->inbounds()),  'inbounds');
        $outbounds = $this->extractList($this->fetchCached('outbounds', fn() => $this->api->outbounds()), 'outbounds');
        $endpoints = $this->extractList($this->fetchCached('endpoints', fn() => $this->api->endpoints()), 'endpoints');
        $services  = $this->extractList($this->fetchCached('services',  fn() => $this->api->services()),  'services');

        return [
            "⚙️ <b>S-UI PANEL OVERVIEW</b>",
            self::SEP,
            "⬇️ <b>Total Inbounds:</b> " . count($inbounds),
            "⬆️ <b>Outbounds:</b> "      . count($outbounds),
            "⏺️ <b>Endpoints:</b> "      . count($endpoints),
            "↔️ <b>Services:</b> "       . count($services),
        ];
    }

    /**
     * @param  array<string,bool> $d
     * @return list<string>
     */
    private function renderTraffic(array $d): array
    {
        if (!$d['traffic_total']) {
            return [];
        }

        $up = $down = 0.0;
        foreach ($this->clients() as $c) {
            $up   += (float)($c['up']   ?? 0);
            $down += (float)($c['down'] ?? 0);
        }

        return [
            "📈 <b>NETWORK TRAFFIC</b>",
            self::SEP,
            "📤 <b>Upload:</b> "   . Formatter::bytes($up),
            "📥 <b>Download:</b> " . Formatter::bytes($down),
            "↕️ <b>Total Transferred:</b> " . Formatter::bytes($up + $down),
        ];
    }

    /**
     * @param  array<string,bool> $d
     * @return list<string>
     */
    private function renderClients(array $d): array
    {
        if (!$d['clients_summary'] && !$d['online_count']) {
            return [];
        }

        $L = ["👥 <b>CLIENT OVERVIEW</b>", self::SEP];

        if ($d['clients_summary']) {
            $clients = $this->clients();

            $active = $disabled = $expired = 0;
            foreach ($clients as $c) {
                switch ($this->classifyClient($c)) {
                    case 'expired':  $expired++;  break;
                    case 'disabled': $disabled++; break;
                    default:         $active++;
                }
            }

            $L[] = "📋 <b>Total Clients:</b> " . count($clients);
            $L[] = "├ 🟢 <b>Enabled:</b> "  . $active;
            $L[] = "├ 🔴 <b>Disabled:</b> " . $disabled;
            $L[] = "└ ⏳ <b>Expired:</b> "  . $expired;
        }

        if ($d['online_count']) {
            $online = $this->online();
            $L[]    = "🔗 <b>Online Connections:</b> " . $online['sessions'];
            $L[]    = "🧑 <b>Unique Online Users:</b> " . $online['unique'];
        }

        return $L;
    }

    /**
     * @param  array<string,bool> $d
     * @return list<string>
     */
    private function renderTopUsers(array $d): array
    {
        if (!$d['top_users']) {
            return [];
        }

        $tu         = $this->cfg['top_users'];
        $n          = (int)$tu['count'];
        $barWidth   = (int)$tu['bar_width'];
        $sortBy     = (string)$tu['sort_by'];
        $minBytes   = (float)$tu['min_bytes'];
        $showOnline = (bool)$tu['show_online'];

        $clients = $this->clients();
        if (empty($clients)) {
            return [];
        }

        $onlineNames = $showOnline ? $this->online()['names'] : [];

        $rows = [];
        foreach ($clients as $c) {
            $name   = (string)($c['name'] ?? $c['remark'] ?? '?');
            $used   = $this->clientUsage($c);
            $volume = $this->clientVolume($c);

            if ($used < $minBytes) {
                continue;
            }

            $rows[] = [
                'name'    => $name,
                'used'    => $used,
                'volume'  => $volume,
                'percent' => $volume > 0 ? ($used / $volume) * 100 : 0.0,
                'online'  => isset($onlineNames[$name]),
            ];
        }

        if (empty($rows)) {
            return [];
        }

        usort($rows, static function (array $a, array $b) use ($sortBy): int {
            return match ($sortBy) {
                'percent' => $b['percent'] <=> $a['percent'],
                'name'    => strcmp($a['name'], $b['name']),
                default   => $b['used'] <=> $a['used'],
            };
        });

        $rows = array_slice($rows, 0, $n);

        $L   = [];
        $L[] = "🏆 <b>TOP {$n} CLIENTS · TRAFFIC USAGE</b>";
        $L[] = self::SEP;

        $i = 1;
        foreach ($rows as $r) {
            $status  = $r['online'] ? '🟢 Online' : '⚪️ Offline';
            $name    = Formatter::esc(Formatter::shortName($r['name'], 18));
            $usedStr = Formatter::bytes($r['used'], 2);

            $L[] = "👤{$i}. <b>{$name}</b> · {$status}";

            if ($r['volume'] > 0) {
                $volStr = Formatter::bytes($r['volume'], 0);
                $pct    = number_format($r['percent'], 1);
                $L[]    = "<i>{$usedStr} / {$volStr} · {$pct}%</i>";
                $L[]    = "<code>" . Formatter::bar($r['percent'], $barWidth) . "</code>";
            } else {
                $L[] = "<i>{$usedStr} · unlimited</i>";
                $L[] = "<code>" . Formatter::bar(0, $barWidth) . "</code>";
            }
            $i++;
        }

        return $L;
    }

    // ═══════════════════════════════════════════════════════
    //  Alerts
    // ═══════════════════════════════════════════════════════

    /** @param array<string,mixed> $st */
    private function checkAlerts(array $st): void
    {
        $a = $this->cfg['alerts'];
        if (!$a['enabled']) {
            return;
        }

        $name   = Formatter::esc($this->name);
        $toSend = [];

        // A threshold of 0 disables that particular alert.
        $cpu = (float)($st['cpu'] ?? 0);
        if ($a['cpu_threshold'] > 0 && $cpu >= $a['cpu_threshold']) {
            $toSend['cpu'] = "🔥 <b>HIGH CPU</b> on <b>{$name}</b>: " . number_format($cpu, 2) . "%";
        }

        $mem    = is_array($st['mem'] ?? null) ? $st['mem'] : [];
        $memTot = (float)($mem['total'] ?? 0);
        $memPct = $memTot > 0 ? ((float)($mem['current'] ?? 0) / $memTot) * 100 : 0.0;
        if ($a['ram_threshold'] > 0 && $memPct >= $a['ram_threshold']) {
            $toSend['ram'] = "🧠 <b>HIGH RAM</b> on <b>{$name}</b>: " . number_format($memPct, 1) . "%";
        }

        $dsk    = is_array($st['dsk'] ?? null) ? $st['dsk'] : [];
        $dskTot = (float)($dsk['total'] ?? 0);
        $dskPct = $dskTot > 0 ? ((float)($dsk['current'] ?? 0) / $dskTot) * 100 : 0.0;
        if ($a['disk_threshold'] > 0 && $dskPct >= $a['disk_threshold']) {
            $toSend['disk'] = "💿 <b>LOW DISK</b> on <b>{$name}</b>: " . number_format($dskPct, 1) . "%";
        }

        // Only judge the core when the panel actually reported its state.
        if ($a['core_down'] && is_array($st['sbd'] ?? null)) {
            $sbd = $st['sbd'];
            if (empty($sbd['running']) && empty($sbd['maintenance'])) {
                $toSend['core'] = "💀 <b>CORE DOWN</b> on <b>{$name}</b> (not in maintenance)";
            }
        }

        foreach ($toSend as $key => $msg) {
            $this->sendAlert($key, $msg);
        }
    }

    /** Send an alert unless the same kind was sent within the cooldown window. */
    private function sendAlert(string $key, string $message): void
    {
        $now      = time();
        $cooldown = (int)$this->cfg['alerts']['cooldown_seconds'];
        $last     = (int)($this->state['last_alert'][$key] ?? 0);

        if (($now - $last) < $cooldown) {
            return;
        }

        if ($this->tg->send("🚨 <b>ALERT</b>\n{$message}\n<i>" . date('H:i:s') . "</i>") !== null) {
            $this->state['last_alert'][$key] = $now;
            Logger::info('Alert sent', ['server' => $this->name, 'kind' => $key]);
        }
    }

    /** The panel did not answer: count failures and alert once the threshold is reached. */
    private function onUnreachable(): void
    {
        $this->state['fail_count'] = (int)($this->state['fail_count'] ?? 0) + 1;

        $a = $this->cfg['alerts'];
        if (!$a['enabled'] || !$a['offline'] || !empty($this->state['offline_notified'])) {
            return;
        }
        if ($this->state['fail_count'] < $a['offline_after_failures']) {
            return;
        }

        $name = Formatter::esc($this->name);
        $err  = Formatter::esc($this->api->lastError ?? 'unknown');
        $sent = $this->tg->send(
            "🚨 <b>ALERT</b>\n📡 <b>SERVER UNREACHABLE</b>: <b>{$name}</b>\n<i>{$err}</i>\n<i>" . date('H:i:s') . "</i>"
        );

        if ($sent !== null) {
            $this->state['offline_notified'] = true;
            Logger::warning('Server unreachable alert sent', ['server' => $this->name]);
        }
    }

    /** The panel answered: reset the failure counter and announce recovery if needed. */
    private function onReachable(): void
    {
        $wasNotified = !empty($this->state['offline_notified']);

        $this->state['fail_count']       = 0;
        $this->state['offline_notified'] = false;

        $a = $this->cfg['alerts'];
        if ($wasNotified && $a['enabled'] && $a['recovery_notice']) {
            $this->tg->send(
                "✅ <b>RECOVERED</b>\n<b>" . Formatter::esc($this->name) . "</b> is reachable again.\n<i>" . date('H:i:s') . "</i>"
            );
            Logger::info('Recovery notice sent', ['server' => $this->name]);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  Data access
    // ═══════════════════════════════════════════════════════

    /**
     * TTL cache for slow-changing endpoints.
     *
     * @param  callable():(array<mixed>|null) $fetcher
     * @return array<mixed>|null
     */
    private function fetchCached(string $key, callable $fetcher): ?array
    {
        $ttl = (int)$this->cfg['monitor']['stats_cache'];
        $now = time();

        if (isset($this->cache[$key]) && ($now - $this->cache[$key]['at']) < $ttl) {
            return $this->cache[$key]['data'];
        }

        $data              = $fetcher();
        $this->cache[$key] = ['at' => $now, 'data' => $data];

        return $data;
    }

    /**
     * All clients as a list of arrays (cached).
     *
     * @return list<array<string,mixed>>
     */
    private function clients(): array
    {
        $raw = $this->fetchCached('clients', fn() => $this->api->clients());

        $list = [];
        if (is_array($raw)) {
            if (isset($raw['clients']) && is_array($raw['clients'])) {
                $list = $raw['clients'];
            } elseif (array_is_list($raw)) {
                $list = $raw;
            }
        }

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * @param  array<mixed>|null $raw
     * @return array<mixed>
     */
    private function extractList(?array $raw, string $key): array
    {
        if (!is_array($raw)) {
            return [];
        }
        if (isset($raw[$key]) && is_array($raw[$key])) {
            return $raw[$key];
        }
        return array_is_list($raw) ? $raw : [];
    }

    /**
     * Who is online right now (memoised for the duration of one tick).
     *
     * Uses /sessions (live from the core) and falls back to /onlines.
     *
     * @return array{names:array<string,true>,unique:int,sessions:int}
     */
    private function online(): array
    {
        if (isset($this->tickCache['online'])) {
            return $this->tickCache['online'];
        }

        $names    = [];
        $sessions = 0;

        $raw = $this->api->sessions();
        if (is_array($raw)) {
            $rows = $raw['sessions'] ?? $raw;
            if (is_array($rows)) {
                $sessions = count($rows);
                foreach ($rows as $s) {
                    if (is_array($s)) {
                        $user = $s['user'] ?? $s['name'] ?? null;
                        if ($user !== null && $user !== '') {
                            $names[(string)$user] = true;
                        }
                    }
                }
            }
        }

        // Fallback: /onlines (used when /sessions is empty or unsupported).
        if ($sessions === 0) {
            $onl = $this->api->onlines();
            if (is_array($onl)) {
                $rows = $onl['onlines'] ?? $onl;
                if (is_array($rows)) {
                    if (array_key_exists('user', $rows)) {
                        // S-UI shape: {"inbound": [...], "user": ["alice", ...], "outbound": [...]}
                        $users    = is_array($rows['user']) ? $rows['user'] : [];
                        $sessions = count($users);
                        foreach ($users as $u) {
                            if (is_scalar($u) && (string)$u !== '') {
                                $names[(string)$u] = true;
                            }
                        }
                    } else {
                        $sessions = count($rows);
                        foreach ($rows as $k => $v) {
                            if (is_string($k) && !is_numeric($k)) {
                                $names[$k] = true;
                            } elseif (is_array($v) && isset($v['user'])) {
                                $names[(string)$v['user']] = true;
                            }
                        }
                    }
                }
            }
        }

        return $this->tickCache['online'] = [
            'names'    => $names,
            'unique'   => count($names),
            'sessions' => $sessions,
        ];
    }

    /** @param array<string,mixed> $c */
    private function classifyClient(array $c): string
    {
        $expiry = (int)($c['expiry'] ?? 0);

        if ($expiry > 0 && $expiry < time()) {
            return 'expired';
        }

        return empty($c['enable']) ? 'disabled' : 'active';
    }

    /** @param array<string,mixed> $c */
    private function clientUsage(array $c): float
    {
        return (float)($c['up'] ?? 0) + (float)($c['down'] ?? 0);
    }

    /** @param array<string,mixed> $c */
    private function clientVolume(array $c): float
    {
        return (float)($c['volume'] ?? $c['total'] ?? 0);
    }

    // ═══════════════════════════════════════════════════════
    //  Rate calculation (cumulative counter -> bytes/second)
    // ═══════════════════════════════════════════════════════

    private function rate(string $key, float $current): ?float
    {
        $now  = microtime(true);
        $prev = $this->state['rates'][$key] ?? null;

        $this->state['rates'][$key] = ['bytes' => $current, 'at' => $now];

        if (!is_array($prev)) {
            return null;
        }

        $deltaBytes = $current - (float)$prev['bytes'];
        $deltaSec   = $now - (float)$prev['at'];

        // Counter reset (reboot) or a stale sample (service was down for a long time).
        $maxAge = max(300, (int)$this->cfg['monitor']['interval'] * 10);
        if ($deltaBytes < 0 || $deltaSec <= 0 || $deltaSec > $maxAge) {
            return null;
        }

        return $deltaBytes / $deltaSec;
    }

    // ═══════════════════════════════════════════════════════
    //  State persistence
    // ═══════════════════════════════════════════════════════

    /** @return array<string,mixed> */
    private function defaultState(): array
    {
        return [
            'message_id'       => null,
            'last_alert'       => [],
            'rates'            => [],
            'fail_count'       => 0,
            'offline_notified' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function loadState(): array
    {
        $default = $this->defaultState();

        if (is_file($this->stateFile)) {
            $raw  = @file_get_contents($this->stateFile);
            $data = $raw !== false ? json_decode($raw, true) : null;

            if (is_array($data)) {
                // Keep only known keys, so obsolete data from older versions is dropped.
                return array_merge($default, array_intersect_key($data, $default));
            }
        }

        return $default;
    }

    /** Atomic write: temp file + rename, so a crash can never leave a half-written state file. */
    private function saveState(): void
    {
        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            Logger::error('State encode failed', ['server' => $this->name]);
            return;
        }

        $tmp = $this->stateFile . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $this->stateFile)) {
            Logger::error('State write failed', ['server' => $this->name, 'file' => $this->stateFile]);
            @unlink($tmp);
        }
    }
}
