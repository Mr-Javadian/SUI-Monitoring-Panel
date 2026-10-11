<?php
/**
 * Tiny fake Telegram Bot API for local testing:
 *   php -S 127.0.0.1:18096 tests/mock_telegram.php
 * Set telegram.api_base to http://127.0.0.1:18096 in the config.
 * Every call is appended to /tmp/tg_calls.log.
 */

declare(strict_types=1);

header('Content-Type: application/json');

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = basename($path);
$store  = '/tmp/tg_messages.json';
$msgs   = is_file($store) ? (json_decode((string)file_get_contents($store), true) ?: []) : [];
$next   = (int)(is_file('/tmp/tg_next') ? file_get_contents('/tmp/tg_next') : 100);

file_put_contents('/tmp/tg_calls.log', json_encode(['m' => $method, 'p' => $_POST]) . "\n", FILE_APPEND);

$fail = static function (int $code, string $desc, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error_code' => $code, 'description' => $desc] + $extra);
    exit;
};

if (is_file('/tmp/tg_mode_ratelimit')) {
    unlink('/tmp/tg_mode_ratelimit');
    $fail(429, 'Too Many Requests: retry after 1', ['parameters' => ['retry_after' => 1]]);
}
if (is_file('/tmp/tg_mode_500')) {
    http_response_code(502);
    echo 'Bad gateway';
    exit;
}

switch ($method) {
    case 'getMe':
        echo json_encode(['ok' => true, 'result' => ['id' => 1, 'username' => 'mock_bot']]);
        break;

    case 'getUpdates':
        echo json_encode(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => ['id' => 777, 'type' => 'private', 'first_name' => 'Test', 'username' => 'tester']]],
            ['update_id' => 2, 'my_chat_member' => ['chat' => ['id' => -100555, 'type' => 'supergroup', 'title' => 'Ops Group']]],
        ]]);
        break;

    case 'sendMessage':
        if (str_contains($_POST['text'] ?? '', 'FORCE-PARSE-ERROR') && isset($_POST['parse_mode'])) {
            $fail(400, "Bad Request: can't parse entities: Unsupported start tag");
        }
        $id = $next++;
        file_put_contents('/tmp/tg_next', (string)$next);
        $msgs[$id] = $_POST['text'] ?? '';
        file_put_contents($store, json_encode($msgs));
        echo json_encode(['ok' => true, 'result' => ['message_id' => $id]]);
        break;

    case 'editMessageText':
        $id = (int)($_POST['message_id'] ?? 0);
        if (!isset($msgs[$id])) {
            $fail(400, 'Bad Request: message to edit not found');
        }
        if ($msgs[$id] === ($_POST['text'] ?? '')) {
            $fail(400, 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same');
        }
        $msgs[$id] = $_POST['text'] ?? '';
        file_put_contents($store, json_encode($msgs));
        echo json_encode(['ok' => true, 'result' => ['message_id' => $id]]);
        break;

    case 'deleteMessage':
        unset($msgs[(int)($_POST['message_id'] ?? 0)]);
        file_put_contents($store, json_encode($msgs));
        echo json_encode(['ok' => true, 'result' => true]);
        break;

    default:
        $fail(404, 'Not Found');
}
