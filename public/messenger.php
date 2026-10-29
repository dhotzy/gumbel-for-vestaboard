<?php
declare(strict_types=1);

// Messenger (nur Admin): Telegram-Bot und Slack-Slash-Befehl einrichten, Telegram-Chats freigeben

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Csrf;
use Vesta\Feed;
use Vesta\GuestPost;
use Vesta\Messenger;
use Vesta\Time;
use Vesta\View;

$user = Auth::requireAdmin();
$messenger = App::messenger();
$config = $messenger->config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $chatId = View::post('chat_id');
    switch (View::post('action')) {
        case 'telegram_connect':
            $result = $messenger->connectTelegram(trim(View::post('token')));
            View::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? t('Verbunden mit @{bot}. Schreib dem Bot jetzt eine Nachricht und gib dich unten frei.', ['bot' => $result['bot']]) : (string) $result['error']);
            break;
        case 'telegram_disconnect':
            $messenger->disconnectTelegram();
            View::flash('success', t('Telegram getrennt.'));
            break;
        case 'telegram_check':
            $poll = $messenger->pollTelegram();
            View::flash(isset($poll['error']) ? 'error' : 'success', $poll['error'] ?? t('Abgefragt – {count} neue Nachricht(en).', ['count' => $poll['received']]));
            App::triggerArbiter();
            break;
        case 'telegram_approve':
            $messenger->approveTelegram($chatId);
            View::flash('success', t('Freigegeben.'));
            break;
        case 'telegram_remove':
            $messenger->removeTelegram($chatId);
            View::flash('success', t('Entfernt.'));
            break;
        case 'save':
            $secret = trim(View::post('slack_secret'));
            $config['slack']['enabled'] = !empty($_POST['slack_enabled']);
            if ($secret !== '' && $secret !== Feed::MASK) {
                $config['slack']['secret'] = mb_substr($secret, 0, 100);
            }
            if ($config['slack']['enabled'] && $config['slack']['secret'] === '') {
                $config['slack']['enabled'] = false;
                View::flash('error', t('Für Slack bitte zuerst das Signing Secret eintragen.'));
            }
            $config['cooldown_min'] = in_array((int) View::post('cooldown_min'), Messenger::COOLDOWNS, true) ? (int) View::post('cooldown_min') : 1;
            $config['expires_min'] = in_array((int) View::post('expires_min'), GuestPost::EXPIRES, true) ? (int) View::post('expires_min') : 120;
            $messenger->save($config);
            View::flash('success', t('Gespeichert.'));
            break;
    }
    View::redirect('messenger.php');
}

$telegram = $config['telegram'];
$slackUrl = str_replace('/post.php?t=', '/api/slack.php', GuestPost::url(''));
$minutes = static fn (int $m): string => $m === 0 ? t('keine') : ($m >= 60 ? t('{count} Std', ['count' => intdiv($m, 60)]) : t('{count} Min', ['count' => $m]));
View::header(t('Messenger'), $user, 'settings');
?>
<h1><?= t('Messenger') ?></h1>
<p class="muted"><a href="settings.php">← <?= t('Einstellungen') ?></a></p>
<p class="muted"><?= t('Nachrichten aus Telegram oder Slack erscheinen als Quelle „Messenger“ auf dem Board – Rang und Mindestdauer stellst du in der Rangfolge ein.') ?></p>

