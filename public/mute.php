<?php
declare(strict_types=1);

// Schnell-Mute (nur Admin): mit einem Klick stumm schalten oder beenden – zurück zur vorherigen Seite

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Csrf;
use Vesta\Time;
use Vesta\View;

Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    View::redirect('queue.php');
}
Csrf::verify();
$settings = App::settings();
$now = App::clock()->now();
// nur eigene Seiten als Rücksprung (kein Open Redirect)
$return = preg_match('/^[a-z_]+\.php$/', View::post('return')) === 1 ? View::post('return') : 'queue.php';

if (View::post('action') === 'stop') {
    $settings->stopMute();
    App::triggerArbiter(); // wartende Nachrichten sofort abarbeiten
    View::flash('success', t('Stummschaltung beendet – wartende Nachrichten kommen jetzt.'));
    View::redirect($return);
}

$choice = View::post('minutes');
$strict = !empty($_POST['strict']);
$until = match (true) {
    $choice === 'manual' => null,
    // eigene Dauer: 0,5 bis 48 Stunden
    $choice === 'custom' => ($hours = (float) str_replace(',', '.', View::post('hours'))) >= 0.5 && $hours <= 48
        ? $now->modify('+' . (int) round($hours * 60) . ' minutes')
        : false,
    in_array((int) $choice, [30, 60, 120, 240], true) => $now->modify('+' . (int) $choice . ' minutes'),
    default => false,
};
if ($until === false) {
    View::flash('error', t('Unbekannte Dauer.'));
    View::redirect($return);
}
$settings->startMute($until, $strict);
View::flash('success', $until === null
    ? t('Board ist stumm, bis du es beendest.')
    : t('Board ist stumm bis {time} Uhr.', ['time' => Time::local($until)->format(Time::local($until)->format('Y-m-d') === Time::local($now)->format('Y-m-d') ? 'H:i' : Vesta\I18n::dateFormat('d.m. H:i'))]));
View::redirect($return);
