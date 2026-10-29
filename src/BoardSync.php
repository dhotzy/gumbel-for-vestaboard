<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Liest das echte Board zurück (Cron, minütlich) und erkennt Änderungen von außen,
 * z. B. über die Vestaboard-App. Solche Änderungen landen als Quelle "external" im Archiv.
 */
final class BoardSync
{
    /** Direkt nach dem Senden nicht abgleichen – die Cloud braucht ein paar Sekunden */
    public const SETTLE_SECONDS = 30;

    public function __construct(
        private readonly MessageRepo $messages,
        private readonly Settings $settings,
        private readonly BoardClient $board,
        private readonly Clock $clock,
        private readonly ?ApiLog $apiLog = null,
    ) {
    }

    /**
     * @return array{ok: bool, changed: bool, detail: string}
     */
    public function sync(): array
    {
        $now = $this->clock->now();
        $lastSent = $this->settings->get('last_sent_at');
        if (!empty($lastSent) && Time::secondsBetween(Time::fromDb((string) $lastSent), $now) < self::SETTLE_SECONDS) {
            return ['ok' => true, 'changed' => false, 'detail' => t('Gerade erst gesendet – Abgleich beim nächsten Lauf.')];
        }

        $read = $this->board->read();
        $this->apiLog?->record(null, 'read', $read, $now);
        // nur das Format prüfen – das Board kann Codes zeigen, die unser Editor nicht anbietet
        $isNoteFormat = is_array($read['grid'] ?? null) && count($read['grid']) === Charset::ROWS
            && count(array_filter($read['grid'], static fn ($row): bool => is_array($row) && count($row) === Charset::COLS)) === Charset::ROWS;
        if (!$read['ok'] || !$isNoteFormat) {
            return ['ok' => false, 'changed' => false, 'detail' => (string) ($read['error'] ?? t('Board-Anzeige nicht lesbar.'))];
        }

        $grid = $read['grid'];
        $this->settings->set('board_live', ['grid' => $grid, 'read_at' => Time::db($now)]);

        $current = $this->messages->current();
        $known = $current === null ? null : json_decode((string) $current['grid'], true);
        if ($known === $grid) {
            return ['ok' => true, 'changed' => false, 'detail' => t('Board zeigt den erwarteten Stand.')];
        }

        $this->messages->endShowing($now);
        $this->messages->insert([
            'source' => Sources::EXTERNAL,
            'user_id' => null,
            'text_raw' => Charset::gridToRawText($grid),
            'align' => 'left',
            'grid' => json_encode($grid),
            'status' => 'showing',
            'created_at' => Time::db($now),
            'shown_at' => Time::db($now),
        ]);
        return ['ok' => true, 'changed' => true, 'detail' => t('Änderung von außen erkannt und archiviert.')];
    }
}
