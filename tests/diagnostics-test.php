<?php
declare(strict_types=1);

// Aufruf: php tests/diagnostics-test.php – Erkennung ungepatchter PHP-Versionen (PHP2Shell, Oktober 2026)

require __DIR__ . '/support.php';

use Vesta\Diagnostics;

section('PHP-Sicherheitsstand');
check('8.4.27 ist gepatcht', Diagnostics::phpPatched('8.4.27'));
check('8.4.26 ist nicht gepatcht', !Diagnostics::phpPatched('8.4.26'));
check('8.2.35 / 8.3.36 / 8.5.12 sind gepatcht', Diagnostics::phpPatched('8.2.35') && Diagnostics::phpPatched('8.3.36') && Diagnostics::phpPatched('8.5.12'));
check('8.3.30 und 8.5.11 nicht', !Diagnostics::phpPatched('8.3.30') && !Diagnostics::phpPatched('8.5.11'));
check('8.1 und älter nie', !Diagnostics::phpPatched('8.1.99') && !Diagnostics::phpPatched('7.4.33'));
check('Neuere Zweige gelten als gepatcht', Diagnostics::phpPatched('8.6.0') && Diagnostics::phpPatched('9.0.0'));
check('Zusätze wie -dev werden verstanden', Diagnostics::phpPatched('8.4.27-dev') && !Diagnostics::phpPatched('8.4.3RC1'));
check('Mindestversion passend zum Zweig', Diagnostics::minimumPatch('8.3.10') === '8.3.36' && Diagnostics::minimumPatch('8.1.2') === '8.4.27');

finish();
