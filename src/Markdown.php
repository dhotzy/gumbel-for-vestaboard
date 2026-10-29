<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Kleiner Markdown-Renderer für die Hilfe-Seite (docs/*.md → HTML im App-Stil).
 * Unterstützt genau das, was die Doku nutzt: Überschriften, Absätze, Listen (auch eine Ebene eingerückt),
 * Tabellen, Code-Blöcke, Zitate, Trennlinien, **fett**, *kursiv*, `code`, Links, Schaubilder (![…](…/public/assets/docs/x.svg)). HTML im Text wird immer maskiert.
 */
final class Markdown
{
    /** @param callable(string): string $linkMap formt Link-Ziele um (z. B. INTEGRATIONEN.md → hilfe.php?doc=…) */
    public function __construct(private $linkMap = null)
    {
        $this->linkMap ??= static fn (string $href): string => $href;
    }

    /**
     * Dokument in Abschnitte zerlegen: Einleitung (vor der ersten ##) und je ## ein Abschnitt.
     * @return array{title: string, intro: string, sections: list<array{id: string, title: string, html: string}>}
     */
    public function document(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $title = '';
        $parts = [['title' => null, 'lines' => []]];
        $inCode = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '```')) {
                $inCode = !$inCode;
            }
            if (!$inCode && preg_match('/^# (.+)$/', $line, $m) && $title === '') {
                $title = trim($m[1]);
                continue;
            }
            if (!$inCode && preg_match('/^## (.+)$/', $line, $m)) {
                $parts[] = ['title' => trim($m[1]), 'lines' => []];
                continue;
            }
            $parts[array_key_last($parts)]['lines'][] = $line;
        }
        $intro = array_shift($parts);
        $sections = [];
        foreach ($parts as $part) {
            $sections[] = ['id' => self::slug($part['title']), 'title' => $part['title'], 'html' => $this->blocks($part['lines'])];
        }
        return ['title' => $title, 'intro' => $this->blocks($intro['lines']), 'sections' => $sections];
    }

    /** Anker wie bei GitHub: klein, Satzzeichen weg, Leerzeichen → Bindestrich (Umlaute bleiben) */
    public static function slug(string $title): string
    {
        $slug = mb_strtolower(trim($title));
        $slug = (string) preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $slug);
        return (string) preg_replace('/\s/u', '-', $slug);
    }

    /** @param list<string> $lines */
    public function blocks(array $lines): string
    {
        $html = '';
        $count = count($lines);
        for ($i = 0; $i < $count;) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            if (str_starts_with($line, '```')) { // Code-Block
                $language = trim(substr($line, 3));
                $code = [];
                for ($i++; $i < $count && !str_starts_with($lines[$i], '```'); $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $html .= '<pre class="code"' . ($language !== '' ? ' data-lang="' . View::e($language) . '"' : '') . '><code>' . View::e(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (preg_match('/^(#{3,6}) (.+)$/', $line, $m)) {
                $level = min(4, strlen($m[1]));
                $html .= '<h' . $level . ' id="' . View::e(self::slug($m[2])) . '">' . $this->inline($m[2]) . '</h' . $level . '>';
                $i++;
                continue;
            }
            if (preg_match('/^-{3,}$/', trim($line))) {
                $html .= '<hr>';
                $i++;
                continue;
            }
            if (str_starts_with($line, '>')) { // Zitat → Hinweis-Kasten
                $quote = [];
                for (; $i < $count && str_starts_with($lines[$i], '>'); $i++) {
                    $quote[] = ltrim(substr($lines[$i], 1));
                }
                $html .= '<aside class="doc-note">' . $this->blocks($quote) . '</aside>';
                continue;
            }
            if (str_starts_with(ltrim($line), '|')) { // Tabelle
                $rows = [];
                for (; $i < $count && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
                    $rows[] = $lines[$i];
                }
                $html .= $this->table($rows);
                continue;
            }
            if (preg_match('/^(\s*)([-*]|\d+\.) /', $line)) { // Liste
                $items = [];
                for (; $i < $count && (preg_match('/^\s*([-*]|\d+\.) /', $lines[$i]) || (trim($lines[$i]) !== '' && preg_match('/^\s{2,}\S/', $lines[$i]))); $i++) {
                    $items[] = $lines[$i];
                }
                $html .= $this->listHtml($items);
                continue;
            }
            $paragraph = [];
            for (; $i < $count && trim($lines[$i]) !== '' && !preg_match('/^(```|#{2,6} |>|\s*\||\s*([-*]|\d+\.) |-{3,}$)/', $lines[$i]); $i++) {
                $paragraph[] = trim($lines[$i]);
            }
            if ($paragraph === []) { // unbekannte Zeile – nicht hängen bleiben
                $paragraph[] = trim($lines[$i]);
                $i++;
            }
            $html .= '<p>' . $this->inline(implode(' ', $paragraph)) . '</p>';
        }
        return $html;
    }

    /** @param list<string> $items Zeilen einer Liste; eingerückte Einträge werden zu einer Unterliste */
    private function listHtml(array $items): string
    {
        $ordered = (bool) preg_match('/^\s*\d+\. /', $items[0]);
        $html = $ordered ? '<ol>' : '<ul>';
        $current = null;
        $children = [];
        $flush = function () use (&$html, &$current, &$children): void {
            if ($current === null) {
                return;
            }
            $html .= '<li>' . $this->inline($current) . ($children !== [] ? $this->listHtml($children) : '') . '</li>';
            $current = null;
            $children = [];
        };
        foreach ($items as $item) {
            if (preg_match('/^\S/', $item) && preg_match('/^([-*]|\d+\.) (.*)$/', $item, $m)) {
                $flush();
                $current = $m[2];
            } elseif (preg_match('/^\s+([-*]|\d+\.) /', $item)) {
                $children[] = ltrim($item);
            } elseif ($current !== null) {
                $current .= ' ' . trim($item); // Fortsetzungszeile
            }
        }
        $flush();
        return $html . ($ordered ? '</ol>' : '</ul>');
    }

    /** @param list<string> $rows */
    private function table(array $rows): string
    {
        // Zellen an | trennen – aber nicht innerhalb von `code` und nicht bei \|
        $cells = static function (string $row): array {
            $row = trim(trim($row), '|');
            $parts = [''];
            $inCode = false;
            $length = strlen($row);
            for ($i = 0; $i < $length; $i++) {
                $char = $row[$i];
                if ($char === '\\' && ($row[$i + 1] ?? '') === '|') {
                    $parts[array_key_last($parts)] .= '|';
                    $i++;
                    continue;
                }
                if ($char === '`') {
                    $inCode = !$inCode;
                }
                if ($char === '|' && !$inCode) {
                    $parts[] = '';
                    continue;
                }
                $parts[array_key_last($parts)] .= $char;
            }
            return array_map('trim', $parts);
        };
        $head = $cells(array_shift($rows));
        if ($rows !== [] && preg_match('/^\s*\|?\s*:?-{2,}/', $rows[0])) {
            array_shift($rows); // Trennzeile
        }
        $html = '<div class="doc-table"><table><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($cells($row) as $cell) {
                $html .= '<td>' . $this->inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** Inline: `code` zuerst schützen, dann maskieren, dann Links, fett, kursiv */
    public function inline(string $text): string
    {
        $codes = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . View::e($m[1]) . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);
        $text = View::e($text);
        // Schaubilder: nur SVGs aus public/assets/docs/ (auf GitHub per relativem Pfad, in der App unter assets/docs/)
        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $m): string {
            if (!preg_match('#(?:^|/)public/assets/docs/([a-z0-9.\-]+\.svg)$#', html_entity_decode($m[2], ENT_QUOTES), $file)) {
                return $m[1];
            }
            return '<img class="doc-figure" src="assets/docs/' . $file[1] . '" alt="' . $m[1] . '" loading="lazy">';
        }, $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $m): string {
            $href = ($this->linkMap)(html_entity_decode($m[2], ENT_QUOTES));
            // nur http(s), eigene .php-Seiten (ohne //host) und Anker – kein javascript:, kein protokoll-relatives //evil
            $href = preg_match('#^(https?://|[a-z0-9_-][a-z0-9_.-]*\.php(?:[?\#].*)?$|\#)#i', $href) === 1 ? $href : '#';
            $external = preg_match('#^https?://#', $href) === 1;
            return '<a href="' . View::e($href) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
        }, $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $text);
        return (string) preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $codes[(int) $m[1]], $text);
    }
}
