<?php

namespace app\common\service;

class EncodingUtils
{
    public static function concatenate($arrays) {
        return implode('', $arrays);
    }
}