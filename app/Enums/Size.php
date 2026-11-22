<?php

namespace App\Enums;

/**
 * Output sizes Pixelforge accepts. 1024x1024 is its default.
 */
enum Size: string
{
    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';

    public function isSquare(): bool
    {
        return $this === self::Square;
    }
}
