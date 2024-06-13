<?php

namespace app\common\service;

use app\common\model\UserCurrencyModel;
use app\common\model\WalletModel;
use think\facade\Db;

class CurrencyService extends ComService
{
    protected UserCurrencyModel $currencyModel;
    protected WalletModel $walletModel;
    public function __construct(UserCurrencyModel $currencyModel,WalletModel $walletModel)
    {
        $this->currencyModel = $currencyModel;
        $this->walletModel = $walletModel;
    }

    public function insertCurrency($params): array
    {
        $data = $this->checkData($params);//过滤无用数据
        //查询用户可用余额
        $wallet = $this->walletModel->where('user_id',$params['user_id'])->find();
        if($wallet['money'] < $params['number']) return errorArray('余额不足');
        Db::startTrans();
        $ret1 = $this->walletModel->where('id',$wallet['id'])->update(['money'=>$wallet['money'] - $params['number']]);
       $ret =  $this->currencyModel->insertGetId($data);
       if(!$ret || ! $ret1) {
           Db::rollback();
           return errorArray('提交申请失败');
       }
       Db::commit();
       return successArray('提交成功');
    }

    public function getCurrency(){
        $ret = $this->currencyModel->order('status')->paginate(tp_page(input('size')));
        return successArray($ret);
    }

    public function setCurrency($id,$type){
        $data = $this->currencyModel->where('id',$id)->find();
        if(!$data) return errorArray('无此参数');
        if($type == 1){
            $ret = $this->currencyModel->update(['status'=>1,'update_time'=>time()]);
            if(!$ret) return errorArray('处理失败');
        }else{
            $ret = $this->currencyModel->update(['status'=>2,'update_time'=>time()]);
            $money = $this->walletModel->where('user_id',$data['user_id'])->value('money');
            $ret1 = $this->walletModel->where('user_id',$data['user_id'])->update(['money'=>$money + $data['number']]);
            if(!$ret || $ret1) return errorArray('处理失败');
        }
        return successArray(['id'=>$id],'处理成功');
    }

    private function checkData($params): array
    {
        return [
            'user_id'=> $params['user_id'],
            'address_id'=>$params['address_id'],
            'number'=>$params['number'],
            'status'=>0,
            'create_time'=>time(),
            'update_time'=>time(),
        ];
    }

}