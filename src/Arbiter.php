<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;

/**
 * Die einzige Stelle, die ans Board sendet (Konzept Kapitel 3).
 *
 * Reihenfolge der Entscheidung:
 *  1. abgelaufene Nachrichten verwerfen
 *  2. Kandidaten: fällig, und bei Mute nur Force
 *  3. Force: sofort – außer eine laufende Force-Nachricht mit gleichem/höherem Rang ist noch in ihrer Mindestdauer
 *  4. sonst: warten, bis die Mindestanzeigedauer der aktuellen Nachricht abgelaufen ist
 *  5. Auswahl: Quellen-Rang → vorne angestellt → älteste
 *  6. nichts zu tun → Ruhezustand
 */
final class Arbiter
{
    public const RATE_LIMIT_SECONDS = 15;
    public const MAX_ATTEMPTS = 3;
    public const RETRY_AFTER_SECONDS = 60;

    /** Fehler, für die die Nachricht nichts kann → kein Versuch verbraucht */
    private const NON_ATTEMPT_STATUSES = [401, 403, 423, 429];

    public function __construct(
        private readonly MessageRepo $messages,
        private readonly Settings $settings,
        private readonly BoardClient $board,
        private readonly Clock $clock,
        private readonly ?Lock $lock = null,
        private readonly ?ApiLog $apiLog = null,
    ) {
    }

    /**
     * @return array{action: string, message_id: ?int, detail: string, retry_in: ?int}
     *   action: sent | waiting | rate_limited | muted | idle | error | locked
     */
    public function run(): array
    {
        if ($this->lock !== null && !$this->lock->acquire()) {
            return self::result('locked', null, 'Ein anderer Durchlauf ist aktiv.');
        }
        try {
            return $this->decide($this->clock->now());
        } finally {
            $this->lock?->release();
        }
    }

    private function decide(DateTimeImmutable $now): array
    {
        $this->settings->refresh(); // z. B. last_sent_at kann inzwischen vom Cron geschrieben sein
        $this->messages->expireDue($now);
        $current = $this->messages->current();

        $eligible = [];
        $mutedCount = 0;
        $retryCount = 0;
        $strict = $this->settings->isStrictMute($now);
        foreach ($this->messages->queued() as $message) {
            if ($message['not_before'] !== null && Time::fromDb($message['not_before']) > $now) {
                continue;
            }
            if ($message['last_attempt_at'] !== null
                && Time::secondsBetween(Time::fromDb($message['last_attempt_at']), $now) < self::RETRY_AFTER_SECONDS) {
                $retryCount++;
                continue; // nach einem Fehler frühestens nach 60 s erneut – egal wie oft die Seite geladen wird
            }
            $message['effective_forced'] = $this->isForced($message);
            if ((!$message['effective_forced'] || $strict) && $this->settings->isMuted($message['source'], $now)) {
                $mutedCount++;
                continue;
            }
            $eligible[] = $message;
        }

        if ($eligible === []) {
            if ($mutedCount > 0 && $this->settings->globalMuteReason($now) !== null) {
                return self::result('muted', null, "$mutedCount Nachricht(en) warten wegen Stummschaltung.");
            }
            if ($retryCount > 0) {
                return self::result('waiting', null, "$retryCount Nachricht(en) warten auf einen erneuten Versuch.");
            }
            return $this->applyIdle($now, $current);
        }

        usort($eligible, fn (array $a, array $b): int => $this->sortKey($a) <=> $this->sortKey($b));
        $forcedCandidates = array_values(array_filter($eligible, static fn (array $m): bool => $m['effective_forced']));
        $remaining = $current === null ? 0 : $this->remainingSeconds($current, $now);

        if ($forcedCandidates !== []) {
            $best = $forcedCandidates[0];
            $currentIsProtectedForce = $remaining > 0
                && (int) $current['sent_forced'] === 1
                && $this->settings->rank($best['source']) >= $this->settings->rank($current['source']);
            if ($currentIsProtectedForce) {
                return self::result('waiting', (int) $best['id'], 'Laufende Force-Nachricht hat Vorrang.', $remaining);
            }
        } else {
            $best = $eligible[0];
            if ($remaining > 0) {
                // Admin/Wecker (einstellbar) dürfen die Mindestdauer anderer Quellen beenden – nicht aber eine laufende Force-Nachricht
                $interrupter = null;
                if ((int) $current['sent_forced'] !== 1) {
                    foreach ($eligible as $candidate) {
                        if ($this->settings->canInterrupt($candidate['source'], $current['source'])) {
                            $interrupter = $candidate;
                            break;
                        }
                    }
                }
                if ($interrupter === null) {
                    return self::result('waiting', (int) $eligible[0]['id'], 'Mindestanzeigedauer läuft noch.', $remaining);
                }
                $best = $interrupter;
            }
        }

        $wait = $this->rateLimitWait($now);
        if ($wait > 0) {
            return self::result('rate_limited', (int) $best['id'], 'Vestaboard-Limit: 15 Sekunden zwischen Nachrichten.', $wait);
        }
        return $this->send($best, $now);
    }

