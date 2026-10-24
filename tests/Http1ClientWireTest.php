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
    expect($server)->not->toBeFalse();
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $port = substr(strrchr($address, ':'), 1);
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

            public function eof(): bool
            {
                return $this->bytes === '';
            }

            public function read(int $maxBytes = PHP_INT_MAX): string
            {
                $chunk = substr($this->bytes, 0, $maxBytes);
                $this->bytes = substr($this->bytes, strlen($chunk));

                return $chunk;
            }

            public function trailers(): ?Headers
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
        expect($client)->not->toBeFalse();
        $peer = stream_socket_accept($server, 2);
        expect($peer)->not->toBeFalse();
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
                expect($written)->toBeInt()->toBeGreaterThan(0);
                $offset += $written;
            }
        }
        $received = '';
        while (strlen($received) < strlen($expected)) {
            $chunk = fread($peer, strlen($expected) - strlen($received));
            expect($chunk)->toBeString()->not->toBe('');
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
            expect($bytes)->toBeString()->not->toBe('');
            $frame .= $bytes;
            $parser->push($bytes, $head, $onBody, $complete);
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
