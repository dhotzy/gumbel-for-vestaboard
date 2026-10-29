<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Darstellung der Integrationen-Seite (public/webhooks.php): Formular je Integration, Vorlagen mit Vorschau,
 * Payload-Felder, Zustell-Verlauf und Anleitungen. Die Seite selbst kümmert sich nur um Aktionen.
 */
final class IntegrationView
{
    /** Kopierbarer Wert mit Knopf */
    public static function copyField(string $label, string $value, bool $secret = false): string
    {
        return '<div class="copy-row" data-copy-scope><span class="copy-label">' . View::e($label) . '</span>'
            . '<code class="' . ($secret ? 'secret' : '') . '" data-copy>' . View::e($value) . '</code>'
            . '<button type="button" class="copy-button" data-copy-button>' . t('Kopieren') . '</button></div>';
    }

    /** Anleitung je Modul; mit Schlüssel (direkt nach dem Anlegen) zum Abhaken */
    public static function setupHelp(string $help, string $endpoint, ?string $key): string
    {
        $keyField = $key !== null ? self::copyField('X-Key', $key, true) : '<p class="muted hint">' . t('Der API-Schlüssel wird nur beim Anlegen angezeigt – sonst „Neuer API-Schlüssel“.') . '</p>';
        return match ($help) {
            'nfc' => '<ol class="steps">'
                . '<li>' . t('App <strong>Kurzbefehle</strong> → <strong>Automation</strong> → <strong>+</strong> → <strong>NFC</strong> → Sticker scannen, „Sofort ausführen“ wählen.') . '</li>'
                . '<li>' . t('Aktion <strong>„Inhalte von URL abrufen“</strong> hinzufügen und die Webhook-URL einsetzen:') . self::copyField('URL', $endpoint) . '</li>'
                . '<li>' . t('In der Aktion auf <strong>„Mehr anzeigen“</strong>: Methode <strong>POST</strong>, unter Header einen Eintrag hinzufügen – Name <code>X-Key</code>, Wert = API-Schlüssel:') . $keyField . '</li>'
                . '<li>' . t('Fertig. Handy an den Sticker halten – der Text stellt sich an.') . '</li></ol>',
            'pull' => '<ol class="steps">'
                . '<li>' . t('Adresse einer <strong>JSON-Schnittstelle</strong> oder eines <strong>RSS-/Atom-Feeds</strong> eintragen und das Intervall wählen.') . '</li>'
                . '<li>' . t('<strong>„Jetzt abrufen“</strong> – danach erscheinen die Felder zum Antippen (bei RSS: <code>title</code>, <code>description</code>, <code>date</code>, <code>feed</code>, <code>items.1.title</code> …).') . '</li>'
                . '<li>' . t('Vorlage bauen, z. B. <code>{{feed|15}}</code> / <code>{{title}}</code>. Neues erscheint, gleicher Text wird nicht wiederholt.') . '</li></ol>'
                . '<p class="muted hint">' . t('Nur öffentliche http(s)-Adressen, höchstens 512 KB, Zeitlimit 8 Sekunden.') . '</p>',
            default => '<ol class="steps">'
                . '<li>' . t('Im Dienst (oder in Make, Zapier, IFTTT, n8n, Home Assistant) einen Webhook bzw. eine HTTP-Aktion anlegen, Webhook-URL:') . self::copyField('URL', $endpoint) . '</li>'
                . '<li>' . t('Methode <strong>POST</strong>, Header <code>X-Key</code> = API-Schlüssel:') . $keyField . '</li>'
                . '<li>' . t('Body als JSON (oder Formular) mit beliebigen Feldern. Was aufs Board kommt, legst du in den <strong>Vorlagen</strong> fest – der <strong>Testmodus</strong> zeigt dir dafür die Felder des letzten Webhooks. Ohne Vorlage wird das Feld <code>text</code> genommen.') . '</li></ol>'
                . '<pre class="code">{"event": "booking.confirmed", "text": "' . t('NEUE BUCHUNG') . '", "force": false, "expires_in_min": 60}</pre>'
                . '<p class="muted hint">' . t('Antwort <code>{"ok": true, "message_id": 123}</code>, außerdem <code>ignored</code>, <code>held_until</code> (Zeitfenster) oder im Testmodus <code>"test": true</code> mit dem Text. Fehler: 401 (Schlüssel/Signatur), 429 (Mindestabstand/Warteschlange voll). Optional mit HMAC-Signatur – siehe „Sicherheit“ und die Dokumentation.') . '</p>',
        };
    }

