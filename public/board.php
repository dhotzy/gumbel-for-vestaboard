<?php
declare(strict_types=1);

// Board-Verbindung (nur Admin): Cloud API (Standard) oder Local API im Heimnetz

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\BoardConnection;
use Vesta\Csrf;
use Vesta\Feed;
use Vesta\LocalVestaClient;
use Vesta\View;

$user = Auth::requireAdmin();
$connection = App::boardConnection();
$current = $connection->get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = View::post('action');
    $host = trim(View::post('local_host'));
    if ($action === 'enable') {
        $result = LocalVestaClient::enable($host, trim(View::post('enablement_token')));
        if ($result['ok']) {
            $connection->save(['mode' => 'local', 'local_host' => $host, 'local_key' => (string) $result['key']]);
            View::flash('success', t('Local API freigeschaltet – Gumbel spricht jetzt direkt mit dem Board.'));
        } else {
            View::flash('error', (string) $result['error']);
        }
        View::redirect('board.php');
    }
    if ($action === 'save') {
        $mode = View::post('mode') === 'local' ? 'local' : 'cloud';
        $values = ['mode' => $mode];
        $token = trim(View::post('cloud_token'));
        if (BoardConnection::configToken() === '' && $token !== '' && $token !== Feed::MASK) {
            $values['cloud_token'] = mb_substr($token, 0, 200);
        }
        $key = trim(View::post('local_key'));
        if ($key !== '' && $key !== Feed::MASK) {
            $values['local_key'] = mb_substr($key, 0, 200);
        } elseif ($host !== $current['local_host']) {
            $values['local_key'] = ''; // neue Adresse: den alten Key nicht an ein anderes Gerät schicken
        }
        if ($mode === 'local' && !LocalVestaClient::isValidHost($host)) {
            View::flash('error', t('Bitte die Adresse des Boards im Heimnetz angeben, z. B. 192.168.1.50.'));
            View::redirect('board.php');
        }
        $values['local_host'] = $host;
        $connection->save($values);
    }
    // Nach dem Speichern (und bei „Verbindung testen“) einmal lesen
    $read = App::boardConnection()->isConfigured() ? App::boardConnection()->client()->read() : ['ok' => false, 'error' => t('Verbindung ist noch nicht vollständig eingerichtet.')];
    View::flash($read['ok'] ? 'success' : 'error', $read['ok'] ? t('Verbindung steht – das Board antwortet.') : t('Keine Verbindung: {error}', ['error' => (string) $read['error']]));
    View::redirect('board.php');
}

$mode = $connection->mode();
$configToken = BoardConnection::configToken() !== '';
View::header(t('Board-Verbindung'), $user, 'settings');
?>
<h1><?= t('Board-Verbindung') ?></h1>
<?php if (isset($_GET['welcome']) || !$connection->isConfigured()): ?>
    <div class="card welcome">
        <p><strong><?= t('Schritt 2 von 2: Board verbinden') ?></strong></p>
        <p class="muted"><?= t('Am einfachsten mit der Cloud API: In der Vestaboard-App unter Einstellungen → API einen Read/Write-Token erstellen und unten eintragen. Danach „Speichern und testen“.') ?></p>
        <?php if ($connection->isConfigured()): ?><p><a class="button primary" href="index.php"><?= t('Fertig – erste Nachricht schreiben') ?></a></p><?php endif; ?>
    </div>
<?php else: ?>
    <p class="muted"><a href="settings.php">← <?= t('Einstellungen') ?></a></p>
<?php endif; ?>

<form method="post" class="card" data-busy>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <fieldset>
        <label><input type="radio" name="mode" value="cloud" <?= $mode === 'cloud' ? 'checked' : '' ?>> <?= t('Cloud API') ?> <span class="muted">(<?= t('Standard') ?>)</span></label>
        <label><input type="radio" name="mode" value="local" <?= $mode === 'local' ? 'checked' : '' ?>> <?= t('Local API') ?></label>
    </fieldset>
    <p class="muted hint"><?= t('Cloud API funktioniert überall – auch wenn Gumbel bei einem Webhoster läuft. Local API spricht direkt im Heimnetz mit dem Board, ohne Vestaboard-Cloud und schneller – nur wenn Gumbel im selben Netz läuft (Docker, Raspberry Pi, NAS).') ?></p>
    <?php if ((app_config()['board_driver'] ?? '') === 'log'): ?><p class="flash flash-warn"><?= t('Testbetrieb: In der Konfiguration ist kein echtes Board eingestellt (board_driver = log) – Nachrichten landen in var/board.log.') ?></p><?php endif; ?>

    <h2 class="headline headline-small"><?= t('Cloud API') ?></h2>
    <?php if ($configToken): ?>
        <p class="muted"><?= t('Der Token ist in der Konfiguration bzw. Umgebung (VESTABOARD_TOKEN) eingetragen und hat Vorrang.') ?></p>
    <?php else: ?>
        <label><?= t('Read/Write-Token') ?> <span class="muted"><?= t('(Vestaboard-App → Einstellungen → API → Cloud API)') ?></span>
            <input type="password" name="cloud_token" autocomplete="off" spellcheck="false" maxlength="200" value="<?= $current['cloud_token'] !== '' ? View::e(Feed::MASK) : '' ?>"></label>
    <?php endif; ?>

    <h2 class="headline headline-small"><?= t('Local API') ?></h2>
    <label><?= t('Adresse des Boards') ?> <span class="muted"><?= t('(IP im Heimnetz, steht in der Vestaboard-App bzw. im Router)') ?></span>
        <input name="local_host" maxlength="260" spellcheck="false" placeholder="192.168.1.50" value="<?= View::e($current['local_host']) ?>"></label>
    <label><?= t('Local-API-Key') ?> <span class="muted"><?= t('(falls schon vorhanden – sonst unten freischalten)') ?></span>
        <input type="password" name="local_key" autocomplete="off" spellcheck="false" maxlength="200" value="<?= $current['local_key'] !== '' ? View::e(Feed::MASK) : '' ?>"></label>

    <div class="buttons">
        <button type="submit" class="primary"><?= t('Speichern und testen') ?></button>
    </div>
</form>

<form method="post" class="card" data-busy>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="enable">
    <h2 class="headline"><?= t('Local API freischalten') ?></h2>
    <ol class="steps">
        <li><?= t('Auf vestaboard.com/local-api einen Freischalt-Token anfordern – er kommt per E-Mail.') ?></li>
        <li><?= t('Adresse des Boards und den Token hier eintragen.') ?></li>
        <li><?= t('Gumbel tauscht den Token einmalig gegen einen dauerhaften Key und schaltet auf Local API um.') ?></li>
    </ol>
    <label><?= t('Adresse des Boards') ?> <input name="local_host" maxlength="260" spellcheck="false" placeholder="192.168.1.50" value="<?= View::e($current['local_host']) ?>" required></label>
    <label><?= t('Freischalt-Token') ?> <input type="password" name="enablement_token" autocomplete="off" spellcheck="false" maxlength="200" required></label>
    <div class="buttons">
        <button type="submit"><?= t('Freischalten') ?></button>
    </div>
</form>

<form method="post" class="card" data-busy>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="test">
    <p><?= t('Aktuell: {mode}', ['mode' => $mode === 'local' ? t('Local API') : t('Cloud API')]) ?> · <?= $connection->isConfigured() ? t('eingerichtet') : t('noch nicht eingerichtet') ?></p>
    <button type="submit"><?= t('Verbindung testen') ?></button>
</form>
<?php
View::footer();
