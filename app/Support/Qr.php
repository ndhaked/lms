<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR codes as inline SVG (no image library). A phone or any scanner reads the text back, e.g. an accession number. */
class Qr
{
    public static function svg(string $text, int $size = 96): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($text);

        // drop the XML declaration so the markup can sit inside an HTML page
        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }
}
