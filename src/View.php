<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Seitenrahmen, Navigation und Flash-Meldungen.
 */
final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE);
    }

    /** GET-Parameter als String ('' wenn fehlend oder manipuliert, z. B. als Array). */
    public static function query(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** POST-Parameter als String ('' wenn fehlend oder manipuliert). */
    public static function post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    /** Ergebnis einer Service-Aktion als Flash melden und zurückleiten. */
    public static function finish(array $result, string $success, string $url): never
    {
        $result['ok'] ? self::flash('success', $success) : self::flash('error', (string) $result['error']);
        self::redirect($url);
    }

    public static function header(string $title, ?array $user, string $active = ''): void
    {
        $nav = [];
        if ($user !== null) {
            $nav = ['compose' => ['index.php', t('Schreiben')], 'queue' => ['queue.php', t('Board')], 'history' => ['history.php', t('Archiv')]];
            if (MessageService::isAdmin($user)) {
                $nav['auto'] = ['automation.php', t('Automatik')];
                $nav['settings'] = ['settings.php', t('Einstellungen')];
            }
            if (!MessageService::isAdmin($user)) {
                $nav['queue'][1] = t('Warteschlange');
            }
        }
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        ?>
<!doctype html>
<html lang="<?= I18n::htmlLang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f6f5f1" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex">
    <title><?= self::e($title) ?> · Gumbel</title>
    <link rel="preload" href="assets/fonts/inter-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= self::asset('board.css') ?>">
    <link rel="stylesheet" href="<?= self::asset('app.css') ?>">
    <meta name="csrf" content="<?= Csrf::token() ?>">
    <script type="application/json" id="i18n-data"><?= json_encode(self::jsTexts(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="<?= self::asset('theme.js') ?>"></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php" aria-label="<?= self::e(t('Gumbel – Startseite')) ?>"><?php foreach (str_split('GUMBEL') as $i => $letter): ?><span class="brand-tile<?= $i === 0 ? ' accent' : '' ?>" aria-hidden="true"><?= $letter ?></span><?php endforeach; ?></a>
    <?php if ($user !== null): ?>
        <nav aria-label="<?= self::e(t('Hauptmenü')) ?>">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= $href ?>"<?= $key === $active ? ' class="active" aria-current="page"' : '' ?>><?= self::icon($key) ?><span><?= self::e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <div class="topbar-end">
        <?php if ($user !== null && MessageService::isAdmin($user)) { echo self::quickMute(); } ?>
        <button type="button" class="icon-button theme-toggle" data-theme-toggle title="<?= self::e(t('Darstellung: Auto')) ?>" aria-label="<?= self::e(t('Darstellung: Auto')) ?>"><?= self::icon('theme-auto') . self::icon('theme-light') . self::icon('theme-dark') ?></button>
        <?php if ($user !== null): ?>
            <details class="usermenu">
                <summary aria-label="<?= self::e(t('Konto von {name}', ['name' => $user['name']])) ?>"><span class="avatar" aria-hidden="true"><?= self::e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span></summary>
                <div class="usermenu-panel">
                    <p><?= self::e($user['name']) ?></p>
                    <a class="usermenu-link" href="hilfe.php"><?= self::e(t('Hilfe & Handbuch')) ?></a>
                    <form method="post" action="logout.php" data-busy>
                        <?= Csrf::field() ?>
                        <button type="submit"><?= self::e(t('Abmelden')) ?></button>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </div>
</header>
<?php if ($user !== null) {
    echo self::muteBanner();
}
if ($user !== null && MessageService::isAdmin($user) && !in_array($active, ['queue', 'help'], true)) {
    echo self::liveStrip();
} ?>
<main>
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= self::e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= self::e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($user !== null && MessageService::isAdmin($user) && App::$migrationError): ?>
        <div class="flash flash-error" role="alert"><?= self::e(t('Datenbank-Update fehlgeschlagen – neue Funktionen sind evtl. nicht verfügbar. Details im PHP-Fehlerlog.')) ?></div>
    <?php endif; ?>
    <?php if ($user !== null && !MessageService::isAdmin($user)) {
        echo self::replyBanners($user, in_array($active, ['compose', 'queue'], true) ? ($active === 'compose' ? 'index.php' : 'queue.php') : '');
    } ?>
<?php
    }

    /** Texte für public/assets/app.js (dort per tr('Deutsch') nachgeschlagen) */
    private static function jsTexts(): array
    {
        return [
            'Darstellung: Auto' => t('Darstellung: Auto'),
            'Speichert …' => t('Speichert …'),
            'Gespeichert ✓' => t('Gespeichert ✓'),
            'Nicht gespeichert – bitte „Speichern“ tippen.' => t('Nicht gespeichert – bitte „Speichern“ tippen.'),
            'Darstellung: Hell' => t('Darstellung: Hell'),
            'Darstellung: Dunkel' => t('Darstellung: Dunkel'),
            'Vorschau gerade nicht verfügbar.' => t('Vorschau gerade nicht verfügbar.'),
            'JA' => t('JA'),
            'NEIN' => t('NEIN'),
            'Aktuellen Text durch das Motiv ersetzen?' => t('Aktuellen Text durch das Motiv ersetzen?'),
            'Alle Farbkacheln aus dem Text entfernen?' => t('Alle Farbkacheln aus dem Text entfernen?'),
            'Name der Vorlage:' => t('Name der Vorlage:'),
            'Kopiert ✓' => t('Kopiert ✓'),
            'Bitte von Hand markieren und kopieren' => t('Bitte von Hand markieren und kopieren'),
            '{hours} Std {minutes} Min' => t('{hours} Std {minutes} Min'),
            '{minutes}:{seconds} Min' => t('{minutes}:{seconds} Min'),
        ];
    }

    /** Ungelesene Antworten des Admins als Hinweis oben auf der Seite */
    private static function replyBanners(array $user, string $back): string
    {
        try {
            $replies = App::messages()->unseenReplies((int) $user['id']);
        } catch (\Throwable $e) {
            error_log('Antworten: ' . $e->getMessage());
            return '';
        }
        $html = '';
        foreach ($replies as $m) {
            $html .= '<div class="flash flash-reply" role="status">'
                . '<p><strong>' . self::e(t('{name} hat geantwortet', ['name' => $m['reply_name'] ?? 'Admin'])) . '</strong> <span class="muted">' . self::e(t('auf „{text}“', ['text' => mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', (string) $m['text_raw'])), 0, 40, '…')])) . '</span></p>'
                . '<p class="reply-text">' . nl2br(self::e($m['reply_text'])) . '</p>'
                . '<form method="post" action="history.php" data-busy>' . Csrf::field()
                . '<input type="hidden" name="action" value="reply_seen"><input type="hidden" name="id" value="' . (int) $m['id'] . '">'
                . '<input type="hidden" name="back" value="' . self::e($back) . '"><button type="submit">' . self::e(t('Gelesen')) . '</button></form></div>';
        }
        return $html;
    }

    /** Symbolleiste für Herz und Farbkacheln (im Designer: Pinsel + Radierer) */
    public static function tokenBar(bool $designer, bool $toLastField = false): string
    {
        $html = '<div class="tokens" role="group" aria-label="' . self::e(t('Symbole einfügen')) . '">';
        $attribute = $toLastField ? 'data-insert-last' : 'data-insert'; // Webhooks: ins zuletzt benutzte Vorlagen-Feld
        foreach (['heart' => ['♥', t('Herz')], 'red' => ['', t('Rot')], 'orange' => ['', t('Orange')], 'yellow' => ['', t('Gelb')], 'green' => ['', t('Grün')], 'blue' => ['', t('Blau')], 'violet' => ['', t('Lila')], 'white' => ['', t('Weiß')], 'black' => ['', t('Schwarz')]] as $token => [$symbol, $name]) {
            $html .= '<button type="button" class="token token-' . $token . '" ' . $attribute . '="{' . $token . '}" title="' . self::e($name) . '" aria-label="' . self::e($name) . '">' . $symbol . '</button>';
        }
        if ($toLastField) {
            return $html . '</div>';
        }
        return $html . '<button type="button" class="token token-erase" data-insert=" " title="' . self::e(t('Radierer (Kachel leeren)')) . '" aria-label="' . self::e(t('Radierer')) . '" data-designer-only' . ($designer ? '' : ' hidden') . '>⌫</button>'
            . '<button type="button" class="strip-colors" data-strip-colors>' . self::e(t('Alle Farben entfernen')) . '</button></div>';
    }

    /** Asset-URL mit Änderungszeit, damit Browser nach einem Deploy die neue Datei laden */
    private static function asset(string $file): string
    {
        $path = APP_ROOT . '/public/assets/' . $file;
        return 'assets/' . $file . (is_file($path) ? '?v=' . filemtime($path) : '');
    }

    /** Inline-SVG-Icons für die Navigation (Feather-Stil, currentColor). */
    private static function icon(string $key): string
    {
        $paths = [
            'compose' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'queue' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M6 10h2M11 10h2M16 10h2M6 14h2M11 14h2M16 14h2"/>',
            'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
            'settings' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
            'auto' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6M22 6l-3-3"/>',
            'theme-auto' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
            'theme-light' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'theme-dark' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
            'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
            'mute' => '<path d="M13.7 21a2 2 0 0 1-3.4 0"/><path d="M18.6 13A17.9 17.9 0 0 1 18 8M6.3 6.3A5.9 5.9 0 0 0 6 8c0 7-3 9-3 9h14M18 8a6 6 0 0 0-9.3-5M1 1l22 22"/>',
            'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$key] ?? '') . '</svg>';
    }

    /** Ende der Stummschaltung: heute nur die Uhrzeit, sonst mit Datum */
    private static function muteTime(\DateTimeImmutable $until): string
    {
        $local = Time::local($until);
        return $local->format('Y-m-d') === Time::local(App::clock()->now())->format('Y-m-d') ? $local->format('H:i') : $local->format(I18n::dateFormat('d.m. H:i'));
    }

    /** Hinweis für alle, solange das Board von Hand stummgeschaltet ist */
    public static function muteBanner(): string
    {
        try {
            $mute = App::settings()->manualMute(App::clock()->now());
        } catch (\Throwable $e) {
            return '';
        }
        if (!$mute['active']) {
            return '';
        }
        $text = $mute['until'] !== null
            ? t('Board ist stumm bis {time} Uhr – neue Nachrichten warten so lange.', ['time' => self::muteTime($mute['until'])])
            : t('Board ist stumm, bis die Stummschaltung beendet wird – neue Nachrichten warten so lange.');
        return '<p class="mute-banner">' . self::icon('mute') . '<span>' . self::e($text) . ($mute['strict'] ? ' <strong>' . self::e(t('Komplett still')) . '</strong>' : '') . '</span></p>';
    }

    /** Admin: Glocke in der Kopfzeile – mit einem Klick stumm schalten oder beenden */
    public static function quickMute(): string
    {
        try {
            $mute = App::settings()->manualMute(App::clock()->now());
        } catch (\Throwable $e) {
            return '';
        }
        $return = self::e(basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? 'queue.php'), PHP_URL_PATH)) ?: 'queue.php');
        $html = '<details class="usermenu quickmute' . ($mute['active'] ? ' is-muted' : '') . '">'
            . '<summary class="icon-button" title="' . self::e($mute['active'] ? t('Stumm – beenden') : t('Schnell stumm schalten')) . '" aria-label="' . self::e($mute['active'] ? t('Stumm – beenden') : t('Schnell stumm schalten')) . '">' . self::icon($mute['active'] ? 'mute' : 'bell') . '</summary>'
            . '<div class="usermenu-panel"><form method="post" action="mute.php" data-busy>' . Csrf::field() . '<input type="hidden" name="return" value="' . $return . '">';
        if ($mute['active']) {
            $html .= '<p>' . self::e($mute['until'] !== null ? t('Stumm bis {time} Uhr', ['time' => self::muteTime($mute['until'])]) : t('Stumm, bis du es beendest'))
                . ($mute['strict'] ? ' · ' . self::e(t('Komplett still')) : '') . '</p>'
                . '<button type="submit" name="action" value="stop" class="primary">' . self::e(t('Stummschaltung beenden')) . '</button>';
        } else {
            $html .= '<p>' . self::e(t('Board stumm schalten für …')) . '</p><div class="quickmute-grid">';
            foreach ([30 => t('30 Min'), 60 => t('1 Std'), 120 => t('2 Std'), 240 => t('4 Std')] as $minutes => $label) {
                $html .= '<button type="submit" name="minutes" value="' . $minutes . '">' . self::e($label) . '</button>';
            }
            $html .= '<span class="quickmute-custom"><input type="number" name="hours" min="0.5" max="48" step="0.5" value="8" inputmode="decimal" aria-label="' . self::e(t('Stunden')) . '">'
                . '<button type="submit" name="minutes" value="custom">' . self::e(t('Std. stumm')) . '</button></span>'
                . '<button type="submit" name="minutes" value="manual" class="quickmute-wide">' . self::e(t('bis ich es beende')) . '</button></div>'
                . '<label class="quickmute-strict"><input type="checkbox" name="strict" value="1"> <span>' . self::e(t('Komplett still')) . '<span class="muted">' . self::e(t('auch Force wartet')) . '</span></span></label>'
                . '<input type="hidden" name="action" value="start">';
        }
        return $html . '</form></div></details>';
    }

    /** Admin: aktuelle Board-Anzeige über jeder Seite – Board mittig, darunter klein „Aktuell: Quelle · seit …“ */
    public static function liveStrip(): string
    {
        try {
            $current = App::messages()->current();
            $live = (array) App::settings()->get('board_live');
        } catch (\Throwable $e) {
            error_log('Live-Leiste: ' . $e->getMessage());
            return '';
        }
        $grid = $current !== null ? json_decode((string) $current['grid'], true) : ($live['grid'] ?? null);
        if (!is_array($grid)) {
            return '<a class="live" href="queue.php"><span class="live-info">' . self::e(t('Noch kein Board-Stand bekannt.')) . '</span></a>';
        }
        $info = [];
        if ($current !== null) {
            $label = Sources::label((string) $current['source']);
            $info[] = $label . (!empty($current['user_name']) && $current['user_name'] !== $label ? ' · ' . $current['user_name'] : '');
            $info[] = t('seit {time} Uhr', ['time' => Time::format($current['shown_at'], 'H:i')]);
        }
        return '<a class="live" href="queue.php" title="' . self::e(t('Zur Board-Seite')) . '">'
            . '<span class="live-board">' . GridHtml::render($grid) . '</span>'
            . ($info !== [] ? '<span class="live-info"><strong>' . self::e(t('Aktuell:')) . '</strong> ' . self::e(implode(' · ', $info)) . '</span>' : '')
            . '</a>';
    }

    public static function footer(): void
    {
        ?>
</main>
<script src="<?= self::asset('app.js') ?>"></script>
</body>
</html>
<?php
    }

    /** Farbton für den Status-Badge */
    public static function statusTone(string $status): string
    {
        return match ($status) {
            'showing', 'shown' => 'ok',
            'queued' => 'wait',
            'failed' => 'error',
            default => '',
        };
    }

    public static function statusLabel(string $status): string
    {
        return [
            'queued' => t('wartet'),
            'showing' => t('läuft'),
            'shown' => t('angezeigt'),
            'expired' => t('abgelaufen'),
            'deleted' => t('gelöscht'),
            'failed' => t('fehlgeschlagen'),
        ][$status] ?? $status;
    }
}
