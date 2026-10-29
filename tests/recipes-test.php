<?php
declare(strict_types=1);

// Aufruf: php tests/recipes-test.php – mitgelieferte Rezepte (recipes/*.json)

require __DIR__ . '/support.php';

use Vesta\I18n;
use Vesta\Inbound;
use Vesta\MessageRepo;
use Vesta\RecipeBook;
use Vesta\WebhookTemplate;

$book = new RecipeBook();
$files = glob(APP_ROOT . '/recipes/*.json') ?: [];

section('Alle Rezepte');
check('Es gibt Rezepte', count($files) >= 10);
check('Jede Datei ist ein gültiges Rezept', count($book->all()) === count($files));
foreach (['de', 'en'] as $lang) {
    I18n::set($lang);
    foreach ($book->all() as $entry) {
        $recipe = $entry['recipe'];
        $ok = $entry['name'] !== '' && $entry['description'] !== '' && isset(Inbound::MODULES[$recipe['module']])
            && in_array($recipe['source'], Inbound::MODULES[$recipe['module']]['sources'], true)
            && is_string($recipe['template'])
            && count(WebhookTemplate::parseRules($recipe['rules'] ?? [])) === count($recipe['rules'] ?? [])
            && ($recipe['module'] !== 'abruf' || str_starts_with((string) $recipe['pull_url'], 'https://'));
        check("[$lang] {$entry['id']}: vollständig", $ok);
    }
}
I18n::set('de');
check('Sprache wird gewählt', str_contains((string) $book->load('uptime-kuma')['recipe']['template'], 'WIEDER DA'));
I18n::set('en');
check('… auch Englisch', str_contains((string) $book->load('uptime-kuma')['recipe']['template'], 'BACK UP'));
check('Unsinnige ID → nichts', $book->load('../config/config') === null && $book->load('gibtsnicht') === null);

section('Hinzufügen');
$pdo = memoryDb();
$messages = new MessageRepo($pdo);
$inbound = new Inbound($pdo, $messages, new FixedClock('2026-10-29 12:00:00'));
$result = $inbound->import($book->load('uptime-kuma')['recipe']);
$hook = $inbound->find((int) $result['id']);
check('Webhook-Rezept wird im Testmodus angelegt', $result['ok'] && (int) $hook['test_mode'] === 1 && (int) $hook['active'] === 1);
$inbound->update((int) $result['id'], ['test_mode' => 0]);
$down = $inbound->receive((string) $result['key'], ['heartbeat' => ['status' => 0], 'monitor' => ['name' => 'Website']]);
check('Bedingung greift: Ausfall rot und Force', $down['ok'] && str_contains((string) $messages->find((int) $down['id'])['text_raw'], 'DOWN') && (int) $messages->find((int) $down['id'])['forced'] === 1);
$football = $inbound->import($book->load('football-next-match')['recipe']);
check('Header-Namen aus dem Rezept, Wert als Platzhalter', Vesta\Feed::storedHeaders($inbound->find((int) $football['id'])) === [['X-Auth-Token', 'YOUR-KEY']]);

finish();
