<?php
/**
 * Minimal Telegram Bot API client.
 *
 * Handles the edge cases that matter for a "single live message" bot:
 *   - 429 Too Many Requests  -> waits for retry_after and retries (bounded)
 *   - "message is not modified" -> reported as success (harmless no-op)
 *   - HTML parse errors      -> retried once as plain text
 *   - transport errors       -> reported as a *transient* failure, which lets the
 *                               caller keep its message_id instead of spamming
 *                               a brand-new message on every network hiccup
 */

declare(strict_types=1);

namespace SuiMonitor;

final class TelegramBot
{
    /** edit() outcomes. */
    public const EDIT_OK     = 'ok';      // edited (or nothing to change)
    public const EDIT_GONE   = 'gone';    // message was deleted / cannot be edited -> send a new one
    public const EDIT_FAILED = 'failed';  // transient failure -> keep the message_id, try again later

    private const MAX_RATE_LIMIT_RETRIES = 3;
    private const MAX_RETRY_AFTER        = 30;

    private string $token;
    private string $chatId;
    private string $apiBase;
    private string $proxy;

    /**
     * @param array{bot_token?:string,chat_id?:string|int,api_base?:string,proxy?:string} $cfg
     */
    public function __construct(array $cfg)
    {
        $this->token   = (string)($cfg['bot_token'] ?? '');
        $this->chatId  = (string)($cfg['chat_id'] ?? '');
        $this->apiBase = rtrim((string)($cfg['api_base'] ?? 'https://api.telegram.org'), '/');
        $this->proxy   = (string)($cfg['proxy'] ?? '');
    }

    /**
     * Low-level API call.
     *
     * Always returns an array: ['ok' => bool, 'result' => mixed,
     * 'error_code' => int (0 = transport error), 'description' => string].
     *
     * @param  array<string,mixed> $params
     * @return array{ok:bool,result:mixed,error_code:int,description:string}
     */
    public function call(string $method, array $params = [], int $depth = 0): array
    {
        $ch   = curl_init($this->apiBase . '/bot' . $this->token . '/' . $method);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($this->proxy !== '') {
            $opts[CURLOPT_PROXY] = $this->proxy;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        unset($ch);

        if ($raw === false || $err !== '') {
            $err = $this->redact($err);
            Logger::error('Telegram cURL error', ['method' => $method, 'error' => $err]);
            return self::result(false, null, 0, $err !== '' ? $err : 'transport error');
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            Logger::error('Telegram invalid JSON', ['method' => $method]);
            return self::result(false, null, 0, 'invalid JSON from Telegram');
        }

        if (!empty($json['ok'])) {
            return self::result(true, $json['result'] ?? null, 0, '');
        }

        $code = (int)($json['error_code'] ?? 0);
        $desc = (string)($json['description'] ?? 'unknown');

        // 429: wait as long as Telegram asks (bounded), then retry.
        if ($code === 429 && $depth < self::MAX_RATE_LIMIT_RETRIES) {
            $wait = min((int)($json['parameters']['retry_after'] ?? 5), self::MAX_RETRY_AFTER);
            Logger::warning('Telegram rate limit hit', ['method' => $method, 'retry_after' => $wait]);
            sleep(max(1, $wait));
            return $this->call($method, $params, $depth + 1);
        }

        // Editing with identical text is a harmless no-op.
        if (stripos($desc, 'message is not modified') !== false) {
            return self::result(true, ['message_id' => $params['message_id'] ?? 0], $code, $desc);
        }

        // Malformed HTML: retry once as plain text.
        if (stripos($desc, "can't parse entities") !== false
            && isset($params['text'], $params['parse_mode'])) {
            Logger::warning('Telegram HTML parse failed, retrying as plain text', ['method' => $method]);
            $params['text'] = html_entity_decode(strip_tags((string)$params['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            unset($params['parse_mode']);
            return $this->call($method, $params, $depth + 1);
        }

        Logger::warning('Telegram API error', ['method' => $method, 'code' => $code, 'desc' => $desc]);

        return self::result(false, null, $code, $desc);
    }

    /**
     * Edit an existing message.
     *
     * @return string one of the EDIT_* constants
     */
    public function edit(int $messageId, string $text): string
    {
        $res = $this->call('editMessageText', [
            'chat_id'                  => $this->chatId,
            'message_id'               => $messageId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        if ($res['ok']) {
            return self::EDIT_OK;
        }

        // Only these errors mean the message can never be edited again.
        if ($res['error_code'] === 400
            && preg_match("/message to edit not found|message can't be edited|MESSAGE_ID_INVALID/i", $res['description'])) {
            return self::EDIT_GONE;
        }

        return self::EDIT_FAILED;
    }

    /** Send a new message. Returns its message_id, or null on failure. */
    public function send(string $text): ?int
    {
        $res = $this->call('sendMessage', [
            'chat_id'                  => $this->chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        $id = is_array($res['result']) ? ($res['result']['message_id'] ?? null) : null;

        return $res['ok'] && $id !== null ? (int)$id : null;
    }

    /** Delete a message. */
    public function delete(int $messageId): bool
    {
        return $this->call('deleteMessage', [
            'chat_id'    => $this->chatId,
            'message_id' => $messageId,
        ])['ok'];
    }

    /** Verify the bot token. Result holds the bot's id/username on success. */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    // ── Internals ──────────────────────────────────────────

    /** Never let the bot token leak into logs. */
    private function redact(string $text): string
    {
        return $this->token !== '' ? str_replace($this->token, '***', $text) : $text;
    }

    /**
     * @param  mixed $result
     * @return array{ok:bool,result:mixed,error_code:int,description:string}
     */
    private static function result(bool $ok, $result, int $code, string $desc): array
    {
        return ['ok' => $ok, 'result' => $result, 'error_code' => $code, 'description' => $desc];
    }
}
