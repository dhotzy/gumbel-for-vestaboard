<?php
declare(strict_types=1);

// Aktuelle Anzeige + Warteschlange (+ Admin-Status und -Aktionen)

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Charset;
use Vesta\Csrf;
use Vesta\GridHtml;
use Vesta\I18n;
use Vesta\MessageService;
use Vesta\MessageView;
use Vesta\Sources;
use Vesta\Time;
use Vesta\View;

$user = Auth::requireUser();
$isAdmin = MessageService::isAdmin($user);
$service = App::messageService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'bulk' && $isAdmin) {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $bulk = (string) ($_POST['bulk_action'] ?? '');
        $done = 0;
        foreach ($ids as $bulkId) {
            $result = $bulk === 'delete' ? $service->delete($user, $bulkId) : ($bulk === 'jump' ? $service->jump($user, $bulkId) : ['ok' => false]);
            $done += $result['ok'] ? 1 : 0;
        }
        View::flash($done > 0 ? 'success' : 'error', $done > 0 ? t('{count} Nachricht(en) bearbeitet.', ['count' => $done]) : t('Keine Nachricht ausgewählt.'));
        App::triggerArbiter();
        View::redirect('queue.php');
    }

    if ($action === 'sync' && $isAdmin) {
        $sync = App::boardSync()->sync();
        View::flash($sync['ok'] ? 'success' : 'error', $sync['detail']);
        View::redirect('queue.php');
    }

    [$result, $success, $waitForce] = match ($action) {
        'delete' => [$service->delete($user, $id), t('Nachricht gelöscht.'), false],
        'jump' => [$service->jump($user, $id), t('Nachricht nach vorne gestellt.'), false],
        'play_now' => [$service->playNow($user, $id), t('Wird sofort eingespielt.'), true],
        'retry' => [$service->retry($user, $id), t('Nachricht ist wieder in der Warteschlange.'), false],
        'skip' => [$service->skipCurrent($user), t('Aktuelle Nachricht übersprungen.'), false],
        'clear' => [$service->clearBoard($user), t('Board wird geleert.'), true],
        default => [['ok' => false, 'error' => t('Unbekannte Aktion.')], '', false],
    };
    if ($result['ok']) {
        App::triggerArbiter($waitForce);
    }
    View::finish($result, $success, 'queue.php');
}

App::triggerArbiter(); // Seite zeigt immer den aktuellsten Stand
$current = App::messages()->current();
$plan = App::arbiter()->plan();
$remaining = App::arbiter()->currentRemaining();
$settings = App::settings();
$now = App::clock()->now();
$muteReason = $settings->globalMuteReason($now);
$boardStatus = (array) $settings->get('board_status');

// Nicht-Admins sehen fremde Nachrichten nur als Platzhalter „Andere Nachricht“ (ohne Inhalt, Autor, Quelle)
$visible = static fn (?array $m): bool => $m !== null && ($isAdmin || (int) $m['user_id'] === (int) $user['id']);
$due = $plan['due'];
$scheduled = array_values(array_filter($plan['scheduled'], $visible));
$otherGrid = Charset::textToGrid(t('Andere Nachricht'))['grid'];

$actionsFor = static function (array $m) use ($user, $isAdmin, $service): string {
    $html = '';
    if ($service->canManage($user, $m)) {
        $html .= '<a class="button" href="index.php?id=' . (int) $m['id'] . '">' . View::e(t('Bearbeiten')) . '</a>';
        $html .= MessageView::action('queue.php', 'delete', (int) $m['id'], t('Löschen'), t('Nachricht wirklich löschen?'), 'danger');
    }
    if ((int) $m['jump'] !== 1 && MessageService::can($user, 'jump') && $service->canManage($user, $m)) {
        $html .= MessageView::action('queue.php', 'jump', (int) $m['id'], t('Vorne anstellen'));
    }
    if ($isAdmin && $m['user_id'] !== null && (int) $m['user_id'] !== (int) $user['id']) {
        $html .= MessageView::replyForm('history.php', $m, 'queue.php');
    }
    if ($isAdmin) {
        $html .= MessageView::action('queue.php', 'play_now', (int) $m['id'], t('Jetzt einspielen'), t('Sofort aufs Board (auch bei Stummschaltung)?'));
    }
    return $html;
};
$checkbox = static fn (array $m): string => $isAdmin
    ? '<label class="select"><input type="checkbox" name="ids[]" value="' . (int) $m['id'] . '" form="bulk"><span class="sr">' . View::e(t('Auswählen')) . '</span></label>'
    : '';

