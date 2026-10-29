<?php
declare(strict_types=1);

// Briefkasten (nur Admin): Gast-Nachrichten ohne Konto erlauben, Regeln, Link und QR-Code

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Csrf;
use Vesta\GuestPost;
use Vesta\QrCode;
use Vesta\View;

$user = Auth::requireAdmin();
$guest = App::guestPost();
$config = $guest->config();
$pick = static fn (string $field, array $allowed, int $fallback): int => in_array((int) View::post($field), $allowed, true) ? (int) View::post($field) : $fallback;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = View::post('action');
    if ($action === 'regenerate') {
        $guest->regenerateToken();
        View::flash('success', t('Neuer Link erstellt – alte Links und gedruckte QR-Codes funktionieren nicht mehr.'));
    } else {
        $guest->save([
            'enabled' => !empty($_POST['enabled']),
            'can_force' => !empty($_POST['can_force']),
            'jump' => !empty($_POST['jump']),
            'cooldown_min' => $pick('cooldown_min', GuestPost::COOLDOWNS, 10),
            'max_per_hour' => $pick('max_per_hour', GuestPost::PER_HOUR, 10),
            'max_waiting' => $pick('max_waiting', GuestPost::WAITING, 5),
            'expires_min' => $pick('expires_min', GuestPost::EXPIRES, 120),
        ]);
        if (!empty($_POST['enabled']) && $config['token'] === '') {
            $guest->regenerateToken();
        }
        View::flash('success', t('Gespeichert.'));
    }
    View::redirect('guest.php');
}

$url = $config['token'] !== '' ? GuestPost::url((string) $config['token']) : '';
if ($url !== '' && isset($_GET['qr'])) {
    header('Content-Type: image/svg+xml');
    header('Content-Disposition: attachment; filename="gumbel-briefkasten.svg"');
    echo QrCode::encode($url)->toSvg(12);
    exit;
}
if ($url !== '' && isset($_GET['print'])) {
    // Druckansicht: QR-Code mit kurzer Anleitung, z. B. zum Aufhängen neben dem Board
    View::header(t('Briefkasten'), null);
    echo '<div class="guest-print"><h1>' . View::e(t('Schick uns eine Nachricht aufs Board')) . '</h1>'
        . '<div class="qr">' . QrCode::encode($url)->toSvg(10) . '</div>'
        . '<p>' . View::e(t('QR-Code mit der Handy-Kamera scannen, Text schreiben, abschicken – fertig. Kein Konto nötig.')) . '</p>'
        . '<p class="muted"><button type="button" class="primary no-print" data-print>' . View::e(t('Drucken')) . '</button></p></div>';
    View::footer();
    exit;
}

$minutes = static fn (int $m): string => $m >= 60 ? t('{count} Std', ['count' => intdiv($m, 60)]) : t('{count} Min', ['count' => $m]);
View::header(t('Briefkasten'), $user, 'settings');
?>
<h1><?= t('Briefkasten') ?></h1>
<p class="muted"><a href="settings.php">← <?= t('Einstellungen') ?></a></p>
<p class="muted"><?= t('Freunde und Gäste schicken über einen Link oder QR-Code eine Nachricht ans Board – ohne Konto. Sie können nur Text, Farben und Herzen schreiben, sonst nichts.') ?></p>

<form method="post" class="card" data-busy>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <label><input type="checkbox" name="enabled" value="1" <?= $config['enabled'] ? 'checked' : '' ?>> <span><strong><?= t('Briefkasten offen') ?></strong></span></label>
    <label><input type="checkbox" name="jump" value="1" <?= $config['jump'] ? 'checked' : '' ?>> <span><?= t('vorne anstellen') ?> <span class="muted"><?= t('(Gast-Nachrichten stellen sich vorne an – Rang und Mindestdauer der Quelle „Gäste“ stellst du in der Rangfolge ein)') ?></span></span></label>
    <label><input type="checkbox" name="can_force" value="1" <?= $config['can_force'] ? 'checked' : '' ?>> <span><?= t('Gäste dürfen Force') ?> <span class="muted"><?= t('(„Sofort zeigen“ – unterbricht laufende Nachrichten)') ?></span></span></label>
    <div class="field-row">
        <label><?= t('Pause pro Gerät') ?> <select name="cooldown_min"><?php foreach (GuestPost::COOLDOWNS as $m): ?><option value="<?= $m ?>" <?= (int) $config['cooldown_min'] === $m ? 'selected' : '' ?>><?= View::e($minutes($m)) ?></option><?php endforeach; ?></select></label>
        <label><?= t('Höchstens pro Stunde') ?> <select name="max_per_hour"><?php foreach (GuestPost::PER_HOUR as $n): ?><option value="<?= $n ?>" <?= (int) $config['max_per_hour'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
        <label><?= t('Höchstens wartend') ?> <select name="max_waiting"><?php foreach (GuestPost::WAITING as $n): ?><option value="<?= $n ?>" <?= (int) $config['max_waiting'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
        <label><?= t('Verfällt nach') ?> <select name="expires_min"><?php foreach (GuestPost::EXPIRES as $m): ?><option value="<?= $m ?>" <?= (int) $config['expires_min'] === $m ? 'selected' : '' ?>><?= View::e($minutes($m)) ?></option><?php endforeach; ?></select></label>
    </div>
    <p class="muted hint"><?= t('Gast-Nachrichten, die nicht rechtzeitig drankommen, verfallen. Stummschaltung und Ruhezeiten gelten wie für alle anderen.') ?></p>
    <div class="buttons"><button type="submit" class="primary"><?= t('Speichern') ?></button></div>
</form>

<?php if ($config['enabled'] && $url !== ''): ?>
    <section class="card guest-share">
        <h2 class="headline"><?= t('Link und QR-Code') ?></h2>
        <div class="qr"><?= QrCode::encode($url)->toSvg(6) ?></div>
        <div class="copy-row" data-copy-scope><span class="copy-label"><?= t('Link zum Weitergeben') ?></span><code data-copy><?= View::e($url) ?></code><button type="button" class="copy-button" data-copy-button><?= t('Kopieren') ?></button></div>
        <div class="buttons">
            <a class="button" href="guest.php?qr=1"><?= t('QR-Code herunterladen (SVG)') ?></a>
            <a class="button" href="guest.php?print=1" target="_blank" rel="noopener"><?= t('Druckansicht') ?></a>
        </div>
        <form method="post" data-busy>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="regenerate">
            <p class="muted hint"><?= t('Link in falsche Hände geraten? Ein neuer Link macht alle alten Links und QR-Codes ungültig.') ?></p>
            <button type="submit" class="link danger" data-confirm-click="<?= View::e(t('Neuen Link erstellen? Alte QR-Codes funktionieren danach nicht mehr.')) ?>"><?= t('Neuen Link erstellen') ?></button>
        </form>
    </section>
<?php elseif (!$config['enabled']): ?>
    <p class="muted"><?= t('Der Briefkasten ist geschlossen – der Link zeigt dann nur „Briefkasten geschlossen“.') ?></p>
<?php endif; ?>
<?php
View::footer();
