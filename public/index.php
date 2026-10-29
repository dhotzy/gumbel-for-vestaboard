<?php
declare(strict_types=1);

// Nachricht schreiben (neu) bzw. bearbeiten (?id=…)

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Art;
use Vesta\Auth;
use Vesta\Charset;
use Vesta\Csrf;
use Vesta\GridHtml;
use Vesta\MessageService;
use Vesta\Time;
use Vesta\View;

$user = Auth::requireUser();
$service = App::messageService();
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $editId > 0 ? App::messages()->find($editId) : null;
if ($editId > 0 && ($editing === null || !$service->canManage($user, $editing) || $editing['status'] !== 'queued')) {
    View::flash('error', t('Diese Nachricht kann nicht mehr bearbeitet werden.'));
    View::redirect('queue.php');
}

$input = [
    'text' => $editing['text_raw'] ?? '',
    'align' => $editing['align'] ?? 'center',
    'forced' => $editing !== null ? (int) $editing['forced'] === 1 : (int) $user['default_force'] === 1,
    'jump' => $editing !== null ? (int) $editing['jump'] === 1 : (int) $user['default_jump'] === 1,
    'schedule' => 'now',
    'in_minutes' => 30,
    'at' => '',
];
// Vorlage aus dem Verlauf: Text und Ausrichtung übernehmen, Rest wie bei einer neuen Nachricht
$templateId = $editing === null ? (int) ($_GET['template'] ?? 0) : 0;
if ($templateId > 0) {
    $template = App::messages()->find($templateId);
    if ($template !== null && (MessageService::isAdmin($user) || (int) $template['user_id'] === (int) $user['id'])) {
        $input['text'] = (string) $template['text_raw'];
        $input['align'] = (string) $template['align'];
    }
}
// Gespeicherte Vorlage (?tpl=)
$savedId = $editing === null ? (int) ($_GET['tpl'] ?? 0) : 0;
if ($savedId > 0 && ($saved = App::templates()->find($user, $savedId)) !== null) {
    $input['text'] = (string) $saved['text_raw'];
    $input['align'] = (string) $saved['align'];
}
// Geplant und noch in der Zukunft → Zeitpunkt vorbelegen; schon fällig → "sofort"
if ($editing !== null && $editing['not_before'] !== null && Time::fromDb($editing['not_before']) > App::clock()->now()) {
    $input['schedule'] = 'at';
    $input['at'] = Time::toLocalInput($editing['not_before']);
}
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $input = [
        'text' => View::post('text'),
        'align' => View::post('align', 'center'),
        'forced' => !empty($_POST['forced']),
        'jump' => !empty($_POST['jump']),
        'schedule' => View::post('schedule', 'now'),
        'in_minutes' => (int) ($_POST['in_minutes'] ?? 30),
        'at' => View::post('at'),
    ];
    $back = $editing !== null ? 'index.php?id=' . $editId : 'index.php';
    if (View::post('action') === 'save_template') {
        $saved = App::templates()->save($user, View::post('template_name'), $input['text'], $input['align'], Time::db(App::clock()->now()));
        View::flash($saved['ok'] ? 'success' : 'error', $saved['ok'] ? t('Als Vorlage gespeichert – du findest sie unter „Meine Vorlagen“ und beim Wecker.') : (string) $saved['error']);
        View::redirect($saved['ok'] && $editing === null ? 'index.php?tpl=' . $saved['id'] : $back);
    }
    if (($deleteId = (int) ($_POST['delete_template'] ?? 0)) > 0) {
        $deleted = App::templates()->delete($user, $deleteId);
        View::flash($deleted ? 'success' : 'error', $deleted ? t('Vorlage gelöscht.') : t('Vorlage nicht gefunden.'));
        View::redirect($back);
    }
    $result = $editing !== null ? $service->update($user, $editId, $input) : $service->create($user, $input);
    if ($result['ok']) {
        $isForced = $input['forced'] || App::settings()->isForcedSource((string) $user['source']);
        $arbiter = App::triggerArbiter($isForced && $input['schedule'] === 'now');
        $shownNow = $arbiter['action'] === 'sent' && $arbiter['message_id'] === $result['id'];
        $message = $editing !== null ? t('Änderung gespeichert.') : t('Nachricht ist in der Warteschlange.');
        foreach (App::arbiter()->plan()['due'] as $planned) {
            if ((int) $planned['id'] === (int) $result['id'] && $planned['eta_seconds'] !== null) {
                $message .= ' ' . ($planned['eta_seconds'] > 0
                    ? t('Platz {position} – ca. in {duration} auf dem Board.', ['position' => $planned['position'], 'duration' => Time::duration((int) $planned['eta_seconds'])])
                    : t('Platz {position} – kommt gleich.', ['position' => $planned['position']]));
            }
        }
        View::flash('success', $shownNow ? t('Läuft gerade auf dem Board!') : $message);
        View::redirect('queue.php');
    }
    $error = $result['error'];
}