View::header(t('Board'), $user, 'queue');
?>
<h1><?= $isAdmin ? View::e(t('Board')) : View::e(t('Warteschlange')) ?></h1>

<?php
$lastCron = $settings->get('last_cron_at');
$cronStale = $lastCron === null || Time::secondsBetween(Time::fromDb((string) $lastCron), $now) > 180;
if ($isAdmin && $cronStale): ?>
    <div class="flash flash-error" role="alert"><?= View::e(t('Der Cronjob läuft nicht – automatische Wechsel und der Board-Abgleich stehen still. Letzter Lauf: {time}.', ['time' => Time::format($lastCron, 'd.m. H:i')])) ?></div>
<?php elseif ($isAdmin && !empty($boardStatus['last_error_at']) && (empty($boardStatus['last_ok_at']) || $boardStatus['last_error_at'] > $boardStatus['last_ok_at'])): ?>
    <div class="flash flash-error" role="alert"><?= View::e(t('Letzter Versand fehlgeschlagen: {error}', ['error' => (string) $boardStatus['last_error']])) ?></div>
<?php endif; ?>
<?php if (!$isAdmin && ($visible($current) || array_filter($due, $visible) !== [])): // eigene Nachricht wartet/läuft → Seite aktualisiert sich ?>
    <div hidden data-autorefresh="30"></div>
<?php endif; ?>
<?php if ($muteReason !== null): ?>
    <div class="flash flash-warn"><?= View::e(t('{reason} – nur Force-Nachrichten erscheinen.', ['reason' => $muteReason])) ?></div>
<?php endif; ?>
<?php if (!empty($boardStatus['external_mute'])): ?>
    <div class="flash flash-warn"><?= View::e(t('Das Board ist in der Vestaboard-App stummgeschaltet. Nur Force-Nachrichten kommen durch.')) ?></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<section class="card now">
    <h2><?= View::e(t('Gerade auf dem Board')) ?></h2>
    <?php if ($current !== null): ?>
        <?= GridHtml::render((array) json_decode((string) $current['grid'], true)) ?>
        <p>
            <?php $sourceLabel = Sources::label((string) $current['source']); ?>
            <strong><?= View::e($sourceLabel) ?><?= !empty($current['user_name']) && $current['user_name'] !== $sourceLabel ? ' · ' . View::e($current['user_name']) : '' ?></strong>
            <span class="muted"><?= View::e(t('seit {time} Uhr', ['time' => Time::format($current['shown_at'], 'H:i')])) ?><?= $remaining > 0 ? ' · ' . View::e(t('noch mind. {duration}', ['duration' => Time::duration($remaining)])) : '' ?></span>
        </p>
        <?php if (!empty($current['reply_text'])) {
            echo MessageView::replyBlock($current);
        }
        if ($current['user_id'] !== null && (int) $current['user_id'] !== (int) $user['id']): ?>
            <div class="msg-actions now-reply"><?= MessageView::replyForm('history.php', $current, 'queue.php') ?></div>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted"><?= View::e(t('Noch nichts über diese App gesendet.')) ?></p>
    <?php endif; ?>
    <?php if ($isAdmin):
        $live = (array) $settings->get('board_live'); ?>
        <p class="muted"><?= View::e(t('Mit dem echten Board abgeglichen: {time}', ['time' => !empty($live['read_at']) ? t('{time} Uhr', ['time' => Time::format($live['read_at'], 'd.m. H:i')]) : t('noch nie')])) ?></p>
        <div class="buttons">
            <?php if ($current !== null): ?>
                <?= MessageView::action('queue.php', 'skip', 0, t('Überspringen'), t('Aktuelle Nachricht überspringen?')) ?>
            <?php endif; ?>
            <?= MessageView::action('queue.php', 'clear', 0, t('Board leeren'), t('Board jetzt leeren?'), 'danger') ?>
            <?= MessageView::action('queue.php', 'sync', 0, t('Mit Board abgleichen')) ?>
        </div>
    <?php endif; ?>
