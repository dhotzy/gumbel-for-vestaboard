<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Abholen statt Empfangen: eine URL (JSON oder RSS/Atom) holen und als Payload aufbereiten.
 * Das Ergebnis läuft durch dieselben Vorlagen und Bedingungen wie ein Webhook.
 */
final class Feed
{
    public const INTERVALS = [5 => '5 Min', 15 => '15 Min', 30 => '30 Min', 60 => '1 Std', 180 => '3 Std', 720 => '12 Std', 1440 => '1 Tag'];
    private const MAX_BYTES = 524288;
    private const MAX_ITEMS = 5;

    /** Abruf-Intervalle, übersetzt (Schlüssel wie INTERVALS) */
    public static function intervals(): array
    {
        return [5 => t('5 Min'), 15 => t('15 Min'), 30 => t('30 Min'), 60 => t('1 Std'), 180 => t('3 Std'), 720 => t('12 Std'), 1440 => t('1 Tag')];
    }

    /** @param null|callable(string): array{body: ?string, error: ?string} $http für Tests ersetzbar */
    public function __construct(private $http = null)
    {
        $this->http ??= [self::class, 'httpGet'];
    }

    /** @return array{payload: ?array, error: ?string} */
    /** @param list<array{0: string, 1: string}> $headers eigene Header, z. B. Authorization */
    public function fetch(string $url, array $headers = []): array
    {
        $error = self::checkUrl($url);
        if ($error !== null) {
            return ['payload' => null, 'error' => $error];
        }
        $response = ($this->http)($url, $headers);
        if ($response['body'] === null) {
            return ['payload' => null, 'error' => $response['error'] ?? t('Abruf fehlgeschlagen.')];
        }
        $payload = self::parse($response['body']);
        return $payload === null
            ? ['payload' => null, 'error' => t('Antwort ist weder JSON noch RSS/Atom.')]
            : ['payload' => $payload, 'error' => null];
    }

