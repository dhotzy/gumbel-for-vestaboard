<?php
declare(strict_types=1);

// Slack-Slash-Befehl (z. B. /board Hallo) → Board. Geprüft wird die Slack-Signatur (Signing Secret).

require __DIR__ . '/../../src/bootstrap.php';
send_security_headers();

use Vesta\App;

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('{}');
}
$raw = (string) file_get_contents('php://input', false, null, 0, 8193);
if (strlen($raw) > 8192) {
    http_response_code(413);
    exit('{}');
}
try {
    App::settings(); // Sprache
    $result = App::messenger()->handleSlack($raw, (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? ''), (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? ''));
    if ($result['status'] === 200 && isset($result['body']['text']) && str_starts_with((string) $result['body']['text'], '✓')) {
        App::triggerArbiter();
    }
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Slack-Fehler: ' . $e->getMessage());
    http_response_code(500);
    echo '{"text":"Error"}';
}
