<?php
declare(strict_types=1);

// Hilfe: Handbuch und Integrations-Doku aus docs/*.md, im App-Stil dargestellt.
// Die Markdown-Dateien bleiben die einzige Quelle – diese Seite ist immer so aktuell wie sie.
// Englisch: docs/en/HANDBOOK.md bzw. docs/en/INTEGRATIONS.md, fehlt eine, wird die deutsche Fassung gezeigt.

require __DIR__ . '/../src/web.php';

use Vesta\Auth;
use Vesta\I18n;
use Vesta\Markdown;
use Vesta\MessageService;
use Vesta\View;

$user = Auth::requireUser();
$isAdmin = MessageService::isAdmin($user);

// [deutsche Datei, englische Datei (docs/en/), Bezeichnung]
$docs = ['handbuch' => ['HANDBUCH.md', 'HANDBOOK.md', t('Handbuch')]];
if ($isAdmin) {
    $docs['integrationen'] = ['INTEGRATIONEN.md', 'INTEGRATIONS.md', t('Integrationen')]; // betrifft nur Admins
    $docs['installation'] = ['INSTALLATION.md', 'INSTALL.md', t('Installation')];
}
$doc = isset($docs[View::query('doc')]) ? View::query('doc') : 'handbuch';
$path = APP_ROOT . '/docs/' . $docs[$doc][0];
if (I18n::lang() === 'en' && is_file(APP_ROOT . '/docs/en/' . $docs[$doc][1])) {
    $path = APP_ROOT . '/docs/en/' . $docs[$doc][1];
}

// Querverweise zwischen den Dokumenten bleiben in der App
$markdown = new Markdown(static function (string $href) use ($docs): string {
    if (preg_match('/^(?:\.\.\/|en\/)?(HANDBUCH|INTEGRATIONEN|HANDBOOK|INTEGRATIONS|INSTALLATION|INSTALL)\.md(#.*)?$/', $href, $m)) {
        $target = ['HANDBUCH' => 'handbuch', 'HANDBOOK' => 'handbuch', 'INTEGRATIONEN' => 'integrationen', 'INTEGRATIONS' => 'integrationen', 'INSTALLATION' => 'installation', 'INSTALL' => 'installation'][$m[1]];
        return isset($docs[$target]) ? 'hilfe.php?doc=' . $target . ($m[2] ?? '') : '#';
    }
    if (preg_match('/^(#|https?:\/\/)/', $href)) {
        return $href;
    }
    return '#'; // relative Links ins Repository (z. B. README) gibt es in der App nicht
});
$content = is_file($path) ? $markdown->document((string) file_get_contents($path)) : null;
$sections = $content === null ? [] : array_values(array_filter($content['sections'], static fn (array $s): bool => !in_array($s['title'], ['Inhalt', 'Contents'], true)));

View::header(t('Hilfe'), $user, 'help');
?>
<h1><?= t('Hilfe') ?></h1>
<?php if (count($docs) > 1): ?>
    <nav class="pills" aria-label="<?= View::e(t('Dokumente')) ?>">
        <?php foreach ($docs as $key => [, , $label]): ?>
            <a href="hilfe.php?doc=<?= $key ?>"<?= $key === $doc ? ' class="active" aria-current="page"' : '' ?>><?= View::e($label) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($content === null): ?>
    <div class="empty"><p><?= t('Die Dokumentation wurde nicht gefunden.') ?></p></div>
<?php else: ?>
    <div class="doc-intro"><?= $content['intro'] ?></div>

    <nav class="card doc-toc" id="inhalt" aria-label="<?= View::e(t('Inhalt')) ?>">
        <h2><?= t('Inhalt') ?></h2>
        <ol>
            <?php foreach ($sections as $section): ?>
                <li><a href="#<?= View::e($section['id']) ?>"><?= View::e((string) preg_replace('/^\d+\.\s*/', '', $section['title'])) ?></a></li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <?php foreach ($sections as $section): ?>
        <section class="card doc" id="<?= View::e($section['id']) ?>">
            <h2><?= View::e($section['title']) ?></h2>
            <?= $section['html'] ?>
            <a class="doc-top" href="#inhalt">↑ <?= t('Inhalt') ?></a>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php
View::footer();
