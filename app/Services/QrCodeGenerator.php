<?php

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

class QrCodeGenerator
{
    public static function svg(string $data, int $size = 240): string
    {
        $qrCode = new QrCode(data: $data, size: $size, margin: 8);

        return (new SvgWriter())->write($qrCode)->getString();
    }

    public static function dataUri(string $data, int $size = 240): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $size));
    }
}