$cooldown = $editing === null ? $service->cooldownRemaining($user) : 0;
$input['align'] = Charset::align($input['align']);
$preview = Charset::textToGrid((string) $input['text'], $input['align']);
$canForce = MessageService::can($user, 'force');
$canJump = MessageService::can($user, 'jump');
$canSchedule = MessageService::can($user, 'schedule');

View::header($editing !== null ? t('Bearbeiten') : t('Schreiben'), $user, 'compose');
?>
<h1><?= $editing !== null ? View::e(t('Nachricht bearbeiten')) : View::e(t('Nachricht schreiben')) ?></h1>

<?php if ($error !== null): ?>
    <div class="flash flash-error" role="alert"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if ($cooldown > 0): ?>
    <div class="card cooldown" data-countdown="<?= $cooldown ?>">
        <p><?= t('<strong>Kurz durchatmen!</strong> Deine nächste Nachricht geht in {countdown}.', ['countdown' => '<span data-countdown-text>' . Time::duration($cooldown) . '</span>']) ?></p>
        <p class="muted"><?= View::e(t('Damit das Board nicht überläuft, gibt es eine kleine Pause zwischen deinen Nachrichten. Du kannst aber schon schreiben – abschicken geht, sobald die Zeit um ist.')) ?></p>
    </div>
<?php endif; ?>

