<?php
declare(strict_types=1);

// Aufruf: php tests/message-service-test.php

require __DIR__ . '/support.php';

use Vesta\MessageRepo;
use Vesta\MessageService;
use Vesta\Time;
use Vesta\UserRepo;

function setup(): array
{
    $pdo = memoryDb();
    $clock = new FixedClock('2026-10-26 12:00:00');
    $users = new UserRepo($pdo);
    $messages = new MessageRepo($pdo);
    $service = new MessageService($messages, $clock);
    $base = ['login_type' => 'pin', 'secret_hash' => 'x', 'created_at' => Time::db($clock->now())];
    $ids = [
        'admin' => $users->insert($base + ['name' => 'Admin', 'role' => 'admin', 'source' => 'admin', 'login_type' => 'password']),
        'adult' => $users->insert($base + ['name' => 'Alex', 'role' => 'member', 'source' => 'adults', 'can_force' => 1, 'can_jump' => 1, 'can_schedule' => 1]),
        'kid' => $users->insert($base + ['name' => 'Mia', 'role' => 'kid', 'source' => 'kids', 'cooldown_min' => 15]),
        'kid2' => $users->insert($base + ['name' => 'Leni', 'role' => 'kid', 'source' => 'kids', 'cooldown_min' => 15]),
    ];
    $u = array_map(static fn (int $id): array => $users->find($id), $ids);
    return [$service, $messages, $clock, $u];
}

section('Anlegen und Validierung');
[$service, $messages, $clock, $u] = setup();
$r = $service->create($u['adult'], ['text' => 'Hallo Schatz']);
check('Alex legt Nachricht an', $r['ok']);
$m = $messages->find($r['id']);
check('Quelle = Quelle des Nutzers', $m['source'] === 'adults' && $m['status'] === 'queued');
check('Leerer Text abgelehnt', !$service->create($u['adult'], ['text' => '   '])['ok']);
check('Zu langer Text abgelehnt', !$service->create($u['adult'], ['text' => str_repeat('WORT ', 20)])['ok']);

section('Rechte');
[$service, $messages, $clock, $u] = setup();
check('Kind darf kein Force', $service->create($u['kid'], ['text' => 'X', 'forced' => '1'])['error'] === 'Force ist für dich nicht freigeschaltet.');
check('Kind darf nicht vorne anstellen', !$service->create($u['kid'], ['text' => 'X', 'jump' => '1'])['ok']);
check('Kind darf nicht planen', !$service->create($u['kid'], ['text' => 'X', 'schedule' => 'in', 'in_minutes' => 10])['ok']);
check('Alex darf Force + vorne + planen', $service->create($u['adult'], ['text' => 'X', 'forced' => 1, 'jump' => 1, 'schedule' => 'in', 'in_minutes' => 5])['ok']);
check('Admin darf alles ohne Flags', $service->create($u['admin'], ['text' => 'X', 'forced' => 1, 'jump' => 1])['ok']);

section('Sperrzeit Kinder');
[$service, $messages, $clock, $u] = setup();
$first = $service->create($u['kid'], ['text' => 'Eins']);
check('Erste Nachricht ok', $first['ok']);
$second = $service->create($u['kid'], ['text' => 'Zwei']);
check('Zweite innerhalb 15 Min abgelehnt', !$second['ok'] && str_contains((string) $second['error'], '15 Min'));
check('Anderes Kind ist nicht betroffen', $service->create($u['kid2'], ['text' => 'Hi'])['ok']);
$service->delete($u['kid'], $first['id']);
check('Löschen setzt Sperrzeit nicht zurück', !$service->create($u['kid'], ['text' => 'Drei'])['ok']);
$clock->advance(10 * 60);
check('Restzeit wird angezeigt (5 Min)', str_contains((string) $service->create($u['kid'], ['text' => 'X'])['error'], '5 Min'));
$clock->advance(5 * 60);
check('Nach 15 Min wieder möglich', $service->create($u['kid'], ['text' => 'Vier'])['ok']);
check('Alex hat keine Sperrzeit', $service->create($u['adult'], ['text' => 'A'])['ok'] && $service->create($u['adult'], ['text' => 'B'])['ok']);

