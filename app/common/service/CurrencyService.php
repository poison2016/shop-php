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
        $wallet = $this->walletModel->where('user_id',$params['user_id'])->find()->toArray();
        if($wallet['money'] < $params['number']) return errorArray('余额不足');
        Db::startTrans();
        $ret1 = $this->walletModel->where('uuid',$wallet['uuid'])->update(['money'=>$wallet['money'] - $params['number']]);
       $ret =  $this->currencyModel->insertGetId($data);
       if(!$ret || ! $ret1) {
           Db::rollback();
           return errorArray('提交申请失败');
       }
       Db::commit();
       return successArray('提交成功');
    }

    public function getCurrency(){
        $ret = $this->currencyModel->order('status,create_time DESC')->paginate(tp_page(input('size')))->each(function ($item){
            $item['username'] = Db::name('tz_user')->where('user_id',$item['user_id'])->value('user_name');
            $address = Db::name('t_user_address')->where('id',$item['address_id'])->find();
            $item['address'] = $address['address'];
            $item['address_type'] = $address['type'] == 1?'TRX':'ETH';
            if($item['status'] == 0){
                $item['status_str'] = '审核中';
            }elseif ($item['status'] == 1){
                $item['status_str'] = '通过';
            }elseif ($item['status'] == 2){
                $item['status_str'] = '驳回';
            }
            return $item;
        });
        return successArray($ret);
    }

    public function setCurrency($id,$type){
        $data = $this->currencyModel->where('id',$id)->find();
        if(!$data) return errorArray('无此参数');
        if($type == 1){
            $ret = $this->currencyModel->where('id',$id)->update(['status'=>1,'update_time'=>time()]);
            if(!$ret) return errorArray('处理失败');
        }else{
            $ret = $this->currencyModel->where('id',$id)->update(['status'=>2,'update_time'=>time()]);
            $money = $this->walletModel->where('user_id',$data['user_id'])->value('money');
            $ret1 = $this->walletModel->where('user_id',$data['user_id'])->update(['money'=>$money + $data['number']]);
            if(!$ret || !$ret1) return errorArray('处理失败');
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