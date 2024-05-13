<?php

namespace app\common\service;

class SaltGenerator
{
    public function generateKey() {
        return random_bytes(16); // 生成随机的盐值，长度为 16 字节
    }

    public function getKeyLength() {
        return 16; // 返回盐值长度
    }

}