</section>
<?php elseif ($visible($current)): ?>
<section class="card now">
    <h2><?= View::e(t('Deine Nachricht läuft gerade auf dem Board!')) ?></h2>
    <?= GridHtml::render((array) json_decode((string) $current['grid'], true)) ?>
    <p class="muted"><?= View::e(t('seit {time} Uhr', ['time' => Time::format($current['shown_at'], 'H:i')])) ?></p>
</section>
<?php else: ?>
<section class="card now">
    <h2><?= View::e(t('Gerade auf dem Board')) ?></h2>
    <?php if ($current === null): ?>
        <p class="muted"><?= View::e(t('Gerade läuft keine Nachricht.')) ?></p>
    <?php else: ?>
        <?= GridHtml::render($otherGrid) ?>
    <?php endif; ?>
    <p class="muted"><?= $current === null ? '' : View::e(t('Eine andere Nachricht')) ?><?= $current !== null && $remaining > 0 ? ' · ' . View::e(t('noch mind. {duration}', ['duration' => Time::duration($remaining)])) : '' ?></p>
</section>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <section class="card status">
        <h2><?= View::e(t('Status')) ?></h2>
        <dl>
            <dt><?= View::e(t('Letzter Cron-Lauf')) ?></dt>
            <dd><?= Time::format($settings->get('last_cron_at'), 'd.m. H:i') ?><?php
                $lastCron = $settings->get('last_cron_at');
                if ($lastCron === null || Time::secondsBetween(Time::fromDb((string) $lastCron), $now) > 180): ?>
                    <span class="badge badge-error"><?= View::e(t('Cron läuft nicht')) ?></span><?php endif; ?></dd>
            <dt><?= View::e(t('Letzter erfolgreicher Versand')) ?></dt>
            <dd><?= Time::format($boardStatus['last_ok_at'] ?? null, 'd.m. H:i') ?></dd>
            <dt><?= View::e(t('Letzter Fehler')) ?></dt>
            <dd><?= !empty($boardStatus['last_error']) ? View::e($boardStatus['last_error']) . ' (' . Time::format($boardStatus['last_error_at']) . ')' : '–' ?></dd>
        </dl>
    </section>
<?php endif; ?>

<section>
    <h2><?= View::e(t('Warteschlange')) ?> <span class="muted">(<?= count($due) ?>)</span></h2>
    <?php if ($due === []): ?>
        <div class="empty">
            <p><?= View::e(t('Gerade wartet nichts.')) ?></p>
            <a class="button primary" href="index.php"><?= View::e(t('Nachricht schreiben')) ?></a>
        </div>
    <?php endif; ?>
    <?php if ($due !== [] && $current !== null && $remaining > 0 && !$due[0]['effective_forced'] && $due[0]['eta_seconds'] !== null): ?>
        <p class="muted"><?= $isAdmin
            ? View::e(t('Die aktuelle Nachricht hat ihre Mindestanzeigedauer ({min}) noch nicht erreicht – Platz 1 kommt in {duration}. Mit „Jetzt einspielen“ geht es sofort.', ['min' => Time::duration(App::settings()->minDurationSeconds((string) $current['source'])), 'duration' => Time::duration($remaining)]))
            : View::e(t('Die aktuelle Nachricht bleibt noch eine Weile – Platz 1 kommt in {duration}.', ['duration' => Time::duration($remaining)])) ?></p>
    <?php endif; ?>
    <?php foreach ($due as $m):
        $badges = [t('Platz {position}', ['position' => $m['position']])];
        $badges[] = [$m['eta_seconds'] === null ? t('wartet auf Ende der Stummschaltung') : ($m['eta_seconds'] === 0 || $m['effective_forced'] ? t('kommt jetzt') : t('ca. in {duration}', ['duration' => Time::duration((int) $m['eta_seconds'])])), 'wait'];
        echo $visible($m) ? MessageView::card($m, $badges, $actionsFor($m), $checkbox($m)) : MessageView::otherCard($badges);
    endforeach; ?>
