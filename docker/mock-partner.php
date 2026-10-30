<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json');

if ($method === 'GET' && $path === '/health') {
    http_response_code(200);
    echo '{"status":"ok"}';

    return;
}

$counterFile = sys_get_temp_dir().'/mystery-tag-mock-partner-count';

if ($method === 'GET' && $path === '/stats') {
    $count = is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
    http_response_code(200);
    echo json_encode(['post_count' => $count], JSON_THROW_ON_ERROR);

    return;
}

if ($method !== 'POST' || $path !== '/postback') {
    http_response_code(404);
    echo '{"status":"not_found"}';

    return;
}

$request = json_decode((string) file_get_contents('php://input'), true);
if (! is_array($request)
    || ! isset($request['click_id'], $request['event'])
    || ! array_key_exists('amount', $request)) {
    http_response_code(400);
    echo '{"status":"invalid_request"}';

    return;
}

$count = is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
file_put_contents($counterFile, (string) ($count + 1), LOCK_EX);

$delayMilliseconds = filter_var(getenv('MOCK_PARTNER_DELAY_MS') ?: '0', FILTER_VALIDATE_INT);
if (! is_int($delayMilliseconds) || $delayMilliseconds < 0 || $delayMilliseconds > 30000) {
    $delayMilliseconds = 0;
}

if ($delayMilliseconds > 0) {
    usleep($delayMilliseconds * 1000);
}

$status = filter_var(getenv('MOCK_PARTNER_STATUS') ?: '200', FILTER_VALIDATE_INT);
if (! is_int($status) || $status < 200 || $status > 599) {
    $status = 500;
}

http_response_code($status);
error_log(json_encode(['mock_partner_post' => $count + 1, 'response_status' => $status], JSON_THROW_ON_ERROR));
echo json_encode(['status' => $status < 300 ? 'ok' : 'error'], JSON_THROW_ON_ERROR);
