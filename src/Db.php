<?php
declare(strict_types=1);

namespace Vesta;

use PDO;

/**
 * PDO-Verbindung: MariaDB in Produktion, SQLite lokal und in Tests.
 * SQL bleibt bewusst portabel (keine DB-Zeitfunktionen, kein UPSERT).
 */
final class Db
{
    public static function connect(array $config): PDO
    {
        $driver = $config['driver'] ?? 'mysql';
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . ($config['path'] ?? ':memory:'));
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'] ?? 'localhost', $config['name'] ?? '');
            $pdo = new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['pass'] ?? ''), [
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    public static function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function isInstalled(PDO $pdo): bool
    {
        $sql = self::driver($pdo) === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'"
            : "SHOW TABLES LIKE 'users'";
        return $pdo->query($sql)->fetchColumn() !== false;
    }

    /** Schema-Änderungen nach der Erstinstallation, je Version und Treiber. Nur anhängen, nie ändern. */
    private const MIGRATIONS = [
        1 => [
            'sqlite' => [
                'ALTER TABLE messages ADD COLUMN reply_text TEXT NULL',
                'ALTER TABLE messages ADD COLUMN reply_by INTEGER NULL',
                'ALTER TABLE messages ADD COLUMN reply_at TEXT NULL',
                'ALTER TABLE messages ADD COLUMN reply_seen_at TEXT NULL',
            ],
            'mysql' => [
                'ALTER TABLE messages ADD COLUMN reply_text TEXT NULL',
                'ALTER TABLE messages ADD COLUMN reply_by INT UNSIGNED NULL',
                'ALTER TABLE messages ADD COLUMN reply_at DATETIME NULL',
                'ALTER TABLE messages ADD COLUMN reply_seen_at DATETIME NULL',
            ],
        ],
        2 => [
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS webhook_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    key_hash TEXT NOT NULL UNIQUE,
                    source TEXT NOT NULL,
                    fixed_text TEXT NULL,
                    can_force INTEGER NOT NULL DEFAULT 0,
                    active INTEGER NOT NULL DEFAULT 1,
                    last_used_at TEXT NULL,
                    use_count INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL
                )',
            ],
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS webhook_keys (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(80) NOT NULL,
                    key_hash CHAR(64) NOT NULL,
                    source VARCHAR(20) NOT NULL,
                    fixed_text TEXT NULL,
                    can_force TINYINT(1) NOT NULL DEFAULT 0,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    last_used_at DATETIME NULL,
                    use_count INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY uq_webhook_key (key_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ],
        ],
        3 => [
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                    name TEXT NOT NULL,
                    text_raw TEXT NOT NULL,
                    align TEXT NOT NULL DEFAULT \'center\',
                    created_at TEXT NOT NULL
                )',
            ],
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS templates (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    name VARCHAR(60) NOT NULL,
                    text_raw TEXT NOT NULL,
                    align VARCHAR(10) NOT NULL DEFAULT \'center\',
                    created_at DATETIME NOT NULL,
                    KEY idx_templates_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ],
        ],
        // Webhooks: Testmodus (nur mitschneiden), letzter Payload und letzter Fehler zum Einrichten
        4 => [
            'sqlite' => [
                'ALTER TABLE webhook_keys ADD COLUMN test_mode INTEGER NOT NULL DEFAULT 0',
                'ALTER TABLE webhook_keys ADD COLUMN last_payload TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN last_payload_at TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN last_error TEXT NULL',
            ],
            'mysql' => [
                'ALTER TABLE webhook_keys ADD COLUMN test_mode TINYINT(1) NOT NULL DEFAULT 0',
                'ALTER TABLE webhook_keys ADD COLUMN last_payload TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN last_payload_at DATETIME NULL',
                'ALTER TABLE webhook_keys ADD COLUMN last_error VARCHAR(255) NULL',
            ],
        ],
        // Integrationen: mehrere Vorlagen mit Bedingung (JSON) und einstellbarer Mindestabstand
        5 => [
            'sqlite' => [
                'ALTER TABLE webhook_keys ADD COLUMN rules TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN min_interval INTEGER NOT NULL DEFAULT 60',
            ],
            'mysql' => [
                'ALTER TABLE webhook_keys ADD COLUMN rules TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN min_interval INT UNSIGNED NOT NULL DEFAULT 60',
            ],
        ],
        // Integrationen: Zustell-Verlauf, Optionen der Standard-Vorlage, Zeitfenster, Duplikate, HMAC, Abholen;
        // Nachrichten mit eigener Anzeigedauer
        6 => [
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS webhook_deliveries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key_id INTEGER NOT NULL,
                    received_at TEXT NOT NULL,
                    status TEXT NOT NULL,
                    rule INTEGER NULL,
                    text TEXT NULL,
                    note TEXT NULL,
                    payload TEXT NULL,
                    dedupe_key TEXT NULL,
                    message_id INTEGER NULL
                )',
                'CREATE INDEX IF NOT EXISTS idx_deliveries_key ON webhook_deliveries (key_id, id)',
                'ALTER TABLE webhook_keys ADD COLUMN options TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN windows TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN dedupe_key TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN dedupe_minutes INTEGER NOT NULL DEFAULT 0',
                'ALTER TABLE webhook_keys ADD COLUMN signing_secret TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN require_signature INTEGER NOT NULL DEFAULT 0',
                'ALTER TABLE webhook_keys ADD COLUMN pull_url TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN pull_interval INTEGER NOT NULL DEFAULT 15',
                'ALTER TABLE webhook_keys ADD COLUMN pull_last_at TEXT NULL',
                'ALTER TABLE messages ADD COLUMN min_duration INTEGER NULL',
            ],
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS webhook_deliveries (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    key_id INT UNSIGNED NOT NULL,
                    received_at DATETIME NOT NULL,
                    status VARCHAR(20) NOT NULL,
                    rule SMALLINT NULL,
                    text TEXT NULL,
                    note VARCHAR(255) NULL,
                    payload TEXT NULL,
                    dedupe_key VARCHAR(255) NULL,
                    message_id INT UNSIGNED NULL,
                    KEY idx_deliveries_key (key_id, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'ALTER TABLE webhook_keys ADD COLUMN options TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN windows TEXT NULL',
                'ALTER TABLE webhook_keys ADD COLUMN dedupe_key VARCHAR(255) NULL',
                'ALTER TABLE webhook_keys ADD COLUMN dedupe_minutes INT UNSIGNED NOT NULL DEFAULT 0',
                'ALTER TABLE webhook_keys ADD COLUMN signing_secret VARCHAR(100) NULL',
                'ALTER TABLE webhook_keys ADD COLUMN require_signature TINYINT(1) NOT NULL DEFAULT 0',
                'ALTER TABLE webhook_keys ADD COLUMN pull_url VARCHAR(500) NULL',
                'ALTER TABLE webhook_keys ADD COLUMN pull_interval INT UNSIGNED NOT NULL DEFAULT 15',
                'ALTER TABLE webhook_keys ADD COLUMN pull_last_at DATETIME NULL',
                'ALTER TABLE messages ADD COLUMN min_duration INT UNSIGNED NULL',
            ],
        ],
        // Altdaten-Umstellung der ersten Installation (bereits gelaufen, für neue Installationen ohne Wirkung)
        7 => ['sqlite' => [], 'mysql' => []],
        // Anmelde-Bremse je IP und je Konto+IP (statt Kontosperre, die Fremde auslösen konnten)
        8 => [
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS login_throttle (
                    throttle_key TEXT PRIMARY KEY,
                    failures INTEGER NOT NULL DEFAULT 0,
                    locks INTEGER NOT NULL DEFAULT 0,
                    locked_until TEXT NULL,
                    updated_at TEXT NOT NULL
                )',
            ],
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS login_throttle (
                    throttle_key VARCHAR(190) NOT NULL PRIMARY KEY,
                    failures INT UNSIGNED NOT NULL DEFAULT 0,
                    locks INT UNSIGNED NOT NULL DEFAULT 0,
                    locked_until DATETIME NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ],
        ],
        // Eigene Header beim Abrufen (z. B. Authorization, X-Api-Key)
        9 => [
            'sqlite' => ['ALTER TABLE webhook_keys ADD COLUMN pull_headers TEXT NULL'],
            'mysql' => ['ALTER TABLE webhook_keys ADD COLUMN pull_headers TEXT NULL'],
        ],
        // Briefkasten: Name des Gasts und Geräte-Kennung (HMAC der IP, für die Pause pro Gerät)
        10 => [
            'sqlite' => [
                'ALTER TABLE messages ADD COLUMN guest_name TEXT NULL',
                'ALTER TABLE messages ADD COLUMN guest_key TEXT NULL',
            ],
            'mysql' => [
                'ALTER TABLE messages ADD COLUMN guest_name VARCHAR(40) NULL',
                'ALTER TABLE messages ADD COLUMN guest_key CHAR(64) NULL',
            ],
        ],
    ];

    /** Fehlende Migrationen ausführen (billig, wenn aktuell: eine Abfrage). */
    public static function migrate(PDO $pdo): void
    {
        $statement = $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'");
        $current = (int) json_decode((string) ($statement->fetchColumn() ?: '0'), true);
        $latest = max(array_keys(self::MIGRATIONS));
        if ($current >= $latest) {
            return;
        }
        $driver = self::driver($pdo) === 'sqlite' ? 'sqlite' : 'mysql';
        foreach (self::MIGRATIONS as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            foreach ($statements[$driver] as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (\PDOException $e) {
                    // Spalte existiert schon (z. B. parallel gelaufen) → weiter, alles andere melden
                    if (!preg_match('/duplicate column/i', $e->getMessage())) {
                        throw $e;
                    }
                }
            }
            self::dataMigration($pdo, $version);
            self::setVersion($pdo, $version);
        }
    }

    /** Änderungen an JSON-Einstellungen, die sich nicht portabel in SQL ausdrücken lassen (derzeit keine) */
    private static function dataMigration(PDO $pdo, int $version): void
    {
    }

    private static function setVersion(PDO $pdo, int $version): void
    {
        $update = $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'schema_version'");
        $update->execute([(string) $version]);
        if ($update->rowCount() === 0) {
            $exists = $pdo->query("SELECT 1 FROM settings WHERE name = 'schema_version'")->fetchColumn();
            if ($exists === false) {
                try {
                    $pdo->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?)")->execute([(string) $version]);
                } catch (\PDOException $e) {
                    // paralleler Request war schneller → Wert ist gesetzt
                }
            }
        }
    }

    /** Legt alle Tabellen an, falls sie fehlen. */
    public static function install(PDO $pdo): void
    {
        if (self::isInstalled($pdo)) {
            return;
        }
        $file = APP_ROOT . '/db/schema.' . (self::driver($pdo) === 'sqlite' ? 'sqlite' : 'mysql') . '.sql';
        $sql = (string) file_get_contents($file);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
        self::migrate($pdo); // Erstinstallation = Grundschema + alle Migrationen
    }
}
