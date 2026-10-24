<?php

namespace App\Support;

class Isbn
{
    /** Strip spaces and hyphens; upper-case a trailing x. Returns null for blank input. */
    public static function normalize(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/[\s\-]/', '', (string) $value));

        return $value === '' ? null : $value;
    }

    /** A valid ISBN-10 or ISBN-13 including its check digit (so a mistyped or mis-scanned code is caught). */
    public static function valid(?string $value): bool
    {
        $isbn = self::normalize($value);
        if ($isbn === null) {
            return false;
        }

        if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
            $sum = 0;
            foreach (str_split($isbn) as $i => $c) {
                $sum += ($c === 'X' ? 10 : (int) $c) * (10 - $i);
            }

            return $sum % 11 === 0;
        }

        if (preg_match('/^\d{13}$/', $isbn)) {
            $sum = 0;
            foreach (str_split($isbn) as $i => $c) {
                $sum += (int) $c * ($i % 2 === 0 ? 1 : 3);
            }

            return $sum % 10 === 0;
        }

        return false;
    }
}
