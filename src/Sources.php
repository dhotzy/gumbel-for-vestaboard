<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Herkunft einer Nachricht. Rang und Mindestanzeigedauer sind in den Einstellungen änderbar,
 * hier stehen nur Bezeichnung, Default-Dauer und ab welcher Phase die Quelle Inhalte liefert.
 * Eigene Quellen (z. B. „Buchungen“ für eine bestimmte Integration) legt der Admin in den Einstellungen an;
 * sie werden beim Laden der Einstellungen registriert (useCustom).
 */
final class Sources
{
    public const IDLE = 'idle';
    /** Änderung am Board, die nicht über diese App kam (z. B. Vestaboard-App) */
    public const EXTERNAL = 'external';

    /** Reihenfolge = Default-Rangfolge */
    public const ALL = [
        'alarm' => ['label' => 'Wecker', 'duration' => 5, 'phase' => 2],
        'admin' => ['label' => 'Admin', 'duration' => 10, 'phase' => 1],
        'adults' => ['label' => 'Erwachsene', 'duration' => 10, 'phase' => 1],
        'kids' => ['label' => 'Kinder', 'duration' => 5, 'phase' => 1],
        'nfc' => ['label' => 'NFC', 'duration' => 5, 'phase' => 3],
        'weather' => ['label' => 'Wetter', 'duration' => 5, 'phase' => 2],
        'clock' => ['label' => 'Uhr', 'duration' => 2, 'phase' => 2],
        'webhook' => ['label' => 'Webhook', 'duration' => 5, 'phase' => 3],
        'social' => ['label' => 'Social Media', 'duration' => 3, 'phase' => 3],
        'feed' => ['label' => 'Abruf (Feed)', 'duration' => 5, 'phase' => 3],
        'pool' => ['label' => 'Zufallspool', 'duration' => 5, 'phase' => 2],
        'news' => ['label' => 'Nachrichten', 'duration' => 3, 'phase' => 2],
        'guest' => ['label' => 'Gäste', 'duration' => 5, 'phase' => 1],
        'messenger' => ['label' => 'Messenger', 'duration' => 5, 'phase' => 3],
    ];

    /** Bis zu dieser Phase liefern Quellen bereits Inhalte */
    public const ACTIVE_PHASE = 3;

    /** Quellen, die einem Nutzer zugeordnet werden können */
    public const USER_SOURCES = ['admin', 'adults', 'kids'];

    public const MAX_CUSTOM = 20;

    /** @var array<string, array{label: string, duration: int, phase: int, custom: true}> */
    private static array $custom = [];

    /** Eigene Quellen aus den Einstellungen registrieren: [['id' => 'c_xyz', 'label' => '…', 'duration' => 5], …] */
    public static function useCustom(mixed $list): void
    {
        self::$custom = [];
        foreach ((array) $list as $source) {
            $id = (string) ($source['id'] ?? '');
            $label = trim((string) ($source['label'] ?? ''));
            if (preg_match('/^[a-z0-9_]{2,30}$/', $id) && $label !== '' && !isset(self::ALL[$id]) && $id !== self::IDLE && $id !== self::EXTERNAL) {
                self::$custom[$id] = ['label' => mb_substr($label, 0, 40), 'duration' => max(0, min(240, (int) ($source['duration'] ?? 5))), 'phase' => 3, 'custom' => true];
            }
        }
    }

    /** Eingebaute + eigene Quellen */
    public static function all(): array
    {
        return self::ALL + self::$custom;
    }

    /** @return array<string, array> nur die eigenen Quellen */
    public static function custom(): array
    {
        return self::$custom;
    }

    /** Neue ID für eine eigene Quelle aus der Bezeichnung (c_ + Kleinbuchstaben) */
    public static function customId(string $label, array $taken): string
    {
        $base = 'c_' . substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label))), '_'), 0, 24);
        $base = $base === 'c_' ? 'c_quelle' : $base;
        $id = $base;
        for ($n = 2; isset(self::ALL[$id]) || in_array($id, $taken, true); $n++) {
            $id = $base . '_' . $n;
        }
        return $id;
    }

    public static function label(string $source): string
    {
        return match ($source) {
            self::IDLE => t('Ruhezustand'),
            self::EXTERNAL => t('Extern (Vestaboard-App)'),
            'alarm' => t('Wecker'),
            'admin' => t('Admin'),
            'adults' => t('Erwachsene'),
            'kids' => t('Kinder'),
            'nfc' => t('NFC'),
            'weather' => t('Wetter'),
            'clock' => t('Uhr'),
            'webhook' => t('Webhook'),
            'social' => t('Social Media'),
            'feed' => t('Abruf (Feed)'),
            'pool' => t('Zufallspool'),
            'news' => t('Nachrichten'),
            'guest' => t('Gäste'),
            'messenger' => t('Messenger'),
            default => self::all()[$source]['label'] ?? $source,
        };
    }

    public static function exists(string $source): bool
    {
        return isset(self::all()[$source]);
    }
}
