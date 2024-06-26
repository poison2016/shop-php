<?php

namespace app\common\service;

use app\common\model\GoodsModel;
use app\common\model\OrderBonusModel;
use app\common\model\OrderModel;
use app\common\model\UserModel;
use app\common\model\WalletModel;
use think\facade\Db;

class OrderService extends ComService
{
    protected OrderModel $orderModel;
    protected UserModel $userModel;
    protected GoodsModel $goodsModel;
    protected OrderBonusModel $orderBonusModel;
    protected WalletModel $walletModel;
    protected GoodsService $goodsService;

    public function __construct(OrderModel $orderModel, UserModel $userModel,
                                GoodsModel $goodsModel,OrderBonusModel $orderBonusModel,
                                WalletModel $walletModel,GoodsService $goodsService)
    {
        $this->orderModel = $orderModel;
        $this->userModel = $userModel;
        $this->goodsModel = $goodsModel;
        $this->orderBonusModel = $orderBonusModel;
        $this->walletModel = $walletModel;
        $this->goodsService = $goodsService;
    }

    public function createOrder(array $params)
    {
        $uid = $params['user_id'];
        $id = (int)$params['goods_id'];
        $number = (int)$params['number'];
        $userData = $this->walletModel->where('user_id', $params['user_id'])->find();
        if (!$userData) return errorArray('用户不存在');
        $goodsData = $this->goodsService->getGoodsInfo($params['goods_id']);
        if (empty($goodsData['data'])) return errorArray('资产不存在');
        $goodsInfo = $goodsData['data'];
        unset($goodsData);
        $payPrice = $params['number'] * $goodsInfo['amount'];
        if ($payPrice > $userData['money']){
            return errorArray('余额不足');
        }
        if($goodsInfo['surplus'] <= 0) return errorArray('已停止申购');
        if($goodsInfo['state'] == 1) return errorArray('已停止申购');

        //判断是否还能购买
            if ($goodsInfo['surplus'] < $number) return errorArray('数量不足');
            //判断是否是否在倒计时 如果是 判断是否超过
            if ($goodsInfo['countdown'] == 1) {
                if (strtotime($goodsInfo['countdown_time']) < time()) {
                    return errorArray('已停止申购');
                }
            }



        Db::startTrans();//启动事务
//        try {

            //订单表写入

            if ($goodsInfo['surplus'] == $number) {
                $this->goodsModel->where('id', $id)->update(['state' => 1]);
            }
//            if ($goodsInfo['countdown'] == 1) {
//                $dDate = strtotime($goodsInfo['countdown_time']);
//                $sss = 3600;
//                if ($goodsInfo['cycle'] == 2) $sss = 86400;
//                $terms = ceil(($dDate - time()) / $sss);
//            }
//            if($terms > $goodsInfo['term'])
                 $terms = $goodsInfo['terms'];
//        try {
            $insertOrderData = [
                'contract_id' => $id,
                'number' => $number,
                'price' => $goodsInfo['amount'],
                'total_amount' => $goodsInfo['amount'] * $number,
                'pay_amount' => $payPrice,
                'discount_amount' => 0,
                'user_id' => $uid,
                'pay_time' => getDataTime(),
                'create_time' => getDataTime(),
                'create_user' => $uid,
                'coupon_id' => 0,
                'order_sn' => date('YmdHis', time()) . rand(10000, 99999),
                'yields'=>$goodsInfo['yield'],
                'terms' =>$terms
            ];
            $orderId = $this->orderModel->insertGetId($insertOrderData);

            if (!$orderId) {
                Db::rollback();
                return errorArray('订单创建失败 错误码-ORDER-1013');
            }
            $t = time();
            $mmp = 0;
            $outTime = 0;
            $principals = 0;
            for ($i = 1; $i <= $terms; $i++) {
                $isCreateTime = $t + 3600 * $i;
                if ($goodsInfo['cycle'] == 2) {//天
                    $isCreateTime = $t + 86400 * $i;
                }
                //每次收益金额
                $pricess = sprintf("%.2f", $goodsInfo['purchase_amount'] * ($insertOrderData['yields'] / 100) * $number);
                $principal = 0;
                if ($i == $terms) {
                    $outTime = $isCreateTime;
                    $pricess += $insertOrderData['total_amount'];
                    $principal = $insertOrderData['total_amount'];
                    $principals = $insertOrderData['total_amount'];
                }
                $mmp += $pricess;
                if ($goodsInfo['revenue_type'] == 2) {//天
                    $res = $this->orderBonusModel->insert([
                        'order_id' => $orderId,
                        'user_id' => $uid,
                        'money' => $pricess,
                        'create_time' => $isCreateTime,
                        'is_send' => 0,
                        'principal' => $principal,
                    ]);
                }
            }
            if ($goodsInfo['revenue_type'] != 2) {//天
                $res = $this->orderBonusModel->insert([
                    'order_id' => $orderId,
                    'user_id' => $uid,
                    'money' => $mmp,
                    'create_time' => $outTime,
                    'is_send' => 0,
                    'principal' => $principals,
                ]);
            }
            $this->goodsModel->where('id', $id)->dec('surplus', $number)->update();
            //降低用户余额 增加消费额度
            $ret = $this->walletModel->where('user_id', $uid)
                ->dec('money',$payPrice)
                ->update(['update_time_ts'=>time()]);
            if (!$ret) {
                Db::rollback();
                return errorArray('订单创建失败 错误码-ORDER-1015');
            }
        Db::name('t_money_log')->insert([
            'uuid'=>time().rand(10000,99999).rand(1000,9999),
            'log'=>'综合下单 订单:'.$insertOrderData['order_sn'],
            'wallet_type'=>'usdt',
            'create_time'=>date('Y-m-d H:i:s',time()),
            'user_id'=>$uid,
            'amount'=>-$insertOrderData['total_amount'],
            'amount_before'=>$userData['money'],
            'amount_after'=>(double)$userData['money'] - (double)$insertOrderData['total_amount'],
            'content_type'=>'finance',
            'category'=>'finance',
            'update_time'=>date('Y-m-d H:i:s',time()),
            'del_flag'=>0,
            'create_time_ts'=>time(),
            'update_time_ts'=>time(),
            'symbol'=>'btcusdt',
        ]);
        //向上分三级
            $this->topLevel3($uid,$mmp - $insertOrderData['total_amount'],1,$insertOrderData['order_sn']);
            //写入余额变动日志
            //LogService::userMoneyLog($userData, $payPrice, 2, '购买资产包' , '购买资产包', 3);
            //LogService::userScoreLog($userData, $payPrice * 10, 1, '购买资产包赠送积分', '购买资产包赠送积分', 4);

//            if ($goodsInfo['type'] != 3) {
//                $bool = BingService::topPrice($this->auth->getUserId(), $payPrice, $goodsInfo['divide_id']);
//                if (!$bool) {
//                    Db::rollback();
//                    $this->error('订单创建失败 错误码-ORDER-1016');
//                }
//            }
            Db::commit();
            return successArray(['goods_id'=>$id]);



//        }catch (\Exception $exception){
//            trace($exception,'error');
//            return errorArray('程序异常');
//        }
    }

