<?php
/**
 * Tiny fake S-UI panel for local testing:
 *   php -S 127.0.0.1:18095 tests/mock_sui.php
 * Base URL to use in config: http://127.0.0.1:18095/app/apiv2   (token: testtoken)
 */

declare(strict_types=1);

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$tok  = $_SERVER['HTTP_TOKEN'] ?? '';

if (!str_starts_with($path, '/app/apiv2/')) {
    http_response_code(404);
    exit;
}
if ($tok !== 'testtoken') {
    echo json_encode(['success' => false, 'msg' => 'Invalid token']);
    exit;
}

// Optional failure injection: touch /tmp/mock_sui_down to simulate an outage.
if (is_file('/tmp/mock_sui_down')) {
    http_response_code(503);
    exit;
}

$GB   = 1024 ** 3;
$boot = time() - (3 * 86400 + 4 * 3600 + 12 * 60 + 9);
$tick = (int)(microtime(true) * 1000);

$clients = [
    ['name' => 'alice',        'enable' => true,  'expiry' => 0,                'up' => 12 * $GB,  'down' => 88 * $GB,  'volume' => 200 * $GB],
    ['name' => 'bob',          'enable' => true,  'expiry' => time() + 86400,   'up' => 3 * $GB,   'down' => 20 * $GB,  'volume' => 0],
    ['name' => 'carol-with-a-very-long-name-here', 'enable' => true, 'expiry' => 0, 'up' => 1 * $GB, 'down' => 9 * $GB, 'volume' => 10 * $GB],
    ['name' => 'dave <&> "x"', 'enable' => false, 'expiry' => 0,                'up' => 100000,    'down' => 5000000, 'volume' => 50 * $GB],
    ['name' => 'erin',         'enable' => true,  'expiry' => time() - 100,     'up' => 5 * $GB,   'down' => 5 * $GB,   'volume' => 100 * $GB],
    ['name' => 'frank',        'enable' => true,  'expiry' => 0,                'up' => 2 * $GB,   'down' => 2 * $GB,   'volume' => 0],
    ['name' => 'gina',         'enable' => true,  'expiry' => 0,                'up' => 0,         'down' => 0,         'volume' => 0],
];

switch (basename($path)) {
    case 'status':
        $obj = [
            'cpu' => 37.4,
            'mem' => ['current' => (int)(1.2 * $GB), 'total' => 4 * $GB],
            'swp' => ['current' => 100 * 1024 * 1024, 'total' => 2 * $GB],
            'dsk' => ['current' => 21 * $GB, 'total' => 50 * $GB],
            'dio' => ['read' => 5 * $GB + $tick * 1000, 'write' => 9 * $GB + $tick * 3000],
            'net' => ['sent' => 1, 'recv' => 2],
            'sys' => [
                'hostName' => 'vps-<test>', 'appVersion' => '1.6.3', 'bootTime' => $boot,
                'ipv4' => ['203.0.113.10'], 'ipv6' => ['2001:db8::1', '2001:db8::2'],
                'appMem' => 85 * 1024 * 1024, 'appThreads' => 17,
                'cpuType' => 'Intel(R) Xeon(R) Gold 6130 Processor @ 2.10GHz', 'cpuCount' => 4,
            ],
            'sbd' => ['running' => !is_file('/tmp/mock_core_down'), 'maintenance' => false],
            'db'  => ['size' => 3 * 1024 * 1024],
        ];
        break;
    case 'clients':   $obj = ['clients' => $clients]; break;
    case 'inbounds':  $obj = ['inbounds' => [[], [], []]]; break;
    case 'outbounds': $obj = ['outbounds' => [[], []]]; break;
    case 'endpoints': $obj = ['endpoints' => []]; break;
    case 'services':  $obj = ['services' => [[]]]; break;
    case 'sessions':
        $obj = is_file('/tmp/mock_no_sessions') ? [] : ['sessions' => [['user' => 'alice'], ['user' => 'alice'], ['user' => 'bob']]];
        break;
    case 'onlines':   $obj = ['inbound' => null, 'user' => ['alice'], 'outbound' => null]; break;
    default:
        http_response_code(404);
        exit;
}

echo json_encode(['success' => true, 'msg' => '', 'obj' => $obj]);
