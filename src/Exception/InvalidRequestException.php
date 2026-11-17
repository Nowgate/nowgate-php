<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** 400, 405, 413, 414, 415 and 422: the request itself is wrong; errors points at the fields. */
final class InvalidRequestException extends ApiException
{
}
