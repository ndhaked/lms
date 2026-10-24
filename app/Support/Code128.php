<?php

namespace App\Support;

/**
 * Code 128 (subset B) barcodes as inline SVG, so labels print without any image library.
 * Every common handheld/USB barcode scanner reads Code 128.
 */
class Code128
{
    /** Bar/space widths for values 0-105 (start codes are 103-105); the stop pattern is separate. */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];

    public const STOP = '2331112';

    /** Can this text be encoded? (printable ASCII only) */
    public static function supports(string $text): bool
    {
        return $text !== '' && preg_match('/^[\x20-\x7E]+$/', $text) === 1;
    }

    /** @return list<int> the symbol values: start B, data, checksum, stop is added when drawing */
    public static function values(string $text): array
    {
        if (! self::supports($text)) {
            throw new \InvalidArgumentException('Code 128 text must be printable ASCII.');
        }

        $values = [104];
        foreach (str_split($text) as $i => $char) {
            $values[] = ord($char) - 32;
        }

        $sum = 104;
        foreach (array_slice($values, 1) as $i => $v) {
            $sum += $v * ($i + 1);
        }
        $values[] = $sum % 103;

        return $values;
    }

    /** The bar/space width digits for the whole symbol, in order, ending with the stop pattern. */
    public static function widths(string $text): string
    {
        return implode('', array_map(fn ($v) => self::PATTERNS[$v], self::values($text))).self::STOP;
    }

    public static function svg(string $text, int $height = 40, int $module = 2): string
    {
        $x = 10 * $module;                       // quiet zone
        $bars = '';
        foreach (str_split(self::widths($text)) as $i => $w) {
            $width = (int) $w * $module;
            if ($i % 2 === 0) {
                $bars .= '<rect x="'.$x.'" y="0" width="'.$width.'" height="'.$height.'"/>';
            }
            $x += $width;
        }
        $total = $x + 10 * $module;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$height.'" width="'.$total.'" height="'.$height.'" preserveAspectRatio="none" role="img" aria-label="'.e($text).'" fill="#000">'.$bars.'</svg>';
    }
}
