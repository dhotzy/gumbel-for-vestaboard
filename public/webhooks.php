<?php
declare(strict_types=1);

// Integrationen (nur Admin): Webhooks, Abrufen (JSON/RSS) und NFC-Sticker – je Eintrag eigener API-Schlüssel.
// Welche Module es gibt, steht in Inbound::MODULES; die Darstellung in IntegrationView.

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Charset;
use Vesta\Csrf;
use Vesta\Feed;
use Vesta\Inbound;
use Vesta\IntegrationView;
use Vesta\RecipeBook;
use Vesta\Schedule;
use Vesta\Sources;
use Vesta\Time;
use Vesta\View;
use Vesta\WebhookTemplate;

$user = Auth::requireAdmin();
$inbound = App::inbound();

// Rezept herunterladen
if (isset($_GET['export'])) {
    $hook = $inbound->find((int) $_GET['export']);
    if ($hook === null) {
        View::flash('error', t('Integration nicht gefunden.'));
        View::redirect('webhooks.php');
    }
    $file = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $hook['name'])), '-') ?: 'integration';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="gumbel-' . $file . '.json"');
    header('Cache-Control: no-store');
    echo json_encode(Inbound::export($hook), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $id = (int) ($_POST['id'] ?? 0);
    $module = isset(Inbound::MODULES[View::post('module')]) ? View::post('module') : array_key_first(Inbound::MODULES);
    $config = Inbound::modules()[$module];
    $source = in_array(View::post('source'), $config['sources'], true) ? View::post('source') : $config['sources'][0];
    $name = trim(View::post('name'));
    $fixedText = trim(str_replace("\r", '', View::post('fixed_text')));
    $isNfc = $config['kind'] === 'nfc';
    $isPull = $config['kind'] === 'pull';
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'create':
            case 'save':
                if ($name === '' || mb_strlen($name) > 80) {
                    View::flash('error', t('Bitte einen Namen angeben (höchstens 80 Zeichen).'));
                    break;
                }
                if ($isNfc && ($fixedText === '' || !Charset::textToGrid($fixedText)['fits'])) {
                    View::flash('error', t('Bitte einen Text angeben, der aufs Board passt (3 Zeilen à 15 Zeichen).'));
                    break;
                }
                if (mb_strlen($fixedText) > 300) {
                    View::flash('error', t('Die Vorlage ist zu lang (höchstens 300 Zeichen).'));
                    break;
                }
                $pullUrl = trim(View::post('pull_url'));
                $pullError = $isPull ? Feed::checkUrl($pullUrl) : null;
                if ($pullError !== null) {
                    View::flash('error', $pullError);
                    break;
                }
                $pullInterval = isset(Feed::INTERVALS[(int) View::post('pull_interval')]) ? (int) View::post('pull_interval') : 15;
                // Gespeicherte Werte nur behalten, solange der Host gleich bleibt – sonst ginge der alte Schlüssel an einen neuen Dienst
                $storedHook = $action === 'save' ? ($inbound->find($id) ?? []) : [];
                $sameHost = $storedHook !== [] && strtolower((string) parse_url((string) ($storedHook['pull_url'] ?? ''), PHP_URL_HOST)) === strtolower((string) parse_url($pullUrl, PHP_URL_HOST));
                $pullHeaders = $isPull ? Feed::parseHeaders(View::post('pull_headers'), $sameHost ? Feed::storedHeaders($storedHook) : []) : ['headers' => [], 'error' => null];
                if ($pullHeaders['error'] !== null) {
                    View::flash('error', $pullHeaders['error']);
                    break;
                }
                $pullHeadersJson = $pullHeaders['headers'] === [] ? null : json_encode($pullHeaders['headers'], JSON_UNESCAPED_UNICODE);
                $gap = (int) ($_POST['min_interval'] ?? $config['min_interval']);
                $gap = isset(Inbound::INTERVALS[$gap]) ? $gap : (int) $config['min_interval'];
                if ($action === 'create') {
                    $created = $inbound->createKey($name, $source, $fixedText === '' ? null : $fixedText, !empty($_POST['can_force']), $gap);
                    $id = $created['id'];
                    if ($isPull) {
                        $inbound->update($id, ['pull_url' => $pullUrl, 'pull_interval' => $pullInterval, 'pull_headers' => $pullHeadersJson]);
                        View::flash('success', t('„{name}“ angelegt. Mit „Jetzt abrufen“ siehst du die Felder für die Vorlage.', ['name' => $name]));
                    } else {
                        $_SESSION['new_webhook_key'] = ['id' => $id, 'key' => $created['key']];
                        View::flash('success', t('„{name}“ angelegt. Jetzt Webhook-URL und API-Schlüssel eintragen – Anleitung unten.', ['name' => $name]));
                    }
                    $_SESSION['open_hook'] = $id;
                    break;
                }
                $fields = ['name' => $name, 'source' => $source, 'fixed_text' => $fixedText === '' ? null : $fixedText,
                    'can_force' => !empty($_POST['can_force']) ? 1 : 0, 'active' => !empty($_POST['active']) ? 1 : 0, 'min_interval' => $gap];
                if (!$isNfc) {
                    $dedupeMinutes = (int) View::post('dedupe_minutes');
                    $fields += [
                        'test_mode' => !empty($_POST['test_mode']) ? 1 : 0,
                        'rules' => json_encode(WebhookTemplate::parseRules($_POST['rules'] ?? []), JSON_UNESCAPED_UNICODE),
                        'options' => json_encode(WebhookTemplate::options((array) ($_POST['options'] ?? []))),
                        'windows' => json_encode(Schedule::parse($_POST['windows'] ?? [], 'windows')),
                        'dedupe_key' => mb_substr(trim(View::post('dedupe_key')), 0, 200) ?: null,
                        'dedupe_minutes' => isset(Inbound::DEDUPE[$dedupeMinutes]) ? $dedupeMinutes : 0,
                        'require_signature' => !empty($_POST['require_signature']) ? 1 : 0,
                    ];
                    if ($fields['require_signature'] === 1 && empty($inbound->find($id)['signing_secret'])) {
                        $fields['require_signature'] = 0;
                        View::flash('error', t('Für die Signatur bitte zuerst ein Signatur-Geheimnis erzeugen.'));
                    }
                }
                if ($isPull) {
                    $fields += ['pull_url' => $pullUrl, 'pull_interval' => $pullInterval, 'pull_headers' => $pullHeadersJson];
                }
                $inbound->update($id, $fields);
                $_SESSION['open_hook'] = $id;
                View::flash('success', t('Gespeichert.'));
                break;
            case 'test':
                $result = $inbound->test($id);
                if ($result['ok']) {
                    App::triggerArbiter(true);
                }
                $_SESSION['open_hook'] = $id;
                View::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? t('Test ist in der Warteschlange – gleich auf dem Board.') : (string) $result['error']);
                break;
            case 'poll':
                $hook = $inbound->find($id);
                $result = $hook !== null ? $inbound->poll($hook) : ['ok' => false, 'error' => t('Integration nicht gefunden.')];
                if (!empty($result['id'])) {
                    App::triggerArbiter();
                }
                $_SESSION['open_hook'] = $id;
                $message = match (true) {
                    !$result['ok'] => t('Abruf fehlgeschlagen: {error}', ['error' => $result['error']]),
                    !empty($result['test']) => t('Abgerufen (Testmodus) – die Felder stehen unten.'),
                    !empty($result['unchanged']) => t('Abgerufen – nichts Neues.'),
                    !empty($result['id']) => t('Abgerufen und eingereiht.'),
                    default => t('Abgerufen – nicht angezeigt (siehe Verlauf).'),
                };
                View::flash($result['ok'] ? 'success' : 'error', $message);
                break;
            case 'rotate':
                $key = $inbound->rotateKey($id);
                if ($key !== null) {
                    $_SESSION['new_webhook_key'] = ['id' => $id, 'key' => $key];
                    View::flash('success', t('Neuer API-Schlüssel erzeugt – der alte funktioniert nicht mehr. Bitte im Kurzbefehl bzw. Dienst ersetzen.'));
                }
                break;
            case 'secret':
                $secret = $inbound->rotateSecret($id);
                if ($secret !== null) {
                    $_SESSION['new_signing_secret'] = ['id' => $id, 'secret' => $secret];
                    $_SESSION['open_hook'] = $id;
                    View::flash('success', t('Signatur-Geheimnis erzeugt. Im sendenden Dienst eintragen und dann „HMAC-Signatur verlangen“ einschalten.'));
                }
                break;
            case 'recipe': // mitgeliefertes Rezept aus recipes/
            case 'import':
                if ($action === 'recipe') {
                    $bundled = (new RecipeBook())->load(View::post('recipe_id'));
                    $result = $bundled !== null ? $inbound->import($bundled['recipe']) : ['ok' => false, 'error' => t('Rezept nicht gefunden.')];
                } else {
                    $json = '';
                    if (!empty($_FILES['recipe']['tmp_name']) && is_uploaded_file($_FILES['recipe']['tmp_name']) && (int) $_FILES['recipe']['size'] <= 65536) {
                        $json = (string) file_get_contents($_FILES['recipe']['tmp_name']);
                    }
                    $json = $json !== '' ? $json : View::post('recipe_text');
                    $recipe = json_decode($json, true);
                    $result = is_array($recipe) ? $inbound->import($recipe) : ['ok' => false, 'error' => t('Die Datei bzw. der Text ist kein gültiges JSON.')];
                }
                if ($result['ok']) {
                    $hook = $inbound->find($result['id']);
                    $module = Inbound::moduleFor($hook);
                    if (Inbound::MODULES[$module]['kind'] !== 'pull') {
                        $_SESSION['new_webhook_key'] = ['id' => $result['id'], 'key' => $result['key']];
                    }
                    $_SESSION['open_hook'] = $result['id'];
                    View::flash('success', t('Rezept „{name}“ importiert – im Testmodus, bis du es prüfst und den Testmodus ausschaltest.', ['name' => $hook['name']]));
                } else {
                    View::flash('error', (string) $result['error']);
                }
                break;
            case 'delete':
                $inbound->delete($id);
                View::flash('success', t('Gelöscht – der API-Schlüssel funktioniert nicht mehr.'));
                break;
            default:
                View::flash('error', t('Unbekannte Aktion.'));
        }
    } catch (Throwable $e) {
        error_log('Integrationen: ' . $e->getMessage());
        View::flash('error', t('Speichern fehlgeschlagen. Bitte noch einmal versuchen.'));
    }
    View::redirect('webhooks.php#' . $module);
}

