<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;
use PDO;

/**
 * Integrationen: andere Dienste schicken per Webhook Texte an api/inbound.php (direkt oder über Make, Zapier,
 * IFTTT, n8n, Home Assistant), NFC-Sticker per iOS-Kurzbefehl – oder Gumbel holt eine URL selbst ab (JSON/RSS).
 * Pro Integration ein eigener API-Schlüssel (Header X-Key, gespeichert nur als SHA-256-Hash), optional HMAC-Signatur.
 *
 * Ablauf (process): Vorlage wählen → füllen → kürzen → Duplikat? → Testmodus? → Zeitfenster → Flut-Schutz →
 * Mindestabstand → einreihen. Jeder Aufruf landet im Zustell-Verlauf (die letzten 50 je Integration).
 */
final class Inbound
{
    /**
     * Module = Bereiche auf der Integrationen-Seite (Pill-Menü), erstes Modul = Standard-Tab.
     * Neuen Dienst einbinden: Quelle in Sources::ALL anlegen (Rang, Mindestdauer) und hier einem Modul
     * zuordnen – oder ein eigenes Modul eintragen. Die Seite baut Liste, Anlegen und Anleitung daraus.
     *  kind:   webhook = empfängt (api/inbound.php), pull = Gumbel holt eine URL ab, nfc = fester Text per Kurzbefehl
     *  help:   Anleitung auf der Seite (api, pull, nfc)
     */
    public const MODULES = [
        'webhooks' => [
            'label' => 'Webhooks', 'title' => 'Webhooks', 'sources' => ['webhook', 'social'], 'kind' => 'webhook', 'help' => 'api', 'min_interval' => 0,
            'add' => 'Neuer Webhook', 'placeholder' => 'z. B. Buchungen', 'text_placeholder' => 'NEUE BUCHUNG',
            'intro' => 'Jeder Dienst, der Webhooks senden kann – direkt oder über Make, Zapier, IFTTT, n8n oder Home Assistant – schickt so Texte aufs Board.',
        ],
        'abruf' => [
            'label' => 'Abrufen', 'title' => 'Abrufen (JSON & RSS)', 'sources' => ['feed'], 'kind' => 'pull', 'help' => 'pull', 'min_interval' => 0,
            'add' => 'Neuer Abruf', 'placeholder' => 'z. B. Tagesschau', 'text_placeholder' => '{{title}}',
            'intro' => 'Gumbel holt regelmäßig eine Adresse ab – JSON-Schnittstelle oder RSS/Atom-Feed – und zeigt Neues an. Gleicher Inhalt wird nur einmal angezeigt.',
        ],
        'nfc' => [
            'label' => 'NFC', 'title' => 'NFC-Sticker', 'sources' => ['nfc'], 'kind' => 'nfc', 'help' => 'nfc', 'min_interval' => 60,
            'add' => 'Neuer Sticker', 'placeholder' => 'z. B. Küche', 'text_placeholder' => 'ESSEN IST FERTIG',
            'intro' => 'Ein NFC-Sticker löst per iPhone-Kurzbefehl einen Webhook aus – sein Text erscheint auf dem Board.',
        ],
    ];

    /** Mindestabstand zwischen zwei Nachrichten einer Integration (Sekunden), Auswahl in der Oberfläche */
    public const INTERVALS = [0 => 'kein', 10 => '10 Sek', 60 => '1 Min', 300 => '5 Min', 900 => '15 Min'];
    /** Duplikat-Zeitraum (Minuten) */
    public const DEDUPE = [0 => 'aus', 10 => '10 Min', 60 => '1 Std', 1440 => '24 Std', 10080 => '7 Tage'];
    /** Schutz gegen Fluten: so viele Nachrichten einer Quelle dürfen gleichzeitig warten */
    public const MAX_QUEUED_PER_SOURCE = 20;
    public const DEFAULT_EXPIRES_MIN = 60;
    public const MAX_EXPIRES_MIN = 1440;
    public const LOG_KEEP = 50;
    public const SIGNATURE_TOLERANCE = 300;
    public const STATUS_LABELS = [
        'queued' => 'eingereiht', 'held' => 'zurückgehalten', 'test' => 'Testmodus', 'ignored' => 'ignoriert',
        'duplicate' => 'Duplikat', 'unchanged' => 'unverändert', 'error' => 'Fehler',
    ];
    private const EDITABLE = ['name', 'source', 'fixed_text', 'can_force', 'active', 'test_mode', 'rules', 'min_interval',
        'options', 'windows', 'dedupe_key', 'dedupe_minutes', 'require_signature', 'pull_url', 'pull_interval', 'pull_headers'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly MessageRepo $messages,
        private readonly Clock $clock,
        private readonly ?Feed $feed = null,
    ) {
    }

