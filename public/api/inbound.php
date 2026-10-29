<?php
declare(strict_types=1);

// Eingang für Fremdsysteme und NFC-Sticker (Phase 3).
// POST, Schlüssel im Header "X-Key" (oder Feld "key"), Body JSON oder Formular:
//   { "text": "NEUE BUCHUNG", "force": false, "expires_in_min": 60 }
// Optional HMAC: X-Timestamp (Unix-Sekunden) + X-Signature: sha256=hex(hmac_sha256("<timestamp>.<body>", geheimnis)).
// Text kommt aus der Vorlage der Integration ({{feld.pfad}} aus dem Payload) oder aus dem Feld "text".
// Testmodus: Payload wird nur mitgeschnitten, Antwort enthält "test": true und den Text, der angezeigt würde.

require __DIR__ . '/../../src/bootstrap.php';
send_security_headers();

use Vesta\App;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    App::settings(); // Sprache für Texte und Fehlermeldungen (lädt die Einstellungen)
} catch (Throwable $e) {
    error_log('Webhook: ' . $e->getMessage()); // Fehler kommt unten beim Empfang erneut und wird beantwortet
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => t('Nur POST erlaubt.')]);
    exit;
}

// Nur JSON oder URL-kodiertes Formular, höchstens 64 KB – und der Payload stammt immer aus genau diesem
// rohen Body (so deckt die HMAC-Signatur alles ab; Multipart/$_POST wird bewusst nicht verwendet).
const MAX_BODY = 65536; // 64 KB – Shopify-Bestellungen u. Ä. sind 10–30 KB
$type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => t('Anfrage zu groß (höchstens 64 KB).')], JSON_UNESCAPED_UNICODE);
    exit;
}
$raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
if (strlen($raw) > MAX_BODY) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => t('Anfrage zu groß (höchstens 64 KB).')], JSON_UNESCAPED_UNICODE);
    exit;
}
if (str_contains($type, 'application/json')) {
    $payload = json_decode($raw, true, 32);
} elseif ($type === '' || str_contains($type, 'application/x-www-form-urlencoded')) {
    parse_str($raw, $payload);
} else {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => t('Bitte als JSON oder Formular (application/x-www-form-urlencoded) senden.')], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => t('Ungültiges JSON.')]);
    exit;
}
// Header bevorzugt; ?key= nur für Dienste, die keine eigenen Header senden können (GitHub, Shopify) – landet im Access-Log
$key = (string) ($_SERVER['HTTP_X_KEY'] ?? (is_string($_GET['key'] ?? null) ? $_GET['key'] : (is_string($payload['key'] ?? null) ? $payload['key'] : '')));
if (($payload['key'] ?? null) === $key) {
    unset($payload['key']); // Schlüssel nie im Mitschnitt/Verlauf speichern
}
$meta = ['raw' => $raw, 'signature' => (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''), 'timestamp' => (string) ($_SERVER['HTTP_X_TIMESTAMP'] ?? '')];

try {
    $result = App::inbound()->receive($key, $payload, $meta);
    if ($result['ok']) {
        App::triggerArbiter();
    }
} catch (Throwable $e) {
    error_log('Webhook: ' . $e->getMessage());
    $result = ['ok' => false, 'status' => 500, 'error' => t('Interner Fehler.'), 'id' => null];
}
http_response_code($result['status']);
$response = ['ok' => $result['ok'], 'error' => $result['error'], 'message_id' => $result['id']];
if (!empty($result['test'])) {
    $response += ['test' => true, 'text' => $result['text'] ?? '']; // Testmodus: nur mitgeschnitten
}
if (!empty($result['ignored'])) {
    $response['ignored'] = true; // keine passende Vorlage, „nicht anzeigen“ oder Duplikat
}
if (!empty($result['held_until'])) {
    $response['held_until'] = $result['held_until']; // außerhalb des Zeitfensters, erscheint später (UTC)
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);
