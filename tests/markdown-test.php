<?php
declare(strict_types=1);

// Aufruf: php tests/markdown-test.php

require __DIR__ . '/support.php';

use Vesta\Markdown;

$md = new Markdown(static fn (string $href): string => $href === 'X.md' ? 'hilfe.php?doc=x' : $href);

section('Blöcke');
$doc = $md->document("# Titel\n\nEinleitung **fett**.\n\n## 1. Erster Teil\n\nText *kursiv* mit `code`.\n\n## Zweiter: Teil / B\n\n- a\n  - a1\n- b\n");
check('Titel und Einleitung', $doc['title'] === 'Titel' && str_contains($doc['intro'], '<strong>fett</strong>'));
check('Abschnitte mit GitHub-Ankern', $doc['sections'][0]['id'] === '1-erster-teil' && $doc['sections'][1]['id'] === 'zweiter-teil--b');
check('Kursiv und Code', str_contains($doc['sections'][0]['html'], '<em>kursiv</em>') && str_contains($doc['sections'][0]['html'], '<code>code</code>'));
check('Unterliste', str_contains($doc['sections'][1]['html'], '<li>a<ul><li>a1</li></ul></li>'));
check('Umlaute im Anker bleiben', Markdown::slug('9. Sicherheit: API-Schlüssel') === '9-sicherheit-api-schlüssel');

section('Tabellen');
$table = $md->blocks(['| Filter | Beispiel |', '|---|---|', '| Zahl | `{{name|8}}` |', '| Escape | a \| b |']);
check('| in Code trennt keine Spalte', str_contains($table, '<td><code>{{name|8}}</code></td>'));
check('\| ist ein Zeichen', str_contains($table, '<td>a | b</td>'));
check('Kopfzeile', substr_count($table, '<th>') === 2);

section('Sicherheit');
check('HTML wird maskiert', str_contains($md->inline('<script>alert(1)</script>'), '&lt;script&gt;'));
check('javascript:-Links werden entschärft', str_contains($md->inline('[x](javascript:alert(1))'), 'href="#"'));
check('Doku-Links werden umgeschrieben', str_contains($md->inline('[Doku](X.md)'), 'href="hilfe.php?doc=x"'));
check('Externe Links in neuem Tab', str_contains($md->inline('[GH](https://github.com)'), 'target="_blank"'));
check('Code-Block maskiert', str_contains($md->blocks(['```html', '<b>x</b>', '```']), '&lt;b&gt;x&lt;/b&gt;'));

section('Echte Doku');
foreach (['HANDBUCH.md', 'INTEGRATIONEN.md'] as $file) {
    $real = $md->document((string) file_get_contents(__DIR__ . '/../docs/' . $file));
    check($file . ': hat Titel und Abschnitte', $real['title'] !== '' && count($real['sections']) >= 8);
}


section('Schaubilder');
$md = new Vesta\Markdown();
$img = $md->inline('![Ablauf](../public/assets/docs/queue.de.svg)');
check('SVG aus assets/docs wird Bild', str_contains($img, '<img class="doc-figure" src="assets/docs/queue.de.svg" alt="Ablauf"'));
check('Fremde Bildquelle → nur Alt-Text', !str_contains($md->inline('![x](https://evil.example/a.svg)'), '<img'));
check('Kein Ausbruch über Pfad oder Endung', !str_contains($md->inline('![x](../public/assets/docs/../../config/x.svg)'), '<img') && !str_contains($md->inline('![x](../public/assets/docs/a.svg"onerror=1)'), 'onerror'));

finish();
