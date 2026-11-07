<?php

declare(strict_types=1);

/**
 * Serveur SMTP de boucle locale (127.0.0.1 uniquement) lancé par un test : accepte UNE connexion, répond via
 * FakeSmtpScript, écrit la transcription dans le fichier donné en argument. Usage : php loopback_smtp_server.php <transcription>
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Tests\Support\Integrations\FakeSmtpScript;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "bind impossible\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$connection = @stream_socket_accept($server, 20);
if ($connection === false) {
    exit(2);
}
$script = new FakeSmtpScript();
fwrite($connection, $script->greeting());
stream_set_timeout($connection, 10);
while (($chunk = fread($connection, 8192)) !== false && $chunk !== '') {
    $reply = $script->receive($chunk);
    if ($reply !== '') {
        fwrite($connection, $reply);
    }
    if (in_array('QUIT', $script->commands, true)) {
        break;
    }
}
fclose($connection);
file_put_contents((string) ($argv[1] ?? 'php://stderr'), json_encode(['commands' => $script->commands, 'data' => $script->data, 'authenticated' => $script->authenticated]));
