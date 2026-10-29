<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use Vesta\App;
use Vesta\Auth;
use Vesta\Csrf;
use Vesta\View;

if (Auth::user() !== null) {
    View::redirect('index.php');
}

$error = null;
$name = trim((string) ($_POST['name'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $error = Auth::login($name, (string) ($_POST['secret'] ?? ''));
    if ($error === null) {
        View::redirect('index.php');
    }
}

View::header(t('Anmelden'), null);
if (App::users()->count() === 0): // frisch installiert: Weg zur Einrichtung zeigen statt einer Anmeldung, die nicht gehen kann
?>
<div class="narrow">
    <h1><?= View::e(t('Willkommen bei Gumbel')) ?></h1>
    <div class="card">
        <p><?= View::e(t('Noch nicht eingerichtet. Öffne den Einrichtungslink – er enthält einen geheimen Schlüssel, damit niemand anderes dein Board übernimmt.')) ?></p>
        <p><strong>Docker</strong><br><span class="muted"><?= View::e(t('Docker Desktop: Container „gumbel“ → Logs. Terminal: docker compose logs gumbel')) ?></span></p>
        <p><strong><?= View::e(t('Webhosting')) ?></strong><br><span class="muted"><?= View::e(t('setup.php?key= plus der cron_key aus config/config.php')) ?></span></p>
    </div>
</div>
<?php
    View::footer();
    exit;
endif;
?>
<div class="narrow">
    <h1><?= View::e(t('Anmelden')) ?></h1>
    <?php if ($error !== null): ?><div class="flash flash-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
    <form method="post" class="card" data-busy>
        <?= Csrf::field() ?>
        <label><?= View::e(t('Name')) ?> <input name="name" value="<?= View::e($name) ?>" required autocomplete="username" autocapitalize="words" autofocus></label>
        <label><?= View::e(t('Passwort oder PIN')) ?> <input type="password" name="secret" required autocomplete="current-password"></label>
        <button type="submit" class="primary"><?= View::e(t('Anmelden')) ?></button>
    </form>
    <p class="muted"><?= View::e(t('Du bleibst 30 Tage angemeldet.')) ?></p>
</div>
<?php
View::footer();
