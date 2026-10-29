<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Vestaboard Cloud API (https://docs.vestaboard.com/docs/read-write-api/endpoints).
 * Max. 1 Nachricht pro 15 Sekunden, sonst verwirft Vestaboard Nachrichten.
 *
 * Ergebnis jeder Anfrage:
 * array{ok: bool, status: int, error: ?string, body: ?array, raw: string, duration_ms: int}
 */
final class VestaClient implements BoardClient
{
    private const BASE_URL = 'https://cloud.vestaboard.com/';

    public function __construct(
        private readonly string $token,
        private readonly int $timeoutSeconds = 10
    ) {
    }

    public static function fromConfig(): self
    {
        return new self((string) (app_config()['vestaboard_token'] ?? ''));
    }

    /**
     * Liest die aktuelle Anzeige. Zusätzliche Felder: grid, message_id.
     */
    public function read(): array
    {
        $result = $this->request('GET');
        $result['grid'] = null;
        $result['message_id'] = null;
        if (!$result['ok']) {
            return $result;
        }
        $current = $result['body']['currentMessage'] ?? null;
        $grid = is_array($current) ? Charset::layoutToGrid($current['layout'] ?? null) : null;
        if ($grid === null) {
            $result['ok'] = false;
            $result['error'] = 'Antwort enthält kein lesbares Layout.';
            return $result;
        }
        $result['grid'] = $grid;
        $result['message_id'] = $current['id'] ?? null;
        return $result;
    }

    /**
     * Sendet ein 3×15-Raster. forced = auch bei Vestaboard-Mute/Ruhezeit anzeigen.
     */
    public function send(array $grid, bool $forced = false): array
    {
        if (!Charset::isValidGrid($grid)) {
            return self::failure(0, 'Ungültiges Raster (erwartet 3×15 gültige Zeichencodes).');
        }
        $payload = ['characters' => $grid];
        if ($forced) {
            $payload['forced'] = true;
        }
        return $this->request('POST', $payload);
    }

    private function request(string $method, ?array $payload = null): array
    {
        if ($this->token === '') {
            return self::failure(0, 'Kein Vestaboard-Token in config/config.php eingetragen.');
        }
        if (!function_exists('curl_init')) {
            return self::failure(0, 'PHP-Erweiterung cURL fehlt.');
        }

        $headers = ['X-Vestaboard-Token: ' . $this->token, 'Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];
        // Windows-PHP bringt keine Root-Zertifikate mit → System-Zertifikatsspeicher nutzen (nur lokal relevant)
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $handle = curl_init(self::BASE_URL);
        curl_setopt_array($handle, $options);
        $started = microtime(true);
        $raw = curl_exec($handle);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($raw === false) {
            $result = self::failure(0, 'Verbindung zu Vestaboard fehlgeschlagen: ' . curl_error($handle));
            $result['duration_ms'] = $durationMs;
            return $result;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = json_decode((string) $raw, true);
        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'error' => $ok ? null : self::describeError($status),
            'body' => is_array($body) ? $body : null,
            'raw' => (string) $raw,
            'duration_ms' => $durationMs,
        ];
    }

    private static function describeError(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => "Token ungültig oder ohne Berechtigung (HTTP $status).",
            $status === 404 => 'Kein Board zu diesem Token gefunden (HTTP 404).',
            $status === 423 => 'Board ist in der Vestaboard-App stummgeschaltet (HTTP 423). Nur mit forced möglich.',
            $status === 429 => 'Zu viele Anfragen (HTTP 429) – max. 1 Nachricht pro 15 Sekunden.',
            $status >= 500 => "Vestaboard-Server-Fehler (HTTP $status).",
            default => "Unerwartete Antwort von Vestaboard (HTTP $status).",
        };
    }

    private static function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'body' => null, 'raw' => '', 'duration_ms' => 0];
    }
}
