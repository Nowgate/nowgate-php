<?php

declare(strict_types=1);

// The default transport, curl, against PHP's built-in server: every answer returned as it is,
// redirects not followed, and no answer thrown as a TransportException.

namespace Nowgate\Tests;

use Nowgate\Client;
use Nowgate\Exception\ConnectionException;
use Nowgate\Transport\CurlTransport;
use Nowgate\Transport\Request;
use Nowgate\Transport\TransportException;

/**
 * Starts PHP's built-in server with tests/server.php, and returns its base URL and process.
 *
 * @return array{string, resource}
 */
function server(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === false) {
        throw new Failure('no free port');
    }
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    if ($process === false) {
        throw new Failure('the server never started');
    }
    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port);
        if ($probe !== false) {
            fclose($probe);
            return ["http://127.0.0.1:{$port}/v1", $process];
        }
        usleep(20_000);
    }
    throw new Failure('the server never listened');
}

test('curl returns every answer as it is, follows no redirect, and throws when there is none', function (): void {
    [$base, $process] = server();
    try {
        $curl = new CurlTransport();
        $headers = ['Authorization' => 'Bearer x', 'User-Agent' => 'nowgate-php/test', 'Content-Type' => 'application/json'];
        $answer = $curl->send(new Request('POST', "{$base}/echo", $headers, '{"a":1}', 5));
        same(200, $answer->status);
        same('req_local', $answer->headers['x-request-id']);
        $echo = json_decode($answer->body, true);
        same(['method' => 'POST', 'body' => '{"a":1}', 'agent' => 'nowgate-php/test', 'authorization' => 'Bearer x'], $echo);
        same(404, $curl->send(new Request('GET', "{$base}/missing", [], null, 5))->status);
        $moved = $curl->send(new Request('GET', "{$base}/moved", [], null, 5));
        same(302, $moved->status);
        same('/v1/elsewhere', $moved->headers['location']);
        $slow = throws(TransportException::class, fn () => $curl->send(new Request('GET', "{$base}/slow", [], null, 0.2)));
        same(true, $slow->timedOut);

        $nowgate = new Client(['api_key' => 'ngk_test_' . Fake::SECRET, 'base_url' => $base, 'timeout' => 0.2, 'max_retries' => 1]);
        Fake::requester($nowgate)->sleep = static function (float $seconds): void {
        };
        throws(ConnectionException::class, fn () => $nowgate->paymentLinks->retrieve('slow'), "/didn't answer within 0.2 s/");
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
    $refused = throws(TransportException::class, fn () => (new CurlTransport())->send(new Request('GET', $base, [], null, 5)));
    same(false, $refused->timedOut);
});