    /** JSON → Array; RSS/Atom → {feed, title, link, description, date, items: [...]} (erster Eintrag oben) */
    public static function parse(string $body): ?array
    {
        $body = trim($body);
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        if (!str_starts_with($body, '<')) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return null;
        }
        $items = [];
        if (isset($xml->channel)) { // RSS 2.0
            foreach ($xml->channel->item as $item) {
                $items[] = self::item((string) $item->title, (string) $item->link, (string) $item->description, (string) $item->pubDate);
            }
            $feedTitle = (string) $xml->channel->title;
        } elseif ($xml->getName() === 'feed') { // Atom
            foreach ($xml->entry as $entry) {
                $link = isset($entry->link) ? (string) ($entry->link->attributes()['href'] ?? '') : '';
                $items[] = self::item((string) $entry->title, $link, (string) ($entry->summary ?? $entry->content ?? ''), (string) ($entry->updated ?? $entry->published ?? ''));
            }
            $feedTitle = (string) $xml->title;
        } else {
            return null;
        }
        $items = array_slice($items, 0, self::MAX_ITEMS);
        return ['feed' => trim($feedTitle)] + ($items[0] ?? ['title' => '', 'link' => '', 'description' => '', 'date' => '']) + ['items' => $items];
    }

    private static function item(string $title, string $link, string $description, string $date): array
    {
        $timestamp = strtotime($date);
        return [
            'title' => trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'link' => trim($link),
            'description' => trim(mb_substr(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 300)),
            'date' => $timestamp !== false ? gmdate('c', $timestamp) : trim($date),
        ];
    }

    public const MAX_HEADERS = 5;
    /** Platzhalter für gespeicherte Header-Werte in der Oberfläche (Geheimnisse werden nie angezeigt) */
    public const MASK = '••••••';
    /** Header, die Gumbel selbst setzt oder die das Ziel verbiegen würden */
    private const RESERVED_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'accept-encoding', 'te', 'upgrade', 'expect'];

    /**
     * Header-Zeilen „Name: Wert“ aus dem Formular prüfen. Ein Wert MASK (oder leer) übernimmt den gespeicherten Wert.
     * @param list<array{0: string, 1: string}> $stored
     * @return array{headers: list<array{0: string, 1: string}>, error: ?string}
     */
    public static function parseHeaders(string $text, array $stored = []): array
    {
        $old = [];
        foreach ($stored as [$name, $value]) {
            $old[strtolower($name)] = $value;
        }
        $headers = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9\-]{0,59})\s*:\s*(.*)$/', $line, $m)) {
                return ['headers' => [], 'error' => t('Header bitte als „Name: Wert“ angeben, eine Zeile je Header.')];
            }
            [$name, $value] = [$m[1], trim($m[2])];
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                return ['headers' => [], 'error' => t('Den Header „{name}“ setzt Gumbel selbst.', ['name' => $name])];
            }
            if ($value === self::MASK || $value === '') {
                $value = $old[strtolower($name)] ?? '';
            }
            if ($value === '' || strlen($value) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                return ['headers' => [], 'error' => t('Ungültiger oder leerer Wert für „{name}“.', ['name' => $name])];
            }
            $headers[] = [$name, $value];
        }
        if (count($headers) > self::MAX_HEADERS) {
            return ['headers' => [], 'error' => t('Höchstens {count} eigene Header.', ['count' => self::MAX_HEADERS])];
        }
        return ['headers' => $headers, 'error' => null];
    }

    /** @return list<array{0: string, 1: string}> gespeicherte Header einer Integration */
    public static function storedHeaders(array $hook): array
    {
        $list = json_decode((string) ($hook['pull_headers'] ?? ''), true);
        return is_array($list) ? array_values(array_filter($list, static fn ($h): bool => is_array($h) && count($h) === 2 && is_string($h[0]) && is_string($h[1]))) : [];
    }

    /** Für das Formular: Namen sichtbar, Werte maskiert – Platzhalter aus Rezepten bleiben sichtbar (muss noch ersetzt werden) */
    public static function headersForForm(array $hook): string
    {
        return implode("\n", array_map(static fn (array $h): string => $h[0] . ': ' . ($h[1] === Inbound::SECRET_PLACEHOLDER ? $h[1] : self::MASK), self::storedHeaders($hook)));
    }

    /** Erlaubte Ports – keine internen Dienste wie Redis (6379) oder SSH (22) über einen Abruf erreichbar */
    public const PORTS = [80, 443, 8080, 8443];
    private const MAX_REDIRECTS = 3;

    /** Nur http(s), übliche Ports und öffentliche Adressen – kein Zugriff auf interne Dienste des Servers oder des LANs */
    public static function checkUrl(string $url): ?string
    {
        return self::target($url)['error'];
    }

    /**
     * Ziel prüfen und auflösen. Alle IPv4-Adressen des Namens müssen öffentlich sein (sonst DNS-Tricks),
     * IPv6-Adressen ebenfalls (verbunden wird nur per IPv4 auf die geprüfte Adresse, siehe httpGet).
     * @return array{error: ?string, host?: string, port?: int, ip?: string}
     */
    public static function target(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return ['error' => t('Bitte eine vollständige http(s)-Adresse angeben.')];
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, self::PORTS, true)) {
            return ['error' => t('Nur die Ports 80, 443, 8080 und 8443 sind erlaubt.')];
        }
        $host = trim(strtolower((string) $parts['host']), '[]');
        $public = static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        $notPublic = ['error' => t('Diese Adresse ist nicht öffentlich erreichbar (oder der Name ist unbekannt).')];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $public($host) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ['error' => null, 'host' => $host, 'port' => $port, 'ip' => $host] : $notPublic;
        }
        $ips = gethostbynamel($host) ?: [];
        $v6 = function_exists('dns_get_record') ? array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6') : [];
        if ($ips === [] || array_filter([...$ips, ...$v6], static fn (string $ip): bool => !$public($ip)) !== []) {
            return $notPublic;
        }
        return ['error' => null, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Abholen mit festgenagelter, geprüfter IP (kein DNS-Rebinding) und Weiterleitungen, die einzeln geprüft werden.
     * @return array{body: ?string, error: ?string}
     */
    public static function httpGet(string $url, array $headers = []): array
    {
        $extra = array_map(static fn (array $h): string => $h[0] . ': ' . $h[1], $headers);
        $origin = static fn (string $u): string => strtolower((string) parse_url($u, PHP_URL_SCHEME) . '://' . parse_url($u, PHP_URL_HOST) . ':' . (parse_url($u, PHP_URL_PORT) ?? ''));
        $firstOrigin = $origin($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::target($url);
            if ($target['error'] !== null) {
                return ['body' => null, 'error' => $target['error']];
            }
            $handle = curl_init($url);
            $body = '';
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'Gumbel-Vestaboard/1.0',
                CURLOPT_SSL_OPTIONS => PHP_OS_FAMILY === 'Windows' ? CURLSSLOPT_NATIVE_CA : 0, // lokal: Windows-Zertifikatsspeicher
                // eigene Header (API-Schlüssel) nur an den ursprünglichen Host – nie an ein fremdes Weiterleitungsziel
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, application/rss+xml, application/atom+xml, text/xml;q=0.9, */*;q=0.5'],
                    $origin($url) === $firstOrigin ? $extra : []), // kein https→http, kein anderer Host oder Port
                // Größe begrenzen: Abbruch, sobald mehr als MAX_BYTES ankommen
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    $body .= $chunk;
                    return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
                },
            ]);
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);
            $error = curl_error($handle);
            curl_close($handle);
            if ($ok === false) {
                return ['body' => null, 'error' => strlen($body) > self::MAX_BYTES ? t('Antwort zu groß (max. 512 KB).') : t('Nicht erreichbar: {error}', ['error' => $error])];
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $location; // nächste Runde prüft das neue Ziel vollständig
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return ['body' => null, 'error' => t('Antwort mit HTTP {status}.', ['status' => $status])];
            }
            return ['body' => $body, 'error' => null];
        }
        return ['body' => null, 'error' => t('Zu viele Weiterleitungen.')];
    }
}