$newKey = $_SESSION['new_webhook_key'] ?? null;
$newSecret = $_SESSION['new_signing_secret'] ?? null;
$openHook = (int) ($_SESSION['open_hook'] ?? 0);
unset($_SESSION['new_webhook_key'], $_SESSION['new_signing_secret'], $_SESSION['open_hook']);
header('Cache-Control: no-store'); // Schlüssel nicht im Browser-Cache
$hooks = $inbound->all();
$endpoint = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/inbound.php';
$newHook = is_array($newKey) ? $inbound->find((int) $newKey['id']) : null;

View::header(t('Integrationen'), $user, 'settings');
?>
<h1><?= t('Integrationen') ?></h1>
<p class="muted"><?= t('Andere Dienste schicken per Webhook Texte aufs Board, Gumbel holt Feeds ab, NFC-Sticker lösen Nachrichten aus. Jede Integration ist einzeln abschaltbar und hat einen eigenen Verlauf.') ?> <a href="hilfe.php?doc=integrationen"><?= t('Dokumentation') ?></a></p>

<nav class="pills" aria-label="<?= View::e(t('Bereiche')) ?>" data-tabs>
    <?php foreach (Inbound::modules() as $moduleId => $config):
        $count = count(array_filter($hooks, static fn (array $h): bool => Inbound::moduleFor($h) === $moduleId && (int) $h['active'] === 1)); ?>
        <a href="#<?= $moduleId ?>"<?= $count > 0 ? ' class="is-on"' : '' ?>><?= View::e($config['label']) ?></a>
    <?php endforeach; ?>
