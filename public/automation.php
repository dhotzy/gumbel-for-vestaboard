<?php
declare(strict_types=1);

// Automatik (nur Admin): Wecker, Uhr, Wetter, Zufallspool, Nachrichten. Erzeugt Nachrichten, gesendet wird über den Arbiter.

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Art;
use Vesta\Auth;
use Vesta\Automation;
use Vesta\Charset;
use Vesta\Csrf;
use Vesta\Feed;
use Vesta\GridHtml;
use Vesta\Sayings;
use Vesta\Schedule;
use Vesta\ScheduleView;
use Vesta\Time;
use Vesta\View;

$user = Auth::requireAdmin();
$automation = App::automation();
const WEEKDAYS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
const INTERVALS = [15 => '15 Min', 30 => '30 Min', 60 => '1 Std', 90 => '1,5 Std', 120 => '2 Std', 180 => '3 Std', 240 => '4 Std'];

/** Intervall-Beschriftungen in der aktuellen Sprache (Schlüssel wie INTERVALS) */
function intervalLabels(): array
{
    return [15 => t('15 Min'), 30 => t('30 Min'), 60 => t('1 Std'), 90 => t('1,5 Std'), 120 => t('2 Std'), 180 => t('3 Std'), 240 => t('4 Std')];
}

/** Gemeinsame Felder: an/aus, Intervall, Zeitfenster (mehrere Zyklen) */
function scheduleFields(array $config): array
{
    $windows = slotsFromPost('windows', 'intervals');
    if ($windows === []) {
        View::flash('error', t('Mindestens ein Zeitfenster mit Tagen und Uhrzeiten ist nötig – die bisherigen bleiben.'));
        $windows = (array) $config['windows'];
    }
    return [
        'enabled' => !empty($_POST['enabled']),
        'interval' => (int) ($windows[0]['interval'] ?? $config['interval']), // Rückfall für alte Zeitfenster ohne eigenen Takt
        'windows' => $windows,
    ];
}

/** Zyklen aus dem Formular; meldet, wenn ein angefangener Zyklus unvollständig war */
function slotsFromPost(string $name, string $kind): array
{
    $rows = (array) ($_POST[$name] ?? []);
    $slots = Schedule::parse($rows, $kind);
    if (count($slots) < count($rows)) {
        $dropped = count($rows) - count($slots);
        View::flash('error', $dropped === 1 ? t('Ein Zyklus ohne Tage oder Uhrzeit wurde nicht gespeichert.') : t('{count} Zyklen ohne Tage oder Uhrzeit wurden nicht gespeichert.', ['count' => $dropped]));
    }
    return $slots;
}

function daysField(string $name, array $selected, ?string $legend = null): string
{
    $html = '<fieldset><legend>' . ($legend ?? t('Tage')) . '</legend><span class="days">';
    foreach (Schedule::ALL_DAYS as $day) {
        $label = Schedule::dayLabel($day);
        $checked = in_array($day, array_map('intval', $selected), true) ? ' checked' : '';
        $html .= '<label><input type="checkbox" name="' . $name . '[]" value="' . $day . '"' . $checked . '> ' . $label . '</label>';
    }
    return $html . '</span></fieldset>';
}

/** Liste von Zyklen mit „+ Weiterer Zyklus“ (gemeinsamer Baustein, siehe ScheduleView) */
function slotsField(string $name, array $slots, string $kind, string $legend): string
{
    return ScheduleView::field($name, $slots, $kind, $legend);
}

