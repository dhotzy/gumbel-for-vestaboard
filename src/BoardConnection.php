<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Wie Gumbel mit dem Board spricht: Cloud API (Standard) oder Local API im Heimnetz.
 * Einstellung 'board_connection'; ein Token in config/Umgebung hat Vorrang vor dem in der Oberfläche.
 */
final class BoardConnection
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array{mode: string, cloud_token: string, local_host: string, local_key: string} */
    public function get(): array
    {
        return (array) $this->settings->get('board_connection');
    }

    public function mode(): string
    {
        return $this->get()['mode'] === 'local' ? 'local' : 'cloud';
    }

    /** Token aus config.php bzw. Umgebung (Docker) – dann ist das Feld in der Oberfläche gesperrt */
    public static function configToken(): string
    {
        try {
            return trim((string) (app_config()['vestaboard_token'] ?? ''));
        } catch (\RuntimeException) {
            return ''; // ohne Konfigurationsdatei (z. B. Tests): Token kommt aus der Oberfläche
        }
    }

    public function cloudToken(): string
    {
        return self::configToken() !== '' ? self::configToken() : trim((string) $this->get()['cloud_token']);
    }

    /** Ist eine Verbindung eingerichtet? (für Einrichtung und Hinweise) */
    public function isConfigured(): bool
    {
        $connection = $this->get();
        return $this->mode() === 'local'
            ? $connection['local_host'] !== '' && $connection['local_key'] !== ''
            : $this->cloudToken() !== '';
    }

    public function client(): BoardClient
    {
        $connection = $this->get();
        return $this->mode() === 'local'
            ? new LocalVestaClient((string) $connection['local_host'], (string) $connection['local_key'])
            : new VestaClient($this->cloudToken());
    }

    public function save(array $values): void
    {
        $this->settings->set('board_connection', array_replace($this->get(), array_intersect_key($values, array_flip(['mode', 'cloud_token', 'local_host', 'local_key']))));
    }
}
