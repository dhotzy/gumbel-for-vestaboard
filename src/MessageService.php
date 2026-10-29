<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;

/**
 * Geschäftsregeln rund um Nachrichten: Rechte, Sperrzeit, Planung, Statuswechsel.
 * Jede Methode liefert array{ok: bool, error: ?string, id: ?int}.
 */
final class MessageService
{
    public const MAX_SCHEDULE_MINUTES = 60 * 24 * 30;

    public function __construct(
        private readonly MessageRepo $messages,
        private readonly Clock $clock,
    ) {
    }

    public static function isAdmin(array $user): bool
    {
        return $user['role'] === 'admin';
    }

    /** permission: force | jump | schedule */
    public static function can(array $user, string $permission): bool
    {
        return self::isAdmin($user) || (int) ($user['can_' . $permission] ?? 0) === 1;
    }

    public function canManage(array $user, array $message): bool
    {
        return self::isAdmin($user) || (int) $message['user_id'] === (int) $user['id'];
    }

    /** Sekunden bis zur nächsten erlaubten Nachricht (0 = jetzt möglich). */
    public function cooldownRemaining(array $user): int
    {
        $minutes = (int) ($user['cooldown_min'] ?? 0);
        if ($minutes <= 0 || self::isAdmin($user)) {
            return 0;
        }
        $last = $this->messages->lastCreatedAtByUser((int) $user['id']);
        if ($last === null) {
            return 0;
        }
        return max(0, $minutes * 60 - Time::secondsBetween(Time::fromDb($last), $this->clock->now()));
    }

    /**
     * @param array{text?: string, align?: string, forced?: mixed, jump?: mixed, schedule?: string, in_minutes?: mixed, at?: string} $input
     */
    public function create(array $user, array $input): array
    {
        $cooldown = $this->cooldownRemaining($user);
        if ($cooldown > 0) {
            return self::fail(t('Deine nächste Nachricht ist in {duration} möglich.', ['duration' => Time::duration($cooldown)]));
        }
        $fields = $this->buildFields($user, $input);
        if (is_string($fields)) {
            return self::fail($fields);
        }
        $id = $this->messages->insert($fields + [
            'source' => $user['source'],
            'user_id' => (int) $user['id'],
            'status' => 'queued',
            'created_at' => Time::db($this->clock->now()),
        ]);
        return self::ok($id);
    }

    public function update(array $user, int $id, array $input): array
    {
        $message = $this->editable($user, $id);
        if (is_string($message)) {
            return self::fail($message);
        }
        $fields = $this->buildFields($user, $input, $message);
        if (is_string($fields)) {
            return self::fail($fields);
        }
        $this->messages->update($id, $fields + ['updated_at' => Time::db($this->clock->now())]);
        return self::ok($id);
    }

    public function delete(array $user, int $id): array
    {
        $message = $this->editable($user, $id);
        if (is_string($message)) {
            return self::fail($message);
        }
        $this->messages->update($id, [
            'status' => 'deleted',
            'deleted_by' => (int) $user['id'],
            'deleted_at' => Time::db($this->clock->now()),
        ]);
        return self::ok($id);
    }

    public function restore(array $user, int $id): array
    {
        $message = $this->messages->find($id);
        if (!self::isAdmin($user) || $message === null || $message['status'] !== 'deleted') {
            return self::fail(t('Nur gelöschte Nachrichten können wiederhergestellt werden.'));
        }
        if ($message['expires_at'] !== null && Time::fromDb($message['expires_at']) <= $this->clock->now()) {
            return self::fail(t('Die Nachricht ist inzwischen abgelaufen.'));
        }
        $this->messages->update($id, ['status' => 'queued', 'deleted_by' => null, 'deleted_at' => null]);
        return self::ok($id);
    }

    public function retry(array $user, int $id): array
    {
        $message = $this->messages->find($id);
        if (!self::isAdmin($user) || $message === null || $message['status'] !== 'failed') {
            return self::fail(t('Nur fehlgeschlagene Nachrichten können erneut versucht werden.'));
        }
        $this->messages->update($id, ['status' => 'queued', 'attempts' => 0, 'last_error' => null, 'last_attempt_at' => null]);
        return self::ok($id);
    }

