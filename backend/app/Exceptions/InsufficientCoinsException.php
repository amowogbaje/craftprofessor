<?php

namespace App\Exceptions;

use Exception;

class InsufficientCoinsException extends Exception
{
    public function __construct(public readonly int $required, public readonly int $available)
    {
        parent::__construct("Insufficient coins: needs {$required}, has {$available}.");
    }

    public function render()
    {
        return response()->json([
            'message' => 'You don\'t have enough coins for this. Top up to continue.',
            'required_coins' => $this->required,
            'available_coins' => $this->available,
        ], 402);
    }
}