    /** Module inkl. eigener Quellen (die gehören zu „Webhooks“ – und sind auch beim Abrufen wählbar) */
    public static function modules(): array
    {
        $modules = self::MODULES;
        $texts = [
            'webhooks' => [
                'label' => t('Webhooks'), 'title' => t('Webhooks'), 'add' => t('Neuer Webhook'), 'placeholder' => t('z. B. Buchungen'), 'text_placeholder' => t('NEUE BUCHUNG'),
                'intro' => t('Jeder Dienst, der Webhooks senden kann – direkt oder über Make, Zapier, IFTTT, n8n oder Home Assistant – schickt so Texte aufs Board.'),
            ],
            'abruf' => [
                'label' => t('Abrufen'), 'title' => t('Abrufen (JSON & RSS)'), 'add' => t('Neuer Abruf'), 'placeholder' => t('z. B. Tagesschau'),
                'intro' => t('Gumbel holt regelmäßig eine Adresse ab – JSON-Schnittstelle oder RSS/Atom-Feed – und zeigt Neues an. Gleicher Inhalt wird nur einmal angezeigt.'),
            ],
            'nfc' => [
                'label' => t('NFC'), 'title' => t('NFC-Sticker'), 'add' => t('Neuer Sticker'), 'placeholder' => t('z. B. Küche'), 'text_placeholder' => t('ESSEN IST FERTIG'),
                'intro' => t('Ein NFC-Sticker löst per iPhone-Kurzbefehl einen Webhook aus – sein Text erscheint auf dem Board.'),
            ],
        ];
        foreach ($texts as $id => $text) {
            $modules[$id] = $text + $modules[$id];
        }
        $custom = array_keys(Sources::custom());
        $modules['webhooks']['sources'] = array_merge($modules['webhooks']['sources'], $custom);
        $modules['abruf']['sources'] = array_merge($modules['abruf']['sources'], $custom);
        return $modules;
    }

    /** Mindestabstand-Auswahl mit übersetzten Bezeichnungen (Schlüssel wie INTERVALS) */
    public static function intervals(): array
    {
        return [0 => t('kein'), 10 => t('10 Sek'), 60 => t('1 Min'), 300 => t('5 Min'), 900 => t('15 Min')];
    }

    /** Duplikat-Zeitraum-Auswahl mit übersetzten Bezeichnungen (Schlüssel wie DEDUPE) */
    public static function dedupeOptions(): array
    {
        return [0 => t('aus'), 10 => t('10 Min'), 60 => t('1 Std'), 1440 => t('24 Std'), 10080 => t('7 Tage')];
    }

