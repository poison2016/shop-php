<?php

namespace app\common\service;

use InvalidArgumentException;

final class Hex
{
    private const HEX = "0123456789abcdef";

    private function __construct() {}

    public static function encode($bytes) {
        $nBytes = strlen($bytes);
        $result = '';
        for ($i = 0; $i < $nBytes; ++$i) {
            $byte = ord($bytes[$i]);
            $result .= self::HEX[($byte & 0xf0) >> 4] . self::HEX[$byte & 0x0f];
        }
        return $result;
    }

    public static function decode($s) {
        $nChars = strlen($s);
        if ($nChars % 2 != 0) {
            throw new InvalidArgumentException("Hex-encoded string must have an even number of characters");
        }

        $result = '';
        for ($i = 0; $i < $nChars; $i += 2) {
            $msb = hexdec($s[$i]);
            $lsb = hexdec($s[$i + 1]);
            if ($msb === false || $lsb === false) {
                throw new InvalidArgumentException("Detected a Non-hex character at " . ($i + 1) . " or " . ($i + 2) . " position");
            }
            $result .= chr(($msb << 4) | $lsb);
        }

        return $result;
    }
}