<?php
declare(strict_types=1);

// Einstellungen (nur Admin): Rangfolge, Mindestdauer, Mute, Ruhezeiten, Force, Ruhezustand

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Charset;
use Vesta\Csrf;
use Vesta\I18n;
use Vesta\GridHtml;
use Vesta\Sources;
use Vesta\Time;
use Vesta\View;

$user = Auth::requireAdmin();
$settings = App::settings();
const QUIET_SLOTS = 3;
$weekdays = [1 => t('Mo'), 2 => t('Di'), 3 => t('Mi'), 4 => t('Do'), 5 => t('Fr'), 6 => t('Sa'), 7 => t('So')];

// Eigene Quellen anlegen/löschen (eigenes kleines Formular)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['custom_action'])) {
    Csrf::verify();
    $custom = array_values(array_map(static fn (string $id, array $s): array => ['id' => $id, 'label' => $s['label'], 'duration' => $s['duration']], array_keys(Sources::custom()), Sources::custom()));
    if ($_POST['custom_action'] === 'add') {
        $label = trim(View::post('custom_label'));
        if ($label === '' || mb_strlen($label) > 40) {
            View::flash('error', t('Bitte eine Bezeichnung angeben (höchstens 40 Zeichen).'));
        } elseif (count($custom) >= Sources::MAX_CUSTOM) {
            View::flash('error', t('Es sind höchstens {max} eigene Quellen möglich.', ['max' => Sources::MAX_CUSTOM]));
        } else {
            $custom[] = ['id' => Sources::customId($label, array_column($custom, 'id')), 'label' => $label, 'duration' => 5];
            $settings->set('custom_sources', $custom);
            View::flash('success', t('Quelle „{label}“ angelegt – sie steht jetzt in der Rangfolge und bei den Integrationen zur Auswahl.', ['label' => $label]));
        }
    } elseif ($_POST['custom_action'] === 'delete') {
        $id = View::post('custom_id');
        $inUse = array_filter(App::inbound()->all(), static fn (array $h): bool => $h['source'] === $id);
        if ($inUse !== []) {
            View::flash('error', t('Diese Quelle nutzt noch eine Integration („{name}“) – dort zuerst eine andere Quelle wählen.', ['name' => reset($inUse)['name']]));
        } else {
            $settings->set('custom_sources', array_values(array_filter($custom, static fn (array $s): bool => $s['id'] !== $id)));
            View::flash('success', t('Quelle gelöscht.'));
        }
    }
    View::redirect('settings.php#quellen');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $errors = [];

    $order = array_values(array_filter((array) ($_POST['order'] ?? []), [Sources::class, 'exists']));
    $settings->set('source_order', array_values(array_unique($order)));

    $durations = [];
    foreach (array_keys(Sources::all()) as $source) {
        $durations[$source] = max(0, min(240, (int) ($_POST['duration'][$source] ?? Sources::all()[$source]['duration'])));
    }
    $settings->set('min_duration', $durations);

    $muteMode = in_array($_POST['mute_mode'] ?? 'off', ['off', 'on', 'until'], true) ? (string) $_POST['mute_mode'] : 'off';
    $muteUntil = null;
    if ($muteMode === 'until') {
        $until = Time::parseLocal((string) ($_POST['mute_until'] ?? ''));
        if ($until === null || $until <= App::clock()->now()) {
            $errors[] = t('„Stumm bis“ braucht einen Zeitpunkt in der Zukunft – Stummschaltung bleibt aus.');
            $muteMode = 'off';
        } else {
            $muteUntil = Time::db($until);
        }
    }
    $mutedSources = array_values(array_filter((array) ($_POST['mute_sources'] ?? []), [Sources::class, 'exists']));
    $settings->set('mute', ['mode' => $muteMode, 'until' => $muteUntil, 'sources' => $mutedSources, 'strict' => $muteMode !== 'off' && !empty($_POST['mute_strict'])]);

    $windows = [];
    for ($i = 0; $i < QUIET_SLOTS; $i++) {
        $from = (string) ($_POST['quiet'][$i]['from'] ?? '');
        $to = (string) ($_POST['quiet'][$i]['to'] ?? '');
        if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) {
            continue;
        }
        $days = array_values(array_intersect(array_map('intval', (array) ($_POST['quiet'][$i]['days'] ?? [])), array_keys($weekdays)));
        $windows[] = ['enabled' => !empty($_POST['quiet'][$i]['enabled']), 'from' => $from, 'to' => $to, 'days' => $days ?: array_keys($weekdays)];
    }
    $settings->set('quiet_hours', $windows);

    $settings->set('interrupt_sources', array_values(array_filter((array) ($_POST['interrupt_sources'] ?? []), [Sources::class, 'exists'])));
    $settings->set('force_all', !empty($_POST['force_all']));
    $language = (string) ($_POST['language'] ?? '');
    $settings->set('language', isset(I18n::LANGUAGES[$language]) ? $language : null);
    $settings->set('force_sources', array_values(array_filter((array) ($_POST['force_sources'] ?? []), [Sources::class, 'exists'])));

    $idleMode = in_array($_POST['idle_mode'] ?? 'last', ['last', 'clear', 'text'], true) ? (string) $_POST['idle_mode'] : 'last';
    $idleText = trim((string) ($_POST['idle_text'] ?? ''));
    $idleAlign = ($_POST['idle_align'] ?? 'center') === 'left' ? 'left' : 'center';
    if ($idleMode === 'text' && ($idleText === '' || !Charset::textToGrid($idleText, $idleAlign)['fits'])) {
        $errors[] = t('Der Ruhezustand-Text fehlt oder passt nicht aufs Board – Ruhezustand bleibt „letzte Nachricht“.');
        $idleMode = 'last';
    }
    $settings->set('idle', [
        'mode' => $idleMode,
        'after_min' => max(1, min(1440, (int) ($_POST['idle_after'] ?? 30))),
        'text' => $idleText,
        'align' => $idleAlign,
    ]);

    App::triggerArbiter();
    foreach ($errors as $error) {
        View::flash('error', $error);
    }
    View::flash('success', t('Einstellungen gespeichert.'));
    View::redirect('settings.php');
}

