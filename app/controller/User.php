<?php

namespace app\controller;

use app\BaseController;

class User extends BaseController
{


    public function getUserInfo(){

    }

    public function createAddress(){

    }

    public function ImportAddress(){

    }

    public function ImportCard(){

    }

    public function login(){
        $username = input('username');
        $password = input('password');

    $rawPassword = "your_password_here";
    $salt = $this->generateSalt();
    $encodedPassword = $this->encode($rawPassword, $salt);
    echo "Encoded password: " . bin2hex($encodedPassword) . "\n";

    // 验证密码
    $isValid = $this->matches($rawPassword, $encodedPassword);
    echo "Password is " . ($isValid ? "valid" : "invalid") . "\n";

    }
    // 生成随机的盐值
    function generateSalt($length = 16) {
        return random_bytes($length);
    }

// 将原始密码和盐值进行拼接并计算摘要
    function encode($rawPassword, $salt) {
        $concatenated = $salt . $rawPassword;
        return hash('sha256', $concatenated, true);
    }

// 验证原始密码与摘要是否匹配
    function matches($rawPassword, $encodedPassword) {
        $saltLength = 16; // 假设盐值长度为 16 字节
        $salt = substr($encodedPassword, 0, $saltLength);
        $expectedEncoded = $this->encode($rawPassword, $salt);
        return hash_equals($expectedEncoded, $encodedPassword);
    }



}