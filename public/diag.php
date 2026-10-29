<?php
declare(strict_types=1);

/**
 * Phase-0-Diagnose im Browser: diag.php?key=<cron_key>
 * Nur aktiv, wenn in config.php 'diag_enabled' => true und der Schlüssel stimmt.
 */

require __DIR__ . '/../src/bootstrap.php';
send_security_headers(true); // Diagnose-Seite nutzt eingebettetes CSS

use Vesta\Charset;
use Vesta\Diagnostics;
use Vesta\GridHtml;
use Vesta\VestaClient;

try {
    $config = app_config();
} catch (RuntimeException) {
    $config = [];
}

$key = (string) ($_POST['key'] ?? $_GET['key'] ?? '');
$expectedKey = (string) ($config['cron_key'] ?? '');
if (empty($config['diag_enabled']) || $expectedKey === '' || !hash_equals($expectedKey, $key)) {
    http_response_code(404);
    exit('Not found');
}

try {
    \Vesta\App::settings(); // setzt die Sprache (I18n), falls die Datenbank schon erreichbar ist
} catch (Throwable) {
}

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$checks = Diagnostics::environment();
$envOk = !in_array(false, array_column($checks, 'ok'), true);
try {
    $client = \Vesta\App::board(); // Cloud oder Local API laut Einstellung
} catch (Throwable) {
    $client = VestaClient::fromConfig(); // Datenbank noch nicht erreichbar
}

$text = (string) ($_POST['text'] ?? t("HALLO {heart}\nTEST OK {green}"));
$forced = isset($_POST['forced']);
$preview = Charset::textToGrid($text);
$outcome = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $envOk && $preview['fits']) {
    $outcome = Diagnostics::sendAndVerify($client, $preview['grid'], $forced);
}
$current = $envOk ? $client->read() : null;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
}

function status(bool $ok): string
{
    return $ok ? '<span class="ok">OK</span>' : '<span class="fail">' . e(t('FEHLER')) . '</span>';
}
?>
<!doctype html>
<html lang="<?= \Vesta\I18n::htmlLang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(t('Board-Diagnose')) ?></title>
    <link rel="stylesheet" href="assets/board.css">
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0 auto; padding: 16px; max-width: 760px; background: #f6f6f4; color: #1a1a1a; }
        h1 { font-size: 1.4rem; }
        h2 { font-size: 1.1rem; margin-top: 2rem; }
        table { border-collapse: collapse; width: 100%; }
        td { padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        .ok { color: #187a3c; font-weight: 700; }
        .fail { color: #b3261e; font-weight: 700; }
        .box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px; margin: 12px 0; }
        .warn { color: #8a5a00; }
        textarea { width: 100%; box-sizing: border-box; font: inherit; padding: 8px; }
        button { font: inherit; padding: 8px 16px; cursor: pointer; }
        code { background: #eee; padding: 1px 4px; border-radius: 3px; }
    </style>
</head>
<body>
<h1><?= e(t('Board-Diagnose (Phase 0)')) ?></h1>

<h2><?= e(t('1. Umgebung')) ?></h2>
<table>
    <?php foreach ($checks as $check): ?>
        <tr><td><?= status($check['ok']) ?></td><td><?= e($check['label']) ?></td><td><?= e($check['detail']) ?></td></tr>
    <?php endforeach; ?>
</table>

<?php if ($outcome !== null): ?>
    <h2><?= e(t('Ergebnis Testversand')) ?></h2>
    <div class="box">
        <p><?= status($outcome['send']['ok']) ?> <?= e(t('Senden')) ?>
            <?= $outcome['send']['ok'] ? '– HTTP ' . $outcome['send']['status'] . ', ' . $outcome['send']['duration_ms'] . ' ms' : '– ' . e((string) $outcome['send']['error']) ?></p>
        <?php if ($outcome['read'] !== null): ?>
            <p><?= status($outcome['matches']) ?> <?= e(t('Zurücklesen')) ?> –
                <?= e($outcome['matches'] ? t('Board zeigt die Testnachricht') : t('Board zeigt etwas anderes (Verzögerung? Board offline?)')) ?></p>
        <?php endif; ?>
        <p class="warn"><?= e(t('Vor dem nächsten Senden mindestens 15 Sekunden warten.')) ?></p>
    </div>
<?php endif; ?>

<h2><?= e(t('2. Aktuelle Anzeige')) ?></h2>
<?php if ($current === null): ?>
    <p class="fail"><?= e(t('Erst die Umgebungsfehler beheben.')) ?></p>
<?php elseif (!$current['ok']): ?>
    <p><?= status(false) ?> <?= e((string) $current['error']) ?></p>
<?php else: ?>
    <?= GridHtml::render($current['grid']) ?>
    <p><?= e(t('Format:')) ?> <?= count($current['grid']) ?>×<?= count($current['grid'][0] ?? []) ?>
        <?= count($current['grid']) === Charset::ROWS && count($current['grid'][0] ?? []) === Charset::COLS ? status(true) : status(false) . ' ' . e(t('(erwartet 3×15)')) ?></p>
<?php endif; ?>

<h2><?= e(t('3. Testnachricht senden')) ?></h2>
<form method="post" class="box">
    <input type="hidden" name="key" value="<?= e($key) ?>">
    <label for="text"><?= e(t('Text (Platzhalter:')) ?> <code>{heart}</code> <code>{red}</code> <code>{orange}</code> <code>{yellow}</code> <code>{green}</code> <code>{blue}</code> <code>{violet}</code> <code>{white}</code> <code>{black}</code>)</label>
    <textarea id="text" name="text" rows="3"><?= e($text) ?></textarea>
    <p><?= e(t('Vorschau:')) ?></p>
    <?= GridHtml::render($preview['grid']) ?>
    <?php foreach ($preview['warnings'] as $warning): ?>
        <p class="warn"><?= e($warning) ?></p>
    <?php endforeach; ?>
    <p><label><input type="checkbox" name="forced" <?= $forced ? 'checked' : '' ?>> <?= e(t('forced (auch bei Stummschaltung in der Vestaboard-App)')) ?></label></p>
    <button type="submit" <?= $envOk ? '' : 'disabled' ?>><?= e(t('Senden und zurücklesen')) ?></button>
    <p><small><?= e(t('Die Vorschau aktualisiert sich beim Absenden. Text zu lang → es wird nicht gesendet.')) ?></small></p>
</form>
</body>
</html>