section('Planung');
[$service, $messages, $clock, $u] = setup();
$in = $service->create($u['adult'], ['text' => 'X', 'schedule' => 'in', 'in_minutes' => 30]);
check('"in 30 Min" → not_before = jetzt + 30 Min', $messages->find($in['id'])['not_before'] === Time::db($clock->now()->modify('+30 minutes')));
$at = $service->create($u['adult'], ['text' => 'X', 'schedule' => 'at', 'at' => '2026-10-26T18:00']);
check('"um 18:00" Ortszeit → 17:00 UTC', $messages->find($at['id'])['not_before'] === '2026-10-26 17:00:00');
check('Vergangenheit abgelehnt', !$service->create($u['adult'], ['text' => 'X', 'schedule' => 'at', 'at' => '2026-10-26T09:00'])['ok']);
check('Ungültige Minuten abgelehnt', !$service->create($u['adult'], ['text' => 'X', 'schedule' => 'in', 'in_minutes' => 0])['ok']);
check('Mehr als 30 Tage abgelehnt', !$service->create($u['adult'], ['text' => 'X', 'schedule' => 'at', 'at' => '2027-01-01T10:00'])['ok']);

section('Bearbeiten, Löschen, Wiederherstellen');
[$service, $messages, $clock, $u] = setup();
$own = $service->create($u['kid'], ['text' => 'Mein Text']);
check('Eigene Nachricht bearbeiten', $service->update($u['kid'], $own['id'], ['text' => 'Neuer Text'])['ok']);
check('Text geändert', $messages->find($own['id'])['text_raw'] === 'Neuer Text');
check('Fremde Nachricht nicht bearbeitbar', !$service->update($u['kid2'], $own['id'], ['text' => 'Hack'])['ok']);
check('Fremde Nachricht nicht löschbar', !$service->delete($u['kid2'], $own['id'])['ok']);
check('Admin darf fremde bearbeiten', $service->update($u['admin'], $own['id'], ['text' => 'Admin'])['ok']);
check('Löschen = Soft-Delete', $service->delete($u['kid'], $own['id'])['ok'] && $messages->find($own['id'])['status'] === 'deleted');
check('Gelöschte nicht mehr bearbeitbar', !$service->update($u['kid'], $own['id'], ['text' => 'X'])['ok']);
check('Kind kann nicht wiederherstellen', !$service->restore($u['kid'], $own['id'])['ok']);
check('Admin stellt wieder her', $service->restore($u['admin'], $own['id'])['ok'] && $messages->find($own['id'])['status'] === 'queued');
$messages->update($own['id'], ['status' => 'showing']);
check('Laufende Nachricht nicht bearbeitbar', !$service->update($u['admin'], $own['id'], ['text' => 'X'])['ok']);

section('Admin-Aktionen');
[$service, $messages, $clock, $u] = setup();
$planned = $service->create($u['adult'], ['text' => 'Spaeter', 'schedule' => 'in', 'in_minutes' => 60]);
check('Kind kann nicht sofort einspielen', !$service->playNow($u['kid'], $planned['id'])['ok']);
check('Admin spielt sofort ein', $service->playNow($u['admin'], $planned['id'])['ok']);
$m = $messages->find($planned['id']);
check('→ forced und Planung aufgehoben', (int) $m['forced'] === 1 && $m['not_before'] === null);
$failed = $service->create($u['adult'], ['text' => 'Kaputt']);
$messages->update($failed['id'], ['status' => 'failed', 'attempts' => 3]);
check('Retry setzt Versuche zurück', $service->retry($u['admin'], $failed['id'])['ok'] && (int) $messages->find($failed['id'])['attempts'] === 0);
$clear = $service->clearBoard($u['admin']);
check('Board leeren = leere Force-Nachricht', $clear['ok'] && (int) $messages->find($clear['id'])['forced'] === 1);
check('Kind kann Board nicht leeren', !$service->clearBoard($u['kid'])['ok']);
check('Überspringen ohne laufende Nachricht → Fehler', !$service->skipCurrent($u['admin'])['ok']);
$messages->update($failed['id'], ['status' => 'showing', 'shown_at' => Time::db($clock->now())]);
check('Überspringen setzt ended_at', $service->skipCurrent($u['admin'])['ok'] && $messages->find($failed['id'])['ended_at'] !== null);

