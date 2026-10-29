<?php
declare(strict_types=1);

// Archiv: alles, was auf dem Board war oder sein sollte – nach Tagen gruppiert

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Csrf;
use Vesta\I18n;
use Vesta\MessageService;
use Vesta\MessageView;
use Vesta\Sources;
use Vesta\Time;
use Vesta\View;

$user = Auth::requireUser();
$isAdmin = MessageService::isAdmin($user);
$service = App::messageService();
const PAGE_SIZE = 40;
$weekdayNames = [1 => t('Montag'), 2 => t('Dienstag'), 3 => t('Mittwoch'), 4 => t('Donnerstag'), 5 => t('Freitag'), 6 => t('Samstag'), 7 => t('Sonntag')];

$filterKeys = ['period' => 1, 'status' => 1, 'user' => 1, 'source' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $id = (int) ($_POST['id'] ?? 0);
    [$result, $success] = match ((string) ($_POST['action'] ?? '')) {
        'duplicate' => [$service->duplicate($user, $id), t('Nachricht ist wieder in der Warteschlange.')],
        'restore' => [$service->restore($user, $id), t('Nachricht wiederhergestellt.')],
        'purge' => [$service->purge($user, $id), t('Eintrag gelöscht.')],
        'reply' => [$service->reply($user, $id, View::post('reply')), View::post('reply') === '' ? t('Antwort entfernt.') : t('Antwort gesendet.')],
        'reply_seen' => [$service->markReplySeen($user, $id), t('Antwort als gelesen markiert.')],
        default => [['ok' => false, 'error' => t('Unbekannte Aktion.')], ''],
    };
    if ($result['ok']) {
        App::triggerArbiter();
    }
    $back = in_array(View::post('back'), ['index.php', 'queue.php'], true) ? View::post('back') : 'history.php?' . http_build_query(array_intersect_key($_GET, $filterKeys));
    View::finish($result, $success, $back);
}

$periods = ['hour' => t('Letzte Stunde'), 'today' => t('Heute'), '10d' => t('Letzte 10 Tage'), '30d' => t('Letzte 30 Tage'), 'all' => t('Alles')];
$period = isset($periods[View::query('period')]) ? View::query('period') : '10d';
$statuses = ['' => t('Alle'), 'showing' => t('läuft'), 'shown' => t('angezeigt'), 'expired' => t('abgelaufen'), 'deleted' => t('gelöscht'), 'failed' => t('fehlgeschlagen')];
$status = isset($statuses[View::query('status')]) ? View::query('status') : '';
$sources = ['' => t('Alle')] + array_combine(array_keys(Sources::all()), array_map([Sources::class, 'label'], array_keys(Sources::all())))
    + [Sources::EXTERNAL => Sources::label(Sources::EXTERNAL), Sources::IDLE => Sources::label(Sources::IDLE)];
$source = $isAdmin && isset($sources[View::query('source')]) ? View::query('source') : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$now = App::clock()->now();
$since = match ($period) {
    'hour' => $now->modify('-1 hour'),
    'today' => Time::local($now)->setTime(0, 0),
    '10d' => Time::local($now)->setTime(0, 0)->modify('-9 days'),
    '30d' => Time::local($now)->setTime(0, 0)->modify('-29 days'),
    default => null,
};
$filterUser = $isAdmin ? (int) ($_GET['user'] ?? 0) : (int) $user['id'];
$rows = App::messages()->history([
    'since' => $since === null ? null : Time::db($since),
    'status' => $status,
    'user_id' => $filterUser ?: null,
    'source' => $source,
], PAGE_SIZE + 1, ($page - 1) * PAGE_SIZE);
$hasMore = count($rows) > PAGE_SIZE;
$rows = array_slice($rows, 0, PAGE_SIZE);

View::header(t('Archiv'), $user, 'history');
?>
<h1><?= View::e(t('Archiv')) ?></h1>

