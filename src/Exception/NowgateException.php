<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** Every exception the SDK throws, except an InvalidArgumentException for an argument it can't send. */
class NowgateException extends \RuntimeException
{
}