$order = $settings->sourceOrder();
$durations = (array) $settings->get('min_duration');
$mute = (array) $settings->get('mute');
// Eingerichtete Ruhezeiten plus ein leerer Platz für eine weitere (max. QUIET_SLOTS)
$quiet = (array) $settings->get('quiet_hours');
$quiet = array_pad($quiet, min(QUIET_SLOTS, count($quiet) + 1), ['enabled' => false, 'from' => '', 'to' => '', 'days' => array_keys($weekdays)]);
$forceSources = (array) $settings->get('force_sources');
$idle = (array) $settings->get('idle');
$muteReason = $settings->globalMuteReason(App::clock()->now());

View::header(t('Einstellungen'), $user, 'settings');
?>
<h1><?= t('Einstellungen') ?></h1>

<div class="nav-cards">
    <a class="card nav-card" href="users.php">
        <strong><?= t('Nutzer') ?></strong>
        <span class="muted"><?= t('Zugänge, PINs, Rechte und Pausen') ?></span>
    </a>
    <a class="card nav-card" href="board.php">
        <strong><?= t('Board-Verbindung') ?></strong>
        <span class="muted"><?= t('Cloud API oder Local API im Heimnetz') ?></span>
    </a>
    <a class="card nav-card" href="messenger.php">
        <strong><?= t('Messenger') ?></strong>
        <span class="muted"><?= t('Nachrichten per Telegram oder Slack aufs Board') ?></span>
    </a>
    <a class="card nav-card" href="guest.php">
        <strong><?= t('Briefkasten') ?></strong>
        <span class="muted"><?= t('Gäste schicken ohne Konto eine Nachricht – per Link oder QR-Code') ?></span>
    </a>
    <a class="card nav-card" href="webhooks.php">
        <strong><?= t('Integrationen') ?></strong>
        <span class="muted"><?= t('Webhooks und NFC-Sticker') ?></span>
    </a>
</div>

<section class="card" id="quellen">
    <h2><?= t('Eigene Quellen') ?></h2>
    <p class="muted"><?= t('Für Integrationen mit eigenem Rang und eigener Mindestanzeigedauer, z. B. ein Buchungssystem oder Home Assistant. Rang und Dauer stellst du unten in der Rangfolge ein.') ?></p>
    <?php foreach (Sources::custom() as $customId => $customSource): ?>
        <form method="post" class="custom-source" data-busy>
            <?= Csrf::field() ?>
            <input type="hidden" name="custom_action" value="delete">
            <input type="hidden" name="custom_id" value="<?= View::e($customId) ?>">
            <span><?= View::e($customSource['label']) ?></span>
            <button type="submit" class="link danger" data-confirm-click="<?= View::e(t('Quelle „{label}“ löschen?', ['label' => $customSource['label']])) ?>"><?= t('Löschen') ?></button>
        </form>
    <?php endforeach; ?>
    <form method="post" class="custom-source-add" data-busy>
        <?= Csrf::field() ?>
        <input type="hidden" name="custom_action" value="add">
        <label><?= t('Neue Quelle') ?> <input name="custom_label" maxlength="40" placeholder="<?= View::e(t('z. B. Buchungen')) ?>" required></label>
        <button type="submit"><?= t('Anlegen') ?></button>
    </form>
