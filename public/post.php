<?php
declare(strict_types=1);

// Briefkasten: Gäste schicken ohne Konto eine Nachricht ans Board (geheimer Link / QR-Code). Sonst keine Funktionen.

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Charset;
use Vesta\Csrf;
use Vesta\GridHtml;
use Vesta\GuestPost;
use Vesta\View;

$guest = App::guestPost();
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
if (!$guest->isOpen($token)) {
    unset($_SESSION['guest_token']);
    http_response_code(404);
    View::header(t('Briefkasten'), null);
    echo '<h1>' . View::e(t('Briefkasten geschlossen')) . '</h1><p class="muted">' . View::e(t('Dieser Link funktioniert nicht (mehr). Frag am besten nach einem neuen QR-Code.')) . '</p>';
    View::footer();
    exit;
}
$_SESSION['guest_token'] = $token; // erlaubt die Live-Vorschau ohne Konto
$config = $guest->config();
$input = ['text' => '', 'name' => (string) ($_SESSION['guest_name'] ?? '')];
$sent = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $input = ['text' => (string) ($_POST['text'] ?? ''), 'name' => (string) ($_POST['name'] ?? '')];
    $result = $guest->submit($_POST, GuestPost::clientKey());
    if ($result['ok']) {
        $_SESSION['guest_name'] = mb_substr(trim($input['name']), 0, GuestPost::MAX_NAME);
        $_SESSION['guest_sent'] = (int) ($result['position'] ?? 1);
        if ($result['id'] !== null) {
            App::triggerArbiter();
        }
        View::redirect('post.php?t=' . rawurlencode($token));
    }
    View::flash('error', (string) $result['error']);
}
if (isset($_SESSION['guest_sent'])) {
    $sent = (int) $_SESSION['guest_sent'];
    unset($_SESSION['guest_sent']);
}
$preview = Charset::textToGrid($input['text'], 'center');

View::header(t('Nachricht ans Board'), null);
?>
<h1><?= View::e(t('Nachricht ans Board')) ?></h1>
<?php if ($sent !== null): ?>
    <p class="flash flash-success"><?= View::e($sent <= 1 ? t('Danke! Deine Nachricht kommt gleich aufs Board.') : t('Danke! Deine Nachricht ist in der Warteschlange (Platz {position}).', ['position' => $sent])) ?></p>
<?php endif; ?>
<p class="muted"><?= View::e(t('Schreib einen kurzen Gruß – er erscheint auf dem Vestaboard. Du brauchst kein Konto.')) ?></p>

<form method="post" action="post.php" class="card compose" data-busy data-preview>
    <?= Csrf::field() ?>
    <input type="hidden" name="t" value="<?= View::e($token) ?>">
    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>

    <div class="preview" data-preview-target aria-live="polite"><?= GridHtml::render($preview['grid']) ?></div>
    <ul class="warnings" data-preview-warnings></ul>

    <label for="text"><?= View::e(t('Text')) ?> <span class="muted"><?= View::e(t('(3 Zeilen à 15 Zeichen, Umlaute werden zu AE/OE/UE)')) ?></span></label>
    <textarea id="text" name="text" rows="3" maxlength="200" required data-preview-input><?= "\n" . View::e($input['text']) ?></textarea>
    <p class="counter<?= $preview['fits'] ? '' : ' over' ?>" data-preview-counter aria-live="polite"><?= View::e(Charset::counterText($preview)) ?></p>
    <?= View::tokenBar(false) ?>

    <label><?= View::e(t('Dein Name')) ?> <span class="muted"><?= View::e(t('(optional, sieht nur der Board-Besitzer)')) ?></span>
        <input name="name" maxlength="<?= GuestPost::MAX_NAME ?>" autocomplete="nickname" value="<?= View::e($input['name']) ?>"></label>
    <?php if ($config['can_force']): ?>
        <label><input type="checkbox" name="forced" value="1"> <span><?= View::e(t('Sofort zeigen')) ?> <span class="muted"><?= View::e(t('(Force – drängelt sich vor)')) ?></span></span></label>
    <?php endif; ?>

    <div class="buttons">
        <button type="submit" class="primary" data-preview-submit <?= $preview['fits'] ? '' : 'disabled' ?>><?= View::e(t('Abschicken')) ?></button>
    </div>
</form>
<?php
View::footer();
