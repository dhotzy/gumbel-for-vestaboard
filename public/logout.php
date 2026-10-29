<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use Vesta\Auth;
use Vesta\Csrf;
use Vesta\View;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    Auth::logout();
}
View::redirect('login.php');
