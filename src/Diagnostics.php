<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Phase-0-Prüfungen, gemeinsam genutzt von scripts/test-board.php und public/diag.php.
 */
final class Diagnostics
{
    /** Wartezeit zwischen Senden und Zurücklesen, damit die Cloud den neuen Stand kennt */
    public const VERIFY_DELAY_SECONDS = 3;

    /**
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public static function environment(): array
    {
        $checks = [
            ['label' => t('PHP-Version ≥ 8.2'), 'ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION],
            ['label' => t('cURL-Erweiterung'), 'ok' => extension_loaded('curl'), 'detail' => extension_loaded('curl') ? t('vorhanden') : t('fehlt')],
            ['label' => t('mbstring-Erweiterung'), 'ok' => extension_loaded('mbstring'), 'detail' => extension_loaded('mbstring') ? t('vorhanden') : t('fehlt')],
            ['label' => t('intl-Erweiterung (optional, für seltene Akzente)'), 'ok' => true, 'detail' => class_exists(\Normalizer::class) ? t('vorhanden') : t('nicht vorhanden – gängige Akzente (é, à, ç …) gehen trotzdem')],
        ];

        try {
            $config = app_config();
            $checks[] = ['label' => 'config/config.php', 'ok' => true, 'detail' => t('gefunden')];
            try {
                $hasToken = App::boardConnection()->isConfigured();
            } catch (\Throwable) {
                $hasToken = trim((string) ($config['vestaboard_token'] ?? '')) !== '';
            }
            $checks[] = ['label' => t('Vestaboard-Token eingetragen'), 'ok' => $hasToken, 'detail' => $hasToken ? t('ja') : t('nein – unter Einstellungen → Board-Verbindung eintragen')];
        } catch (\RuntimeException $e) {
            $checks[] = ['label' => 'config/config.php', 'ok' => false, 'detail' => $e->getMessage()];
        }

        $checks[] = ['label' => t('Zeitzone'), 'ok' => true, 'detail' => Time::zone()->getName() . ', ' . Time::local(new \DateTimeImmutable())->format('d.m.Y H:i:s')];
        return $checks;
    }

    /**
     * Sendet, wartet kurz und liest zurück.
     * @return array{send: array, read: ?array, matches: bool}
     */
    public static function sendAndVerify(BoardClient $client, array $grid, bool $forced): array
    {
        $send = $client->send($grid, $forced);
        if (!$send['ok']) {
            return ['send' => $send, 'read' => null, 'matches' => false];
        }
        sleep(self::VERIFY_DELAY_SECONDS);
        $read = $client->read();
        return ['send' => $send, 'read' => $read, 'matches' => $read['ok'] && $read['grid'] === $grid];
    }
}