/** Wecker-Text mit Vorlagen-Auswahl, Ausrichtung und Live-Vorschau */
function alarmTextFields(array $alarm, array $templates): string
{
    $align = Charset::align($alarm['align'] ?? 'center');
    $text = (string) ($alarm['text'] ?? '');
    $preview = Charset::textToGrid($text, $align);
    $html = '<div class="preview' . ($align === 'grid' ? ' painting' : '') . '" data-preview-target>' . GridHtml::render($preview['grid']) . '</div>'
        . '<ul class="warnings" data-preview-warnings></ul>';
    if ($templates !== []) {
        $html .= '<label>' . t('Vorlage übernehmen') . ' <select data-fill-template><option value="">' . t('– auswählen –') . '</option>';
        foreach ($templates as $tpl) {
            $html .= '<option data-text="' . View::e($tpl['text_raw']) . '" data-align="' . View::e($tpl['align']) . '">' . View::e($tpl['name']) . '</option>';
        }
        $html .= '</select></label>';
    }
    $html .= '<label>' . t('Text') . ' <textarea name="text" rows="3" maxlength="400" required data-preview-input placeholder="' . View::e(t('GUTEN MORGEN')) . '">' . "\n" . View::e($text) . '</textarea></label>'
        . '<p class="counter' . ($preview['fits'] ? '' : ' over') . '" data-preview-counter aria-live="polite">' . View::e(Charset::counterText($preview)) . '</p>'
        . '<p class="muted hint designer-hint" data-designer-hint' . ($align === 'grid' ? '' : ' hidden') . '>' . t('Designer: Farbe oder Herz wählen und auf die Kacheln oben tippen.') . '</p>'
        . View::tokenBar($align === 'grid')
        . '<fieldset class="inline"><legend>' . t('Ausrichtung') . '</legend>';
    foreach (['center' => t('mittig'), 'left' => t('links'), 'grid' => t('Designer')] as $value => $label) {
        $html .= '<label><input type="radio" name="align" value="' . $value . '" data-preview-align' . ($align === $value ? ' checked' : '') . '> ' . $label . '</label>';
    }
    return $html . '</fieldset>';
}

/** Eine Zeile im Zufallspool: Text (bis 3 Board-Zeilen), Schalter, Entfernen */
function poolRow(string $index, string $text, bool $on): string
{
    $fits = $text === '' || Charset::textToGrid($text)['fits'];
    $lines = max(1, min(3, substr_count($text, "\n") + 1));
    return '<div class="pool-row' . ($fits ? '' : ' over') . '" data-pool-row>'
        . '<textarea name="sayings[' . $index . '][text]" rows="' . $lines . '" maxlength="200" class="pool-text" data-token-target placeholder="' . View::e(t('NEUER SPRUCH')) . '" aria-label="' . View::e(t('Spruch')) . '" data-autosize>' . View::e($text) . '</textarea>'
        . ($fits ? '' : '<span class="pool-warn">' . t('passt nicht aufs Board') . '</span>')
        . '<input type="checkbox" name="sayings[' . $index . '][on]" value="1" aria-label="' . View::e(t('Spruch aktiv')) . '"' . ($on && $fits ? ' checked' : '') . '>'
        . '<button type="button" class="pool-remove" data-pool-remove aria-label="' . View::e(t('Spruch entfernen')) . '" title="' . View::e(t('Entfernen')) . '">×</button>'
        . '</div>';
}

