<?php
declare (strict_types=1);

namespace app\common\service;

use app\common\model\GoodsLossModel;
use app\common\model\OrderModel;
use app\common\model\StockallocationModel;
use think\facade\Db;

class ToolsService
{
    /**
     * 生成随机字符串
     * @param $length
     * @return bool|string
     */
    public static function createRandStr($length)
    {
        $letters_init = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $letters = str_shuffle($letters_init);
        $total = 62 - $length;
        $start = mt_rand(0, $total);
        $rand_str = substr($letters, $start, $length);

        return $rand_str;
    }

    /**
     * 生成用户名user_name
     * @return string
     */
    public static function createUserName()
    {
        $rand_str = self::createRandStr(3);
        $rand_int = mt_rand(10000, 99999);
        $user_name = 'VIP_' . strtoupper($rand_str) . '_' . $rand_int;
        return $user_name;
    }

    /**
     * 生成订单号
     * @param int $is_buy
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function createOrderSn(int $is_buy = 0)
    {
        while (true) {
            $orderSn = date('Ymd') . mt_rand(10000, 99999);
            if ($is_buy == 1) {
                $orderId = Db::table('rd_buy_order')->where('order_sn', $orderSn)->find();
            } else {
                $orderId = Db::table('rd_order')->where('order_sn', $orderSn)->find();
            }
            if (!$orderId) {
                return $orderSn;
                break;
            }
            sleep(1);
        }
    }

    /**生成调拨单号
     * @return string
     */
    public static function createSaSn()
    {
        while (true) {
            $sn = date('Ymd') . mt_rand(10000, 99999);
            $saId = (new StockallocationModel())->where('sn', $sn)->column('id');
            if (!$saId) {
                return $sn;
                break;
            }
            sleep(1);
        }
    }

    /**生成报损单号
     * @return string
     * @author Poison
     * @date 2020/12/18 10:10 下午
     */
    public static function createLossSn()
    {
        while (true) {
            $sn = date('Ymd') . mt_rand(10000, 99999);
            $lossCount = (new GoodsLossModel())->where('sn',$sn)->count();
            if (!$lossCount) {
                return $sn;
                break;
            }
            sleep(1);
        }
    }

    /**判断用户是否是老用户 如果是 返回true 不如不是 返回false
     * @param int $userId
     * @return bool
     * @author Poison
     * @date 2021/1/7 9:37 上午
     */
    public static function searchOrder(int $userId){
        $endTime = time() - 3;
        $orderCount = (new OrderModel())->where('user_id',$userId)->where('add_time < '.$endTime)->count();
        if($orderCount <= 0){
            return false;
        }
        return true;
    }

    /**生成储值卡编号
     * @param $userId
     * @return string
     * @author Poison
     * @date 2021/2/20 10:10 上午
     */
    public static function createPartnerSn($userId){
        return 'RD' . str_repeat('0', 7 - strlen(''.$userId)) . $userId;
    }

    /**生成话费充值编号
     * @param $userId
     * @return string
     * @author Poison
     * @date  2021/6/18 16:06
     */
    public static function createChargingPay(): string
    {
        return 'RD-' . 'PHONE-' . date('YmdHis', time()) . rand(1000, 9999);
    }


}