<form method="post" class="card compose" data-busy>
    <?= Csrf::field() ?>
    <?php if ($editing !== null): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>

    <div class="preview<?= $input['align'] === 'grid' ? ' painting' : '' ?>" data-preview-target aria-live="polite"><?= GridHtml::render($preview['grid']) ?></div>
    <ul class="warnings" data-preview-warnings>
        <?php foreach ($preview['warnings'] as $warning): ?><li><?= View::e($warning) ?></li><?php endforeach; ?>
    </ul>

    <label for="text"><?= View::e(t('Text')) ?> <span class="muted"><?= View::e(t('(3 Zeilen à 15 Zeichen, Umlaute werden zu AE/OE/UE)')) ?></span></label>
    <p class="muted hint designer-hint" data-designer-hint <?= $input['align'] === 'grid' ? '' : 'hidden' ?>><?= View::e(t('Designer: Farbe oder Herz unten wählen und auf die Kacheln oben tippen. Buchstaben schreibst du im Textfeld – jede Zeile steht genau so auf dem Board.')) ?></p>
    <textarea id="text" name="text" rows="3" maxlength="400" required data-preview-input><?= "\n" . View::e($input['text']) ?></textarea>
    <p class="counter<?= $preview['fits'] ? '' : ' over' ?>" data-preview-counter aria-live="polite"><?= View::e(Charset::counterText($preview)) ?></p>

    <?= View::tokenBar($input['align'] === 'grid') ?>

    <?php $templates = App::templates()->forUser($user); ?>
    <details class="art-picker">
        <summary class="button"><?= View::e(t('Meine Vorlagen')) ?> <span class="muted">(<?= count($templates) ?>)</span></summary>
        <?php if ($templates === []): ?>
            <p class="muted hint"><?= View::e(t('Noch keine Vorlagen. Text oder Motiv gestalten und unten „Als Vorlage speichern“ tippen.')) ?></p>
        <?php endif; ?>
        <div class="art-grid">
            <?php foreach ($templates as $tpl): ?>
                <div class="art-item">
                    <button type="button" class="art-load" data-art="<?= View::e($tpl['text_raw']) ?>" data-align="<?= View::e($tpl['align']) ?>">
                        <?= GridHtml::render(Charset::textToGrid((string) $tpl['text_raw'], (string) $tpl['align'])['grid']) ?>
                        <span><?= View::e($tpl['name']) ?><?= MessageService::isAdmin($user) && (int) $tpl['user_id'] !== (int) $user['id'] ? ' <span class="muted">· ' . View::e($tpl['user_name'] ?? '') . '</span>' : '' ?></span>
                    </button>
                    <button type="submit" name="delete_template" value="<?= (int) $tpl['id'] ?>" class="link danger" formnovalidate data-confirm-click="<?= View::e(t('Vorlage „{name}“ löschen?', ['name' => $tpl['name']])) ?>"><?= View::e(t('Löschen')) ?></button>
                </div>
            <?php endforeach; ?>
        </div>
    </details>

    <details class="art-picker">
        <summary class="button"><?= View::e(t('Vestaboard-Kunst als Vorlage')) ?></summary>
        <p class="muted hint"><?= View::e(t('Motiv antippen – es landet im Designer und du kannst es weiter bemalen oder beschriften.')) ?></p>
        <div class="art-grid">
            <?php foreach (array_keys(Art::ALL) as $artId): ?>
                <button type="button" class="art-item" data-art="<?= View::e((string) Art::text($artId)) ?>">
                    <?= GridHtml::render(Charset::textToGrid((string) Art::text($artId), 'grid')['grid']) ?>
                    <span><?= View::e(Art::label($artId)) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </details>

    <fieldset class="inline">
        <legend><?= View::e(t('Ausrichtung')) ?></legend>
        <label><input type="radio" name="align" value="center" data-preview-align <?= $input['align'] === 'center' ? 'checked' : '' ?>> <?= View::e(t('mittig')) ?></label>
        <label><input type="radio" name="align" value="left" data-preview-align <?= $input['align'] === 'left' ? 'checked' : '' ?>> <?= View::e(t('links')) ?></label>
        <label><input type="radio" name="align" value="grid" data-preview-align <?= $input['align'] === 'grid' ? 'checked' : '' ?>> <?= View::e(t('Designer')) ?> <span class="muted"><?= View::e(t('(Kacheln malen)')) ?></span></label>
    </fieldset>

    <?php if ($canSchedule): ?>
        <fieldset class="schedule">
            <legend><?= View::e(t('Wann?')) ?></legend>
            <label><input type="radio" name="schedule" value="now" <?= $input['schedule'] === 'now' ? 'checked' : '' ?>> <?= View::e(t('sofort / nächster freier Platz')) ?></label>
            <label><input type="radio" name="schedule" value="in" <?= $input['schedule'] === 'in' ? 'checked' : '' ?>> <?= t('in {input} Minuten', ['input' => '<input type="number" name="in_minutes" min="1" max="' . MessageService::MAX_SCHEDULE_MINUTES . '" value="' . (int) $input['in_minutes'] . '" class="short">']) ?></label>
            <label><input type="radio" name="schedule" value="at" <?= $input['schedule'] === 'at' ? 'checked' : '' ?>> <?= t('am {input}', ['input' => '<input type="datetime-local" name="at" value="' . View::e($input['at']) . '">']) ?></label>
        </fieldset>
    <?php endif; ?>

    <?php if ($canForce || $canJump): ?>
        <fieldset class="options">
            <legend><?= View::e(t('Optionen')) ?></legend>
            <?php if ($canForce): ?>
                <label title="<?= View::e(t('Erscheint sofort, auch bei Stummschaltung')) ?>"><input type="checkbox" name="forced" value="1" <?= $input['forced'] ? 'checked' : '' ?>> <span>Force <span class="muted"><?= View::e(t('(erscheint sofort, auch bei Stummschaltung – überspringt die Warteschlange)')) ?></span></span></label>
            <?php endif; ?>
            <?php if ($canJump): ?>
                <label><input type="checkbox" name="jump" value="1" <?= $input['jump'] ? 'checked' : '' ?>> <span><?= View::e(t('vorne anstellen')) ?> <span class="muted"><?= View::e(t('(stellt sich vorne statt hinten in der Warteschlange an – wichtigere Nachrichten können trotzdem vorher kommen)')) ?></span></span></label>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <div class="buttons">
        <input type="hidden" name="template_name" value="" data-template-name>
        <button type="submit" name="action" value="save_template" formnovalidate data-template-save><?= View::e(t('Als Vorlage speichern')) ?></button>
        <button type="submit" class="primary" data-preview-submit <?= $cooldown > 0 ? 'data-locked' : '' ?> <?= $cooldown > 0 || !$preview['fits'] ? 'disabled' : '' ?>><?= $editing !== null ? View::e(t('Speichern')) : View::e(t('Abschicken')) ?></button>
        <?php if ($editing !== null): ?><a href="queue.php" class="button"><?= View::e(t('Abbrechen')) ?></a><?php endif; ?>
    </div>
</form>
<?php
View::footer();
