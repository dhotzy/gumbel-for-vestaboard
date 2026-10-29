<?php
declare(strict_types=1);

/**
 * Ersteinrichtung: setup.php?key=<cron_key>
 * Legt die Tabellen an und den ersten Admin. Funktioniert nur, solange es keinen Nutzer gibt.
 */

require __DIR__ . '/../src/bootstrap.php';
send_security_headers();

use Vesta\App;
use Vesta\Auth;
use Vesta\Db;
use Vesta\Time;
use Vesta\View;

$config = app_config();
$key = (string) ($_POST['key'] ?? $_GET['key'] ?? '');
if (($config['cron_key'] ?? '') === '' || !hash_equals((string) $config['cron_key'], $key)) {
    http_response_code(404);
    exit('Not found');
}

try {
    $pdo = App::pdo();
    Db::install($pdo);
    App::settings(); // setzt die Sprache (I18n)
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Setup: ' . $e->getMessage()); // Details nur ins Log, nicht in die Antwort
    exit(View::e(t('Datenbank nicht erreichbar. Bitte die Zugangsdaten in der Konfiguration prüfen.')));
}

if (App::users()->count() > 0) {
    http_response_code(404);
    exit(t('Bereits eingerichtet.'));
}

$error = null;
$name = trim((string) ($_POST['name'] ?? ''));
// Sprache gleich im ersten Schritt wählen (gilt für Oberfläche und Board-Texte)
$language = (string) ($_POST['language'] ?? $_GET['lang'] ?? \Vesta\I18n::lang());
if (isset(\Vesta\I18n::LANGUAGES[$language])) {
    \Vesta\I18n::set($language);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $error = $name === '' ? t('Bitte einen Namen angeben.') : Auth::validateSecret('password', $password);
    // Sperre: zwei gleichzeitige Einrichtungen dürfen nicht zwei Admins anlegen
    $lock = new Vesta\Lock(APP_ROOT . '/var/setup.lock');
    if ($error === null && (!$lock->acquire(5) || App::users()->count() > 0)) {
        http_response_code(409);
        exit(t('Bereits eingerichtet.'));
    }
    if ($error === null) {
        App::users()->insert([
            'name' => $name,
            'role' => 'admin',
            'login_type' => 'password',
            'secret_hash' => Auth::hashSecret($password),
            'source' => 'admin',
            'can_force' => 1,
            'can_jump' => 1,
            'can_schedule' => 1,
            'created_at' => Time::db(App::clock()->now()),
        ]);
        App::settings()->set('language', \Vesta\I18n::lang());
        // gleich angemeldet weiter zu Schritt 2: Board verbinden
        Auth::startSession();
        header('Location: ' . (Auth::login($name, $password) === null ? 'board.php?welcome=1' : 'login.php'));
        exit;
    }
}
?>
<!doctype html>
<html lang="<?= \Vesta\I18n::htmlLang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= View::e(t('Einrichtung')) ?> · Gumbel</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<main class="narrow">
    <h1><?= View::e(t('Einrichtung')) ?></h1>
    <p class="muted"><?= View::e(t('Schritt 1 von 2: Admin-Zugang anlegen. Danach verbindest du dein Board.')) ?></p>
    <?php if ($error !== null): ?><div class="flash flash-error"><?= View::e($error) ?></div><?php endif; ?>
    <form method="post" class="card">
        <input type="hidden" name="key" value="<?= View::e($key) ?>">
        <label>Sprache / Language <select name="language">
            <?php foreach (\Vesta\I18n::LANGUAGES as $code => $label): ?><option value="<?= $code ?>" <?= \Vesta\I18n::lang() === $code ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
        </select></label>
        <label><?= View::e(t('Name')) ?> <input name="name" value="<?= View::e($name) ?>" required autocomplete="username"></label>
        <label><?= View::e(t('Passwort (mind. 8 Zeichen)')) ?> <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <button type="submit" class="primary"><?= View::e(t('Admin anlegen')) ?></button>
    </form>
</main>
</body>
</html>
