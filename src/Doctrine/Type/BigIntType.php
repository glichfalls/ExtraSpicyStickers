<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * Overrides the default bigint type to return int instead of string.
 * Safe on 64-bit PHP 8 where all Telegram IDs fit in a native int.
 */
class BigIntType extends \Doctrine\DBAL\Types\BigIntType
{
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): int
    {
        return (int) $value;
    }
}
