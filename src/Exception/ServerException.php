<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** 5xx: Nowgate couldn't answer. A POST's retry with the same idempotency key is safe. */
final class ServerException extends ApiException
{
}