</nav>

<?php foreach (Inbound::modules() as $moduleId => $config):
    $moduleHooks = array_values(array_filter($hooks, static fn (array $h): bool => Inbound::moduleFor($h) === $moduleId)); ?>
<section class="card" id="<?= $moduleId ?>">
    <h2 class="headline"><?= View::e($config['title']) ?></h2>
    <p class="muted hint"><?= View::e($config['intro']) ?></p>

    <?php if ($newHook !== null && Inbound::moduleFor($newHook) === $moduleId): ?>
        <div class="key-once">
            <h3><?= t('„{name}“ einrichten', ['name' => View::e($newHook['name'])]) ?></h3>
            <p class="muted hint"><?= t('Den API-Schlüssel siehst du nur jetzt. Geht er verloren: „Neuer API-Schlüssel“ beim Eintrag.') ?></p>
            <?= IntegrationView::setupHelp($config['help'], $endpoint, (string) $newKey['key']) ?>
        </div>
    <?php endif; ?>

    <?php if ($moduleHooks === []): ?>
        <p class="muted"><?= t('Noch nichts angelegt.') ?></p>
    <?php endif; ?>
    <div class="hook-list">
    <?php foreach ($moduleHooks as $hook):
        $hookId = (int) $hook['id'];
        $active = (int) $hook['active'] === 1;
        $summary = $config['kind'] === 'pull'
            ? (string) parse_url((string) $hook['pull_url'], PHP_URL_HOST)
            : ($hook['fixed_text'] !== null && $hook['fixed_text'] !== '' ? (string) preg_replace('/\s+/', ' ', (string) $hook['fixed_text']) : Sources::label((string) $hook['source']));
        $used = (int) $hook['use_count'] > 0 ? t('{count}× · zuletzt {time}', ['count' => (int) $hook['use_count'], 'time' => Time::format($hook['last_used_at'], 'd.m. H:i')]) : t('noch nichts angezeigt');
        $log = $config['kind'] === 'nfc' ? [] : $inbound->deliveries($hookId); ?>
        <details class="hook<?= $active ? '' : ' inactive' ?>" id="hook-<?= $hookId ?>"<?= $openHook === $hookId ? ' open' : '' ?>>
            <summary>
                <span class="hook-name"><?= View::e($hook['name']) ?><?= $active ? '' : ' <span class="muted">· ' . t('aus') . '</span>' ?></span>
                <span class="hook-meta"><?= View::e(mb_strimwidth($summary, 0, 40, '…')) ?> · <?= $used ?></span>
                <?php if ((int) ($hook['test_mode'] ?? 0) === 1): ?><span class="hook-meta hook-test"><?= t('Testmodus – schneidet nur mit') ?></span><?php endif; ?>
                <?php if (!empty($hook['last_error'])): ?><span class="hook-meta hook-error"><?= t('Letzter Hinweis: {note}', ['note' => View::e($hook['last_error'])]) ?></span><?php endif; ?>
            </summary>
            <form method="post" data-busy>
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $hookId ?>">
                <?= IntegrationView::fields($moduleId, $hook, is_array($newSecret) && (int) $newSecret['id'] === $hookId ? (string) $newSecret['secret'] : null) ?>
                <label><input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>> <?= t('Aktiv') ?></label>
                <div class="buttons">
                    <button type="submit" name="action" value="save" class="primary"><?= t('Speichern') ?></button>
                    <?php if ($config['kind'] === 'pull'): ?>
                        <button type="submit" name="action" value="poll" formnovalidate><?= t('Jetzt abrufen') ?></button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="test" formnovalidate <?= $active ? '' : 'disabled' ?>><?= t('Jetzt testen') ?></button>
                    <?php if ($config['kind'] !== 'pull'): ?>
                        <button type="submit" name="action" value="rotate" formnovalidate data-confirm-click="<?= View::e(t('Neuen API-Schlüssel erzeugen? Der alte funktioniert danach nicht mehr.')) ?>"><?= t('Neuer API-Schlüssel') ?></button>
                    <?php endif; ?>
                    <?php if ($config['kind'] !== 'nfc'): ?>
                        <a class="button" href="webhooks.php?export=<?= $hookId ?>"><?= t('Rezept exportieren') ?></a>
                    <?php endif; ?>
                    <button type="submit" name="action" value="delete" class="danger" formnovalidate data-confirm-click="<?= View::e(t('„{name}“ löschen? Der API-Schlüssel funktioniert danach nicht mehr.', ['name' => $hook['name']])) ?>"><?= t('Löschen') ?></button>
                </div>
                <?php if ($config['kind'] !== 'nfc'): ?>
                    <details class="sub"><summary><?= t('Verlauf ({count})', ['count' => count($log)]) ?></summary><?= IntegrationView::deliveries($log) ?></details>
                <?php endif; ?>
            </form>
        </details>
    <?php endforeach; ?>
    </div>

    <details class="new-hook" <?= $moduleHooks === [] ? 'open' : '' ?>>
        <summary class="button">+ <?= View::e($config['add']) ?></summary>
        <form method="post" data-busy>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <?= IntegrationView::fields($moduleId, ['source' => $config['sources'][0], 'min_interval' => $config['min_interval']]) ?>
            <div class="buttons"><button type="submit" class="primary"><?= View::e(t('{name} anlegen', ['name' => $config['add']])) ?></button></div>
        </form>
    </details>

    <?php $bundledRecipes = $config['kind'] !== 'nfc' ? (new RecipeBook())->forModule($moduleId) : []; ?>
    <?php if ($bundledRecipes !== []): ?>
        <details class="help recipes" <?= $moduleHooks === [] ? 'open' : '' ?>>
            <summary><?= t('Fertige Rezepte ({count})', ['count' => count($bundledRecipes)]) ?></summary>
            <p class="muted hint"><?= t('Mit einem Klick hinzufügen – landet im Testmodus{pull}. Danach Vorlage prüfen und anpassen.', ['pull' => $config['kind'] === 'pull' ? t(' und ausgeschaltet, bis du die Adresse geprüft und es aktiviert hast') : '']) ?></p>
            <?php foreach ($bundledRecipes as $entry): ?>
                <form method="post" class="recipe" data-busy>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="recipe">
                    <input type="hidden" name="module" value="<?= $moduleId ?>">
                    <input type="hidden" name="recipe_id" value="<?= View::e($entry['id']) ?>">
                    <div><strong><?= View::e($entry['name']) ?></strong>
                        <span class="muted"><?= View::e($entry['description']) ?></span>
                        <?php if ($entry['needs'] !== ''): ?><span class="recipe-needs"><?= View::e(t('Braucht: {what}', ['what' => $entry['needs']])) ?></span><?php endif; ?></div>
                    <button type="submit"><?= t('Hinzufügen') ?></button>
                </form>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>

    <?php if ($config['kind'] !== 'nfc'): ?>
        <details class="help">
            <summary><?= t('Rezept importieren') ?></summary>
            <form method="post" enctype="multipart/form-data" data-busy>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="module" value="<?= $moduleId ?>">
                <label><?= t('Rezept-Datei (.json)') ?> <input type="file" name="recipe" accept="application/json,.json"></label>
                <label><?= t('… oder JSON einfügen') ?><textarea name="recipe_text" rows="4" class="plain" placeholder='{"format": "gumbel-integration", …}'></textarea></label>
                <p class="muted hint"><?= t('Ein Rezept enthält Vorlagen, Bedingungen und Einstellungen – keine Schlüssel. Importiert wird im Testmodus.') ?></p>
                <div class="buttons"><button type="submit"><?= t('Importieren') ?></button></div>
            </form>
        </details>
    <?php endif; ?>

    <details class="help">
        <summary><?= t('So richtest du es ein') ?></summary>
        <?= IntegrationView::setupHelp($config['help'], $endpoint, null) ?>
    </details>
</section>
<?php endforeach; ?>
<?php
View::footer();
