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
       $data = $this->userAddressModel->field('id,user_id,address,type,name')->where('user_id',$userId)->select()->toArray();
       foreach ($data as &$v){
           $v['address_sub'] = customSubstr($v['address']);
            if($v['type'] == 1){//trx
                $trx = $this->trxService->getBalance($v['address'])['data'];
                $v['balance'] = 'TRX:'.formatNumber($trx['balance']);
                $v['usdt_balance'] = 'USDT:'.formatNumber($trx['usdt_balance']);
            }
       }
       return successArray($data);
    }

    public function SendMoney($params){
        $userAddressInfo = $this->userAddressModel->where(['address'=>$params['address'],'user_id'=>$params['user_id']])->find();
        if(!$userAddressInfo) return errorArray('地址不存在');
        if($userAddressInfo['password'] !== md5Password($params['pay_password'])) return  errorArray('钱包密码不正确');
        if($userAddressInfo['type'] == 1){//trx
            return $this->trxService->transfer('',$params['money'],$userAddressInfo['prv_key'],$userAddressInfo['address'],$params['user_id']);
        }else{//Eth

        }

    }

    public function  test(string $address){
        //$ret = $this->trxService->transfer('TF7hR99wuqwHWW6cPQG5kF66qb6RSTVowi',1,'8589e0113e5a9d6e5e73d6368bbb8014046d697c2d425cb408e90c5ee4e9016d','TX6Fvj7vzpMeftE725yUqjQdGnAC5XkUcc');
        $ret = $this->trxService->getTrxList($address);
        return $ret;
    }

}