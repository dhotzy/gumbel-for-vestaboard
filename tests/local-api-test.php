<?php
declare(strict_types=1);

// Aufruf: php tests/local-api-test.php – startet eine nachgebaute Local API auf einem freien Port

require __DIR__ . '/support.php';

use Vesta\BoardConnection;
use Vesta\Charset;
use Vesta\LocalVestaClient;
use Vesta\Settings;
use Vesta\VestaClient;

section('Adresse');
check('IP ist gültig', LocalVestaClient::isValidHost('192.168.1.50'));
check('Name mit Port ist gültig', LocalVestaClient::isValidHost('vestaboard.local:7000'));
check('URL ist ungültig', !LocalVestaClient::isValidHost('http://192.168.1.50'));
check('Pfad ist ungültig', !LocalVestaClient::isValidHost('192.168.1.50/evil'));

section('Verbindung wählen');
$settings = new Settings(memoryDb());
$connection = new BoardConnection($settings);
check('Standard ist Cloud', $connection->mode() === 'cloud' && $connection->client() instanceof VestaClient);
$connection->save(['mode' => 'local', 'local_host' => '192.168.1.50', 'local_key' => 'k', 'unknown' => 'x']);
check('Local wählbar', $connection->mode() === 'local' && $connection->client() instanceof LocalVestaClient && $connection->isConfigured());
check('Unbekannte Felder werden nicht gespeichert', !array_key_exists('unknown', $connection->get()));
$connection->save(['local_key' => '']);
check('Ohne Key nicht eingerichtet', !$connection->isConfigured());

section('Gegen nachgebaute Local API');
@unlink(sys_get_temp_dir() . '/gumbel-fake-local-api.json');
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
fclose($socket);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake-local-api.php'], [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}
$host = "127.0.0.1:$port";
check('Falscher Freischalt-Token → Fehler', !LocalVestaClient::enable($host, 'falsch')['ok']);
$enabled = LocalVestaClient::enable($host, 'enable-me');
check('Freischalten liefert Key', $enabled['ok'] && $enabled['key'] === 'local-key-123');
$client = new LocalVestaClient($host, (string) $enabled['key']);
$grid = Charset::textToGrid('HALLO LOKAL')['grid'];
check('Senden klappt', $client->send($grid)['ok']);
$read = $client->read();
check('Lesen liefert das gesendete Raster', $read['ok'] && $read['grid'] === $grid);
$wrong = (new LocalVestaClient($host, 'falsch'))->send($grid);
check('Falscher Key → 401 mit Meldung', !$wrong['ok'] && $wrong['status'] === 401 && $wrong['error'] !== null);
check('Ungültiges Raster wird nicht gesendet', !$client->send([[1]])['ok']);
proc_terminate($server);
check('Nicht erreichbar → verständlicher Fehler', ($unreachable = (new LocalVestaClient('127.0.0.1:' . $port, 'k', 1))->read()) && !$unreachable['ok'] && $unreachable['status'] === 0);

finish();
