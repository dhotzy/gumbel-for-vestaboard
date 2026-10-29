<?php
declare(strict_types=1);

// Live-Vorschau für den Editor: POST text, align → JSON {html, fits, warnings}

require __DIR__ . '/../../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Charset;
use Vesta\Csrf;
use Vesta\GridHtml;
use Vesta\WebhookTemplate;

header('Content-Type: application/json; charset=utf-8');

// Angemeldet – oder Gast mit gültigem Briefkasten-Link (dann ohne Webhook-Payload)
$isGuest = Auth::user() === null && App::guestPost()->isOpen((string) ($_SESSION['guest_token'] ?? ''));
if (Auth::user() === null && !$isGuest) {
    http_response_code(401);
    echo json_encode(['error' => t('Nicht angemeldet.')], JSON_UNESCAPED_UNICODE);
    exit;
}
Csrf::verify();

$text = mb_substr((string) ($_POST['text'] ?? ''), 0, 500);
$align = Charset::align($_POST['align'] ?? 'center');
$payloadRaw = $isGuest ? '' : (string) ($_POST['payload'] ?? '');
$payload = strlen($payloadRaw) <= 65536 ? json_decode($payloadRaw, true, 32) : null; // wie beim Empfang: höchstens 64 KB
if (is_array($payload)) {
    // Webhook-Vorlage mit dem letzten Payload füllen; Ergebnis begrenzen (Platzhalter können Text vervielfachen)
    $text = mb_substr(WebhookTemplate::textFor($text, $payload), 0, Charset::FIT_MAX_CHARS);
}
if (!empty($_POST['fit']) && $align !== 'grid') {
    $text = Charset::fit($text); // Webhook-Vorlagen: so gekürzt, wie es später aufs Board kommt
}
$result = Charset::textToGrid($text, $align);

echo json_encode([
    'html' => GridHtml::render($result['grid']),
    'fits' => $result['fits'],
    'warnings' => $result['warnings'],
    'counter' => Charset::counterText($result),
    'exact' => Charset::gridToRawText($result['grid'], true), // für den Wechsel in den Designer
], JSON_UNESCAPED_UNICODE);
