<?php

namespace app\controller;

use app\BaseController;
use app\common\service\UsdtService;
use app\Request;
use think\App;

class Usdt extends BaseController
{
    protected UsdtService $usdtService;
    public function __construct(App $app,UsdtService $usdtService)
    {
        parent::__construct($app);
        $this->usdtService = $usdtService;
    }

    public function userWalletList(Request $request){
       return $this->requestData($this->usdtService->getUserAddressList($request->comUserId));
    }

    public function test(){
        return $this->requestData($this->usdtService->test(input('address')));
    }

}