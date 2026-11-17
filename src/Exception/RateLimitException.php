<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** 429: too many requests; retryAfter says when to try again. */
final class RateLimitException extends ApiException
{
}
