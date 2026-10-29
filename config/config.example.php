<?php
// Vorlage. Kopieren nach config/config.php und ausfüllen.
// config/config.php steht in .gitignore und landet nie im Repo.

return [
    // Vestaboard Cloud API Token (Lesen + Schreiben), in der Vestaboard-App unter Einstellungen → API erstellen
    'vestaboard_token' => '',

    // Langer Zufallswert, schützt cron.php und diag.php
    // erzeugen z. B. mit: php -r "echo bin2hex(random_bytes(24));"
    'cron_key' => '',

    // Diagnose-Seite public/diag.php aktiv? Nur kurz zum Testen einschalten.
    'diag_enabled' => false,

    // PHP-Fehler im Browser anzeigen – nur lokal, nie auf dem Server
    'debug' => false,

    'timezone' => 'Europe/Berlin',

    // 'vestaboard' = echtes Board, 'log' = schreibt nur nach var/board.log (lokale Entwicklung)
    'board_driver' => 'vestaboard',

    // Hinter einem Reverse-Proxy: dessen IP(s), kommagetrennt – nur dann wird X-Forwarded-For geglaubt (Login-Sperre je IP)
    'trusted_proxies' => '',

    // true, wenn TLS vor PHP endet und HTTPS nicht erkannt wird (Secure-Cookie + HSTS)
    'force_https' => false,

    // MariaDB/MySQL-Zugangsdaten deines Hosters. Lokal oder Docker: 'driver' => 'sqlite', 'path' => APP_ROOT . '/var/dev.sqlite'
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'name' => '',
        'user' => '',
        'pass' => '',
    ],
];
