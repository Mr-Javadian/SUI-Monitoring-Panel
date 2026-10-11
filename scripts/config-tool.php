#!/usr/bin/env php
<?php
/**
 * Config helper used by the installer (install.sh).
 *
 * Edits config/config.php IN PLACE while preserving every comment, by replacing
 * only the value of a single option (or the managed servers block).
 *
 * Usage:
 *   php scripts/config-tool.php [--file=PATH] <command> [args]
 *
 * Commands:
 *   set <section> <key> <string|int|float|bool|auto> <value>
 *   get <section> <key>
 *   list-servers
 *   reset-servers
 *   add-server <name> <base_url> <token> <verify_ssl: 1|0>
 *   remove-server <name>
 *   detect-chat <bot_token> [api_base] [proxy]    (prints: id|type|title)
 */

declare(strict_types=1);

const BEGIN_MARK = '// >>> SERVERS-BEGIN';
const END_MARK   = '// <<< SERVERS-END';

$argvList = $argv;
array_shift($argvList);

$file = dirname(__DIR__) . '/config/config.php';
foreach ($argvList as $i => $a) {
    if (str_starts_with($a, '--file=')) {
        $file = substr($a, 7);
        unset($argvList[$i]);
    }
}
$argvList = array_values($argvList);
$cmd      = $argvList[0] ?? '';

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "config-tool: {$msg}\n");
    exit($code);
}

function readConfigFile(string $file): string
{
    $s = @file_get_contents($file);
    return $s === false ? fail("cannot read {$file}") : $s;
}

function writeConfigFile(string $file, string $content): void
{
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $content) === false) {
        fail("cannot write {$tmp}");
    }
    @chmod($tmp, fileperms($file) & 0777);
    if (!@rename($tmp, $file)) {
        fail("cannot replace {$file}");
    }
}

/** @return array<string,mixed> */
function includeConfig(string $file): array
{
    $cfg = (static function (string $f) {
        return require $f;
    })($file);
    return is_array($cfg) ? $cfg : fail("{$file} does not return an array");
}

/** Render a PHP literal for the requested type. */
function literal(string $type, string $value): string
{
    switch ($type) {
        case 'int':
            return (string)(int)$value;
        case 'float':
            return rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
        case 'bool':
            return in_array(strtolower($value), ['1', 'true', 'yes', 'y', 'on'], true) ? 'true' : 'false';
        case 'auto':
            return preg_match('/^-?\d{1,18}$/', $value) ? $value : var_export($value, true);
        default:
            return var_export($value, true);
    }
}

function serverBlock(string $name, string $url, string $token, bool $verify): string
{
    return "        " . var_export($name, true) . " => [\n"
         . "            'base_url'   => " . var_export($url, true) . ",\n"
         . "            'token'      => " . var_export($token, true) . ",\n"
         . "            'verify_ssl' => " . ($verify ? 'true' : 'false') . ",\n"
         . "        ],\n";
}

/** Replace the text between the SERVERS markers. */
function replaceServersBlock(string $src, string $newBody): string
{
    $b = strpos($src, BEGIN_MARK);
    $e = strpos($src, END_MARK);
    if ($b === false || $e === false || $e < $b) {
        fail('SERVERS-BEGIN / SERVERS-END markers not found in the config file (edit servers by hand).');
    }

    $bodyStart = strpos($src, "\n", $b);
    $lineStart = (int)strrpos(substr($src, 0, $e), "\n") + 1;   // start of the END marker's line

    return substr($src, 0, $bodyStart + 1) . $newBody . substr($src, $lineStart);
}