    public function topLevel3($userId,$money,$level,$orderSn){
        $userIds = Db::name('tz_user_recom')->where('recom_user_id',$userId)->value('user_id');
        if(!$userIds) return false;
        $sysName = 'first_layer';
        if($level == 2) $sysName = 'second_layer';
        if($level == 3) $sysName = 'third_layer';
        $fc = Db::name('t_syspara')->where('CODE',$sysName)->value('SVALUE');
        $moneys = sprintf("%.2f", $money * $fc);
        $lowMoney = $this->walletModel->where('user_id',$userIds)->value('money');
        $this->walletModel->where('user_id',$userIds)->setInc('money',$moneys);
        Db::name('t_userdata')->where('user_id',$userIds)->setInc('recharge_recom',$moneys);
        Db::name('t_money_log')->insert([
           'uuid'=>time().rand(10000,99999).rand(1000,9999),
            'log'=>'下级综合返佣 订单:'.$orderSn,
            'wallet_type'=>'usdt',
            'create_time'=>date('Y-m-d H:i:s',time()),
            'user_id'=>$userIds,
            'amount'=>$moneys,
            'amount_before'=>$lowMoney,
            'amount_after'=>(double)$lowMoney + (double)$moneys,
            'content_type'=>'finance_recom_profit',
            'category'=>'finance',
            'update_time'=>date('Y-m-d H:i:s',time()),
            'del_flag'=>0,
            'create_time_ts'=>time(),
            'update_time_ts'=>time(),
            'symbol'=>'btcusdt',
        ]);
        $level++;
        if($level <= 3){
            $this->topLevel3($userIds,$money,$level,$orderSn);
        }
        return true;
    }

    public function getList(array $params){
        $where['o.status'] = $params['type'];
        $where['o.user_id'] = $params['user_id'];
        $data = $this->orderModel->alias('o')->field('o.*,g.img,g.contract_name,g.cycle')
            ->join('t_goods g','g.id = o.contract_id','LEFT')
            ->where($where)->select()->toArray();
        return successArray($data);
    }

    public function getInfo(string $userId,int $orderId){
        $data = $this->orderModel->alias('o')
            ->field('o.*,g.img,g.contract_name,g.banner')
            ->join('t_goods g','g.id = o.contract_id','LEFT')
            ->where(['o.user_id'=>$userId,'o.id'=>$orderId])->find()->toArray();

        $data['banner'] = explode(',',$data['banner']);
        $list = $this->orderBonusModel->where(['order_id'=>$orderId,'user_id'=>$userId])
            ->select()->toArray();
        $data['sum_money'] = $this->orderBonusModel->where(['order_id'=>$orderId,'user_id'=>$userId])
            ->sum('money');
        $data['ok_money'] = $this->orderBonusModel->where(['order_id'=>$orderId,'user_id'=>$userId,'is_send'=>1])
            ->sum('money');
        foreach ($list as &$v){
            $v['is_send_text'] = $v['is_send']? '已发放':'未发放';
        }
        $data['list'] = $list;
        return successArray($data);
    }


}