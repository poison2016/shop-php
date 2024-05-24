<?php

namespace app\controller;

use app\BaseController;
use app\common\service\UserService;
use app\Request;
use app\validate\FilterValid;
use think\App;

class User extends BaseController
{
protected UserService $userService;
public function __construct(App $app,UserService $userService)
{
    parent::__construct($app);
    $this->userService = $userService;
}
    public function createAddress(Request $request)
    {
        $params = input('post.');
        $params['user_id'] = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
        $params['type'] = 2;
        return $this->requestData($this->userService->createUserAddress($params));
    }

    public function addressList(Request $request){
        $params['user_id'] = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
        return $this->requestData($this->userService->addressList($params));
    }

    public function getAddressInfo(Request $request){
        $params['user_id'] = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
        $params['address_id'] = input('address_id',0);
        return $this->requestData($this->userService->getAddressInfo($params));
    }

    public function setMoney(Request $request){
        $params['user_id'] = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
        $params['address_id'] = input('address_id',0);
        $params['money'] = input('money',0);
        $params['pay_password'] = input('pay_password','');
        return $this->requestData($this->userService->setMoney($params));
    }

    public function importAddress(Request $request){
        $params = input('post.');
        $params['user_id'] = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
        $params['type'] = 1;
        return $this->requestData($this->userService->createUserAddress($params));
    }

    public function register(){
        $params['username'] = input('username','');
        $params['password'] = input('password','');
        $rule = [
            'username' => ['must', '', '账号不能为空'],
            'password' => ['must', '', '密码不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->userService->registerUser($params));
    }

    public function login(){
        $params['username'] = input('username','');
        $params['password'] = input('password','');
        $rule = [
            'username' => ['must', '', '账号不能为空'],
            'password' => ['must', '', '密码不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->userService->loginUser($params));
    }

    public function payPassword(Request $request){
        $params['user_id'] = $request->comUserId;
        $params['pay_password'] = input('pay_password','');
        $rule = [
            'pay_password' => ['must', '', '支付密码不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->userService->insertPayPassword($params));
    }

    public function ImportCard(){

    }

//    public function login(){
//        $username = input('username');
//        $password = input('password');
//
//    $rawPassword = "123123";
//    $salt = $this->generateSalt();
//    $encodedPassword = $this->encode($rawPassword, $salt);
//     var_dump($encodedPassword);exit();
//    echo "Encoded password: " . bin2hex($encodedPassword) . "\n";
//
//    // 验证密码
//    $isValid = $this->matches($rawPassword, $encodedPassword);
//    echo "Password is " . ($isValid ? "valid" : "invalid") . "\n";
//
//    }
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