<form method="get" class="filters card">
    <label><?= View::e(t('Zeitraum')) ?>
        <select name="period"><?php foreach ($periods as $value => $label): ?>
            <option value="<?= $value ?>" <?= $value === $period ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label><?= View::e(t('Status')) ?>
        <select name="status"><?php foreach ($statuses as $value => $label): ?>
            <option value="<?= $value ?>" <?= $value === $status ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
        </select>
    </label>
    <?php if ($isAdmin): ?>
        <label><?= View::e(t('Quelle')) ?>
            <select name="source"><?php foreach ($sources as $value => $label): ?>
                <option value="<?= View::e($value) ?>" <?= $value === $source ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label><?= View::e(t('Person')) ?>
            <select name="user">
                <option value="0"><?= View::e(t('Alle')) ?></option>
                <?php foreach (App::users()->all() as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $filterUser ? 'selected' : '' ?>><?= View::e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <button type="submit"><?= View::e(t('Filtern')) ?></button>
</form>

<?php if ($rows === []): ?>
    <div class="empty">
        <p><?= View::e(t('Für diesen Zeitraum gibt es keine Einträge.')) ?></p>
        <a class="button" href="history.php?period=all"><?= View::e(t('Alles anzeigen')) ?></a>
    </div>
<?php endif; ?>

<?php
$currentDay = null;
foreach ($rows as $m):
    $when = Time::local(Time::fromDb((string) ($m['shown_at'] ?? $m['created_at'])));
    $day = $when->format('Y-m-d');
    if ($day !== $currentDay):
        $currentDay = $day; ?>
        <h2 class="day"><?= View::e($weekdayNames[(int) $when->format('N')]) ?>, <?= $when->format(I18n::dateFormat('d.m.Y')) ?></h2>
    <?php endif;

    $badges = [];
    if ($m['shown_at'] !== null) {
        $badges[] = $m['ended_at'] !== null
            ? t('{from}–{to} Uhr', ['from' => Time::format($m['shown_at'], 'H:i'), 'to' => Time::format($m['ended_at'], 'H:i')])
            : t('seit {time} Uhr', ['time' => Time::format($m['shown_at'], 'H:i')]);
    }
    $badges[] = [View::statusLabel((string) $m['status']), View::statusTone((string) $m['status'])];
    $actions = '';
    if (!in_array($m['source'], [Sources::IDLE, Sources::EXTERNAL], true) && $service->canManage($user, $m)) {
        $actions .= MessageView::action('history.php', 'duplicate', (int) $m['id'], t('Erneut senden'), !empty($user['cooldown_min']) ? t('Nochmal senden? Danach beginnt deine Pause.') : '');
    }
    if ($m['source'] !== Sources::IDLE && ($isAdmin || (int) $m['user_id'] === (int) $user['id'])) {
        $actions .= '<a class="button" href="index.php?template=' . (int) $m['id'] . '">' . View::e(t('Als Vorlage')) . '</a>';
    }
    if ($isAdmin && $m['status'] === 'deleted') {
        $actions .= MessageView::action('history.php', 'restore', (int) $m['id'], t('Wiederherstellen'));
    }
    if ($isAdmin && $m['user_id'] !== null && (int) $m['user_id'] !== (int) $user['id']) {
        $actions .= MessageView::replyForm('history.php', $m);
    }
    if ($isAdmin && !in_array($m['status'], ['queued', 'showing'], true)) {
        $actions .= MessageView::action('history.php', 'purge', (int) $m['id'], t('Löschen'), t('Eintrag endgültig aus dem Verlauf löschen?'), 'danger');
    }
    echo MessageView::card($m, $badges, $actions);
endforeach; ?>

<nav class="pager">
    <?php $query = ['period' => $period, 'status' => $status, 'user' => $filterUser, 'source' => $source]; ?>
    <?php if ($page > 1): ?><a class="button" href="?<?= http_build_query($query + ['page' => $page - 1]) ?>">← <?= View::e(t('Neuere')) ?></a><?php endif; ?>
    <?php if ($hasMore): ?><a class="button" href="?<?= http_build_query($query + ['page' => $page + 1]) ?>"><?= View::e(t('Ältere')) ?> →</a><?php endif; ?>
</nav>
<?php
View::footer();
