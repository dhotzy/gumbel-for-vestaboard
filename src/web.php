<?php
declare(strict_types=1);

// Gemeinsamer Einstieg aller Web-Seiten.

require __DIR__ . '/bootstrap.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Db;

send_security_headers();

// Fehler nie im Browser zeigen, außer im Debug-Modus (nur lokal)
ini_set('display_errors', !empty(app_config()['debug']) ? '1' : '0');

try {
    if (!Db::isInstalled(App::pdo())) {
        http_response_code(503);
        exit('Not set up yet – please open setup.php. / Noch nicht eingerichtet. Bitte setup.php aufrufen.');
    }
    App::settings(); // setzt die Sprache (I18n)
} catch (Throwable $e) {
    error_log('DB-Fehler: ' . $e->getMessage());
    http_response_code(503);
    exit(t('Datenbank nicht erreichbar. Bitte später erneut versuchen.'));
}

Auth::startSession();
