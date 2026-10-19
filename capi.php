<?php
// Requires PHP 8+ and the cURL extension.
// Never put the access token in public HTML.

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

function reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['ok' => false]);
}

$token = getenv('META_CAPI_TOKEN');
$version = getenv('META_GRAPH_VERSION');
$landing = getenv('LANDING_URL');

if (!$token || !$landing ||
    !preg_match('/^v\d+\.\d+$/', $version ?: '')) {
    reply(503, ['ok' => false, 'error' => 'Server configuration missing']);
}

$url = parse_url($landing);

if (!$url || ($url['scheme'] ?? '') !== 'https' ||
    empty($url['host'])) {
    reply(503, ['ok' => false]);
}

$allowedOrigin = 'https://' . $url['host'];
if (isset($url['port'])) {
    $allowedOrigin .= ':' . $url['port'];
}

// Reject cross-origin browser requests.
// This check is not a complete bot-protection system.
if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $allowedOrigin) {
    reply(403, ['ok' => false]);
}

$raw = file_get_contents('php://input', false, null, 0, 2049);
if ($raw === false || strlen($raw) > 2048) {
    reply(413, ['ok' => false]);
}

$input = json_decode($raw, true);
$id = is_array($input) ? ($input['event_id'] ?? '') : '';

if (!is_string($id) ||
    !preg_match('/^[a-zA-Z0-9-]{10,100}$/', $id)) {
    reply(400, ['ok' => false]);
}

session_name('rb_tracking');
session_set_cookie_params([
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
    'path' => '/'
]);
session_start();

// Basic per-session repeat filtering.
// It does not identify all bots or stop fake ad clicks.
$now = time();
$last = (int)($_SESSION['last_tg_event'] ?? 0);

if ($now - $last < 30) {
    session_write_close();
    reply(429, ['ok' => false]);
}

$_SESSION['last_tg_event'] = $now;
session_write_close();

$userData = [
    'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'client_user_agent' => substr(
        $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000
    )
];

// Use existing Meta cookies when present; do not fabricate them.
foreach (['_fbp' => 'fbp', '_fbc' => 'fbc'] as $cookie => $field) {
    $value = $_COOKIE[$cookie] ?? '';
    if (is_string($value) && strlen($value) <= 500 &&
        preg_match('/^fb\.\d+\.\d+\.[A-Za-z0-9._-]+$/', $value)) {
        $userData[$field] = $value;
    }
}

$payload = [
    'data' => [[
        'event_name' => 'TelegramClick',
        'event_time' => $now,
        'event_id' => $id,
        'action_source' => 'website',
        'event_source_url' => $landing,
        'user_data' => $userData,
        'custom_data' => [
            'channel_name' => 'RATHORE BHAI'
        ]
    ]]
];

$endpoint = 'https://graph.facebook.com/' .
    $version . '/1062681406350004/events';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ],
    CURLOPT_POSTFIELDS => json_encode($payload)
]);

$response = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = is_string($response) ?
    json_decode($response, true) : null;

if ($status < 200 || $status >= 300 ||
    (int)($result['events_received'] ?? 0) < 1) {
    // Do not expose credentials or Meta's raw response publicly.
    reply(502, ['ok' => false]);
}

reply(200, ['ok' => true]);