</section>

<?php if ($scheduled !== []): ?>
    <section>
        <h2><?= View::e(t('Geplant')) ?> <span class="muted">(<?= count($scheduled) ?>)</span></h2>
        <?php foreach ($scheduled as $m):
            echo MessageView::card($m, [t('geplant für {time} Uhr', ['time' => Time::format($m['not_before'], 'd.m. H:i')])], $actionsFor($m), $checkbox($m));
        endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($isAdmin && ($upcoming = App::automation()->upcomingAlarms()) !== []):
    $today = Time::local($now)->format('Y-m-d');
    $tomorrow = Time::local($now)->modify('+1 day')->format('Y-m-d');
    $weekdays = [1 => t('Mo'), 2 => t('Di'), 3 => t('Mi'), 4 => t('Do'), 5 => t('Fr'), 6 => t('Sa'), 7 => t('So')]; ?>
    <section>
        <h2><?= View::e(t('Kommende Wecker')) ?> <span class="muted">(<?= count($upcoming) ?>)</span></h2>
        <?php foreach ($upcoming as $entry):
            $alarm = $entry['alarm'];
            $day = $entry['at']->format('Y-m-d');
            $dayLabel = $day === $today ? t('heute') : ($day === $tomorrow ? t('morgen') : $weekdays[(int) $entry['at']->format('N')] . ' ' . $entry['at']->format(I18n::dateFormat('d.m.')));
            $when = t('{day} {time} Uhr', ['day' => $dayLabel, 'time' => $entry['at']->format('H:i')]);
            $fake = ['grid' => json_encode(Charset::textToGrid((string) $alarm['text'], (string) ($alarm['align'] ?? 'center'))['grid']), 'source' => 'alarm', 'user_name' => null, 'forced' => !empty($alarm['forced']) ? 1 : 0, 'jump' => 0, 'last_error' => null, 'created_at' => null];
            echo MessageView::card($fake, [[$when, 'wait']], '<a class="button" href="automation.php#wecker">' . View::e(t('Bearbeiten')) . '</a>');
        endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($isAdmin && ($due !== [] || $scheduled !== [])): ?>
    <form method="post" id="bulk" class="card bulk" data-busy data-confirm="<?= View::e(t('Ausgewählte Nachrichten bearbeiten?')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="bulk">
        <label><?= View::e(t('Ausgewählte:')) ?>
            <select name="bulk_action">
                <option value="delete"><?= View::e(t('löschen')) ?></option>
                <option value="jump"><?= View::e(t('vorne anstellen')) ?></option>
            </select>
        </label>
        <button type="submit"><?= View::e(t('Ausführen')) ?></button>
    </form>
<?php endif; ?>

<?php if ($isAdmin):
    $failed = App::messages()->failed();
    if ($failed !== []): ?>
        <section>
            <h2><?= View::e(t('Fehlgeschlagen')) ?> <span class="muted">(<?= count($failed) ?>)</span></h2>
            <?php foreach ($failed as $m):
                echo MessageView::card($m, [], MessageView::action('queue.php', 'retry', (int) $m['id'], t('Erneut versuchen'), '', 'primary'));
            endforeach; ?>
        </section>
    <?php endif;
endif;
View::footer();
