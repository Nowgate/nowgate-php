<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** No answer: the connection was refused or reset, or an attempt timed out. */
final class ConnectionException extends NowgateException
{
}
