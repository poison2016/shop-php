<?php

namespace app\common\service;

use app\common\model\UserAddressModel;
use app\common\model\UserModel;

class UsdtService extends ComService
{
    protected UserAddressModel $userAddressModel;
    protected UserModel $userModel;
    protected TrxService $trxService;
    public function __construct(UserAddressModel $userAddressModel,UserModel $userModel,TrxService $trxService)
    {
        $this->userModel = $userModel;
        $this->userAddressModel = $userAddressModel;
        $this->trxService = $trxService;
    }

    public function getUserAddressList($userId): array
    {
       $data = $this->userAddressModel->where('user_id',$userId)->select()->toArray();
       return successArray($data);
    }

    public function  test(string $address){
        $ret = $this->trxService->getTrxList($address);
        return $ret;
    }

}