    /** Admin: sofort einspielen (einmaliger Force, auch geplante Nachrichten). */
    public function playNow(array $user, int $id): array
    {
        $message = $this->messages->find($id);
        if (!self::isAdmin($user) || $message === null || $message['status'] !== 'queued') {
            return self::fail(t('Nur wartende Nachrichten können sofort eingespielt werden.'));
        }
        $this->messages->update($id, ['forced' => 1, 'not_before' => null, 'last_attempt_at' => null]);
        $this->endCurrent(); // "sofort" heißt sofort – auch eine laufende Force-Nachricht weicht
        return self::ok($id);
    }

    public function jump(array $user, int $id): array
    {
        $message = $this->editable($user, $id);
        if (is_string($message)) {
            return self::fail($message);
        }
        if (!self::can($user, 'jump')) {
            return self::fail(t('Vorne anstellen ist für dich nicht freigeschaltet.'));
        }
        $this->messages->update($id, ['jump' => 1]);
        return self::ok($id);
    }

    /** Nachricht aus dem Verlauf erneut in die Warteschlange (neue Nachricht, gleiche Einstellungen). */
    public const MAX_REPLY_LENGTH = 300;

    /** Admin antwortet auf die Nachricht eines Nutzers (leer = Antwort entfernen). */
    public function reply(array $user, int $id, string $text): array
    {
        $message = $this->messages->find($id);
        if (!self::isAdmin($user) || $message === null || $message['user_id'] === null || (int) $message['user_id'] === (int) $user['id']) {
            return self::fail(t('Auf diese Nachricht kann nicht geantwortet werden.'));
        }
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_REPLY_LENGTH || !mb_check_encoding($text, 'UTF-8')) {
            return self::fail(t('Die Antwort ist zu lang (max. {max} Zeichen).', ['max' => self::MAX_REPLY_LENGTH]));
        }
        $now = Time::db($this->clock->now());
        $this->messages->update($id, $text === ''
            ? ['reply_text' => null, 'reply_by' => null, 'reply_at' => null, 'reply_seen_at' => null]
            : ['reply_text' => $text, 'reply_by' => (int) $user['id'], 'reply_at' => $now, 'reply_seen_at' => null]);
        return self::ok($id);
    }

    /** Nutzer hat die Antwort gelesen. */
    public function markReplySeen(array $user, int $id): array
    {
        $message = $this->messages->find($id);
        if ($message === null || (int) $message['user_id'] !== (int) $user['id'] || $message['reply_text'] === null) {
            return self::fail(t('Antwort nicht gefunden.'));
        }
        $this->messages->update($id, ['reply_seen_at' => Time::db($this->clock->now())]);
        return self::ok($id);
    }

    /** Admin: Eintrag endgültig aus dem Verlauf löschen – nicht, solange er wartet oder läuft. */
    public function purge(array $user, int $id): array
    {
        $message = $this->messages->find($id);
        if (!self::isAdmin($user) || $message === null) {
            return self::fail(t('Eintrag nicht gefunden.'));
        }
        if (in_array($message['status'], ['queued', 'showing'], true)) {
            return self::fail(t('Wartende oder laufende Nachrichten bitte in der Warteschlange löschen.'));
        }
        $this->messages->remove($id);
        return self::ok($id);
    }

    public function duplicate(array $user, int $id): array
    {
        $message = $this->messages->find($id);
        if ($message === null || !$this->canManage($user, $message) || in_array($message['source'], [Sources::IDLE, Sources::EXTERNAL], true)) {
            return self::fail(t('Diese Nachricht kann nicht erneut gesendet werden.'));
        }
        // Flags nur übernehmen, wenn der Nutzer sie selbst setzen darf (z. B. Force vom Admin per "Jetzt einspielen")
        return $this->create($user, [
            'text' => $message['text_raw'],
            'align' => $message['align'],
            'forced' => (int) $message['forced'] === 1 && self::can($user, 'force'),
            'jump' => (int) $message['jump'] === 1 && self::can($user, 'jump'),
        ]);
    }

    /** Admin: Board leeren = leeres Raster als Force-Nachricht. */
    public function clearBoard(array $user): array
    {
        if (!self::isAdmin($user)) {
            return self::fail(t('Nur Admins dürfen das Board leeren.'));
        }
        $id = $this->messages->insert([
            'source' => $user['source'],
            'user_id' => (int) $user['id'],
            'text_raw' => '',
            'align' => 'center',
            'grid' => json_encode(Charset::emptyGrid()),
            'forced' => 1,
            'status' => 'queued',
            'created_at' => Time::db($this->clock->now()),
        ]);
        $this->endCurrent();
        return self::ok($id);
    }

    private function endCurrent(): void
    {
        $current = $this->messages->current();
        if ($current !== null && $current['ended_at'] === null) {
            $this->messages->update((int) $current['id'], ['ended_at' => Time::db($this->clock->now())]);
        }
    }

    /** Admin: aktuelle Anzeige beenden, damit die nächste Nachricht sofort darf. */
    public function skipCurrent(array $user): array
    {
        $current = $this->messages->current();
        if (!self::isAdmin($user) || $current === null) {
            return self::fail(t('Es läuft gerade keine Nachricht.'));
        }
        $this->messages->update((int) $current['id'], ['ended_at' => Time::db($this->clock->now())]);
        return self::ok((int) $current['id']);
    }

    /**
     * Prüft die Eingabe und baut die DB-Felder. Gibt bei Fehler den Fehlertext zurück.
     */
    private function buildFields(array $user, array $input, ?array $existing = null): array|string
    {
        $align = Charset::align($input['align'] ?? 'center');
        $text = (string) ($input['text'] ?? '');
        // Im Designer zählen führende Leerfelder und Leerzeilen mit
        $text = $align === 'grid' ? rtrim($text) : trim($text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            return t('Der Text enthält ungültige Zeichen.');
        }
        if (trim($text) === '') {
            return t('Bitte einen Text eingeben.');
        }
        $preview = Charset::textToGrid($text, $align);
        if (!$preview['fits']) {
            return t('Der Text passt nicht auf das Board (max. 3 Zeilen à 15 Zeichen).');
        }

        // Ohne Recht: beim Bearbeiten den bisherigen Wert behalten (z. B. vom Admin gesetzt), sonst ablehnen
        if (self::can($user, 'force')) {
            $forced = !empty($input['forced']);
        } elseif ($existing !== null) {
            $forced = (int) $existing['forced'] === 1;
        } elseif (!empty($input['forced'])) {
            return t('Force ist für dich nicht freigeschaltet.');
        } else {
            $forced = false;
        }
        if (self::can($user, 'jump')) {
            $jump = !empty($input['jump']);
        } elseif ($existing !== null) {
            $jump = (int) $existing['jump'] === 1;
        } elseif (!empty($input['jump'])) {
            return t('Vorne anstellen ist für dich nicht freigeschaltet.');
        } else {
            $jump = false;
        }

        $notBefore = $this->parseSchedule($input);
        if (is_string($notBefore)) {
            return $notBefore;
        }
        if ($notBefore !== null && !self::can($user, 'schedule')) {
            return t('Planen ist für dich nicht freigeschaltet.');
        }

        return [
            'text_raw' => $text,
            'align' => $align,
            'grid' => json_encode($preview['grid']),
            'forced' => $forced ? 1 : 0,
            'jump' => $jump ? 1 : 0,
            'not_before' => $notBefore === null ? null : Time::db($notBefore),
        ];
    }

    private function parseSchedule(array $input): DateTimeImmutable|string|null
    {
        $now = $this->clock->now();
        switch ($input['schedule'] ?? 'now') {
            case 'in':
                $minutes = (int) ($input['in_minutes'] ?? 0);
                if ($minutes < 1 || $minutes > self::MAX_SCHEDULE_MINUTES) {
                    return t('Bitte eine Minutenzahl zwischen 1 und {max} angeben.', ['max' => self::MAX_SCHEDULE_MINUTES]);
                }
                return $now->modify("+$minutes minutes");
            case 'at':
                $at = Time::parseLocal((string) ($input['at'] ?? ''));
                if ($at === null) {
                    return t('Bitte Datum und Uhrzeit angeben.');
                }
                if ($at < $now->modify('-1 minute')) {
                    return t('Der Zeitpunkt liegt in der Vergangenheit.');
                }
                if ($at > $now->modify('+' . self::MAX_SCHEDULE_MINUTES . ' minutes')) {
                    return t('Maximal 30 Tage im Voraus planbar.');
                }
                return $at;
            default:
                return null;
        }
    }

    private function editable(array $user, int $id): array|string
    {
        $message = $this->messages->find($id);
        if ($message === null || !$this->canManage($user, $message)) {
            return t('Nachricht nicht gefunden.');
        }
        if ($message['status'] !== 'queued') {
            return t('Nur wartende Nachrichten können geändert werden.');
        }
        return $message;
    }

    private static function ok(int $id): array
    {
        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'id' => null];
    }
}
