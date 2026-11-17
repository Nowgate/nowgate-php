<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** 403: the key may not do this (insufficient_scope, ip_not_allowed, ...). */
final class PermissionException extends ApiException
{
}
