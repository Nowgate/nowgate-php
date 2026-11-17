<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** 401: the key is missing, unknown or revoked. */
final class AuthenticationException extends ApiException
{
}
