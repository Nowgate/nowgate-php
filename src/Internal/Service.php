<?php

declare(strict_types=1);

namespace Nowgate\Internal;

/**
 * A group of operations, such as payments: the generated services build on it.
 *
 * @internal
 */
abstract class Service
{
    public function __construct(protected readonly Requester $requester)
    {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['requester' => $this->requester->__debugInfo()];
    }
}
