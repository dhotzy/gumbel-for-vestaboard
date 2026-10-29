<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Darstellung einer Nachricht als Karte (Board-Vorschau + Metadaten).
 */
final class MessageView
{
    /** @param list<string> $badges zusätzliche Hinweise */
    /** Platzhalter für fremde Nachrichten (Nicht-Admins sehen weder Inhalt noch Autor) */
    public static function otherCard(array $badges = []): string
    {
        $html = '<article class="msg msg-other"><div class="msg-meta"><strong>' . View::e(t('Andere Nachricht')) . '</strong>';
        return $html . self::badges($badges) . '</div></article>';
    }

    public static function card(array $message, array $badges = [], string $actions = '', string $select = ''): string
    {
        $grid = json_decode((string) $message['grid'], true);
        $author = $message['user_name'] ?? null;
        $meta = [Sources::label((string) $message['source'])];
        if ($author !== null && $author !== '' && $author !== Sources::label((string) $message['source'])) {
            $meta[] = $author;
        }
        if ((int) $message['forced'] === 1) {
            $badges[] = t('Force');
        }
        if ((int) $message['jump'] === 1) {
            $badges[] = t('vorne');
        }
        if (!empty($message['last_error'])) {
            $badges[] = [t('Fehler: {error}', ['error' => $message['last_error']]), 'error'];
        }

        $html = '<article class="msg">';
        $html .= $select;
        $html .= '<div class="msg-board">' . (is_array($grid) ? GridHtml::render($grid) : '') . '</div>';
        $html .= '<div class="msg-meta"><strong>' . View::e(implode(' · ', $meta)) . '</strong>';
        if (!empty($message['created_at'])) {
            $html .= '<span class="muted">' . View::e(t('erstellt {time}', ['time' => Time::format($message['created_at'])])) . '</span>';
        }
        $html .= self::badges($badges);
        if (!empty($message['reply_text'])) {
            $html .= self::replyBlock($message);
        }
        $html .= '</div>';
        if ($actions !== '') {
            $html .= '<div class="msg-actions">' . $actions . '</div>';
        }
        return $html . '</article>';
    }

    /** Antwort mit Lesestatus („gelesen“ setzt der Nutzer über den Hinweis in der App) */
    public static function replyBlock(array $message): string
    {
        $seen = !empty($message['reply_seen_at'])
            ? '<span class="reply-seen is-seen">' . View::e(t('✓ gelesen {time}', ['time' => Time::format($message['reply_seen_at'], 'd.m. H:i')])) . '</span>'
            : '<span class="reply-seen">' . View::e(t('noch nicht gelesen')) . '</span>';
        return '<div class="reply"><strong>' . View::e(!empty($message['reply_name']) ? t('Antwort von {name}', ['name' => $message['reply_name']]) : t('Antwort'))
            . (!empty($message['reply_at']) ? ' · ' . Time::format($message['reply_at'], 'd.m. H:i') : '') . '</strong>'
            . '<span>' . nl2br(View::e($message['reply_text'])) . '</span>' . $seen . '</div>';
    }

    /** Badges als Text oder [Text, Ton] mit Ton ok | wait | error */
    private static function badges(array $badges): string
    {
        $html = '';
        foreach ($badges as $badge) {
            [$text, $tone] = is_array($badge) ? $badge + [1 => ''] : [$badge, ''];
            $html .= '<span class="badge' . ($tone !== '' ? ' badge-' . View::e($tone) : '') . '">' . View::e($text) . '</span>';
        }
        return $html;
    }

    /** Admin: Antwort-Formular (aufklappbar) für die Nachricht eines Nutzers */
    public static function replyForm(string $url, array $message, string $back = ''): string
    {
        return '<details class="reply-form"><summary class="button">' . View::e(!empty($message['reply_text']) ? t('Antwort ändern') : t('Antworten')) . '</summary>'
            . '<form method="post" action="' . View::e($url) . '" data-busy>' . Csrf::field()
            . '<input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="' . (int) $message['id'] . '">'
            . ($back !== '' ? '<input type="hidden" name="back" value="' . View::e($back) . '">' : '')
            . '<label>' . View::e(t('Antwort an {name}', ['name' => $message['user_name'] ?? ''])) . ' <span class="muted">' . View::e(t('(erscheint nur in der App, nicht auf dem Board)')) . '</span>'
            . '<textarea name="reply" rows="2" maxlength="' . MessageService::MAX_REPLY_LENGTH . '" class="plain">' . View::e($message['reply_text'] ?? '') . '</textarea></label>'
            . '<button type="submit" class="primary">' . View::e(t('Antwort senden')) . '</button></form></details>';
    }

    public static function action(string $url, string $action, int $id, string $label, string $confirm = '', string $class = ''): string
    {
        return '<form method="post" action="' . View::e($url) . '" data-busy'
            . ($confirm !== '' ? ' data-confirm="' . View::e($confirm) . '"' : '') . '>'
            . Csrf::field()
            . '<input type="hidden" name="action" value="' . View::e($action) . '">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="' . View::e($class) . '">' . View::e($label) . '</button></form>';
    }
}
