<?php

declare(strict_types=1);

namespace Nowgate\Transport;

/**
 * Sends one attempt and returns the answer, whatever its status. When there's no answer (the
 * connection is refused or reset, or the attempt times out), it throws a TransportException:
 * the client retries those. CurlTransport is the default; pass your own to use another HTTP
 * client.
 */
interface Transport
{
    /** @throws TransportException when there's no answer */
    public function send(Request $request): Response;
}
