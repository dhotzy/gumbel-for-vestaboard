<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Vestaboard Local API (https://docs.vestaboard.com/docs/local-api/introduction) – direkt im Heimnetz,
 * ohne Vestaboard-Cloud. Gumbel muss dafür im selben Netz laufen (Docker, Raspberry Pi, NAS).
 *
 * Ergebnis wie VestaClient: array{ok: bool, status: int, error: ?string, body: ?array, raw: string, duration_ms: int}
 */
final class LocalVestaClient implements BoardClient
{
    public const DEFAULT_PORT = 7000;

    public function __construct(
        private readonly string $host,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 5
    ) {
    }

    /** Host oder IP im Heimnetz, optional mit Port – keine URL, keine Pfade */
    public static function isValidHost(string $host): bool
    {
        return preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,252})(:\d{2,5})?$/', $host) === 1;
    }

    /**
     * Local API einmalig freischalten: Enablement-Token (von Vestaboard per E-Mail) gegen einen dauerhaften API-Key tauschen.
     * @return array{ok: bool, key: ?string, error: ?string}
     */
    public static function enable(string $host, string $enablementToken): array
    {
        if (!self::isValidHost($host) || $enablementToken === '') {
            return ['ok' => false, 'key' => null, 'error' => t('Bitte Adresse des Boards und Freischalt-Token angeben.')];
        }
        $result = self::call($host, 'POST', '/local-api/enablement', ['X-Vestaboard-Local-Api-Enablement-Token: ' . $enablementToken], null, 8);
        $key = $result['body']['apiKey'] ?? null;
        if (!$result['ok'] || !is_string($key) || $key === '') {
            return ['ok' => false, 'key' => null, 'error' => $result['error'] ?? t('Board hat keinen API-Key geliefert.')];
        }
        return ['ok' => true, 'key' => $key, 'error' => null];
    }

    public function read(): array
    {
        $result = self::call($this->host, 'GET', '/local-api/message', $this->auth(), null, $this->timeoutSeconds);
        $result['grid'] = null;
        $result['message_id'] = null;
        if (!$result['ok']) {
            return $result;
        }
        // Antwort: {"message": [[…]]} – ältere Firmware liefert das Raster direkt
        $layout = $result['body']['message'] ?? $result['body'];
        $grid = Charset::layoutToGrid($layout);
        if ($grid === null) {
            $result['ok'] = false;
            $result['error'] = t('Antwort enthält kein lesbares Layout.');
            return $result;
        }
        $result['grid'] = $grid;
        return $result;
    }

    /** forced gibt es lokal nicht – die Local API zeigt immer an (Ruhezeiten regelt Gumbel selbst) */
    public function send(array $grid, bool $forced = false): array
    {
        if (!Charset::isValidGrid($grid)) {
            return self::failure(0, 'Ungültiges Raster (erwartet 3×15 gültige Zeichencodes).');
        }
        return self::call($this->host, 'POST', '/local-api/message', $this->auth(), $grid, $this->timeoutSeconds);
    }

    private function auth(): array
    {
        return ['X-Vestaboard-Local-Api-Key: ' . $this->apiKey];
    }

    private static function call(string $host, string $method, string $path, array $headers, ?array $payload, int $timeout): array
    {
        if (!self::isValidHost($host)) {
            return self::failure(0, t('Keine gültige Adresse für das Board eingetragen.'));
        }
        if (!function_exists('curl_init')) {
            return self::failure(0, 'PHP-Erweiterung cURL fehlt.');
        }
        $url = 'http://' . (str_contains($host, ':') ? $host : $host . ':' . self::DEFAULT_PORT) . $path;
        $headers[] = 'Accept: application/json';
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $handle = curl_init($url);
        curl_setopt_array($handle, $options);
        $started = microtime(true);
        $raw = curl_exec($handle);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        if ($raw === false) {
            $result = self::failure(0, t('Board im Heimnetz nicht erreichbar ({error}). Läuft Gumbel im selben Netz?', ['error' => curl_error($handle)]));
            $result['duration_ms'] = $durationMs;
            return $result;
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = json_decode((string) $raw, true);
        $ok = $status >= 200 && $status < 300;
        return [
            'ok' => $ok,
            'status' => $status,
            'error' => $ok ? null : match (true) {
                $status === 401, $status === 403 => t('Local-API-Key ungültig (HTTP {status}).', ['status' => $status]),
                $status === 404 => t('Local API ist auf diesem Board nicht freigeschaltet (HTTP 404).'),
                default => t('Unerwartete Antwort vom Board (HTTP {status}).', ['status' => $status]),
            },
            'body' => is_array($body) ? $body : null,
            'raw' => (string) $raw,
            'duration_ms' => $durationMs,
        ];
    }

    private static function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'body' => null, 'raw' => '', 'duration_ms' => 0];
    }
}
