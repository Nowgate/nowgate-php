<?php

declare(strict_types=1);

namespace Nowgate;

/**
 * Amounts are decimal strings in the asset's units, as the API sends and takes them. These
 * helpers convert and compare them exactly, without floating point or bcmath.
 */
final class Amounts
{
    private const DECIMAL = '/\A(0|[1-9][0-9]*)(?:\.([0-9]+))?\z/';

    /**
     * An amount in the asset's smallest units, as a string of digits: toUnits('1.5', 6) is
     * '1500000'. An asset's decimals come from $nowgate->assets->list(). An amount more precise
     * than that is refused.
     *
     * @return string the units' decimal digits
     */
    public static function toUnits(string $amount, int $decimals): string
    {
        self::checkDecimals($decimals);
        [$whole, $fraction] = self::parts($amount, 'amount');
        if (preg_match('/[1-9]/', substr($fraction, $decimals)) === 1) {
            throw new \InvalidArgumentException("{$amount} has more decimals than the asset's {$decimals}");
        }
        $digits = ltrim($whole . str_pad(substr($fraction, 0, $decimals), $decimals, '0'), '0');
        return $digits === '' ? '0' : $digits;
    }

    /** An amount of smallest units, in the API's canonical form: fromUnits('1500000', 6) is '1.5'. */
    public static function fromUnits(string|int $units, int $decimals): string
    {
        self::checkDecimals($decimals);
        if (is_int($units)) {
            if ($units < 0) {
                throw new \InvalidArgumentException("units can't be negative");
            }
            $units = (string) $units;
        } elseif (preg_match('/\A[0-9]+\z/', $units) !== 1) {
            throw new \InvalidArgumentException('units must be a whole number: an int or digits');
        }
        $digits = str_pad(ltrim($units, '0'), $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, strlen($digits) - $decimals);
        $fraction = rtrim(substr($digits, strlen($digits) - $decimals), '0');
        return $fraction === '' ? $whole : "{$whole}.{$fraction}";
    }

    /**
     * Compares two amounts: -1 when a is less than b, 0 when they're equal, 1 when it's more.
     *
     * @return -1|0|1
     */
    public static function compare(string $a, string $b): int
    {
        [$aWhole, $aFraction] = self::parts($a, 'a');
        [$bWhole, $bFraction] = self::parts($b, 'b');
        if (strlen($aWhole) !== strlen($bWhole)) {
            return strlen($aWhole) < strlen($bWhole) ? -1 : 1;
        }
        $length = max(strlen($aFraction), strlen($bFraction));
        $order = strcmp($aWhole . str_pad($aFraction, $length, '0'), $bWhole . str_pad($bFraction, $length, '0'));
        return $order <=> 0;
    }

    /** @return array{string, string} */
    private static function parts(string $amount, string $name): array
    {
        if (preg_match(self::DECIMAL, $amount, $match) !== 1) {
            throw new \InvalidArgumentException("{$name} must be a decimal string, such as \"12.5\"");
        }
        return [$match[1], $match[2] ?? ''];
    }

    private static function checkDecimals(int $decimals): void
    {
        if ($decimals < 0 || $decimals > 255) {
            throw new \InvalidArgumentException("decimals must be the asset's decimals, a whole number from 0 to 255");
        }
    }
}
