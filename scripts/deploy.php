<?php
declare(strict_types=1);

// Deploy per FTPS auf klassisches PHP-Hosting.
// Aufruf: php scripts/deploy.php
//
// Zugangsdaten stehen in config/deploy.ini (nicht in Git, Vorlage wird beim ersten Aufruf angelegt).
// Erzeugt aus config/config.php (Token) + deploy.ini (DB) eine Server-Config und lädt sie als
// config/config.php hoch. Passwörter und Token werden nie ausgegeben.

const ROOT = __DIR__ . '/..';
const APP_ROOT = ROOT; // config.php nutzt APP_ROOT für den lokalen SQLite-Pfad
const INI = ROOT . '/config/deploy.ini';
const CRON_KEY_FILE = ROOT . '/var/prod-cron-key.txt';
const UPLOAD_PREFIXES = ['public/', 'src/', 'db/', 'docs/', 'lang/', 'recipes/', 'config/.htaccess', 'config/config.example.php', 'scripts/.htaccess', 'var/.htaccess', '.htaccess'];

if (!is_file(INI)) {
    file_put_contents(INI, <<<INI
    ; Zugangsdaten für den Deploy – Datei steht in .gitignore
    ftp_host = "ftp.example.com"
    ftp_user = ""
    ftp_pass = ""
    remote_dir = "/"

    db_name = ""
    db_user = ""
    db_pass = ""

    INI);
    fwrite(STDERR, "config/deploy.ini angelegt. Bitte FTP- und DB-Zugangsdaten eintragen und erneut aufrufen.\n");
    exit(1);
}

$ini = parse_ini_file(INI, false, INI_SCANNER_RAW);
foreach (['ftp_host', 'ftp_user', 'ftp_pass', 'remote_dir', 'db_name', 'db_user', 'db_pass'] as $key) {
    if (trim((string) ($ini[$key] ?? '')) === '') {
        fwrite(STDERR, "config/deploy.ini: '$key' fehlt.\n");
        exit(1);
    }
}

$local = require ROOT . '/config/config.php';
if (trim((string) ($local['vestaboard_token'] ?? '')) === '') {
    fwrite(STDERR, "config/config.php: vestaboard_token fehlt.\n");
    exit(1);
}

if (!is_file(CRON_KEY_FILE)) {
    file_put_contents(CRON_KEY_FILE, bin2hex(random_bytes(24)));
}
$cronKey = trim((string) file_get_contents(CRON_KEY_FILE));

$prodConfig = "<?php\n// Server-Config, erzeugt von scripts/deploy.php\nreturn " . var_export([
    'vestaboard_token' => (string) $local['vestaboard_token'],
    'cron_key' => $cronKey,
    'diag_enabled' => false,
    'debug' => false,
    'timezone' => 'Europe/Berlin',
    'board_driver' => 'vestaboard',
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'name' => (string) $ini['db_name'],
        'user' => (string) $ini['db_user'],
        'pass' => (string) $ini['db_pass'],
    ],
], true) . ";\n";

exec('git -C ' . escapeshellarg(ROOT) . ' ls-files', $tracked, $code);
if ($code !== 0) {
    fwrite(STDERR, "git ls-files fehlgeschlagen.\n");
    exit(1);
}
$files = array_values(array_filter($tracked, static function (string $file): bool {
    foreach (UPLOAD_PREFIXES as $prefix) {
        if ($file === $prefix || str_starts_with($file, $prefix)) {
            return true;
        }
    }
    return false;
}));

function upload(array $ini, string $remotePath, string $content): ?string
{
    $url = 'ftp://' . $ini['ftp_host'] . '/' . ltrim(rtrim((string) $ini['remote_dir'], '/') . '/' . $remotePath, '/');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => $ini['ftp_user'] . ':' . $ini['ftp_pass'],
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $stream,
        CURLOPT_INFILESIZE => strlen($content),
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_USE_SSL => CURLUSESSL_ALL,
        CURLOPT_SSL_OPTIONS => PHP_OS_FAMILY === 'Windows' ? CURLSSLOPT_NATIVE_CA : 0,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $ok = curl_exec($ch);
    $error = $ok ? null : curl_error($ch);
    fclose($stream);
    return $error;
}

$failed = 0;
foreach ($files as $file) {
    $error = upload($ini, $file, (string) file_get_contents(ROOT . '/' . $file));
    echo ($error === null ? 'ok    ' : 'FEHLER ') . $file . ($error !== null ? " – $error" : '') . "\n";
    $failed += $error === null ? 0 : 1;
    if ($failed > 0 && preg_match('/Login|Access denied|530/i', (string) $error)) {
        break; // falsche Zugangsdaten: nicht alle Dateien durchprobieren
    }
}
if ($failed === 0) {
    $error = upload($ini, 'config/config.php', $prodConfig);
    echo ($error === null ? 'ok    ' : 'FEHLER ') . 'config/config.php (Server-Config)' . ($error !== null ? " – $error" : '') . "\n";
    $failed += $error === null ? 0 : 1;
}

echo $failed === 0
    ? "\nFertig. Cron-Schlüssel steht in var/prod-cron-key.txt.\n"
    : "\n$failed Fehler.\n";
exit($failed === 0 ? 0 : 1);