    /** Statusanzeige im Verlauf */
    public static function statusBadge(string $status): string
    {
        $tone = match ($status) {
            'queued' => 'ok',
            'held', 'test' => 'wait',
            'error' => 'error',
            default => '',
        };
        return '<span class="badge' . ($tone !== '' ? ' badge-' . $tone : '') . '">' . View::e(Inbound::statusLabel($status)) . '</span>';
    }

    /** Formularfelder einer Integration (ohne <form>) */
    public static function fields(string $module, array $hook, ?string $newSecret = null): string
    {
        $config = Inbound::modules()[$module];
        $kind = $config['kind'];
        $saved = isset($hook['id']);
        $html = '<input type="hidden" name="module" value="' . $module . '">'
            . '<label>' . t('Name') . ' <input name="name" value="' . View::e($hook['name'] ?? '') . '" maxlength="80" placeholder="' . View::e($config['placeholder']) . '" required></label>';
        if (count($config['sources']) > 1) {
            $html .= '<label>' . t('Quelle') . ' <span class="muted">' . t('(bestimmt Rang und Mindestdauer auf dem Board)') . '</span><select name="source">';
            foreach ($config['sources'] as $source) {
                $html .= '<option value="' . $source . '"' . (($hook['source'] ?? '') === $source ? ' selected' : '') . '>' . View::e(Sources::label($source)) . '</option>';
            }
            $html .= '</select></label>';
        }
        if ($kind === 'pull') {
            $interval = (int) ($hook['pull_interval'] ?? 15);
            $html .= '<label>' . t('Adresse (JSON oder RSS)') . ' <input type="url" name="pull_url" value="' . View::e($hook['pull_url'] ?? '') . '" maxlength="500" placeholder="https://www.tagesschau.de/index~rss2.xml" required></label>'
                . '<label>' . t('Abrufen alle') . ' <select name="pull_interval">';
            foreach (Feed::intervals() as $minutes => $label) {
                $html .= '<option value="' . $minutes . '"' . ($interval === $minutes ? ' selected' : '') . '>' . View::e($label) . '</option>';
            }
            $html .= '</select></label>'
                . '<label>' . t('Eigene Header') . ' <span class="muted">' . t('(optional, z. B. für API-Schlüssel – eine Zeile je Header; gespeicherte Werte werden nicht angezeigt)') . '</span>'
                . '<textarea name="pull_headers" rows="2" maxlength="2000" spellcheck="false" autocomplete="off" placeholder="Authorization: Bearer abc123&#10;X-Api-Key: abc123">'
                . View::e(Feed::headersForForm($hook)) . '</textarea></label>';
        }
        if ($kind === 'nfc') {
            return $html . self::nfcFields($hook);
        }

        $payload = json_decode((string) ($hook['last_payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : null;
        if ($saved) {
            $html .= '<label><input type="checkbox" name="test_mode" value="1"' . ((int) ($hook['test_mode'] ?? 0) === 1 ? ' checked' : '') . '> <span>' . t('Testmodus')
                . '<span class="muted">' . ($kind === 'pull' ? t('Abrufe nur mitschneiden, nichts aufs Board – zum Einrichten der Vorlagen') : t('Webhooks nur mitschneiden, nichts aufs Board – zum Einrichten der Vorlagen')) . '</span></span></label>'
                . self::payloadFields($hook, $payload, $kind);
        }
        $matched = $payload !== null ? WebhookTemplate::render($hook, $payload)['rule'] : false;
        $payloadJson = $payload !== null ? ' data-payload="' . View::e((string) json_encode($payload, JSON_UNESCAPED_UNICODE)) . '"' : '';
        $fieldsList = $saved && $payload !== null ? 'fields-' . (int) $hook['id'] : '';

        $html .= View::tokenBar(false, true)
            . '<div class="template-block" data-preview-scope>'
            . '<p class="template-head">' . t('Standard-Vorlage') . ($matched === null ? ' <span class="badge badge-ok">' . t('passt auf letzten Aufruf') . '</span>' : '') . '</p>'
            . self::templateEditor('fixed_text', (string) ($hook['fixed_text'] ?? ''), $payload, $payloadJson, $config['text_placeholder'])
            . self::optionsRow('options', WebhookTemplate::defaultOptions($hook))
            . '<p class="muted hint">' . t('Gilt, wenn keine Bedingung unten passt.') . ' ' . ($kind === 'webhook' ? t('Leer = Feld „text“ aus dem Webhook.') . ' ' : '')
            . t('Platzhalter <code>{{feld.pfad}}</code>, Filter <code>|10</code> <code>|zahl</code> <code>|eur</code> <code>|datum</code> <code>|zeit</code> <code>|oder:TEXT</code>. Zu lange Texte werden automatisch gekürzt.') . '</p>'
            . '</div>';

        if ($saved) {
            $html .= '<fieldset class="slots rules" data-slots data-slots-min="0"><legend>' . t('Vorlagen mit Bedingung') . '</legend><div class="slot-list" data-slot-list>';
            foreach (WebhookTemplate::rules($hook) as $i => $rule) {
                $html .= self::ruleRow((string) $i, $rule, $payload, $payloadJson, $fieldsList, $matched === $i);
            }
            $blank = ['field' => '', 'op' => 'equals', 'value' => '', 'template' => ''] + WebhookTemplate::options([]);
            $html .= '</div><template data-slot-template>' . self::ruleRow('__i__', $blank, $payload, $payloadJson, $fieldsList, false) . '</template>'
                . '<button type="button" class="slot-add" data-slot-add>' . t('+ Vorlage mit Bedingung') . '</button>'
                . '<p class="muted hint">' . t('Z. B. „event ist gleich booking.confirmed“ → Buchungs-Text mit Force, „event enthält cancel“ → Storno-Text. Die erste passende gewinnt; leere Vorlage = nicht anzeigen.') . '</p></fieldset>';
            if ($fieldsList !== '') {
                $html .= '<datalist id="' . $fieldsList . '">';
                foreach (WebhookTemplate::fields($payload) as [$path]) {
                    $html .= '<option value="' . View::e($path) . '">';
                }
                $html .= '</datalist>';
            }
        }
        return $html . self::advanced($hook, $kind, $newSecret);
    }

    /** NFC: fester Text mit Vorschau, Mindestabstand, Force */
    private static function nfcFields(array $hook): string
    {
        $text = (string) ($hook['fixed_text'] ?? '');
        $preview = Charset::textToGrid($text);
        return '<div class="preview" data-preview-target>' . GridHtml::render($preview['grid']) . '</div><ul class="warnings" data-preview-warnings></ul>'
            . '<label>' . t('Text aufs Board') . '<textarea name="fixed_text" rows="3" maxlength="120" data-preview-input placeholder="' . View::e(t('ESSEN IST FERTIG')) . '" required>' . "\n" . View::e($text) . '</textarea></label>'
            . '<p class="counter' . ($preview['fits'] ? '' : ' over') . '" data-preview-counter aria-live="polite">' . View::e(Charset::counterText($preview)) . '</p>'
            . View::tokenBar(false)
            . self::intervalSelect((int) ($hook['min_interval'] ?? 60))
            . '<label><input type="checkbox" name="can_force" value="1"' . ((int) ($hook['can_force'] ?? 0) === 1 ? ' checked' : '') . '> <span>' . t('Force') . '<span class="muted">' . t('auch während Stummschaltung') . '</span></span></label>';
    }

    /** Zeitfenster, Duplikate, Sicherheit – aufklappbar, damit die Grundeinrichtung kurz bleibt */
    private static function advanced(array $hook, string $kind, ?string $newSecret): string
    {
        $windows = json_decode((string) ($hook['windows'] ?? ''), true);
        $windows = is_array($windows) ? $windows : [];
        $dedupeMinutes = (int) ($hook['dedupe_minutes'] ?? 0);
        $dedupeOptions = Inbound::dedupeOptions();
        $html = '<details class="sub"' . ($windows !== [] ? ' open' : '') . '><summary>' . t('Zeitfenster') . ($windows !== [] ? ' · ' . View::e(Schedule::summary($windows)) : ' · ' . t('immer')) . '</summary>'
            . ScheduleView::field('windows', $windows, 'windows', t('Nur in diesen Zeiten anzeigen'), 0, t('+ Zeitfenster'))
            . '<p class="muted hint">' . t('Ohne Zeitfenster immer. Kommt etwas außerhalb, wird es zurückgehalten und erscheint, sobald das nächste Fenster beginnt.') . '</p></details>'
            . '<details class="sub"' . ($dedupeMinutes > 0 ? ' open' : '') . '><summary>' . t('Duplikate') . ' · ' . View::e($dedupeOptions[$dedupeMinutes] ?? $dedupeOptions[0]) . '</summary>'
            . '<label>' . t('Duplikat-Schlüssel') . ' <span class="muted">' . t('(Platzhalter, z. B. {{event}}-{{booking.number}})') . '</span><input name="dedupe_key" value="' . View::e($hook['dedupe_key'] ?? '') . '" maxlength="200" placeholder="{{event}}-{{booking.number}}"></label>'
            . '<label>' . t('Gleicher Schlüssel wird ignoriert für') . ' <select name="dedupe_minutes">';
        foreach ($dedupeOptions as $minutes => $label) {
            $html .= '<option value="' . $minutes . '"' . ($dedupeMinutes === $minutes ? ' selected' : '') . '>' . View::e($label) . '</option>';
        }
        $html .= '</select></label></details>';

        $html .= '<details class="sub"' . ($newSecret !== null ? ' open' : '') . '><summary>' . t('Sicherheit &amp; Tempo') . '</summary>'
            . self::intervalSelect((int) ($hook['min_interval'] ?? 0));
        if ($kind === 'webhook') {
            $html .= '<label><input type="checkbox" name="can_force" value="1"' . ((int) ($hook['can_force'] ?? 0) === 1 ? ' checked' : '') . '> <span>' . t('Force per Payload erlaubt') . '<span class="muted">' . t('wenn der Webhook "force": true mitschickt') . '</span></span></label>';
            if (isset($hook['id'])) {
                $html .= '<label><input type="checkbox" name="require_signature" value="1"' . ((int) ($hook['require_signature'] ?? 0) === 1 ? ' checked' : '') . '> <span>' . t('HMAC-Signatur verlangen')
                    . '<span class="muted">' . t('Header X-Timestamp + X-Signature: sha256=…, schützt vor Fälschung und Wiederholung') . '</span></span></label>';
                if ($newSecret !== null) {
                    $html .= '<div class="key-once"><p class="muted hint">' . t('Signatur-Geheimnis – nur jetzt sichtbar:') . '</p>' . self::copyField('Secret', $newSecret, true) . '</div>';
                }
                $html .= '<button type="submit" name="action" value="secret" class="link" formnovalidate'
                    . (!empty($hook['signing_secret']) ? ' data-confirm-click="' . View::e(t('Neues Signatur-Geheimnis erzeugen? Das alte funktioniert danach nicht mehr.')) . '"' : '') . '>'
                    . (!empty($hook['signing_secret']) ? t('Neues Signatur-Geheimnis') : t('Signatur-Geheimnis erzeugen')) . '</button>';
            }
        }
        return $html . '</details>';
    }

    private static function intervalSelect(int $gap): string
    {
        $html = '<label>' . t('Mindestabstand') . ' <span class="muted">' . t('(zwischen zwei Nachrichten – sonst stellen sich mehrere einfach an)') . '</span><select name="min_interval">';
        foreach (Inbound::intervals() as $seconds => $label) {
            $html .= '<option value="' . $seconds . '"' . ($gap === $seconds ? ' selected' : '') . '>' . View::e($label) . '</option>';
        }
        return $html . '</select></label>';
    }

    /** Vorlagen-Feld mit eigener Vorschau (gefüllt mit dem letzten Payload, gekürzt wie beim Empfang) */
    private static function templateEditor(string $name, string $text, ?array $payload, string $payloadJson, string $placeholder): string
    {
        $preview = Charset::textToGrid(Charset::fit($payload !== null ? WebhookTemplate::textFor($text, $payload) : $text));
        return '<div class="preview" data-preview-target>' . GridHtml::render($preview['grid']) . '</div><ul class="warnings" data-preview-warnings></ul>'
            . '<textarea name="' . $name . '" rows="3" maxlength="300" data-preview-input data-fit aria-label="' . View::e(t('Vorlage')) . '" placeholder="' . View::e($placeholder) . '"' . $payloadJson . '>' . "\n" . View::e($text) . '</textarea>'
            . '<p class="counter" data-preview-counter aria-live="polite">' . View::e(Charset::counterText($preview)) . '</p>';
    }

    /** Force · Anzeigedauer · Ablauf einer Vorlage */
    private static function optionsRow(string $name, array $options): string
    {
        $html = '<div class="template-options"><label class="opt-force"><input type="checkbox" name="' . $name . '[force]" value="1"' . ($options['force'] ? ' checked' : '') . '> ' . t('Force') . '</label>'
            . '<label class="opt">' . t('Anzeigedauer') . '<select name="' . $name . '[duration]">';
        foreach (WebhookTemplate::durations() as $seconds => $label) {
            $html .= '<option value="' . $seconds . '"' . ($options['duration'] === $seconds ? ' selected' : '') . '>' . View::e($label) . '</option>';
        }
        $html .= '</select></label><label class="opt">' . t('Verfällt nach') . '<select name="' . $name . '[expires]">';
        foreach (WebhookTemplate::expiresOptions() as $minutes => $label) {
            $html .= '<option value="' . $minutes . '"' . ($options['expires'] === $minutes ? ' selected' : '') . '>' . View::e($label) . '</option>';
        }
        return $html . '</select></label></div>';
    }

    /** Eine Vorlage mit Bedingung: Wenn [Feld] [ist gleich|enthält|ist vorhanden] [Wert] → Vorlage + Optionen */
    private static function ruleRow(string $index, array $rule, ?array $payload, string $payloadJson, string $fieldsList, bool $matched): string
    {
        $field = 'rules[' . $index . ']';
        $html = '<div class="slot rule" data-slot data-preview-scope><div class="slot-head"><span class="rule-no" data-label="' . View::e(t('Vorlage')) . '"></span>'
            . ($matched ? '<span class="badge badge-ok">' . t('passt auf letzten Aufruf') . '</span>' : '')
            . '<button type="button" class="slot-remove" data-slot-remove aria-label="' . View::e(t('Vorlage entfernen')) . '" title="' . View::e(t('Entfernen')) . '">×</button></div>'
            . '<div class="rule-when"><span class="muted">' . t('Wenn') . '</span>'
            . '<input name="' . $field . '[field]" value="' . View::e($rule['field']) . '" placeholder="event" aria-label="' . View::e(t('Feld')) . '"' . ($fieldsList !== '' ? ' list="' . $fieldsList . '"' : '') . '>'
            . '<select name="' . $field . '[op]" aria-label="' . View::e(t('Vergleich')) . '">';
        foreach (WebhookTemplate::ops() as $op => $label) {
            $html .= '<option value="' . $op . '"' . ($rule['op'] === $op ? ' selected' : '') . '>' . View::e($label) . '</option>';
        }
        return $html . '</select><input name="' . $field . '[value]" value="' . View::e($rule['value']) . '" placeholder="booking.confirmed" aria-label="' . View::e(t('Wert')) . '"></div>'
            . self::templateEditor($field . '[template]', (string) $rule['template'], $payload, $payloadJson, t('leer = nicht anzeigen'))
            . self::optionsRow($field, WebhookTemplate::options($rule))
            . '</div>';
    }

    /** Letzter empfangener Payload als antippbare Felder → Platzhalter in die zuletzt benutzte Vorlage */
    private static function payloadFields(array $hook, ?array $payload, string $kind): string
    {
        if ($payload === null) {
            return '<div class="payload"><p class="muted hint">' . ($kind === 'pull'
                ? t('Noch nichts abgerufen. Speichern und „Jetzt abrufen“ – dann erscheinen hier die Felder zum Antippen.')
                : t('Noch kein Webhook empfangen. Testmodus einschalten, speichern und den Dienst einmal auslösen – dann erscheinen hier die Felder zum Antippen.')) . '</p></div>';
        }
        $html = '<div class="payload"><p class="payload-head">' . t('Letzter Aufruf · {time} Uhr', ['time' => Time::format($hook['last_payload_at'], 'd.m. H:i')])
            . ((int) ($hook['test_mode'] ?? 0) === 1 ? ' <span class="badge badge-wait">' . t('Testmodus') . '</span>' : '') . '</p>';
        $fields = WebhookTemplate::fields($payload);
        if ($fields === []) {
            return $html . '<p class="muted hint">' . t('Der Aufruf war leer.') . '</p></div>';
        }
        $html .= '<p class="muted hint">' . t('Feld antippen = Platzhalter in die zuletzt angetippte Vorlage einfügen.') . '</p><div class="payload-fields">';
        foreach ($fields as [$path, $value]) {
            $html .= '<button type="button" class="payload-field" data-insert-last="{{' . View::e($path) . '}}"><code>' . View::e($path) . '</code><span>' . View::e(mb_strimwidth($value, 0, 40, '…')) . '</span></button>';
        }
        return $html . '</div><details class="payload-raw"><summary>' . t('Rohdaten (JSON)') . '</summary><pre class="code">' . View::e((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details></div>';
    }

    /** Zustell-Verlauf: die letzten Aufrufe mit Status, Text und Payload */
    public static function deliveries(array $rows): string
    {
        if ($rows === []) {
            return '<p class="muted hint">' . t('Noch keine Aufrufe.') . '</p>';
        }
        $html = '<ul class="deliveries">';
        foreach ($rows as $row) {
            $rule = $row['rule'] !== null ? t('Vorlage {number}', ['number' => (int) $row['rule'] + 1]) : t('Standard');
            $html .= '<li><details><summary>' . self::statusBadge((string) $row['status'])
                . '<span class="delivery-time">' . Time::format($row['received_at'], 'd.m. H:i:s') . '</span>'
                . '<span class="delivery-text">' . View::e(mb_strimwidth((string) preg_replace('/\s+/', ' ', (string) ($row['text'] ?? $row['note'] ?? '')), 0, 46, '…')) . '</span></summary>'
                . '<div class="delivery-detail">'
                . ($row['text'] !== null ? '<p><strong>' . t('Text:') . '</strong> ' . nl2br(View::e((string) $row['text'])) . ' <span class="muted">(' . View::e($rule) . ')</span></p>' : '')
                . ($row['note'] !== null ? '<p class="muted">' . View::e((string) $row['note']) . '</p>' : '')
                . ($row['message_id'] !== null ? '<p class="muted">' . t('Nachricht #{id}', ['id' => (int) $row['message_id']]) . '</p>' : '')
                . '<pre class="code">' . View::e((string) json_encode(json_decode((string) $row['payload'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>'
                . '</div></details></li>';
        }
        return $html . '</ul>';
    }
}
