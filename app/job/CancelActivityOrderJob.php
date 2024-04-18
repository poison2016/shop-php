<?php
declare(strict_types=1);

namespace app\job;



use app\common\model\FamilyActivityOrderModel;
use app\common\service\ComService;
use app\common\service\FamilyActivityOrderService;
use think\facade\Log;
use think\queue\Job;

/**
 * 亲子活动取消订单定时任务
 * @package app\job
 * @author yangliang
 * @date 2021/4/23 16:54
 */
class CancelActivityOrderJob
{

    /**
     * 订单超30分钟未支付，取消订单
     * @param Job $job
     * @param $order_id
     * @throws \Exception
     * @author: yangliang
     * @date: 2021/4/26 15:50
     */
    public function fire(Job $job, $order_id){
            if ($job->attempts() > 3) {
                $job->delete();
            }

            $order = FamilyActivityOrderModel::getById((int)$order_id);
            if (empty($order) || $order['status'] != 0) {
                $job->delete();
                return;
            }

            $res = (new FamilyActivityOrderService())->cancelOrder($order['user_id'], (int)$order_id);
            if($res['code'] != 200){
                throw new \Exception($res['message']);
            }

            $job->delete();
    }



    public function failed($data, $e){
        (new ComService())->sendDingError('亲子活动取消订单任务失败，订单ID：'.$data.'。mesg：'.$e->getMessage(), $e);
    }
}