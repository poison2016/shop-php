<?php

namespace app\common\service;

use app\common\model\UserAddressModel;
use app\common\model\UserModel;
use think\facade\Db;

class UsdtService extends ComService
{
    protected UserAddressModel $userAddressModel;
    protected UserModel $userModel;
    protected TrxService $trxService;
    protected EthService $ethService;
    public function __construct(UserAddressModel $userAddressModel,UserModel $userModel,TrxService $trxService,EthService $ethService)
    {
        $this->userModel = $userModel;
        $this->userAddressModel = $userAddressModel;
        $this->trxService = $trxService;
        $this->ethService = $ethService;
    }

    public function getUserAddressList($userId): array
    {
       $data = $this->userAddressModel->field('id,user_id,address,type,name')->where('user_id',$userId)->where('is_delete',0)->select()->toArray();
       foreach ($data as &$v){
           $v['address_sub'] = customSubstr($v['address']);
            if($v['type'] == 1){//trx
                $trx = $this->trxService->getBalance($v['address'])['data'];
                $v['balance'] = 'TRX:'.formatNumber($trx['balance']);
                $v['usdt_balance'] = 'USDT:'.formatNumber($trx['usdt_balance']);
            }else{
                $v['balance'] = 'ETH:'.$this->ethService->getEthMoney($v['address']);
                $v['usdt_balance'] = 'USDT:'.$this->ethService->getUsdtMoney($v['address']);
            }
       }
       return successArray($data);
    }

    public function delAddress($id,$userId){
        $ret = $this->userAddressModel->where('id',$id)->where('user_id',$userId)->update(['is_delete'=>1]);
        if(!$ret) return errorArray('删除失败');
        return successArray(['id'=>$id],'删除成功');
    }

    public function SendMoney($params){
        $userAddressInfo = $this->userAddressModel->where(['address'=>$params['address'],'user_id'=>$params['user_id']])->find();
        if(!$userAddressInfo) return errorArray('地址不存在');
        if($userAddressInfo['password'] !== md5Password($params['pay_password'])) return  errorArray('钱包密码不正确');
        if($userAddressInfo['type'] == 1){//trx
            return $this->trxService->transfer($params['pay_address'],$params['money'],$userAddressInfo['prv_key'],$userAddressInfo['address'],$params['user_id']);
        }else{//Eth
           return $this->ethService->payUsdt($params['address'],$params['pay_address'],$userAddressInfo['prv_key'],$params['money'],$params['user_id']);
        }

    }

    public function getAddressInfo($params)
    {
        $userAddressInfo = $this->userAddressModel->field('id,user_id,address,type,name')->where(['address' => $params['address'], 'user_id' => $params['user_id']])->find();
        if (!$userAddressInfo) return errorArray('地址不存在');
        if ($userAddressInfo['type'] == 1) {
            $trx = $this->trxService->getBalance($userAddressInfo['address'])['data'];
            $userAddressInfo['usdt_balance'] = formatNumber($trx['usdt_balance']);
        }else{
            $userAddressInfo['usdt_balance'] = $this->ethService->getUsdtMoney($userAddressInfo['address']);
        }
        return successArray($userAddressInfo);
    }

    public function saveAddress($params){
        $params['create_time'] = time();
        $params['password'] = md5Password($params['pay_password']);
        if($params['type'] == 2){
            $params['prv_key'] = '0x'.$params['prv_key'];
        }
        unset($params['pay_password']);
        $res = $this->userAddressModel->where('address',$params['address'])->find();
        if($res) return errorArray('该地址已添加');
        $ret = $this->userAddressModel->insert($params);
        if(!$ret) return errorArray('提交失败');
        return successArray();
    }

    public function getList($params){
        $userAddressInfo = $this->userAddressModel->where(['address'=>$params['address'],'user_id'=>$params['user_id']])->find();
        if(!$userAddressInfo) return errorArray('地址不存在');
        if($userAddressInfo['type'] == 1){
            return $this->trxService->getTrxList($params['address']);
        }else{
            return $this->ethService->getOrderList($params['address']);
        }
        return  successArray();
    }

    public function  test(string $address){
        //$ret = $this->trxService->transfer('TF7hR99wuqwHWW6cPQG5kF66qb6RSTVowi',1,'8589e0113e5a9d6e5e73d6368bbb8014046d697c2d425cb408e90c5ee4e9016d','TX6Fvj7vzpMeftE725yUqjQdGnAC5XkUcc');
        $ret = $this->ethService->payUsdt('0x746092d881A40E3306461326B5cE0ccc64bA9696','0x735Fd33BF0dbaB1f9C5d8eC5E43e14cf71556D91','a2013732cdb28703aa518708500e128f468ef8a4d41e0e586cd3f2393794e4d9',1,123);
        return $ret;
    }

    public function getAdminList($type){
        $data = Db::name('t_admin_address')->where('type',$type)->order('id DESC')->find();
        return successArray($data);
    }

}