    /** Status im Verlauf, übersetzt (Schlüssel wie STATUS_LABELS) */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => t('eingereiht'),
            'held' => t('zurückgehalten'),
            'test' => t('Testmodus'),
            'ignored' => t('ignoriert'),
            'duplicate' => t('Duplikat'),
            'unchanged' => t('unverändert'),
            'error' => t('Fehler'),
            default => $status,
        };
    }

    /** @return list<string> alle Quellen, die über Integrationen kommen dürfen */
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(...array_values(array_map(static fn (array $m): array => $m['sources'], self::modules())))));
    }

    /** Modul einer Integration: Abruf-Adresse → abruf, sonst nach Quelle */
    public static function moduleFor(array $hook): string
    {
        return !empty($hook['pull_url']) ? 'abruf' : self::moduleOf((string) $hook['source']);
    }

    public static function moduleOf(string $source): string
    {
        foreach (self::modules() as $id => $module) {
            if (in_array($source, $module['sources'], true)) {
                return $id;
            }
        }
        return array_key_first(self::MODULES);
    }

    // ---------- Verwaltung

    /** @return array{id: int, key: string} */
    public function createKey(string $name, string $source, ?string $fixedText, bool $canForce, ?int $minInterval = null): array
    {
        $key = bin2hex(random_bytes(24));
        $minInterval ??= (int) self::modules()[self::moduleOf($source)]['min_interval'];
        $this->pdo->prepare('INSERT INTO webhook_keys (name, key_hash, source, fixed_text, can_force, active, use_count, created_at, min_interval) VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?)')
            ->execute([$name, hash('sha256', $key), $source, $fixedText, $canForce ? 1 : 0, Time::db($this->clock->now()), $minInterval]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'key' => $key];
    }

    /** Neuen API-Schlüssel erzeugen, der alte funktioniert ab sofort nicht mehr. */
    public function rotateKey(int $id): ?string
    {
        $key = bin2hex(random_bytes(24));
        $statement = $this->pdo->prepare('UPDATE webhook_keys SET key_hash = ? WHERE id = ?');
        $statement->execute([hash('sha256', $key), $id]);
        return $statement->rowCount() === 1 ? $key : null;
    }

    /** Neues Signatur-Geheimnis (HMAC) – wird im Klartext gespeichert, weil der Server damit nachrechnen muss. */
    public function rotateSecret(int $id): ?string
    {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $statement = $this->pdo->prepare('UPDATE webhook_keys SET signing_secret = ? WHERE id = ?');
        $statement->execute([$secret, $id]);
        return $statement->rowCount() === 1 ? $secret : null;
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM webhook_keys WHERE id = ?');
        $statement->execute([$id]);
        $hook = $statement->fetch();
        return $hook === false ? null : $hook;
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM webhook_keys ORDER BY active DESC, name')->fetchAll();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(self::EDITABLE));
        if ($allowed === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($allowed)));
        $this->pdo->prepare("UPDATE webhook_keys SET $set WHERE id = ?")->execute([...array_values($allowed), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM webhook_deliveries WHERE key_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM webhook_keys WHERE id = ?')->execute([$id]);
    }

    /** @return list<array> Zustell-Verlauf, neueste zuerst */
    public function deliveries(int $id, int $limit = self::LOG_KEEP): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM webhook_deliveries WHERE key_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $statement->execute([$id]);
        return $statement->fetchAll();
    }

    // ---------- Rezepte (Export/Import)

    /** Konfiguration ohne Geheimnisse und Statistik – zum Teilen */
    public static function export(array $hook): array
    {
        return [
            'format' => 'gumbel-integration',
            'version' => 1,
            'name' => (string) $hook['name'],
            'module' => self::moduleFor($hook),
            'source' => (string) $hook['source'],
            'template' => (string) ($hook['fixed_text'] ?? ''),
            'template_options' => WebhookTemplate::defaultOptions($hook),
            'rules' => WebhookTemplate::rules($hook),
            'windows' => json_decode((string) ($hook['windows'] ?? '[]'), true) ?: [],
            'dedupe_key' => (string) ($hook['dedupe_key'] ?? ''),
            'dedupe_minutes' => (int) ($hook['dedupe_minutes'] ?? 0),
            'min_interval' => (int) ($hook['min_interval'] ?? 0),
            'can_force' => (int) $hook['can_force'] === 1,
            'pull_url' => self::maskUrlSecrets((string) ($hook['pull_url'] ?? '')),
            'pull_interval' => (int) ($hook['pull_interval'] ?? 15),
            // nur die Namen – Werte (API-Schlüssel) trägt jeder selbst ein
            'pull_headers' => array_map(static fn (array $h): string => $h[0], Feed::storedHeaders($hook)),
        ];
    }

    public const SECRET_PLACEHOLDER = 'YOUR-KEY';

    /** Schlüssel in der Abruf-Adresse (?apikey=…, &token=…) durch einen Platzhalter ersetzen, bevor sie geteilt wird */
    public static function maskUrlSecrets(string $url): string
    {
        return (string) preg_replace('/([?&](?:[a-z0-9_-]*(?:key|token|secret|password|pass|auth|appid|sig)[a-z0-9_-]*)=)[^&#]*/i', '$1' . self::SECRET_PLACEHOLDER, $url);
    }

    /** Header aus einem Rezept: Namen übernehmen, Werte als Platzhalter (der Admin trägt sie ein) */
    private static function recipeHeaders(mixed $names): ?string
    {
        $headers = [];
        foreach (is_array($names) ? array_slice($names, 0, Feed::MAX_HEADERS) : [] as $name) {
            if (is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{0,59}$/', $name)) {
                $headers[] = [$name, self::SECRET_PLACEHOLDER];
            }
        }
        return $headers === [] ? null : json_encode($headers);
    }

    /** @return array{ok: bool, error: ?string, id?: int, key?: string} */
    public function import(array $recipe): array
    {
        if (($recipe['format'] ?? '') !== 'gumbel-integration') {
            return ['ok' => false, 'error' => t('Das ist kein Gumbel-Rezept (format „gumbel-integration“ fehlt).')];
        }
        $module = isset(self::MODULES[$recipe['module'] ?? '']) ? (string) $recipe['module'] : 'webhooks';
        $source = in_array($recipe['source'] ?? '', self::modules()[$module]['sources'], true) ? (string) $recipe['source'] : self::MODULES[$module]['sources'][0];
        $name = mb_substr(trim((string) ($recipe['name'] ?? t('Importiert'))), 0, 80) ?: t('Importiert');
        $created = $this->createKey($name, $source, mb_substr((string) ($recipe['template'] ?? ''), 0, 300) ?: null, !empty($recipe['can_force']),
            isset(self::INTERVALS[(int) ($recipe['min_interval'] ?? -1)]) ? (int) $recipe['min_interval'] : null);
        $pullUrl = (string) ($recipe['pull_url'] ?? '');
        $this->update($created['id'], [
            'options' => json_encode(WebhookTemplate::options((array) ($recipe['template_options'] ?? []))),
            'rules' => json_encode(WebhookTemplate::parseRules($recipe['rules'] ?? []), JSON_UNESCAPED_UNICODE),
            'windows' => json_encode(Schedule::parse($recipe['windows'] ?? [], 'windows')),
            'dedupe_key' => mb_substr((string) ($recipe['dedupe_key'] ?? ''), 0, 200) ?: null,
            'dedupe_minutes' => isset(self::DEDUPE[(int) ($recipe['dedupe_minutes'] ?? 0)]) ? (int) ($recipe['dedupe_minutes'] ?? 0) : 0,
            'pull_url' => $pullUrl !== '' && Feed::checkUrl($pullUrl) === null ? mb_substr($pullUrl, 0, 500) : null,
            'pull_interval' => isset(Feed::INTERVALS[(int) ($recipe['pull_interval'] ?? 0)]) ? (int) $recipe['pull_interval'] : 15,
            'pull_headers' => self::recipeHeaders($recipe['pull_headers'] ?? []),
            'test_mode' => 1, // importiert = erst einmal nur mitschneiden, bis der Admin es freigibt
            // Abruf-Rezepte holen erst etwas ab, wenn der Admin die Adresse gesehen und die Integration aktiviert hat
            'active' => $pullUrl !== '' ? 0 : 1,
        ]);
        return ['ok' => true, 'error' => null] + $created;
    }

    // ---------- Empfangen, Testen, Abholen

    /**
     * Webhook annehmen.
     * @param array<mixed> $payload
     * @param array{raw?: string, signature?: string, timestamp?: string} $meta für die HMAC-Prüfung
     * @return array{ok: bool, status: int, error: ?string, id: ?int, test?: bool, text?: string, ignored?: bool, held_until?: string}
     */
    public function receive(string $key, array $payload, array $meta = []): array
    {
        if ($key === '' || strlen($key) > 200) {
            return self::fail(401, t('API-Schlüssel fehlt (Header X-Key).'));
        }
        $statement = $this->pdo->prepare('SELECT * FROM webhook_keys WHERE key_hash = ?');
        $statement->execute([hash('sha256', $key)]);
        $hook = $statement->fetch();
        if ($hook === false || (int) $hook['active'] !== 1) {
            return self::fail(401, t('API-Schlüssel ungültig oder Integration deaktiviert.'));
        }
        if ((int) ($hook['require_signature'] ?? 0) === 1) {
            $error = $this->checkSignature($hook, $meta);
            if ($error !== null) {
                $this->log($hook, 'error', null, null, $error, $payload);
                return self::fail(401, $error);
            }
        }
        return $this->process($hook, $payload, true);
    }

    /** Admin-Test: Vorlage mit dem letzten Payload füllen und sofort anstellen – ohne Schlüssel, Abstand, Zeitfenster. */
    public function test(int $id): array
    {
        $hook = $this->find($id);
        if ($hook === null) {
            return self::fail(404, t('Integration nicht gefunden.'));
        }
        $payload = json_decode((string) ($hook['last_payload'] ?? ''), true);
        $rendered = WebhookTemplate::render($hook, is_array($payload) ? $payload : []);
        $text = Charset::fit($rendered['text']) ?: 'TEST {green}' . "\n" . Charset::cut(mb_strtoupper((string) $hook['name']), Charset::COLS);
        $messageId = $this->enqueue($hook, $text, $rendered['options'] + ['expires' => 15], false, null);
        $this->log($hook, 'queued', $rendered['rule'], $text, t('Über „Jetzt testen“'), is_array($payload) ? $payload : [], null, $messageId);
        return ['ok' => true, 'status' => 200, 'error' => null, 'id' => $messageId];
    }

    /** Cron: fällige Abrufe holen. @return list<string> Protokoll */
    public function pollDue(): array
    {
        $now = $this->clock->now();
        $done = [];
        foreach ($this->pdo->query("SELECT * FROM webhook_keys WHERE active = 1 AND pull_url IS NOT NULL AND pull_url <> ''")->fetchAll() as $hook) {
            $last = $hook['pull_last_at'] ?? null;
            if ($last !== null && Time::secondsBetween(Time::fromDb((string) $last), $now) < max(5, (int) $hook['pull_interval']) * 60 - 30) {
                continue;
            }
            $done[] = $this->poll($hook)['status_text'];
        }
        return $done;
    }

    /** Einen Abruf jetzt ausführen (Cron oder Knopf „Jetzt abrufen“). */
    public function poll(array $hook): array
    {
        $this->pdo->prepare('UPDATE webhook_keys SET pull_last_at = ? WHERE id = ?')->execute([Time::db($this->clock->now()), (int) $hook['id']]);
        $fetched = ($this->feed ?? new Feed())->fetch((string) $hook['pull_url'], Feed::storedHeaders($hook));
        if ($fetched['payload'] === null) {
            $this->setError((int) $hook['id'], (string) $fetched['error']);
            $this->log($hook, 'error', null, null, (string) $fetched['error'], []);
            return ['ok' => false, 'status_text' => 'error', 'error' => $fetched['error']];
        }
        $result = $this->process($hook, $fetched['payload'], false);
        return $result + ['status_text' => $result['ok'] ? 'ok' : 'error'];
    }

    /**
     * Gemeinsamer Ablauf für Webhook und Abruf.
     * $webhook = true: Payload-Feld force/expires_in_min erlaubt; false (Abruf): gleicher Text wie zuletzt = unverändert.
     */
    private function process(array $hook, array $payload, bool $webhook): array
    {
        $now = $this->clock->now();
        $id = (int) $hook['id'];
        $this->capture($id, $payload, $now);

        $rendered = WebhookTemplate::render($hook, $payload);
        $text = mb_check_encoding($rendered['text'], 'UTF-8') ? Charset::fit($rendered['text']) : '';
        $options = $rendered['options'];
        $dedupeKey = $this->dedupeKey($hook, $payload);

        if ((int) ($hook['test_mode'] ?? 0) === 1) {
            $note = $text === '' ? self::emptyNote($rendered) : null;
            $this->setError($id, $note);
            $this->log($hook, 'test', $rendered['rule'], $text, $note, $payload, $dedupeKey);
            return ['ok' => true, 'status' => 200, 'error' => null, 'id' => null, 'test' => true, 'text' => $text, 'rule' => $rendered['rule']];
        }
        if ($text === '') {
            $note = self::emptyNote($rendered);
            $this->setError($id, $note);
            $this->log($hook, 'ignored', $rendered['rule'], null, $note, $payload);
            return ['ok' => true, 'status' => 200, 'error' => null, 'id' => null, 'ignored' => true];
        }
        if ($dedupeKey !== null && $this->isDuplicate($id, $dedupeKey, (int) $hook['dedupe_minutes'], $now)) {
            $this->log($hook, 'duplicate', $rendered['rule'], $text, t('Gleicher Duplikat-Schlüssel „{key}“ innerhalb des Zeitraums.', ['key' => $dedupeKey]), $payload, $dedupeKey);
            return ['ok' => true, 'status' => 200, 'error' => null, 'id' => null, 'ignored' => true, 'duplicate' => true];
        }
        if (!$webhook && $this->lastQueuedText($id) === $text) {
            $this->setError($id, null);
            return ['ok' => true, 'status' => 200, 'error' => null, 'id' => null, 'ignored' => true, 'unchanged' => true]; // Abruf ohne Neues: nicht protokollieren
        }

        // Zeitfenster: außerhalb zurückhalten bis zum nächsten Beginn
        $windows = json_decode((string) ($hook['windows'] ?? ''), true);
        $notBefore = null;
        if (is_array($windows) && $windows !== [] && !Schedule::inWindows($windows, $now)) {
            $notBefore = Schedule::nextWindowStart($windows, $now);
        }

        $waiting = count(array_filter($this->messages->queued(), static fn (array $m): bool => $m['source'] === $hook['source']));
        if ($waiting >= self::MAX_QUEUED_PER_SOURCE) {
            $error = t('Warteschlange voll ({count} wartende Nachrichten dieser Quelle).', ['count' => self::MAX_QUEUED_PER_SOURCE]);
            $this->setError($id, $error);
            $this->log($hook, 'error', $rendered['rule'], $text, $error, $payload);
            return self::fail(429, t('Warteschlange voll – bitte später erneut senden.'));
        }

        // Mindestabstand atomar belegen: von zwei gleichzeitigen Requests gewinnt genau einer
        $gap = max(0, (int) ($hook['min_interval'] ?? 60));
        $claim = $this->pdo->prepare('UPDATE webhook_keys SET last_used_at = ?, use_count = use_count + 1 WHERE id = ? AND (? = 0 OR last_used_at IS NULL OR last_used_at <= ?)');
        $claim->execute([Time::db($now), $id, $gap, Time::db($now->modify("-$gap seconds"))]);
        if ($claim->rowCount() === 0) {
            $error = t('Zu schnell – Mindestabstand {interval}.', ['interval' => self::intervals()[$gap] ?? t('{seconds} Sek', ['seconds' => $gap])]);
            $this->setError($id, $error);
            $this->log($hook, 'error', $rendered['rule'], $text, $error, $payload);
            return self::fail(429, t('Zu schnell – Mindestabstand dieser Integration noch nicht erreicht.'));
        }

        if ($webhook && $options['expires'] === 0 && is_numeric($payload['expires_in_min'] ?? null)) {
            $options['expires'] = (int) $payload['expires_in_min'];
        }
        $forced = $options['force'] || ($webhook && (int) $hook['can_force'] === 1 && filter_var($payload['force'] ?? false, FILTER_VALIDATE_BOOL));
        $messageId = $this->enqueue($hook, $text, $options, $forced, $notBefore);
        $this->setError($id, null);
        $this->log($hook, $notBefore !== null ? 'held' : 'queued', $rendered['rule'], $text,
            $notBefore !== null ? t('Außerhalb des Zeitfensters – erscheint ab {time} Uhr.', ['time' => Time::format(Time::db($notBefore), 'd.m. H:i')]) : null,
            $payload, $dedupeKey, $messageId);
        return ['ok' => true, 'status' => 200, 'error' => null, 'id' => $messageId] + ($notBefore !== null ? ['held_until' => Time::db($notBefore)] : []);
    }

    // ---------- Bausteine

    private static function emptyNote(array $rendered): string
    {
        return $rendered['ignored'] ? t('Nicht angezeigt: Vorlage „nicht anzeigen“ hat gegriffen.') : t('Nicht angezeigt: keine passende Vorlage und kein Feld „text“.');
    }

    /** HMAC-SHA256 über "<timestamp>.<body>", Header X-Signature: sha256=<hex>, X-Timestamp: Unix-Sekunden */
    private function checkSignature(array $hook, array $meta): ?string
    {
        $secret = (string) ($hook['signing_secret'] ?? '');
        $timestamp = (string) ($meta['timestamp'] ?? '');
        $signature = (string) ($meta['signature'] ?? '');
        if ($secret === '') {
            return t('Signatur verlangt, aber kein Signatur-Geheimnis erzeugt.');
        }
        if (!ctype_digit($timestamp) || abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return t('Signatur: X-Timestamp fehlt oder ist älter als 5 Minuten.');
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . (string) ($meta['raw'] ?? ''), $secret);
        return hash_equals($expected, $signature) ? null : t('Signatur ungültig (X-Signature).');
    }

    private function dedupeKey(array $hook, array $payload): ?string
    {
        if ((int) ($hook['dedupe_minutes'] ?? 0) <= 0 || trim((string) ($hook['dedupe_key'] ?? '')) === '') {
            return null;
        }
        $key = WebhookTemplate::textFor((string) $hook['dedupe_key'], $payload);
        return $key === '' ? null : mb_substr($key, 0, 250);
    }

    private function isDuplicate(int $id, string $dedupeKey, int $minutes, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM webhook_deliveries WHERE key_id = ? AND dedupe_key = ? AND status IN ('queued', 'held') AND received_at >= ?");
        $statement->execute([$id, $dedupeKey, Time::db($now->modify("-$minutes minutes"))]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function lastQueuedText(int $id): ?string
    {
        $statement = $this->pdo->prepare("SELECT text FROM webhook_deliveries WHERE key_id = ? AND status IN ('queued', 'held') ORDER BY id DESC LIMIT 1");
        $statement->execute([$id]);
        $text = $statement->fetchColumn();
        return $text === false ? null : (string) $text;
    }

    private function log(array $hook, string $status, ?int $rule, ?string $text, ?string $note, array $payload, ?string $dedupeKey = null, ?int $messageId = null): void
    {
        $id = (int) $hook['id'];
        $this->pdo->prepare('INSERT INTO webhook_deliveries (key_id, received_at, status, rule, text, note, payload, dedupe_key, message_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, Time::db($this->clock->now()), $status, $rule, $text, $note === null ? null : mb_substr($note, 0, 250),
                self::compactJson($payload, 6000), $dedupeKey, $messageId]);
        // nur die letzten LOG_KEEP behalten
        $cut = $this->pdo->prepare('SELECT id FROM webhook_deliveries WHERE key_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . self::LOG_KEEP);
        $cut->execute([$id]);
        $oldest = $cut->fetchColumn();
        if ($oldest !== false) {
            $this->pdo->prepare('DELETE FROM webhook_deliveries WHERE key_id = ? AND id <= ?')->execute([$id, (int) $oldest]);
        }
    }

    private function capture(int $id, array $payload, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE webhook_keys SET last_payload = ?, last_payload_at = ? WHERE id = ?')
            ->execute([self::compactJson($payload, 60000), Time::db($now), $id]);
    }

    /**
     * Payload für Mitschnitt und Verlauf: immer gültiges JSON (sonst fehlen die Felder zum Antippen).
     * Ist es zu groß, werden lange Texte und Listen schrittweise gekürzt statt mitten im JSON abzuschneiden.
     */
    public static function compactJson(array $payload, int $maxBytes): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = (string) json_encode($payload, $flags);
        foreach ([[300, 50], [120, 20], [60, 10], [40, 5]] as [$text, $items]) {
            if (strlen($json) <= $maxBytes) {
                return $json;
            }
            $json = (string) json_encode(self::shrink($payload, $text, $items, 0), $flags);
        }
        return strlen($json) <= $maxBytes ? $json : '{}';
    }

    private static function shrink(mixed $value, int $text, int $items, int $depth): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > $text ? mb_substr($value, 0, $text) . '…' : $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        if ($depth >= 8) {
            return [];
        }
        $out = [];
        foreach (array_slice($value, 0, $items, true) as $key => $child) {
            $out[$key] = self::shrink($child, $text, $items, $depth + 1);
        }
        return $out;
    }

    private function setError(int $id, ?string $error): void
    {
        $this->pdo->prepare('UPDATE webhook_keys SET last_error = ? WHERE id = ?')->execute([$error === null ? null : mb_substr($error, 0, 250), $id]);
    }

    /** @param array{force?: bool, duration: int, expires: int} $options */
    private function enqueue(array $hook, string $text, array $options, bool $forced, ?DateTimeImmutable $notBefore): int
    {
        $now = $this->clock->now();
        $start = $notBefore ?? $now;
        $expires = max(1, min(self::MAX_EXPIRES_MIN, $options['expires'] > 0 ? $options['expires'] : self::DEFAULT_EXPIRES_MIN));
        return $this->messages->insert([
            'source' => (string) $hook['source'],
            'user_id' => null,
            'text_raw' => $text,
            'align' => 'center',
            'grid' => json_encode(Charset::textToGrid($text)['grid']),
            'forced' => $forced ? 1 : 0,
            'status' => 'queued',
            'not_before' => $notBefore !== null ? Time::db($notBefore) : null,
            'expires_at' => Time::db($start->modify("+$expires minutes")),
            'created_at' => Time::db($now),
            'min_duration' => $options['duration'] > 0 ? $options['duration'] : null,
        ]);
    }

    private static function fail(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'id' => null];
    }
}