section('Erneut senden');
[$service, $messages, $clock, $u] = setup();
$orig = $service->create($u['kid'], ['text' => 'Nochmal']);
$messages->update($orig['id'], ['status' => 'shown']);
check('Erneut senden unterliegt Sperrzeit', !$service->duplicate($u['kid'], $orig['id'])['ok']);
$clock->advance(16 * 60);
$copy = $service->duplicate($u['kid'], $orig['id']);
check('Nach Sperrzeit: Kopie als neue Nachricht', $copy['ok'] && $copy['id'] !== $orig['id'] && $messages->find($copy['id'])['text_raw'] === 'Nochmal');
check('Fremde Nachricht nicht kopierbar', !$service->duplicate($u['kid2'], $orig['id'])['ok']);

section('Review-Fixes');
[$service, $messages, $clock, $u] = setup();
$kidMsg = $service->create($u['kid'], ['text' => 'Mein Text']);
$service->playNow($u['admin'], $kidMsg['id']);
check('Kind bearbeitet: Admin-Force bleibt erhalten', $service->update($u['kid'], $kidMsg['id'], ['text' => 'Geaendert'])['ok'] && (int) $messages->find($kidMsg['id'])['forced'] === 1);
$messages->update($kidMsg['id'], ['status' => 'shown']);
$clock->advance(16 * 60);
$copy = $service->duplicate($u['kid'], $kidMsg['id']);
check('Erneut senden ohne Force-Recht klappt (Force fällt weg)', $copy['ok'] && (int) $messages->find($copy['id'])['forced'] === 0);
check('Ungültiges UTF-8 wird abgelehnt', !$service->create($u['adult'], ['text' => "Hallo \xC3\x28"])['ok']);

$running = $service->create($u['adult'], ['text' => 'Laeuft', 'forced' => 1]);
$messages->update($running['id'], ['status' => 'showing', 'shown_at' => Time::db($clock->now()), 'sent_forced' => 1]);
$next = $service->create($u['adult'], ['text' => 'Danach']);
$service->playNow($u['admin'], $next['id']);
check('Jetzt einspielen beendet die laufende Anzeige', $messages->find($running['id'])['ended_at'] !== null);

section('Antworten');
[$service, $messages, $clock, $u] = setup();
$kidMessage = $service->create($u['kid'], ['text' => 'Hallo Papa'])['id'];
check('Kind darf nicht antworten', !$service->reply($u['kid'], $kidMessage, 'Hi')['ok']);
check('Admin antwortet', $service->reply($u['admin'], $kidMessage, 'Hab dich lieb!')['ok']);
check('Antwort ist für das Kind ungelesen', count($messages->unseenReplies((int) $u['kid']['id'])) === 1);
check('Anderes Kind sieht sie nicht', $messages->unseenReplies((int) $u['kid2']['id']) === []);
check('Anderes Kind kann sie nicht als gelesen markieren', !$service->markReplySeen($u['kid2'], $kidMessage)['ok']);
check('Kind markiert als gelesen', $service->markReplySeen($u['kid'], $kidMessage)['ok'] && $messages->unseenReplies((int) $u['kid']['id']) === []);
check('Antwort zu lang abgelehnt', !$service->reply($u['admin'], $kidMessage, str_repeat('x', MessageService::MAX_REPLY_LENGTH + 1))['ok']);
check('Leere Antwort entfernt sie', $service->reply($u['admin'], $kidMessage, '')['ok'] && $messages->find($kidMessage)['reply_text'] === null);
$adminMessage = $service->create($u['admin'], ['text' => 'Eigene'])['id'];
check('Admin kann nicht auf eigene Nachricht antworten', !$service->reply($u['admin'], $adminMessage, 'x')['ok']);

section('Aus dem Verlauf löschen');
check('Wartende Nachricht nicht über den Verlauf löschbar', !$service->purge($u['admin'], $kidMessage)['ok']);
$messages->update($kidMessage, ['status' => 'shown']);
check('Kind darf nicht endgültig löschen', !$service->purge($u['kid'], $kidMessage)['ok']);
check('Admin löscht angezeigte Nachricht endgültig', $service->purge($u['admin'], $kidMessage)['ok'] && $messages->find($kidMessage) === null);

finish();
