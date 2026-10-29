<?php
declare(strict_types=1);

// Nutzerverwaltung (nur Admin): anlegen, bearbeiten, deaktivieren – kein Löschen

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Csrf;
use Vesta\Sources;
use Vesta\Time;
use Vesta\View;

$admin = Auth::requireAdmin();
$users = App::users();
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $editId > 0 ? $users->find($editId) : null;
$roles = ['admin' => t('Admin'), 'member' => t('Erwachsene/r'), 'kid' => t('Kind')];
$error = null;

$form = $editing ?? [
    'name' => '', 'role' => 'kid', 'login_type' => 'pin', 'source' => 'kids',
    'can_force' => 0, 'default_force' => 0, 'can_jump' => 0, 'default_jump' => 0, 'can_schedule' => 0,
    'cooldown_min' => 15, 'active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $form = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'role' => isset($roles[View::post('role')]) ? View::post('role') : 'kid',
        'login_type' => ($_POST['login_type'] ?? 'pin') === 'password' ? 'password' : 'pin',
        'source' => in_array($_POST['source'] ?? '', Sources::USER_SOURCES, true) ? (string) $_POST['source'] : 'kids',
        'can_force' => !empty($_POST['can_force']) ? 1 : 0,
        'default_force' => !empty($_POST['default_force']) ? 1 : 0,
        'can_jump' => !empty($_POST['can_jump']) ? 1 : 0,
        'default_jump' => !empty($_POST['default_jump']) ? 1 : 0,
        'can_schedule' => !empty($_POST['can_schedule']) ? 1 : 0,
        'cooldown_min' => ($_POST['cooldown_min'] ?? '') === '' ? null : max(0, min(1440, (int) $_POST['cooldown_min'])),
        'active' => !empty($_POST['active']) ? 1 : 0,
    ];
    $secret = (string) ($_POST['secret'] ?? '');
    $typeChanged = $editing !== null && $editing['login_type'] !== $form['login_type'];

    $existing = $users->findByName($form['name']);
    if ($form['name'] === '' || mb_strlen($form['name']) > 50) {
        $error = t('Bitte einen Namen (max. 50 Zeichen) angeben.');
    } elseif ($existing !== null && (int) $existing['id'] !== $editId) {
        $error = t('Diesen Namen gibt es schon.');
    } elseif ($form['role'] === 'admin' && $form['login_type'] !== 'password') {
        $error = t('Admins melden sich aus Sicherheitsgründen mit Passwort an, nicht mit PIN.');
    } elseif (($editing === null || $typeChanged || $secret !== '') && ($secretError = Auth::validateSecret($form['login_type'], $secret)) !== null) {
        $error = $editing !== null && $typeChanged && $secret === '' ? t('Beim Wechsel zwischen PIN und Passwort bitte ein neues setzen.') : $secretError;
    } elseif ($editing !== null && $editing['role'] === 'admin' && (int) $editing['active'] === 1
        && ($form['role'] !== 'admin' || $form['active'] !== 1) && $users->countActiveAdmins() <= 1) {
        $error = t('Es muss mindestens ein aktiver Admin bleiben.');
    }
    // Voreinstellungen nur, wenn das Recht besteht
    $form['default_force'] = $form['can_force'] ? $form['default_force'] : 0;
    $form['default_jump'] = $form['can_jump'] ? $form['default_jump'] : 0;

    if ($error === null) {
        $fields = $form;
        if ($secret !== '') {
            $fields['secret_hash'] = Auth::hashSecret($secret);
            $fields['failed_logins'] = 0;
            $fields['locked_until'] = null;
        }
        try {
            if ($editing !== null) {
                $users->update($editId, $fields);
                if ((int) $editing['active'] === 1 && $form['active'] === 0) {
                    $dropped = App::messages()->dropQueuedOfUser($editId, (int) $admin['id'], App::clock()->now());
                    if ($dropped > 0) {
                        View::flash('warn', t('{count} wartende Nachricht(en) von {name} wurden gelöscht (im Archiv wiederherstellbar).', ['count' => $dropped, 'name' => $form['name']]));
                    }
                }
                if ($editId === (int) $admin['id'] && $secret !== '') {
                    Auth::rememberSecret((array) $users->find($editId)); // eigenes Passwort geändert → angemeldet bleiben
                }
                View::flash('success', t('Zugang von {name} gespeichert.', ['name' => $form['name']]));
            } else {
                $users->insert($fields + ['created_at' => Time::db(App::clock()->now())]);
                View::flash('success', t('Zugang für {name} angelegt.', ['name' => $form['name']]));
            }
            View::redirect('users.php');
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // MariaDB vergleicht Namen auch ohne Akzente (Jule = Jüle)
            $error = t('Diesen oder einen sehr ähnlichen Namen gibt es schon.');
        }
    }
}

View::header(t('Nutzer'), $admin, 'settings');
?>
<h1><?= t('Nutzer') ?></h1>

