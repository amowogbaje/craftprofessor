<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ImageGeneratorService when a user can't generate right now
 * (daily/monthly limit hit, or insufficient coins) — as opposed to the
 * generation itself failing.
 *
 * The distinction matters to GenerateStoryImages: a hard failure means
 * "this row is broken, count the attempt and maybe retry later"; this
 * exception means "this row is fine, but this user is out of budget for
 * today — leave it untouched and go work on a different user's queue
 * instead."
 */
class UserGenerationLimitReached extends RuntimeException
{
    public function __construct(public readonly int $userId, string $reason)
    {
        parent::__construct($reason);
    }
}