<section class="card">
    <h2 class="headline"><?= t('Telegram') ?> <?= $telegram['enabled'] ? '<span class="badge badge-ok">' . t('verbunden') . '</span>' : '' ?></h2>
    <?php if (!$telegram['enabled']): ?>
        <ol class="steps">
            <li><?= t('In Telegram @BotFather öffnen, /newbot senden und einen Namen wählen.') ?></li>
            <li><?= t('Den Bot-Token (123456:ABC…) hier eintragen und verbinden.') ?></li>
            <li><?= t('Dem Bot eine Nachricht schreiben – du erscheinst unten als Anfrage und gibst dich (und später Familie und Freunde) frei.') ?></li>
        </ol>
        <form method="post" data-busy>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="telegram_connect">
            <label><?= t('Bot-Token') ?> <input type="password" name="token" autocomplete="off" spellcheck="false" maxlength="100" placeholder="123456789:AAH…" required></label>
            <div class="buttons"><button type="submit" class="primary"><?= t('Verbinden') ?></button></div>
        </form>
        <p class="muted hint"><?= t('Gumbel fragt Telegram jede Minute nach neuen Nachrichten – das klappt auch zu Hause ohne öffentliche Adresse.') ?></p>
    <?php else: ?>
        <p class="muted"><?= t('Verbunden mit {bot}', ['bot' => '<strong>@' . View::e($telegram['bot']) . '</strong>']) ?> – <?= t('schreib dem Bot, um es auszuprobieren.') ?></p>
        <?php if ($telegram['pending'] !== []): ?>
            <h3 class="subhead"><?= t('Anfragen') ?></h3>
            <?php foreach ($telegram['pending'] as $id => $pending): ?>
                <form method="post" class="custom-source" data-busy>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="chat_id" value="<?= View::e((string) $id) ?>">
                    <span><?= View::e($pending['name']) ?> <span class="muted">· <?= View::e(Time::format($pending['at'] ?? null, 'd.m. H:i')) ?></span></span>
                    <span><button type="submit" name="action" value="telegram_approve" class="primary"><?= t('Freigeben') ?></button>
                    <button type="submit" name="action" value="telegram_remove" class="link danger"><?= t('Ablehnen') ?></button></span>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
        <h3 class="subhead"><?= t('Freigegeben') ?></h3>
        <?php if ($telegram['allowed'] === []): ?>
            <p class="muted"><?= t('Noch niemand – schreib dem Bot eine Nachricht, dann erscheinst du oben als Anfrage.') ?></p>
        <?php endif; ?>
        <?php foreach ($telegram['allowed'] as $id => $allowed): ?>
            <form method="post" class="custom-source" data-busy>
                <?= Csrf::field() ?>
                <input type="hidden" name="chat_id" value="<?= View::e((string) $id) ?>">
                <span><?= View::e($allowed['name']) ?></span>
                <button type="submit" name="action" value="telegram_remove" class="link danger" data-confirm-click="<?= View::e(t('{name} entfernen?', ['name' => $allowed['name']])) ?>"><?= t('Entfernen') ?></button>
            </form>
        <?php endforeach; ?>
        <form method="post" class="buttons" data-busy>
            <?= Csrf::field() ?>
            <button type="submit" name="action" value="telegram_check"><?= t('Jetzt abfragen') ?></button>
            <button type="submit" name="action" value="telegram_disconnect" class="link danger" data-confirm-click="<?= View::e(t('Telegram trennen? Freigaben werden gelöscht.')) ?>"><?= t('Trennen') ?></button>
        </form>
    <?php endif; ?>
</section>

<form method="post" class="card" data-busy>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <h2 class="headline"><?= t('Slack') ?> <?= $config['slack']['enabled'] ? '<span class="badge badge-ok">' . t('aktiv') . '</span>' : '' ?></h2>
    <p class="muted hint"><?= t('Mit dem Befehl /board Text schickt jeder im Workspace eine Nachricht aufs Board. Braucht eine öffentlich erreichbare Installation mit HTTPS.') ?></p>
    <details class="help" <?= $config['slack']['secret'] === '' ? 'open' : '' ?>>
        <summary><?= t('So richtest du Slack ein') ?></summary>
        <ol class="steps">
            <li><?= t('Auf api.slack.com/apps eine App anlegen („From scratch“).') ?></li>
            <li><?= t('Unter „Slash Commands“ einen Befehl anlegen, z. B. /board, mit dieser Request URL:') ?></li>
        </ol>
        <div class="copy-row" data-copy-scope><span class="copy-label"><?= t('Request URL') ?></span><code data-copy><?= View::e($slackUrl) ?></code><button type="button" class="copy-button" data-copy-button><?= t('Kopieren') ?></button></div>
        <ol class="steps" start="3">
            <li><?= t('Unter „Basic Information“ das Signing Secret kopieren und hier eintragen, dann die App im Workspace installieren.') ?></li>
        </ol>
    </details>
    <label><?= t('Signing Secret') ?> <input type="password" name="slack_secret" autocomplete="off" spellcheck="false" maxlength="100" placeholder="<?= View::e(t('aus Slack: Basic Information')) ?>" value="<?= $config['slack']['secret'] !== '' ? View::e(Feed::MASK) : '' ?>"></label>
    <label><input type="checkbox" name="slack_enabled" value="1" <?= $config['slack']['enabled'] ? 'checked' : '' ?>> <span><?= t('Slack aktiv') ?></span></label>

    <h2 class="headline headline-small"><?= t('Für alle Messenger') ?></h2>
    <div class="field-row">
        <label><?= t('Pause pro Person') ?> <select name="cooldown_min"><?php foreach (Messenger::COOLDOWNS as $m): ?><option value="<?= $m ?>" <?= (int) $config['cooldown_min'] === $m ? 'selected' : '' ?>><?= View::e($minutes($m)) ?></option><?php endforeach; ?></select></label>
        <label><?= t('Verfällt nach') ?> <select name="expires_min"><?php foreach (GuestPost::EXPIRES as $m): ?><option value="<?= $m ?>" <?= (int) $config['expires_min'] === $m ? 'selected' : '' ?>><?= View::e($minutes($m)) ?></option><?php endforeach; ?></select></label>
    </div>
    <p class="muted hint"><?= t('Signal hat keine offizielle Bot-Schnittstelle – kommt vielleicht später. WhatsApp ebenso.') ?></p>
    <div class="buttons"><button type="submit" class="primary"><?= t('Speichern') ?></button></div>
</form>
<?php
View::footer();
