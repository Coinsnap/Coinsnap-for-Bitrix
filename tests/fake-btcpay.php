<?php
// Router for `php -S`: a fake BTCPay Greenfield API for tests/security-unit-test.php.
// State (invoices, keys, forced status) is read from the JSON file in COINSNAP_FAKE_STATE; every request is appended to it.
$stateFile = getenv('COINSNAP_FAKE_STATE');
$state = json_decode((string) file_get_contents($stateFile), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$key = preg_replace('/^token /', '', $auth);

$state['requests'][] = $_SERVER['REQUEST_METHOD'] . ' ' . $path;
file_put_contents($stateFile, json_encode($state));

header('Content-Type: application/json');
if (!empty($state['down'])) {
    http_response_code(500);
    echo '{}';
    return true;
}
if ($path === '/api/v1/api-keys/current') {
    if (!isset($state['keys'][$key])) {
        http_response_code(401);
        echo '{}';
        return true;
    }
    echo json_encode(['apiKey' => $key, 'permissions' => $state['keys'][$key]]);
    return true;
}
if (preg_match('#^/api/v1/stores/([^/]+)/invoices/([^/]+)$#', $path, $m)) {
    $invoice = $state['invoices'][$m[2]] ?? null;
    if ($invoice === null || ($state['store'] ?? '') !== $m[1]) {
        http_response_code(404);
        echo '{}';
        return true;
    }
    echo json_encode($invoice + ['id' => $m[2]]);
    return true;
}
http_response_code(404);
echo '{}';
return true;
