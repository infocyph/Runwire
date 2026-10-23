<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\TlsClientOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (extension_loaded('openssl')) {
    throw new RuntimeException('TLS-unavailable fixture must run with OpenSSL disabled.');
}
try {
    (new TlsClientOptions('localhost'))->context();
    throw new RuntimeException('TLS policy silently downgraded without ext-openssl.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'requires ext-openssl')) {
        throw $error;
    }
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
if (!is_resource($server)) {
    throw new RuntimeException($message);
}
$address = stream_socket_get_name($server, false);
if (!is_string($address)) {
    throw new RuntimeException('Plain TCP has no bound address.');
}
$client = stream_socket_client('tcp://' . $address, $errno, $message, 1.0);
if (!is_resource($client)) {
    throw new RuntimeException('Plain TCP failed without OpenSSL.');
}
$peer = stream_socket_accept($server, 1.0);
if (!is_resource($peer)) {
    throw new RuntimeException('Plain TCP could not accept without OpenSSL.');
}
$connection = new Connection(new SelectLoop(), $client);
$connection->write('plain');
stream_set_timeout($peer, 1);
$received = fread($peer, 5);
$connection->abort();
fclose($peer);
fclose($server);
if ($received !== 'plain') {
    throw new RuntimeException('Plain connection engine degraded without OpenSSL.');
}
fwrite(STDOUT, "tls-unavailable-plain-tcp-ok\n");
