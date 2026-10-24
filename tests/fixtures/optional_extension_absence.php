<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\SocketCapabilityProbe;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeDriverResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;
use Infocyph\Runwire\RuntimeOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? null;
$environment = (new RuntimeEnvironmentProbe())->probe();
if ((new RuntimeDriverResolver())->resolve(new RuntimeOptions(), $environment) !== RuntimeDriver::NATIVE) {
    throw new RuntimeException('Portable PHP must retain single-process native driver selection.');
}

if ($mode === 'no-sockets') {
    if (extension_loaded('sockets') || function_exists('socket_create') || SocketCapabilityProbe::supportsReusePort()) {
        throw new RuntimeException('No-sockets fixture must not advertise ext-sockets/SO_REUSEPORT.');
    }
    if (!SocketCapabilityProbe::supportsUnixSockets()) {
        throw new RuntimeException('Core Unix stream socket support must remain discoverable without ext-sockets.');
    }

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair)) {
        throw new RuntimeException('Core stream socket pair is unavailable without ext-sockets.');
    }

    try {
        if (fwrite($pair[0], "plain\0socket") !== 12 || fread($pair[1], 12) !== "plain\0socket") {
            throw new RuntimeException('Plain socket stream failed without ext-sockets.');
        }
    } finally {
        fclose($pair[0]);
        fclose($pair[1]);
    }
} elseif ($mode === 'no-posix') {
    if (function_exists('posix_getpid') || function_exists('posix_kill')
        || $environment->supportsPosix || $environment->nativePreforkEligible()) {
        throw new RuntimeException('POSIX unavailable must not advertise native prefork capability.');
    }
} else {
    throw new InvalidArgumentException('Expected no-sockets or no-posix compatibility mode.');
}

fwrite(STDOUT, $mode . "-portable-ok\n");
