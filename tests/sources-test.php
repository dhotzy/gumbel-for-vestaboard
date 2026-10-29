<?php
declare(strict_types=1);

// Aufruf: php tests/sources-test.php

require __DIR__ . '/support.php';

use Vesta\Inbound;
use Vesta\Settings;
use Vesta\Sources;

section('Eigene Quellen');
$pdo = memoryDb();
$settings = new Settings($pdo);
check('Ohne eigene Quellen nur eingebaute', Sources::custom() === [] && Sources::exists('adults'));
$settings->set('custom_sources', [['id' => 'c_buchungen', 'label' => 'Buchungen', 'duration' => 7], ['id' => 'Böse ID', 'label' => 'x'], ['id' => 'admin', 'label' => 'Kollision']]);
check('Gültige eigene Quelle registriert, ungültige/kollidierende ignoriert', array_keys(Sources::custom()) === ['c_buchungen']);
check('Label und Dauer', Sources::label('c_buchungen') === 'Buchungen' && $settings->minDurationSeconds('c_buchungen') === 7 * 60);
check('Steht in der Rangfolge', in_array('c_buchungen', $settings->sourceOrder(), true));
check('Bei Webhooks und Abrufen wählbar', in_array('c_buchungen', Inbound::modules()['webhooks']['sources'], true) && in_array('c_buchungen', Inbound::modules()['abruf']['sources'], true));
check('ID aus Bezeichnung, eindeutig', Sources::customId('Home Assistant!', []) === 'c_home_assistant' && Sources::customId('Buchungen', ['c_buchungen']) === 'c_buchungen_2');
check('Umlaute in der ID werden umschrieben', str_starts_with(Sources::customId('Küche', []), 'c_k'));
check('Abruf-Integration gehört zum Modul abruf, auch mit eigener Quelle', Inbound::moduleFor(['source' => 'c_buchungen', 'pull_url' => 'https://x.y']) === 'abruf');

finish();
