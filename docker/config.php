<?php
// Konfiguration im Docker-Container – alle Werte aus Umgebungsvariablen (siehe docker-compose.yml).
// Der Cron-Schlüssel wird beim ersten Start erzeugt und im Daten-Volume gespeichert (var/cron_key).

$keyFile = APP_ROOT . '/var/cron_key';
$cronKey = (string) getenv('GUMBEL_CRON_KEY');
if ($cronKey === '' && is_file($keyFile)) {
    $cronKey = trim((string) file_get_contents($keyFile));
}

return [
    'vestaboard_token' => (string) getenv('VESTABOARD_TOKEN'),
    'cron_key' => $cronKey,
    'diag_enabled' => getenv('GUMBEL_DIAG') === '1',
    'debug' => false,
    'timezone' => getenv('TZ') ?: 'Europe/Berlin',
    'language' => getenv('GUMBEL_LANGUAGE') ?: 'en',
    // 'log' = kein echtes Board (zum Ausprobieren), sonst Vestaboard Cloud API
    'board_driver' => getenv('GUMBEL_BOARD') === 'log' ? 'log' : 'vestaboard',
    'db' => ['driver' => 'sqlite', 'path' => APP_ROOT . '/var/gumbel.sqlite'],
    // Hinter einem Reverse-Proxy mit TLS (Caddy, Traefik, nginx): dessen IP(s), z. B. "172.18.0.1"
    'trusted_proxies' => (string) getenv('GUMBEL_TRUSTED_PROXIES'),
    // Immer HTTPS annehmen (Secure-Cookie, HSTS), wenn der Proxy TLS übernimmt
    'force_https' => getenv('GUMBEL_FORCE_HTTPS') === '1',
];
