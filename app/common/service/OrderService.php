<?php

namespace app\common\service;

use app\common\model\GoodsModel;
use app\common\model\OrderModel;
use app\common\model\UserModel;
use think\facade\Db;

class OrderService extends ComService
{
    protected OrderModel $orderModel;
    protected UserModel $userModel;
    protected GoodsModel $goodsModel;

    public function __construct(OrderModel $orderModel, UserModel $userModel, GoodsModel $goodsModel)
    {
        $this->orderModel = $orderModel;
        $this->userModel = $userModel;
        $this->goodsModel = $goodsModel;
    }

    public function createOrder(array $params)
    {
        $uid = (int)$params['user_id'];
        $id = (int)$params['goods_id'];
        $number = (int)$params['number'];
        $userData = $this->userModel->where('id', $params['user_id'])->find();
        if (!$userData) return errorArray('用户不存在');
        $goodsData = (new GoodsService())->getGoodsInfo($params['goods_id']);
        if (empty($goodsData['data'])) return errorArray('资产不存在');
        $goodsInfo = $goodsData['data'];
        unset($goodsData);
        if ($params['number'] * $goodsInfo['price'] > $userData['balance']){
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
        try {


            //订单表写入

            if ($goodsInfo['surplus'] == $number) {
                //Db::name('contract')->where('id', $id)->update(['state' => 1]);
            }

            if ($goodsInfo['countdown'] == 1) {
                $dDate = strtotime($goodsInfo['countdown_time']);
                $sss = 3600;
                if ($goodsInfo['cycle'] == 2) $sss = 86400;
                $terms = ceil(($dDate - time()) / $sss);
            }
            if($terms > $goodsInfo['term']) $terms = $goodsInfo['term'];
//        try {
            $insertOrderData = [
                'contract_id' => $id,
                'number' => $number,
                'price' => $goodsInfo['amount'],
                'total_amount' => $goodsInfo['amount'] * $number,
                'pay_amount' => $payPrice,
                'discount_amount' => $couponPrice,
                'user_id' => $uid,
                'pay_time' => getDataTime(),
                'create_time' => getDataTime(),
                'create_user' => $uid,
                'coupon_id' => $couponId,
                'order_sn' => date('YmdHis', time()) . rand(10000, 99999),
                'yields'=>float_number_format($goodsInfo['yield'] + $level_interest),
                'terms' =>$terms
            ];
            $orderId = Db::name('contract_order')->insertGetId($insertOrderData);

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
                    $res = Db::name('order_bonus')->insert([
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
                $res = Db::name('order_bonus')->insert([
                    'order_id' => $orderId,
                    'user_id' => $uid,
                    'money' => $mmp,
                    'create_time' => $outTime,
                    'is_send' => 0,
                    'principal' => $principals,
                ]);
            }


            Db::name('contract')->where('id', $id)->dec('surplus', $number)->update();
            //如果有优惠券 核销
            if ($couponId) {
                $ret = Db::name('coupon')->where('id', $couponId)->update(
                    [
                        'state' => 1,
                        'update_time' => getDataTime(),
                        'update_user' => $uid
                    ]
                );
                if (!$ret) {
                    Db::rollback();
                    return errorArray('订单创建失败 错误码-ORDER-1014');
                }
            }
            //降低用户余额 增加消费额度
            $ret = Db::name('user')->where('id', $uid)
                ->update([
                    'money' => $userData['money'] - $payPrice,
                    'pay_money' => $userData['pay_money'] + $payPrice,
                    'contract_amount' => $userData['contract_amount'] + $payPrice,
                    'position' => $userData['position'] + $goodsInfo['amount'] * $number,
                    'score' => $userData['score'] + $payPrice  * 10,
                    'total_score' => $userData['total_score'] + $payPrice  * 10,
                    'coupon_amount'=>$userData['coupon_amount'] + $couponPrice,
                ]);
            if (!$ret) {
                Db::rollback();
                return errorArray('订单创建失败 错误码-ORDER-1015');
            }

            //写入余额变动日志
            LogService::userMoneyLog($userData, $payPrice, 2, '购买资产包' , '购买资产包', 3);
            //LogService::userScoreLog($userData, $payPrice * 10, 1, '购买资产包赠送积分', '购买资产包赠送积分', 4);
            //向上分三级
//            if ($goodsInfo['type'] != 3) {
//                $bool = BingService::topPrice($this->auth->getUserId(), $payPrice, $goodsInfo['divide_id']);
//                if (!$bool) {
//                    Db::rollback();
//                    $this->error('订单创建失败 错误码-ORDER-1016');
//                }
//            }
            Db::commit();
            return successArray(['goods_id'=>$id]);
            //团队等级
            //(new BingService())->incBing($uid);
            //个人等级
            //BingService::incUser($userData, $userData['pay_money'] + $payPrice);
//        }catch (\Exception $e){
//            Db::rollback();
//            $this->error('下单失败 服务器异常 联系管理员处理');
//        }


        }catch (\Exception $exception){
            trace($exception,'error');
            return errorArray('程序异常');
        }
    }


}