    /**
     * Voraussichtliche Reihenfolge für die Anzeige (ohne zu senden).
     * @return array{due: list<array>, scheduled: list<array>}
     *   due: mit position, eta_seconds (null = wartet wegen Stummschaltung), effective_forced, muted
     */
    public function plan(): array
    {
        $now = $this->clock->now();
        $current = $this->messages->current();
        $due = [];
        $scheduled = [];
        $strict = $this->settings->isStrictMute($now);
        foreach ($this->messages->queued() as $message) {
            $message['effective_forced'] = $this->isForced($message);
            $message['muted'] = (!$message['effective_forced'] || $strict) && $this->settings->isMuted($message['source'], $now);
            if ($message['not_before'] !== null && Time::fromDb($message['not_before']) > $now) {
                $scheduled[] = $message;
            } else {
                $due[] = $message;
            }
        }
        usort($due, fn (array $a, array $b): int => [$a['muted'], !$a['effective_forced'], $this->sortKey($a)]
            <=> [$b['muted'], !$b['effective_forced'], $this->sortKey($b)]);
        usort($scheduled, static fn (array $a, array $b): int => $a['not_before'] <=> $b['not_before']);

        $offset = $current === null ? 0 : $this->remainingSeconds($current, $now);
        $first = array_values(array_filter($due, static fn (array $m): bool => !$m['muted']))[0] ?? null;
        if ($first !== null && $current !== null && (int) $current['sent_forced'] !== 1 && $this->settings->canInterrupt($first['source'], $current['source'])) {
            $offset = 0; // Platz 1 darf die laufende Nachricht ablösen
        }
        foreach ($due as $index => &$message) {
            $message['position'] = $index + 1;
            $message['eta_seconds'] = $message['muted'] ? null : $offset;
            if (!$message['muted']) {
                $offset += $this->durationOf($message);
            }
        }
        unset($message);
        return ['due' => $due, 'scheduled' => $scheduled];
    }

    /** Sekunden, die die aktuelle Nachricht noch mindestens stehen bleibt (für die Anzeige). */
    public function currentRemaining(): int
    {
        $current = $this->messages->current();
        return $current === null ? 0 : $this->remainingSeconds($current, $this->clock->now());
    }

    private function send(array $message, DateTimeImmutable $now): array
    {
        $grid = json_decode((string) $message['grid'], true);
        $forced = (bool) $message['effective_forced'];
        $response = $this->board->send(is_array($grid) ? $grid : [], $forced);
        $this->apiLog?->record((int) $message['id'], 'send', $response, $now);

        if ($response['ok']) {
            $this->messages->endShowing($now);
            $this->messages->update((int) $message['id'], [
                'status' => 'showing',
                'shown_at' => Time::db($now),
                'sent_forced' => $forced ? 1 : 0,
                'last_error' => null,
                'last_attempt_at' => null,
            ]);
            $this->settings->set('last_sent_at', Time::db($now));
            $this->settings->recordBoardOk($now);
            return self::result('sent', (int) $message['id'], 'Gesendet.');
        }

        $status = (int) $response['status'];
        $error = (string) $response['error'];
        $attempts = (int) $message['attempts'] + (in_array($status, self::NON_ATTEMPT_STATUSES, true) ? 0 : 1);
        $fields = ['attempts' => $attempts, 'last_error' => mb_substr($error, 0, 255), 'last_attempt_at' => Time::db($now)];
        if ($attempts >= self::MAX_ATTEMPTS) {
            $fields['status'] = 'failed';
        }
        $this->messages->update((int) $message['id'], $fields);
        if ($status === 429) {
            $this->settings->set('last_sent_at', Time::db($now));
        }
        $this->settings->recordBoardError($now, $error, $status === 423);
        return self::result('error', (int) $message['id'], $error);
    }

