<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

// Fehler nie im Browser/in API-Antworten zeigen (Pfade, SQL); web.php schaltet sie nur mit 'debug' ein
ini_set('display_errors', '0');
ini_set('expose_php', '0');

date_default_timezone_set('Europe/Berlin');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Vesta\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Sicherheits-Header für jede Antwort: keine Einbettung in fremde Seiten, keine Inline-Skripte (CSP),
 * kein MIME-Sniffing, keine Referrer nach außen, HSTS bei HTTPS. $inlineStyles nur für die Diagnose-Seite.
 */
function send_security_headers(bool $inlineStyles = false): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Robots-Tag: noindex');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'" . ($inlineStyles ? " 'unsafe-inline'" : '')
        . "; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (\Vesta\Auth::isHttps()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/** Übersetzen (Quelltext Deutsch, siehe Vesta\I18n und lang/) */
function t(string $text, array $params = []): string
{
    return \Vesta\I18n::t($text, $params);
}

/** Übersetzen mit Kontext, wenn derselbe deutsche Text je nach Stelle anders heißt (z. B. „Alle“ = Every/All) */
function tc(string $context, string $text, array $params = []): string
{
    return \Vesta\I18n::t($text, $params, $context);
}

/**
 * Lädt config/config.php (einmalig). Wirft eine Exception, wenn die Datei fehlt.
 */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        // VESTA_CONFIG erlaubt eine abweichende Config, z. B. für lokale Tests ohne echtes Board
        $file = getenv('VESTA_CONFIG') ?: APP_ROOT . '/config/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config/config.php fehlt – bitte aus config/config.example.php kopieren und ausfüllen.');
        }
        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new RuntimeException('config/config.php muss ein Array zurückgeben.');
        }
        $config = $loaded;
        if (!empty($config['timezone'])) {
            date_default_timezone_set($config['timezone']);
        }
    }
    return $config;
}
