<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Rendert ein Raster als HTML-Vorschau des Boards (Styles: public/assets/board.css).
 */
final class GridHtml
{
    private const COLOR_CLASSES = [
        63 => 'red', 64 => 'orange', 65 => 'yellow', 66 => 'green',
        67 => 'blue', 68 => 'violet', 69 => 'white', 70 => 'black', 71 => 'filled',
    ];

    public static function render(array $grid): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', Charset::gridToRawText($grid)));
        $label = $text === '' ? t('Board: leer') : t('Board zeigt: {text}', ['text' => $text]);
        $html = '<div class="vb-board" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE) . '"><div class="vb-frame">';
        foreach ($grid as $r => $row) {
            $html .= '<div class="vb-row">';
            foreach ($row as $c => $code) {
                $pos = ' data-r="' . (int) $r . '" data-c="' . (int) $c . '"';
                $code = (int) $code;
                if (isset(self::COLOR_CLASSES[$code])) {
                    $html .= '<span class="vb-cell vb-' . self::COLOR_CLASSES[$code] . '"' . $pos . '></span>';
                    continue;
                }
                $symbol = Charset::symbol($code) ?? '?';
                $class = $code === Charset::HEART ? 'vb-cell vb-heart' : 'vb-cell';
                $html .= '<span class="' . $class . '"' . $pos . '>' . htmlspecialchars(trim($symbol), ENT_QUOTES | ENT_SUBSTITUTE) . '</span>';
            }
            $html .= '</div>';
        }
        return $html . '</div></div>';
    }
}