switch ($cmd) {

    case 'set': {
        [, $section, $key, $type, $value] = array_pad($argvList, 5, null);
        if ($value === null) {
            fail('usage: set <section> <key> <type> <value>');
        }
        $src = readConfigFile($file);

        if (!preg_match("/'" . preg_quote($section, '/') . "'\\s*=>\\s*\\[/", $src, $m, PREG_OFFSET_CAPTURE)) {
            fail("section '{$section}' not found");
        }
        $pos  = $m[0][1];
        $head = substr($src, 0, $pos);
        $tail = substr($src, $pos);

        $pattern = "/('" . preg_quote($key, '/') . "'\\s*=>\\s*)(?:'(?:[^'\\\\]|\\\\.)*'|[^,\\n]+)(,)/";
        $lit     = literal($type, $value);
        $count   = 0;
        $tail    = preg_replace_callback($pattern, static fn(array $mm): string => $mm[1] . $lit . $mm[2], $tail, 1, $count);

        if (!$count) {
            fail("key '{$section}.{$key}' not found");
        }
        writeConfigFile($file, $head . $tail);
        break;
    }

    case 'get': {
        [, $section, $key] = array_pad($argvList, 3, null);
        $cfg = includeConfig($file);
        $v   = $cfg[$section][$key] ?? '';
        echo is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, "\n";
        break;
    }

    case 'list-servers': {
        $cfg = includeConfig($file);
        foreach (array_keys((array)($cfg['servers'] ?? [])) as $name) {
            echo $name, "\n";
        }
        break;
    }

    case 'reset-servers':
        writeConfigFile($file, replaceServersBlock(readConfigFile($file), ''));
        break;

    case 'add-server': {
        [, $name, $url, $token, $verify] = array_pad($argvList, 5, null);
        if ($verify === null) {
            fail('usage: add-server <name> <base_url> <token> <verify_ssl 1|0>');
        }
        $cfg = includeConfig($file);
        if (isset($cfg['servers'][$name])) {
            fail("a server named '{$name}' already exists", 3);
        }
        $src = readConfigFile($file);
        $e   = strpos($src, END_MARK);
        if ($e === false) {
            fail('SERVERS-END marker not found');
        }
        $lineStart = (int)strrpos(substr($src, 0, $e), "\n") + 1;
        $block     = serverBlock($name, $url, $token, $verify === '1');
        writeConfigFile($file, substr($src, 0, $lineStart) . $block . substr($src, $lineStart));
        break;
    }

    case 'remove-server': {
        $name = $argvList[1] ?? fail('usage: remove-server <name>');
        $cfg  = includeConfig($file);
        if (!isset($cfg['servers'][$name])) {
            fail("no server named '{$name}'", 3);
        }
        $body = '';
        foreach ($cfg['servers'] as $n => $s) {
            if ((string)$n === $name) {
                continue;
            }
            $body .= serverBlock((string)$n, (string)($s['base_url'] ?? ''), (string)($s['token'] ?? ''), !empty($s['verify_ssl']));
        }
        writeConfigFile($file, replaceServersBlock(readConfigFile($file), $body));
        break;
    }

    case 'detect-chat': {
        $token = $argvList[1] ?? fail('usage: detect-chat <bot_token> [api_base] [proxy]');
        $base  = rtrim($argvList[2] ?? '', '/') ?: 'https://api.telegram.org';
        $proxy = $argvList[3] ?? '';

        $ch = curl_init("{$base}/bot{$token}/getUpdates");
        $o  = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8];
        if ($proxy !== '') {
            $o[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $o);
        $raw = curl_exec($ch);
        unset($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json) || empty($json['ok'])) {
            fail('Telegram getUpdates failed: ' . (is_array($json) ? (string)($json['description'] ?? '?') : 'no/invalid response'));
        }

        $seen = [];
        foreach ((array)$json['result'] as $u) {
            foreach (['message', 'edited_message', 'channel_post', 'my_chat_member', 'chat_member'] as $k) {
                $chat = $u[$k]['chat'] ?? null;
                if (!is_array($chat) || !isset($chat['id'])) {
                    continue;
                }
                $id = (string)$chat['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $title = (string)($chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')));
                if (!empty($chat['username'])) {
                    $title .= ' (@' . $chat['username'] . ')';
                }
                $seen[$id] = true;
                echo $id, '|', (string)($chat['type'] ?? '?'), '|', str_replace('|', '/', trim($title)), "\n";
            }
        }
        break;
    }

    default:
        fail("unknown command '{$cmd}'. See the header of this file for usage.", 64);
}