/** Zeitfenster-Zyklen, jeder mit eigenem Takt („alle 2 Std“) */
function windowFields(array $config): string
{
    $windows = array_map(static fn (array $w): array => $w + ['interval' => (int) $config['interval']], (array) $config['windows']);
    return slotsField('windows', $windows, 'intervals', t('Zyklen'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    $anchor = '';
    try {
        switch ($action) {
            case 'alarm_save':
                $alarms = $automation->config('alarms');
                $id = preg_match('/^[a-f0-9]{8}$/', (string) ($_POST['id'] ?? '')) ? (string) $_POST['id'] : bin2hex(random_bytes(4));
                $align = Charset::align(View::post('align', 'center'));
                $text = $align === 'grid' ? rtrim(View::post('text')) : trim(View::post('text'));
                $slots = slotsFromPost('slots', 'times');
                if ($slots === []) {
                    View::flash('error', t('Bitte mindestens einen Zyklus mit Tagen und Uhrzeit angeben.'));
                    $anchor = '#wecker';
                    break;
                }
                if (trim($text) === '' || !Charset::textToGrid($text, $align)['fits']) {
                    View::flash('error', t('Der Wecker-Text fehlt oder passt nicht aufs Board (3 Zeilen à 15 Zeichen).'));
                    $anchor = '#wecker';
                    break;
                }
                $alarm = ['id' => $id, 'enabled' => !empty($_POST['enabled']), 'slots' => $slots, 'text' => $text, 'align' => $align, 'forced' => !empty($_POST['forced'])];
                $found = false;
                foreach ($alarms as $i => $existing) {
                    if (($existing['id'] ?? '') === $id) {
                        $alarms[$i] = $alarm;
                        $found = true;
                    }
                }
                if (!$found) {
                    $alarms[] = $alarm;
                }
                usort($alarms, static fn (array $a, array $b): int => strcmp((string) ($a['slots'][0]['time'] ?? ''), (string) ($b['slots'][0]['time'] ?? '')));
                $automation->save('alarms', $alarms);
                View::flash('success', t('Wecker gespeichert: {summary}.', ['summary' => Schedule::summary($slots)]));
                $anchor = '#wecker';
                break;
            case 'alarm_delete':
                $id = (string) ($_POST['id'] ?? '');
                $automation->save('alarms', array_values(array_filter($automation->config('alarms'), static fn (array $a): bool => ($a['id'] ?? '') !== $id)));
                $automation->forget('alarm_' . $id);
                View::flash('success', t('Wecker gelöscht.'));
                $anchor = '#wecker';
                break;
            case 'clock_save':
                $style = in_array($_POST['style'] ?? '', ['digital', 'words', 'date'], true) ? (string) $_POST['style'] : 'digital';
                $automation->save('clock', ['style' => $style] + scheduleFields($automation->config('clock')));
                View::flash('success', t('Uhr gespeichert.'));
                $anchor = '#uhr';
                break;
            case 'clock_test':
                $automation->sendClock();
                $shown = App::triggerArbiter(true)['action'] === 'sent';
                View::flash('success', $shown ? t('Uhr läuft gerade auf dem Board.') : t('Uhr kommt sofort aufs Board.'));
                $anchor = '#uhr';
                break;
            case 'weather_save':
                $config = $automation->config('weather');
                $cityName = trim(View::post('city'));
                $city = $config['city'];
                if ($cityName === '') {
                    $city = null;
                } elseif (!is_array($city) || mb_strtolower((string) $city['name']) !== mb_strtolower($cityName)) {
                    $city = $automation->geocode($cityName);
                    if ($city === null) {
                        View::flash('error', t('Die Stadt „{city}“ wurde nicht gefunden (oder der Wetterdienst ist nicht erreichbar). Nichts geändert.', ['city' => $cityName]));
                        $anchor = '#wetter';
                        break;
                    }
                }
                $fields = View::post('mode') === 'interval' ? scheduleFields($config) : ['enabled' => !empty($_POST['enabled']), 'interval' => (int) $config['interval'], 'windows' => (array) $config['windows']];
                $fields['mode'] = View::post('mode') === 'interval' ? 'interval' : 'times';
                $times = $fields['mode'] === 'times' ? slotsFromPost('times', 'times') : (array) $config['times'];
                if ($times === []) {
                    View::flash('error', t('Bitte mindestens einen Zyklus mit Tagen und Uhrzeit angeben.'));
                    $anchor = '#wetter';
                    break;
                }
                $fields['times'] = $times;
                if ($city === null && $fields['enabled']) {
                    $fields['enabled'] = false;
                    View::flash('error', t('Ohne Stadt bleibt das Wetter aus.'));
                }
                $automation->save('weather', ['city' => $city] + $fields);
                View::flash('success', is_array($city) ? t('Wetter gespeichert ({city}).', ['city' => $city['name']]) : t('Wetter gespeichert.'));
                $anchor = '#wetter';
                break;
            case 'weather_now':
                $error = $automation->sendWeather(null, true);
                if ($error === null) {
                    App::triggerArbiter(true);
                }
                View::flash($error === null ? 'success' : 'error', $error ?? t('Wetter kommt sofort aufs Board.'));
                $anchor = '#wetter';
                break;
            case 'news_save':
                $anchor = '#nachrichten';
                $config = $automation->config('news');
                $feed = View::post('feed');
                $feed = isset(Automation::NEWS_FEEDS[$feed]) || $feed === 'custom' ? $feed : 'tagesschau';
                $url = trim(View::post('url'));
                if ($feed === 'custom' && ($urlError = Feed::checkUrl($url)) !== null) {
                    View::flash('error', $urlError);
                    break;
                }
                $times = slotsFromPost('times', 'times');
                if ($times === []) {
                    View::flash('error', t('Bitte mindestens einen Zyklus mit Tagen und Uhrzeit angeben.'));
                    break;
                }
                $automation->save('news', [
                    'enabled' => !empty($_POST['enabled']),
                    'feed' => $feed,
                    'url' => $feed === 'custom' ? mb_substr($url, 0, 500) : (string) $config['url'],
                    'count' => max(1, min(Automation::NEWS_MAX, (int) View::post('count'))),
                    'times' => $times,
                ]);
                View::flash('success', t('Nachrichten gespeichert.'));
                break;
            case 'news_now':
                $anchor = '#nachrichten';
                $error = $automation->sendNews(null, true);
                if ($error === null) {
                    App::triggerArbiter(true);
                }
                View::flash($error === null ? 'success' : 'error', $error ?? t('Schlagzeilen kommen sofort aufs Board.'));
                break;
            case 'pool_save':
                // Jede Zeile ein Spruch mit eigenem Schalter; leere Zeilen fallen weg
                $active = [];
                $inactive = [];
                $tooLong = [];
                $order = [];
                foreach ((array) ($_POST['sayings'] ?? []) as $row) {
                    $text = trim(str_replace("\r", '', (string) ($row['text'] ?? '')));
                    if ($text === '' || in_array($text, $active, true) || in_array($text, $inactive, true)) {
                        continue;
                    }
                    $order[] = $text;
                    $fits = mb_strlen($text) <= 200 && Charset::textToGrid($text)['fits'];
                    if (!$fits) {
                        $tooLong[] = $text;
                    }
                    // Unpassende bleiben erhalten, aber ausgeschaltet – nichts von der Eingabe geht verloren
                    if ($fits && !empty($row['on'])) {
                        $active[] = $text;
                    } else {
                        $inactive[] = $text;
                    }
                }
                $art = array_values(array_intersect(array_map('strval', (array) ($_POST['art'] ?? [])), array_keys(Art::ALL)));
                $automation->save('pool', ['items' => array_slice($active, 0, 300), 'inactive' => array_slice($inactive, 0, 300), 'order' => array_slice($order, 0, 600), 'art' => $art] + scheduleFields($automation->config('pool')));
                View::flash('success', $inactive !== [] ? t('{active} Sprüche aktiv, {inactive} ausgeschaltet.', ['active' => count($active), 'inactive' => count($inactive)]) : t('{active} Sprüche aktiv.', ['active' => count($active)]));
                if ($tooLong !== []) {
                    View::flash('error', t('{count} Spruch/Sprüche passen nicht aufs Board und bleiben ausgeschaltet: „{text}…“', ['count' => count($tooLong), 'text' => mb_substr((string) reset($tooLong), 0, 40)]));
                }
                $anchor = '#pool';
                if (View::post('autosave') === '1') { // automatisch gespeichert (app.js) – Antwort als JSON statt Weiterleitung
                    $messages = $_SESSION['flash'] ?? [];
                    unset($_SESSION['flash']);
                    header('Content-Type: application/json; charset=utf-8');
                    $errors = array_column(array_filter($messages, static fn (array $m): bool => $m['type'] === 'error'), 'message');
                    exit(json_encode(['ok' => true, 'messages' => $errors], JSON_UNESCAPED_UNICODE));
                }
                break;
            case 'pool_examples':
                $pool = $automation->config('pool');
                $new = array_values(array_diff(Sayings::all(), $pool['items'], (array) $pool['inactive']));
                $automation->save('pool', ['items' => array_slice(array_merge($pool['items'], $new), 0, 300)] + $pool);
                View::flash('success', t('{count} Beispielsprüche hinzugefügt. Nicht passende einfach ausschalten oder entfernen.', ['count' => count($new)]));
                $anchor = '#pool';
                break;
            case 'pool_test':
                $error = $automation->sendPool(null, true);
                if ($error === null) {
                    App::triggerArbiter(true);
                }
                View::flash($error === null ? 'success' : 'error', $error ?? t('Ein Spruch kommt sofort aufs Board.'));
                $anchor = '#pool';
                break;
            default:
                View::flash('error', t('Unbekannte Aktion.'));
        }
    } catch (Throwable $e) {
        error_log('Automatik-Seite: ' . $e->getMessage());
        View::flash('error', t('Speichern fehlgeschlagen. Bitte noch einmal versuchen.'));
    }
    View::redirect('automation.php' . $anchor);
}

$alarms = $automation->config('alarms');
$templates = App::templates()->forUser($user);
$clock = $automation->config('clock');
$weather = $automation->config('weather');
$pool = $automation->config('pool');
$news = $automation->config('news');
$newsError = (string) $automation->state('news_error');
$weatherError = (string) $automation->state('weather_error');
$now = Time::local(App::clock()->now());

View::header(t('Automatik'), $user, 'auto');
?>
<h1><?= t('Automatik') ?></h1>
<p class="muted"><?= t('Entsteht von selbst und stellt sich normal an – Rangfolge und Ruhezeiten aus den {link}Einstellungen{/link} gelten. „Jetzt …“ zeigt sofort.', ['link' => '<a href="settings.php">', '/link' => '</a>']) ?></p>

<nav class="pills" aria-label="<?= View::e(t('Bereiche')) ?>" data-tabs>
    <?php foreach (['wecker' => [t('Wecker'), array_filter($alarms, static fn (array $a): bool => !empty($a['enabled'])) !== []], 'uhr' => [t('Uhr'), !empty($clock['enabled'])], 'wetter' => [t('Wetter'), !empty($weather['enabled'])], 'pool' => [t('Zufallspool'), !empty($pool['enabled'])], 'nachrichten' => [t('Nachrichten'), !empty($news['enabled'])]] as $id => [$label, $on]): ?>
        <a href="#<?= $id ?>"<?= $on ? ' class="is-on" title="' . View::e(t('aktiv')) . '"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
</nav>

<section class="card" id="wecker">
    <h2 class="headline"><?= t('Wecker') ?></h2>
    <p class="muted hint"><?= t('Erscheint einmal am Tag zur eingestellten Zeit. Mit Force auch während Stummschaltung und Ruhezeiten.') ?></p>
    <?php foreach ($alarms as $alarm): ?>
        <form method="post" class="alarm" data-busy>
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= View::e($alarm['id']) ?>">
            <?= alarmTextFields($alarm, $templates) ?>
            <?= slotsField('slots', (array) $alarm['slots'], 'times', t('Wann')) ?>
            <div class="checks">
                <label><input type="checkbox" name="enabled" value="1" <?= !empty($alarm['enabled']) ? 'checked' : '' ?>> <?= t('aktiv') ?></label>
                <label><input type="checkbox" name="forced" value="1" <?= !empty($alarm['forced']) ? 'checked' : '' ?>> <?= t('Force') ?></label>
            </div>
            <div class="buttons">
                <button type="submit" name="action" value="alarm_save"><?= t('Speichern') ?></button>
                <button type="submit" name="action" value="alarm_delete" class="danger" formnovalidate data-confirm-click="<?= View::e(t('Wecker „{summary}“ wirklich löschen?', ['summary' => Schedule::summary((array) $alarm['slots'])])) ?>"><?= t('Löschen') ?></button>
            </div>
        </form>
    <?php endforeach; ?>
    <?php if ($alarms === []): ?><p class="muted"><?= t('Noch kein Wecker eingerichtet.') ?></p><?php endif; ?>
    <details class="new-alarm" <?= $alarms === [] ? 'open' : '' ?>>
        <summary class="button"><?= t('+ Neuer Wecker') ?></summary>
        <form method="post" data-busy>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="alarm_save">
            <input type="hidden" name="enabled" value="1">
            <?= alarmTextFields([], $templates) ?>
            <?= slotsField('slots', [['days' => [1, 2, 3, 4, 5], 'time' => '07:00']], 'times', t('Wann')) ?>
            <div class="checks"><label><input type="checkbox" name="forced" value="1" checked> <?= t('Force (auch bei Stummschaltung)') ?></label></div>
            <div class="buttons"><button type="submit" class="primary"><?= t('Wecker anlegen') ?></button></div>
        </form>
    </details>
</section>

<section class="card" id="uhr">
    <h2 class="headline"><?= t('Uhr') ?></h2>
    <form method="post" data-busy>
        <?= Csrf::field() ?>
        <label><input type="checkbox" name="enabled" value="1" <?= !empty($clock['enabled']) ? 'checked' : '' ?>> <strong><?= t('Uhrzeit regelmäßig anzeigen') ?></strong></label>
        <fieldset class="inline">
            <legend><?= t('Darstellung') ?></legend>
            <?php foreach (['digital' => t('Digital'), 'words' => t('In Worten'), 'date' => t('Datum + Uhrzeit')] as $style => $label): ?>
                <label><input type="radio" name="style" value="<?= $style ?>" <?= $clock['style'] === $style ? 'checked' : '' ?>> <?= $label ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="clock-samples">
            <?php foreach (['digital', 'words', 'date'] as $style): ?>
                <div class="msg-board"><?= GridHtml::render(Charset::textToGrid(Automation::clockText($style, $now))['grid']) ?></div>
            <?php endforeach; ?>
        </div>
        <?= windowFields($clock) ?>
        <p class="muted hint"><?= t('Erscheint zur vollen Intervall-Zeit (z. B. 14:00, 14:15) und verschwindet nach 2 Minuten, falls sie bis dahin nicht drankam – sonst wäre die Uhrzeit falsch.') ?></p>
        <div class="buttons">
            <button type="submit" name="action" value="clock_save" class="primary"><?= t('Uhr speichern') ?></button>
            <button type="submit" name="action" value="clock_test"><?= t('Jetzt anzeigen') ?></button>
        </div>
    </form>
</section>

<section class="card" id="wetter">
    <h2 class="headline"><?= t('Wetter') ?></h2>
    <?php if ($weatherError !== ''): ?><div class="flash flash-warn"><?= View::e(t('Letzter Abruf fehlgeschlagen: {error}', ['error' => str_replace('Wetterdienst nicht erreichbar', t('Wetterdienst nicht erreichbar'), $weatherError)])) ?></div><?php endif; ?>
    <form method="post" data-busy>
        <?= Csrf::field() ?>
        <label><input type="checkbox" name="enabled" value="1" <?= !empty($weather['enabled']) ? 'checked' : '' ?>> <strong><?= t('Wetter anzeigen') ?></strong></label>
        <label><?= t('Stadt') ?> <input name="city" value="<?= View::e($weather['city']['name'] ?? '') ?>" placeholder="<?= View::e(t('z. B. Frankfurt am Main')) ?>" maxlength="80"></label>
        <?php $daily = $weather['mode'] === 'times'; ?>
        <fieldset class="inline">
            <legend><?= t('Wann') ?></legend>
            <label><input type="radio" name="mode" value="times" data-mode-switch <?= $daily ? 'checked' : '' ?>> <?= t('Zu festen Zeiten') ?></label>
            <label><input type="radio" name="mode" value="interval" data-mode-switch <?= $daily ? '' : 'checked' ?>> <?= t('Regelmäßig') ?></label>
        </fieldset>
        <div data-mode-panel="times" <?= $daily ? '' : 'hidden' ?>>
            <?= slotsField('times', (array) $weather['times'], 'times', t('Zyklen')) ?>
            <p class="muted hint"><?= t('Jeder Zyklus genau einmal am Tag – z. B. Mo–Fr 06:45 und Sa + So 09:00.') ?></p>
        </div>
        <div data-mode-panel="interval" <?= $daily ? 'hidden' : '' ?>>
            <?= windowFields($weather) ?>
        </div>
        <p class="muted hint"><?= t('Daten von Open-Meteo (kostenlos, ohne Konto). Zeigt Stadt, aktuelle Temperatur, Wetterlage und Tiefst-/Höchstwert.') ?></p>
        <div class="buttons">
            <button type="submit" name="action" value="weather_save" class="primary"><?= t('Wetter speichern') ?></button>
            <button type="submit" name="action" value="weather_now" <?= empty($weather['city']) ? 'disabled' : '' ?>><?= t('Jetzt senden') ?></button>
        </div>
    </form>
</section>

<section class="card" id="pool">
    <h2 class="headline"><?= t('Zufallspool') ?></h2>
    <form method="post" data-busy data-autosave="pool_save">
        <?= Csrf::field() ?>
        <label><input type="checkbox" name="enabled" value="1" <?= !empty($pool['enabled']) ? 'checked' : '' ?>> <strong><?= t('Zufällige Sprüche anzeigen') ?></strong></label>
        <fieldset class="pool">
            <legend><?= t('Sprüche') ?> <span class="muted"><?= t('(Enter = neue Zeile auf dem Board)') ?></span></legend>
            <?= View::tokenBar(false, true) ?>
            <div class="pool-list" data-pool-list>
                <?php
                // Gespeicherte Reihenfolge beibehalten; neu Hinzugekommenes (z. B. Beispielsprüche) hinten anhängen
                $known = array_merge($pool['items'], (array) $pool['inactive']);
                $ordered = array_values(array_unique(array_merge(array_intersect((array) $pool['order'], $known), $known)));
                $rows = array_map(static fn (string $t): array => [$t, in_array($t, $pool['items'], true)], $ordered);
                foreach ($rows as $i => [$text, $on]):
                    echo poolRow((string) $i, $text, $on);
                endforeach; ?>
            </div>
            <template data-pool-template><?= poolRow('__i__', '', true) ?></template>
            <button type="button" class="pool-add" data-pool-add><?= t('+ Neuen Spruch anlegen') ?></button>
            <p class="muted hint"><?= t('{active} von {total} Sprüchen aktiv · nie zweimal derselbe hintereinander', ['active' => count($pool['items']), 'total' => count($rows)]) ?></p>
        </fieldset>
        <fieldset>
            <legend><?= t('Vestaboard-Kunst mit in den Pool') ?></legend>
            <div class="art-grid">
                <?php foreach (array_keys(Art::ALL) as $artId): ?>
                    <label class="art-item">
                        <?= GridHtml::render(Charset::textToGrid((string) Art::text($artId), 'grid')['grid']) ?>
                        <span><input type="checkbox" name="art[]" value="<?= $artId ?>" <?= in_array($artId, (array) $pool['art'], true) ? 'checked' : '' ?>> <?= View::e(Art::label($artId)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?= windowFields($pool) ?>
        <div class="buttons">
            <button type="submit" name="action" value="pool_save" class="primary"><?= t('Pool speichern') ?></button>
            <span class="autosave-status muted" data-autosave-status aria-live="polite"><?= t('Änderungen werden automatisch gespeichert.') ?></span>
            <button type="submit" name="action" value="pool_examples" formnovalidate><?= t('+ Beispielsprüche') ?></button>
            <button type="submit" name="action" value="pool_test" <?= $pool['items'] === [] && $pool['art'] === [] ? 'disabled' : '' ?>><?= t('Jetzt einen anzeigen') ?></button>
        </div>
    </form>
</section>
<section class="card" id="nachrichten">
    <h2 class="headline"><?= t('Nachrichten') ?></h2>
    <?php if ($newsError !== ''): ?><div class="flash flash-warn"><?= View::e(t('Letzter Abruf fehlgeschlagen: {error}', ['error' => $newsError])) ?></div><?php endif; ?>
    <form method="post" data-busy>
        <?= Csrf::field() ?>
        <label><input type="checkbox" name="enabled" value="1" <?= !empty($news['enabled']) ? 'checked' : '' ?>> <strong><?= t('Schlagzeilen anzeigen') ?></strong></label>
        <fieldset class="inline">
            <legend><?= t('Quelle') ?></legend>
            <?php foreach (['tagesschau' => 'Tagesschau', 'bbc' => 'BBC News', 'custom' => t('Eigene RSS-Adresse')] as $value => $label): ?>
                <label><input type="radio" name="feed" value="<?= $value ?>" data-mode-switch <?= $news['feed'] === $value ? 'checked' : '' ?>> <?= View::e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div data-mode-panel="custom" <?= $news['feed'] === 'custom' ? '' : 'hidden' ?>>
            <label><?= t('RSS- oder Atom-Adresse') ?> <input type="url" name="url" maxlength="500" value="<?= View::e($news['url']) ?>" placeholder="https://www.spiegel.de/schlagzeilen/index.rss"></label>
        </div>
        <label><?= t('Anzahl Schlagzeilen') ?> <select name="count">
            <?php for ($n = 1; $n <= Automation::NEWS_MAX; $n++): ?><option value="<?= $n ?>" <?= (int) $news['count'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endfor; ?>
        </select></label>
        <?= slotsField('times', (array) $news['times'], 'times', t('Zyklen')) ?>
        <p class="muted hint"><?= t('Jeder Zyklus einmal am Tag. Jede Schlagzeile ist eine eigene Nachricht: oben die Quelle, darunter die Schlagzeile (gekürzt auf das Board).') ?></p>
        <div class="buttons">
            <button type="submit" name="action" value="news_save" class="primary"><?= t('Nachrichten speichern') ?></button>
            <button type="submit" name="action" value="news_now"><?= t('Jetzt senden') ?></button>
        </div>
    </form>
</section>

<?php
View::footer();