<div class="nav-cards">
    <?php foreach ($users->all() as $u):
        $rights = array_filter([
            (int) $u['can_force'] === 1 ? t('Force') : null,
            (int) $u['can_jump'] === 1 ? t('vorne') : null,
            (int) $u['can_schedule'] === 1 ? t('planen') : null,
        ]);
        $summary = array_filter([
            ($roles[$u['role']] ?? $u['role']) . ' · ' . ($u['login_type'] === 'pin' ? t('PIN') : t('Passwort')),
            $u['role'] === 'admin' ? null : ($rights !== [] ? implode(', ', $rights) : null),
            $u['cooldown_min'] ? t('{minutes} Min Pause', ['minutes' => (int) $u['cooldown_min']]) : null,
        ]); ?>
        <a class="nav-card<?= (int) $u['active'] === 1 ? '' : ' inactive' ?>" href="users.php?id=<?= (int) $u['id'] ?>#form">
            <strong><?= View::e($u['name']) ?><?= (int) $u['active'] === 1 ? '' : ' <span class="badge">' . t('deaktiviert') . '</span>' ?><?= $u['locked_until'] !== null && Time::fromDb($u['locked_until']) > App::clock()->now() ? ' <span class="badge badge-error">' . t('gesperrt') . '</span>' : '' ?></strong>
            <span class="muted"><?= View::e(implode(' · ', $summary)) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<section class="card" id="form">
    <h2><?= $editing !== null ? View::e(t('{name} bearbeiten', ['name' => $editing['name']])) : t('Neuer Zugang') ?></h2>
    <?php if ($error !== null): ?><div class="flash flash-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
    <form method="post" data-busy class="user-form">
        <?= Csrf::field() ?>
        <?php if ($editing !== null): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
        <label><?= t('Name') ?> <input name="name" value="<?= View::e($form['name']) ?>" required maxlength="50"></label>
        <label><?= t('Rolle') ?>
            <select name="role"><?php foreach ($roles as $value => $label): ?>
                <option value="<?= $value ?>" <?= $form['role'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
        </label>
        <label><?= t('Quelle (bestimmt den Rang)') ?>
            <select name="source"><?php foreach (Sources::USER_SOURCES as $source): ?>
                <option value="<?= $source ?>" <?= $form['source'] === $source ? 'selected' : '' ?>><?= View::e(Sources::label($source)) ?></option><?php endforeach; ?>
            </select>
        </label>
        <fieldset class="inline">
            <legend><?= t('Anmeldung') ?></legend>
            <label><input type="radio" name="login_type" value="pin" <?= $form['login_type'] === 'pin' ? 'checked' : '' ?>> <?= t('PIN (4–8 Ziffern)') ?></label>
            <label><input type="radio" name="login_type" value="password" <?= $form['login_type'] === 'password' ? 'checked' : '' ?>> <?= t('Passwort (mind. 8 Zeichen)') ?></label>
        </fieldset>
        <label><?= $editing !== null ? t('Neue PIN / neues Passwort (leer = unverändert)') : t('PIN / Passwort') ?>
            <input type="password" name="secret" autocomplete="new-password" <?= $editing === null ? 'required' : '' ?>></label>
        <fieldset>
            <legend><?= t('Rechte <span class="muted">(Admins dürfen immer alles)</span>') ?></legend>
            <label><input type="checkbox" name="can_force" value="1" <?= (int) $form['can_force'] === 1 ? 'checked' : '' ?>> <?= t('Force erlaubt') ?></label>
            <label class="indent"><input type="checkbox" name="default_force" value="1" <?= (int) $form['default_force'] === 1 ? 'checked' : '' ?>> <?= t('Force standardmäßig an') ?></label>
            <p class="muted hint indent"><?= t('Force: Die Nachricht erscheint sofort und unterbricht, was gerade läuft – auch bei Stummschaltung oder Ruhezeit. Sie wartet nicht in der Warteschlange.') ?></p>
            <label><input type="checkbox" name="can_jump" value="1" <?= (int) $form['can_jump'] === 1 ? 'checked' : '' ?>> <?= t('vorne anstellen erlaubt') ?></label>
            <label class="indent"><input type="checkbox" name="default_jump" value="1" <?= (int) $form['default_jump'] === 1 ? 'checked' : '' ?>> <?= t('standardmäßig vorne anstellen') ?></label>
            <p class="muted hint indent"><?= t('Vorne anstellen: Die Nachricht wartet nicht hinten in der Warteschlange, sondern kommt als Nächstes dran, sobald die aktuelle Anzeige ihre Mindestzeit erreicht hat. Nachrichten mit höherer Priorität können trotzdem vorher erscheinen.') ?></p>
            <label><input type="checkbox" name="can_schedule" value="1" <?= (int) $form['can_schedule'] === 1 ? 'checked' : '' ?>> <?= t('Nachrichten planen erlaubt') ?></label>
        </fieldset>
        <label><?= t('Pause zwischen Nachrichten') ?> <input type="number" name="cooldown_min" min="0" max="1440" value="<?= View::e($form['cooldown_min'] ?? '') ?>" class="short"> <?= t('Minuten') ?> <span class="muted"><?= t('(leer = keine)') ?></span></label>
        <label><input type="checkbox" name="active" value="1" <?= (int) $form['active'] === 1 ? 'checked' : '' ?>> <?= t('Zugang aktiv') ?></label>
        <div class="buttons">
            <button type="submit" class="primary"><?= $editing !== null ? t('Speichern') : t('Anlegen') ?></button>
            <?php if ($editing !== null): ?><a class="button" href="users.php"><?= t('Abbrechen') ?></a><?php endif; ?>
        </div>
    </form>
</section>
<?php
View::footer();
