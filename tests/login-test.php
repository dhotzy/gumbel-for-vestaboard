<?php
declare(strict_types=1);

// Aufruf: php tests/login-test.php

require __DIR__ . '/support.php';

use Vesta\LoginThrottle;

section('Anmelde-Bremse');
$pdo = memoryDb();
$clock = new FixedClock('2026-10-29 12:00:00');
$throttle = new LoginThrottle($pdo);
$key = 'u:7|1.2.3.4';
$allowed = 0;
for ($i = 0; $i < 5; $i++) {
    $allowed += $throttle->hit($key, 5, 15, $clock->now()) ? 1 : 0;
}
check('5 Versuche erlaubt', $allowed === 5 && $throttle->lockedFor($key, $clock->now()) === 0);
check('6. Versuch sperrt', !$throttle->hit($key, 5, 15, $clock->now()) && $throttle->lockedFor($key, $clock->now()) === 15 * 60);
check('Andere IP / anderes Konto unberührt', $throttle->lockedFor('u:7|5.6.7.8', $clock->now()) === 0 && $throttle->lockedFor('u:8|1.2.3.4', $clock->now()) === 0);
$clock->advance(16 * 60);
check('Nach Ablauf wieder frei', $throttle->lockedFor($key, $clock->now()) === 0);
for ($i = 0; $i < 6; $i++) {
    $throttle->hit($key, 5, 15, $clock->now());
}
check('Zweite Sperre dauert doppelt so lang', $throttle->lockedFor($key, $clock->now()) === 30 * 60);
$throttle->reset($key);
check('Erfolgreiche Anmeldung setzt zurück', $throttle->lockedFor($key, $clock->now()) === 0 && $throttle->hit($key, 5, 15, $clock->now()));
for ($n = 0; $n < 12; $n++) {
    for ($i = 0; $i < 6; $i++) {
        $throttle->hit('u:9|9.9.9.9', 5, 15, $clock->now());
    }
    $clock->advance(2 * 86400);
}
for ($i = 0; $i < 6; $i++) {
    $throttle->hit('u:9|9.9.9.9', 5, 15, $clock->now());
}
check('Sperre wächst höchstens auf 24 Std', $throttle->lockedFor('u:9|9.9.9.9', $clock->now()) === 1440 * 60);
$throttle->hit('alt', 5, 15, $clock->now());
$clock->advance(3 * 86400);
$throttle->cleanup($clock->now());
check('Alte Einträge werden aufgeräumt', $pdo->query("SELECT COUNT(*) FROM login_throttle WHERE throttle_key = 'alt'")->fetchColumn() == 0);

finish();
