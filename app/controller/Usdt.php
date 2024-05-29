<?php

namespace app\controller;

use app\BaseController;
use app\common\service\UsdtService;
use app\Request;
use app\validate\FilterValid;
use think\App;
use think\Response;

class Usdt extends BaseController
{
    protected UsdtService $usdtService;
    public function __construct(App $app,UsdtService $usdtService)
    {
        parent::__construct($app);
        $this->usdtService = $usdtService;
    }

    public function userWalletList(Request $request){
        $userId = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
       return $this->requestData($this->usdtService->getUserAddressList($userId));
    }

    public function walletPay(Request $request): Response
    {
        $params['user_id'] = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
        $params['address'] = input('address','');
        $params['money'] = input('money','');
        $params['pay_password'] = input('pay_password','');
        $rule = [
            'address' => ['must', '', '地址不能为空'],
            'money' => ['must', '', '金额不能为空'],
            'pay_password' => ['must', '', '钱包密码不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->usdtService->SendMoney($params));
    }

    public function test(){
        return $this->requestData($this->usdtService->test(input('address')));
    }

}