    private function applyIdle(DateTimeImmutable $now, ?array $current): array
    {
        $idle = (array) $this->settings->get('idle');
        $mode = $idle['mode'] ?? 'last';
        if ($mode === 'last' || $current === null || $current['source'] === Sources::IDLE) {
            return self::result('idle', null, 'Nichts zu tun.');
        }
        if ($this->settings->globalMuteReason($now) !== null) {
            return self::result('idle', null, 'Ruhezustand pausiert wegen Stummschaltung.');
        }
        $afterSeconds = max(1, (int) ($idle['after_min'] ?? 30)) * 60;
        $elapsed = Time::secondsBetween(Time::fromDb((string) $current['shown_at']), $now);
        if ($elapsed < $afterSeconds) {
            return self::result('idle', null, 'Ruhezustand noch nicht fällig.', $afterSeconds - $elapsed);
        }
        if ($this->rateLimitWait($now) > 0) {
            return self::result('rate_limited', null, 'Vestaboard-Limit.', $this->rateLimitWait($now));
        }

        $text = $mode === 'text' ? (string) ($idle['text'] ?? '') : '';
        $align = (string) ($idle['align'] ?? 'center');
        $grid = $text === '' ? Charset::emptyGrid() : Charset::textToGrid($text, $align)['grid'];

        // erst senden, dann archivieren – ein Fehlschlag hinterlässt keine wartende Ruhezustand-Nachricht
        $response = $this->board->send($grid, false);
        $this->apiLog?->record(null, 'send', $response, $now);
        if (!$response['ok']) {
            $this->settings->recordBoardError($now, (string) $response['error'], (int) $response['status'] === 423);
            return self::result('error', null, 'Ruhezustand: ' . $response['error']);
        }
        $this->messages->endShowing($now);
        $id = $this->messages->insert([
            'source' => Sources::IDLE,
            'user_id' => null,
            'text_raw' => $text,
            'align' => $align,
            'grid' => json_encode($grid),
            'status' => 'showing',
            'created_at' => Time::db($now),
            'shown_at' => Time::db($now),
        ]);
        $this->settings->set('last_sent_at', Time::db($now));
        $this->settings->recordBoardOk($now);
        return self::result('sent', $id, 'Ruhezustand gesendet.');
    }

    private function isForced(array $message): bool
    {
        return (int) $message['forced'] === 1 || $this->settings->isForcedSource($message['source']);
    }

    /** Sekunden, die die aktuelle Nachricht noch stehen bleiben muss. */
    private function remainingSeconds(array $current, DateTimeImmutable $now): int
    {
        if ($current['ended_at'] !== null || $current['shown_at'] === null) {
            return 0; // übersprungen
        }
        if (json_decode((string) $current['grid'], true) === Charset::emptyGrid()) {
            return 0; // ein leeres Board muss nicht stehen bleiben (z. B. nach "Board leeren")
        }
        $elapsed = Time::secondsBetween(Time::fromDb((string) $current['shown_at']), $now);
        return max(0, $this->durationOf($current) - $elapsed);
    }

    /** Mindestanzeigedauer: eigene der Nachricht (z. B. aus einer Webhook-Vorlage), sonst die der Quelle */
    private function durationOf(array $message): int
    {
        $own = (int) ($message['min_duration'] ?? 0);
        return $own > 0 ? $own : $this->settings->minDurationSeconds((string) $message['source']);
    }

    private function rateLimitWait(DateTimeImmutable $now): int
    {
        $last = $this->settings->get('last_sent_at');
        if (empty($last)) {
            return 0;
        }
        return max(0, self::RATE_LIMIT_SECONDS - Time::secondsBetween(Time::fromDb((string) $last), $now));
    }

    private function sortKey(array $message): array
    {
        return [
            $this->settings->rank($message['source']),
            (int) $message['jump'] === 1 ? 0 : 1,
            (string) $message['created_at'],
            (int) $message['id'],
        ];
    }

    private static function result(string $action, ?int $messageId, string $detail, ?int $retryIn = null): array
    {
        return ['action' => $action, 'message_id' => $messageId, 'detail' => $detail, 'retry_in' => $retryIn];
    }
}
