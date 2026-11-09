<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientBodySourceInterface;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ClientRequestWriter;
use Infocyph\Runwire\Http\Client\Http1ClientResponseParser;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;

it('interoperates with an independent real TCP peer for binary chunked H1 request and response framing', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $reason);
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to open independent HTTP/1 listener.');
    }
    $address = stream_socket_get_name($server, false);
    if (!is_string($address)) {
        fclose($server);
        throw new RuntimeException('Unable to resolve independent HTTP/1 listener address.');
    }
    $separator = strrpos($address, ':');
    if ($separator === false) {
        fclose($server);
        throw new RuntimeException('Independent HTTP/1 listener did not report a port.');
    }
    $port = substr($address, $separator + 1);
    $client = null;
    $peer = null;

    try {
        $policy = new ClientProtocolPolicy(
            preferred: ProtocolVersion::HTTP_1_1,
            allowedVersions: [ProtocolVersion::HTTP_1_1],
            allowPlaintext: true,
        );
        $body = new class implements ClientBodySourceInterface {
            private string $bytes = "binary\0payload";

            #[\Override]
            public function eof(): bool
            {
                return $this->bytes === '';
            }

            #[\Override]
            public function read(int $maxBytes = PHP_INT_MAX): string
            {
                $chunk = substr($this->bytes, 0, $maxBytes);
                $this->bytes = substr($this->bytes, strlen($chunk));

                return $chunk;
            }

            #[\Override]
            public function trailers(): Headers
            {
                return Headers::fromArray(['x-finish' => 'ok']);
            }
        };
        $writer = new Http1ClientRequestWriter(new ClientRequest(
            'POST',
            'http://127.0.0.1:' . $port . '/upload?from=runwire',
            body: $body,
            protocol: $policy,
        ));
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $reason, 2);
        if (!is_resource($client)) {
            throw new RuntimeException('HTTP/1 outbound fixture connection failed.');
        }
        $peer = stream_socket_accept($server, 2);
        if (!is_resource($peer)) {
            throw new RuntimeException('HTTP/1 independent peer did not accept.');
        }
        stream_set_timeout($client, 2);
        stream_set_timeout($peer, 2);

        $expected = '';
        while (!$writer->done()) {
            $frame = $writer->next(4);
            expect($frame)->not->toBe('');
            $expected .= $frame;
            $offset = 0;
            while ($offset < strlen($frame)) {
                $written = fwrite($client, substr($frame, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('Incomplete independent HTTP/1 request write.');
                }
                $offset += $written;
            }
        }
        $received = '';
        while (strlen($received) < strlen($expected)) {
            $chunk = fread($peer, strlen($expected) - strlen($received));
            if (!is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Independent HTTP/1 peer returned an incomplete request.');
            }
            $received .= $chunk;
        }
        expect($received)->toBe($expected)
            ->and($received)->toContain("POST /upload?from=runwire HTTP/1.1\r\n")
            ->and($received)->toContain("Transfer-Encoding: chunked\r\n")
            ->and($received)->toContain("x-finish: ok\r\n\r\n");

        $reply = "HTTP/1.1 201 Created\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n3\r\nok!\r\n0\r\nx-server: yes\r\n\r\n";
        expect(fwrite($peer, $reply))->toBe(strlen($reply));
        $parser = new Http1ClientResponseParser();
        $status = null;
        $response = '';
        $trailers = null;
        $head = static function (int $code, Headers $headers) use (&$status): void {
            expect($headers->first('transfer-encoding'))->toBe('chunked');
            $status = $code;
        };
        $onBody = static function (string $chunk) use (&$response): void {
            $response .= $chunk;
        };
        $complete = static function (?Headers $meta) use (&$trailers): void {
            $trailers = $meta;
        };
        $frame = '';
        while (strlen($frame) < strlen($reply)) {
            $bytes = fread($client, min(7, strlen($reply) - strlen($frame)));
            if (!is_string($bytes) || $bytes === '') {
                throw new RuntimeException('Independent HTTP/1 peer returned an incomplete response.');
            }
            $frame .= $bytes;
            $parser->push($bytes, $head, $onBody, $complete);
        }
        if (!$trailers instanceof Headers) {
            throw new RuntimeException('Independent HTTP/1 response is missing required trailers.');
        }
        expect($status)->toBe(201)
            ->and($response)->toBe('ok!')
            ->and($parser->done())->toBeTrue()
            ->and($parser->keepAlive())->toBeFalse()
            ->and($trailers)->toBeInstanceOf(Headers::class)
            ->and($trailers->first('x-server'))->toBe('yes');
    } finally {
        if (is_resource($peer)) {
            fclose($peer);
        }
        if (is_resource($client)) {
            fclose($client);
        }
        fclose($server);
    }
});
