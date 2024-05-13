<?php

namespace app\common\service;
use think\facade\Hash;
use think\facade\Random;

class PasswordEncoder {
    private $saltGenerator;

    public function __construct() {
        $this->saltGenerator = new SaltGenerator();
    }

    public function hashPassword($rawPassword) {
        $salt = $this->saltGenerator->generateKey();
        $encoded = $this->encodeAndConcatenate($rawPassword, $salt);
        return Hex::encode($encoded);
    }

    public function matches($rawPassword, $encodedPassword) {
        $digested = Hex::decode($encodedPassword);
        $salt = substr($digested, 0, $this->saltGenerator->getKeyLength());
        $expected = $this->encodeAndConcatenate($rawPassword, $salt);
        return $this->matchesByteArrays($digested, $expected);
    }

    protected function encodeAndConcatenate($rawPassword, $salt) {
        return EncodingUtils::concatenate([$salt, $this->hashPasswords($rawPassword, $salt)]);
    }

    protected function hashPasswords($rawPassword, $salt) {
        // 使用 ThinkPHP 6 的 Hash 类进行密码哈希加密
        return Hash::make($salt . $rawPassword);
    }

    protected function matchesByteArrays($expected, $actual) {
        // 比较两个字节数组是否相等
        return hash_equals($expected, $actual);
    }
}