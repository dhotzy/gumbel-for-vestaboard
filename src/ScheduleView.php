<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Formular-Baustein für Zeitpläne aus Zyklen (Tage + Uhrzeit bzw. Tage + von/bis) – Automatik und Integrationen.
 * JS (app.js, [data-slots]) ergänzt „+ Weiterer Zyklus“ und Entfernen.
 */
final class ScheduleView
{
    /** Liste von Zyklen; $min = so viele bleiben mindestens stehen (0 = alle entfernbar) */
    public static function field(string $name, array $slots, string $kind, string $legend, int $min = 1, ?string $addLabel = null): string
    {
        $addLabel ??= t('+ Weiterer Zyklus');
        $html = '<fieldset class="slots" data-slots data-slots-min="' . $min . '"><legend>' . $legend . '</legend><div class="slot-list" data-slot-list>';
        foreach (array_values($slots) as $i => $slot) {
            $html .= self::row($name, (string) $i, $slot, $kind);
        }
        $blank = $kind === 'times' ? ['days' => [], 'time' => ''] : ['days' => [], 'from' => '', 'to' => ''] + ($kind === 'intervals' ? ['interval' => 60] : []);
        return $html . '</div><template data-slot-template>' . self::row($name, '__i__', $blank, $kind) . '</template>'
            . '<button type="button" class="slot-add" data-slot-add>' . View::e($addLabel) . '</button></fieldset>';
    }

    /** Ein Zyklus: Tage + Uhrzeit (times) bzw. Tage + von/bis (windows) */
    public static function row(string $name, string $index, array $slot, string $kind): string
    {
        $field = $name . '[' . $index . ']';
        $html = '<div class="slot" data-slot><div class="slot-head"><span class="slot-no" data-label="' . View::e(t('Zyklus')) . '"></span>'
            . '<button type="button" class="slot-remove" data-slot-remove aria-label="' . View::e(t('Zyklus entfernen')) . '" title="' . View::e(t('Entfernen')) . '">×</button></div>'
            . '<span class="days">';
        foreach (Schedule::ALL_DAYS as $day) {
            $label = Schedule::dayLabel($day);
            $checked = in_array($day, Schedule::days($slot['days'] ?? []), true) ? ' checked' : '';
            $html .= '<label><input type="checkbox" name="' . $field . '[days][]" value="' . $day . '"' . $checked . '> ' . $label . '</label>';
        }
        $html .= '</span><div class="filters">';
        if ($kind === 'times') {
            $html .= '<label>' . t('um') . ' <input type="time" name="' . $field . '[time]" value="' . View::e($slot['time'] ?? '') . '"></label>';
        } else {
            $html .= '<label>' . t('von') . ' <input type="time" name="' . $field . '[from]" value="' . View::e($slot['from'] ?? '') . '"></label>'
                . '<label>' . t('bis') . ' <input type="time" name="' . $field . '[to]" value="' . View::e($slot['to'] ?? '') . '"></label>';
            if ($kind === 'intervals') {
                $minutes = (int) ($slot['interval'] ?? 60);
                $hours = '';
                for ($h = 0; $h <= 24; $h++) {
                    $hours .= '<option value="' . $h . '"' . (intdiv($minutes, 60) === $h ? ' selected' : '') . '>' . $h . '</option>';
                }
                $mins = '';
                for ($m = 0; $m < 60; $m += 5) {
                    $mins .= '<option value="' . $m . '"' . ($minutes % 60 === $m ? ' selected' : '') . '>' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . '</option>';
                }
                $html .= '<span class="every"><span class="every-label">' . tc('Intervall', 'alle') . '</span>'
                    . '<select name="' . $field . '[every_h]" aria-label="' . View::e(t('Stunden')) . '">' . $hours . '</select><span>' . t('Std') . '</span>'
                    . '<select name="' . $field . '[every_m]" aria-label="' . View::e(t('Minuten')) . '">' . $mins . '</select><span>' . t('Min') . '</span></span>';
            }
        }
        return $html . '</div></div>';
    }
}