</section>

<form method="post" class="settings" data-busy>
    <?= Csrf::field() ?>

    <section class="card">
        <h2>Sprache / Language</h2>
        <label><?= t('Oberfläche und Board-Texte (Uhr, Wetter, Sprüche)') ?>
            <select name="language">
                <?php foreach (I18n::LANGUAGES as $code => $name): ?>
                    <option value="<?= $code ?>"<?= I18n::lang() === $code ? ' selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </section>

    <section class="card">
        <h2><?= t('Rangfolge &amp; Mindestanzeigedauer') ?></h2>
        <p class="muted"><?= t('Oben = wichtigste Quelle. Sortieren am Griff ⠿ oder mit ↑↓. Jede Nachricht bleibt mindestens so lange stehen (Force ausgenommen).') ?></p>
        <div class="order-head" aria-hidden="true"><span><?= t('Quelle (oben = wichtigste)') ?></span><span><?= t('Mindestanzeigedauer') ?></span></div>
        <ol class="order" data-order>
            <?php foreach ($order as $source): $phase = Sources::all()[$source]['phase']; ?>
                <li>
                    <span class="drag-handle" data-drag-handle title="<?= View::e(t('Ziehen zum Sortieren')) ?>" aria-hidden="true">⠿</span>
                    <input type="hidden" name="order[]" value="<?= $source ?>">
                    <span class="order-label"><?= View::e(Sources::label($source)) ?><?= $phase > Sources::ACTIVE_PHASE ? ' <span class="muted">' . t('(noch nicht aktiv)') . '</span>' : '' ?></span>
                    <label class="duration"><input type="number" name="duration[<?= $source ?>]" min="0" max="240" value="<?= (int) ($durations[$source] ?? Sources::all()[$source]['duration']) ?>" class="short"> <?= t('Min') ?></label>
                    <span class="order-buttons">
                        <button type="button" data-move="up" aria-label="<?= View::e(t('{label} nach oben', ['label' => Sources::label($source)])) ?>">↑</button>
                        <button type="button" data-move="down" aria-label="<?= View::e(t('{label} nach unten', ['label' => Sources::label($source)])) ?>">↓</button>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
        <fieldset>
            <legend><?= t('Diese Quellen dürfen die Mindestanzeigedauer anderer Quellen beenden') ?></legend>
            <div class="checks">
                <?php foreach (Sources::all() as $source => $info): ?>
                    <label><input type="checkbox" name="interrupt_sources[]" value="<?= $source ?>" <?= in_array($source, (array) $settings->get('interrupt_sources'), true) ? 'checked' : '' ?>> <?= View::e(Sources::label($source)) ?></label>
                <?php endforeach; ?>
            </div>
            <p class="muted hint"><?= t('Eine neue Nachricht dieser Quelle wartet nicht, bis z. B. eine Kinder-Nachricht ihre Mindestzeit erreicht hat. Gegenüber derselben Quelle und laufenden Force-Nachrichten gilt das nicht.') ?></p>
        </fieldset>
    </section>

    <section class="card">
        <h2><?= t('Stummschaltung') ?></h2>
        <?php if ($muteReason !== null): ?><p class="flash flash-warn"><?= View::e(t('Aktiv: {reason}', ['reason' => $muteReason])) ?></p><?php endif; ?>
        <fieldset>
            <label><input type="radio" name="mute_mode" value="off" <?= ($mute['mode'] ?? 'off') === 'off' ? 'checked' : '' ?>> <?= t('aus') ?></label>
            <label><input type="radio" name="mute_mode" value="on" <?= ($mute['mode'] ?? '') === 'on' ? 'checked' : '' ?>> <?= t('an (bis ich es ausschalte)') ?></label>
            <label><input type="radio" name="mute_mode" value="until" <?= ($mute['mode'] ?? '') === 'until' ? 'checked' : '' ?>> <?= t('an bis') ?>
                <input type="datetime-local" name="mute_until" value="<?= View::e(Time::toLocalInput($mute['until'] ?? null)) ?>"></label>
            <label><input type="checkbox" name="mute_strict" value="1" <?= !empty($mute['strict']) ? 'checked' : '' ?>> <span><?= t('Komplett still') ?> <span class="muted"><?= t('auch Force-Nachrichten warten, bis die Stummschaltung endet') ?></span></span></label>
        </fieldset>
        <fieldset>
            <legend><?= t('Einzelne Quellen immer stumm') ?></legend>
            <div class="checks">
                <?php foreach (Sources::all() as $source => $info): ?>
                    <label><input type="checkbox" name="mute_sources[]" value="<?= $source ?>" <?= in_array($source, (array) ($mute['sources'] ?? []), true) ? 'checked' : '' ?>> <?= View::e(Sources::label($source)) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <p class="muted"><?= t('Force-Nachrichten kommen durch (außer bei „komplett still“). Wartende Nachrichten bleiben erhalten und erscheinen nach der Stummschaltung. Schneller geht es über das Glocken-Symbol oben.') ?></p>
    </section>

    <section class="card">
        <h2><?= t('Ruhezeiten') ?></h2>
        <p class="muted"><?= t('Wiederkehrende Stummschaltung, z. B. nachts. Über Mitternacht zählt der Starttag.') ?></p>
        <?php foreach ($quiet as $i => $window): ?>
            <fieldset class="quiet">
                <label><input type="checkbox" name="quiet[<?= $i ?>][enabled]" value="1" <?= !empty($window['enabled']) ? 'checked' : '' ?>> <?= t('aktiv') ?></label>
                <label><?= t('von') ?> <input type="time" name="quiet[<?= $i ?>][from]" value="<?= View::e($window['from'] ?? '') ?>"></label>
                <label><?= t('bis') ?> <input type="time" name="quiet[<?= $i ?>][to]" value="<?= View::e($window['to'] ?? '') ?>"></label>
                <span class="days">
                    <?php foreach ($weekdays as $day => $label): ?>
                        <label><input type="checkbox" name="quiet[<?= $i ?>][days][]" value="<?= $day ?>" <?= in_array($day, array_map('intval', (array) ($window['days'] ?? [])), true) ? 'checked' : '' ?>> <?= $label ?></label>
                    <?php endforeach; ?>
                </span>
            </fieldset>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <h2><?= t('Force') ?></h2>
        <label><input type="checkbox" name="force_all" value="1" <?= $settings->get('force_all') ? 'checked' : '' ?>> <span><?= t('<strong>Alle Nachrichten</strong> als Force behandeln') ?></span></label>
        <fieldset>
            <legend><?= t('Diese Quellen immer als Force') ?></legend>
            <div class="checks">
                <?php foreach (Sources::all() as $source => $info): ?>
                    <label><input type="checkbox" name="force_sources[]" value="<?= $source ?>" <?= in_array($source, $forceSources, true) ? 'checked' : '' ?>> <?= View::e(Sources::label($source)) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <p class="muted"><?= t('Force unterbricht laufende Nachrichten und ignoriert Stummschaltung. Eine laufende Force-Nachricht wird nur von einer höher eingestuften Quelle unterbrochen.') ?></p>
    </section>

    <section class="card">
        <h2><?= t('Ruhezustand') ?></h2>
        <p class="muted"><?= t('Was passiert, wenn eine Zeit lang nichts Neues kommt?') ?></p>
        <fieldset>
            <label><input type="radio" name="idle_mode" value="last" <?= ($idle['mode'] ?? 'last') === 'last' ? 'checked' : '' ?>> <?= t('letzte Nachricht stehen lassen') ?></label>
            <label><input type="radio" name="idle_mode" value="clear" <?= ($idle['mode'] ?? '') === 'clear' ? 'checked' : '' ?>> <?= tc('Ruhezustand', 'Board leeren') ?></label>
            <label><input type="radio" name="idle_mode" value="text" <?= ($idle['mode'] ?? '') === 'text' ? 'checked' : '' ?>> <?= t('folgendes anzeigen:') ?></label>
        </fieldset>
        <label><?= t('nach') ?> <input type="number" name="idle_after" min="1" max="1440" value="<?= (int) ($idle['after_min'] ?? 30) ?>" class="short"> <?= t('Minuten') ?></label>
        <div class="preview" data-preview-target><?= GridHtml::render(Charset::textToGrid((string) ($idle['text'] ?? ''), (string) ($idle['align'] ?? 'center'))['grid']) ?></div>
        <ul class="warnings" data-preview-warnings></ul>
        <textarea name="idle_text" rows="3" maxlength="200" data-preview-input placeholder="<?= View::e(t('z. B. GUTE NACHT {heart}')) ?>"><?= View::e($idle['text'] ?? '') ?></textarea>
        <fieldset class="inline">
            <label><input type="radio" name="idle_align" value="center" data-preview-align <?= ($idle['align'] ?? 'center') !== 'left' ? 'checked' : '' ?>> <?= t('mittig') ?></label>
            <label><input type="radio" name="idle_align" value="left" data-preview-align <?= ($idle['align'] ?? '') === 'left' ? 'checked' : '' ?>> <?= t('links') ?></label>
        </fieldset>
    </section>

    <div class="buttons sticky">
        <button type="submit" class="primary"><?= t('Einstellungen speichern') ?></button>
    </div>
</form>
<?php
View::footer();
