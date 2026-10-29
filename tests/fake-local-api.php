<?php
declare(strict_types=1);

// Nachgebaute Vestaboard Local API für tests/local-api-test.php (php -S … tests/fake-local-api.php)

$state = sys_get_temp_dir() . '/gumbel-fake-local-api.json';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/local-api/enablement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_X_VESTABOARD_LOCAL_API_ENABLEMENT_TOKEN'] ?? '') !== 'enable-me') {
        http_response_code(401);
        exit('{"message":"bad token"}');
    }
    exit('{"message":"Local API enabled","apiKey":"local-key-123"}');
}
if ($path !== '/local-api/message') {
    http_response_code(404);
    exit('{}');
}
if (($_SERVER['HTTP_X_VESTABOARD_LOCAL_API_KEY'] ?? '') !== 'local-key-123') {
    http_response_code(401);
    exit('{}');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($state, (string) file_get_contents('php://input'));
    exit('{"message":"ok"}');
}
$grid = is_file($state) ? json_decode((string) file_get_contents($state), true) : array_fill(0, 3, array_fill(0, 15, 0));
echo json_encode(['message' => $grid]);
