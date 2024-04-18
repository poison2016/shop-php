<?php

/**
 * 订单订阅事件
 */

namespace app\subscribe;


use app\common\model\GoodsModel;
use app\common\model\GoodsStockModel;
use app\common\model\JdOrderGoodsModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\OrderPubModel;
use app\common\model\OrderStockLogModel;
use app\common\model\OrderWaybillModel;
use app\common\model\PurchaseSelfGoodsModel;
use app\common\model\ReturnGoodsModel;
use app\common\model\StockallocationModel;
use app\common\model\UserCardLogsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\service\LogService;
use app\common\service\SendMessageService;
use app\common\service\SmartSendService;
use app\common\service\ThirdPartyOrderService;
use app\common\service\UserCardService;
use think\facade\Log;
use Exception;
use wechat\Logistics;

class OrderSub
{

    private $sLog;
    private $sUserCard;
    private $sSmartSend;
    private $sSendMessage;

    private $mUser;
    private $mGoodsStock;
    private $mOrderStockLog;
    private $mJdOrderGoods;
    private $mOrder;
    private $mOrderGoods;
    private $mStockallocation;
    private $mReturnGoods;
    private $mOrderPub;


    public function __construct()
    {
        $this->sLog = new LogService;
        $this->sUserCard = new UserCardService;
        $this->sSmartSend = new SmartSendService();
        $this->sSendMessage = new SendMessageService();

        $this->mUser = new UserModel();
        $this->mGoodsStock = new GoodsStockModel();
        $this->mOrderStockLog = new OrderStockLogModel();
        $this->mJdOrderGoods = new JdOrderGoodsModel();
        $this->mOrder = new OrderModel();
        $this->mOrderGoods = new OrderGoodsModel();
        $this->mStockallocation = new StockallocationModel();
        $this->mReturnGoods = new ReturnGoodsModel();
        $this->mOrderPub = new OrderPubModel();

    }


    /**
     * 取消订单
     * @param array $data
     * @throws Exception
     * @author yangliang
     * @date 2020/12/7 13:55
     */
    public function onCancelOrder(array $data): void
    {
        if ($data['order']['is_jd'] == 1) {
            //取消京东订单
            self::cancelJDOrder($data['user_id'], $data['order'], $data['note'], $data['is_auto']);
        } else {
            //取消睿鼎订单
            self::cancelReadingOrder($data['user_id'], $data['order'], $data['note'], $data['is_auto']);
        }
    }


    /**
     * 取消睿鼎订单
     * @param int $user_id 用户ID
     * @param array $order 订单信息
     * @param string $note 备注信息
     * @param int $is_auto 是否系统取消   0-否    1-是
     * @param int $source 请求来源  0-取消订单  1-取消睿鼎订单  2-取消京东订单
     * @throws Exception
     * @author yangliang
     * @date 2020/12/4 15:20
     */
    private function cancelReadingOrder(int $user_id, array $order, string $note, int $is_auto, int $source = 0,int $isNumber = 0): void
    {
        //待付款、配书中、核验中不能取消订单
        if (!in_array($order['order_status'], [0, 1, 2])) {
            return;
        }

        $order_goods = OrderGoodsModel::getByOrderId($order['order_id']);
        if (empty($order_goods)) {
            throw new Exception('订单数据异常');
        }

        //更新订单状态无效
        self::changeCancelOrder($order['order_id'], $user_id, $is_auto, $note);

        //更新订单商品已归还已完成
        self::changeCancelOrderGoods($order['order_id']);

        //更新订单商品库存,返还库存
        self::changeOrderGoodsStock($order_goods, $user_id, $order['store_id'], $order['order_sn']);

        //更新订单还书,删除还书信息
        self::changeOrderReturnGoods($order['order_id']);

        //更新订单调拨单商品调出门店库存信息
        self::changeOrderStockallocation($order['order_id'], $user_id);

       if($isNumber == 0){
           //更新订单回退年卡配送次数
           self::changeReturnCardNum($order['order_id']);
       }

        //更新订单赠品
        self::changeOrderPub($order['order_id']);

        if ($source == 0 && $order['order_id'] > 0) {  //只有请求来源是取消订单才验证京东订单，否则只取消睿鼎订单
            //验证是否存在京东订单，若存在京东订单则一并取消
            $jd_order = OrderModel::getByOwnerOrderId($order['order_id']);
            if (!empty($jd_order)) {
                self::cancelJDOrder($user_id, $jd_order, $note, $is_auto, 1,1);
            }
        }

        //订单取消成功发送小程序订阅消息
        self::sendSubscribeMsg($user_id, $order, $order_goods, $note);

        //订单取消成功发送公众号消息
        self::sendTemplateMsg($user_id, $order, $order_goods);

        //取消京东物流
        $order_waybill = OrderWaybillModel::getByOrderSnAndType($order['order_sn'], 1);
        if(!empty($order_waybill)){
            $cancel_res = Logistics::getInstance()->cancelOrder([
                'order_id' => $order_waybill['order_sn'],
                'delivery_id' => $order_waybill['delivery_id'],
                'waybill_id' => $order_waybill['waybill_id']
            ]);

            //取消成功，更新物流运单为已取消
            if($cancel_res['errcode'] == 0){
                OrderWaybillModel::where('id', $order_waybill['id'])->update(['status' => '8', 'update_time' => time()]);
            }
        }
        //TODO 补充后续业务
    }


    /**
     * 取消京东订单
     * @param int $user_id 用户ID
     * @param array $order 订单信息
     * @param string $note 备注信息
     * @param int $is_auto 是否系统取消   0-否    1-是
     * @param int $source 请求来源  0-取消订单  1-取消睿鼎订单  2-取消京东订单
     * @param int $isNumber 如果为1 不进行次数退回
     * @throws Exception
     * @author yangliang
     * @date 2020/12/4 15:15
     */
    private function cancelJDOrder(int $user_id, array $order, string $note, int $is_auto, int $source = 0,int $isNumber = 0): void
    {

        $order_goods = OrderGoodsModel::getByOrderId($order['order_id']);
        if (empty($order_goods)) {
            throw new Exception('订单数据异常');
        }

        //取消订单表-京东子表未向京东单的订单
        $jd_goods_res = $this->mJdOrderGoods->where('order_id', $order['order_id'])
            ->whereIn('status', [0, 1])->update(['status' => -1, 'update_time' => time()]);
//        if (!$jd_goods_res) {
//            throw new Exception('京东订单取消失败');
//        }

        $user_id = $user_id ?? $order['user_id'];

        //取消订单
        self::changeCancelOrder($order['order_id'], $user_id, 0, $note);

        //更新订单商品
        self::changeCancelOrderGoods($order['order_id']);
        //判断是否是第三方库 修改为无效
        $thirdPartyOrder = ThirdPartyOrderService::checkIsOrder((int)$user_id, (int)$order['order_id']);
        if (!$thirdPartyOrder) {//这是开关
            //更新订单商品库存
            self::changeOrderGoodsStock($order_goods, $user_id, $order['store_id'], $order['order_sn']);
        } else {
            //更新订单商品库存
            self::changeOrderGoodsStock($order_goods, $user_id, $order['store_id'], $order['order_sn']);
            self::delThirdPartyOrder((int)$user_id, (int)$order['order_id']);
        }
        //更新订单还书
        self::changeOrderReturnGoods($order['order_id']);

        //处理订单赠品
       if($isNumber == 0){
           //更新订单回退年卡配送次数
           self::changeReturnCardNum($order['order_id']);
       }

        if ($source == 0 && $order['owner_order_id'] > 0) {  //只有请求来源是取消订单才验证睿鼎订单，否则只取消京东订单
            //验证是否存在睿鼎订单，若存在睿鼎订单则一并取消
            $reading_order = OrderModel::getByOrderId($order['owner_order_id']);
            if (!empty($reading_order)) {
                //取消睿鼎订单
                self::cancelReadingOrder($user_id, $reading_order, $note, $is_auto, 2,1);
            }
        }

        //订单取消成功发送小程序订阅消息
        self::sendSubscribeMsg($user_id, $order, $order_goods, $note);

        //订单取消成功发送公众号消息
        self::sendTemplateMsg($user_id, $order, $order_goods, 4);

        //TODO  补充后续业务
    }


    /**
     * 更新订单状态为无效
     * @param int $order_id 订单ID
     * @param int $user_id 下单用户
     * @param int $is_auto 是否自动取消  0-用户取消  1-系统自动取消
     * @param string $note 备注信息
     * @throws Exception
     * @author yangliang
     * @date 2020/12/3 11:54
     */
    private function changeCancelOrder(int $order_id, int $user_id, int $is_auto, string $note): void
    {
        $user = $this->mUser->getOneUser($user_id);
        //更新订单状态为无效
        $res = $this->mOrder->where('order_id', $order_id)->update(['order_status' => -1]);
        if (!$res) {
            throw new Exception('订单取消失败');
        }

        //记录订单日志
        $user_info = [
            'user_id' => ($is_auto == 1) ? 1 : $user['user_id'],
            'user_name' => ($is_auto == 1) ? 'admin' : $user['user_name'],
        ];


        $order_info = [
            'order_id' => $order_id,
            'order_status' => -1
        ];

        $log = $this->sLog->orderLog($order_info, $user_info, $note, '取消订单', 7);
        if (!$log) {
            throw new Exception('订单日志添加失败');
        }
    }

    /**修改第三方库存
     * @param int $userId
     * @param int $orderId
     * @throws Exception
     * @author Poison
     * @date 2021/1/9 3:06 下午
     */
    public function delThirdPartyOrder(int $userId, int $orderId)
    {
        $res = (new PurchaseSelfGoodsModel())->where('user_id', $userId)->where('order_id', $orderId)->update([
            'status' => 9,
            'update_time' => time()
        ]);
        if (!$res) {
            throw new Exception('订单取消失败 错误码：1001');
        }
    }


    /**
     * 更新订单商品状态
     * @param int $order_id 订单ID
     * @throws Exception
     * @author yangliang
     * @date 2020/12/3 15:39
     */
    private function changeCancelOrderGoods(int $order_id): void
    {
        //更新订单商品为已归还已完成
        $res = $this->mOrderGoods->where('order_id', $order_id)->update(['is_repay' => 2, 'is_end' => 1]);
        if (!$res) {
            throw new Exception('订单商品状态更新失败');
        }
    }


    /**
     * 更新订单商品库存
     * @param array $order_goods 订单商品
     * @param int $user_id 用户ID
     * @param int $store_id 门店ID
     * @param string $order_sn 订单编号
     * @throws Exception
     * @author yangliang
     * @date 2020/12/3 15:39
     */
    private function changeOrderGoodsStock(array $order_goods, int $user_id, int $store_id, string $order_sn): void
    {

        $user_info = [
            'user_id' => $user_id
        ];

        //更新订单商品库存
        if (empty($order_goods)) {
            return;
        }

        foreach ($order_goods as $og) {
            //商品原库存
            $old_stock = $this->mGoodsStock->getValueStock($store_id, ['goods_id' => $og['goods_id']], 'stock');

            //增加商品库存
            $gs_res = $this->mGoodsStock->incDataStock($store_id, $og['goods_num'], $og['goods_id']);
            if (!$gs_res) {
                throw new Exception('订单商品<<' . $og['goods_name'] . '>>库存更新失败');
            }

            //记录库存日志
            $stock_log = [
                'update_stock' => 7,  //更新现有库存
                'old_stock' => $old_stock,
                'new_stock' => $old_stock + $og['goods_num'],
                'beizhu' => sprintf('用户取消订单(小程序),现有库存增加%d', $og['goods_num'])
            ];

            $goods_info = [
                'goods_id' => $og['goods_id'],
                'goods_sn' => $og['goods_sn'],
                'goods_name' => $og['goods_name'],
                'goods_num' => $og['goods_num']
            ];
            $log = $this->mOrderStockLog->createOrderStockLogData($store_id, $order_sn, $goods_info, $user_info, $stock_log);
            if (!$log) {
                throw new Exception('订单商品<<' . $og['goods_name'] . '>>库存日志添加失败');
            }
        }
    }


    /**
     * 更新订单还书
     * @param int $order_id 订单ID
     * @throws Exception
     * @author yangliang
     * @date 2020/12/3 17:36
     */
    private function changeOrderReturnGoods(int $order_id): void
    {
        $return_goods = ReturnGoodsModel::getByOrderId($order_id);
        if (empty($return_goods)) {
            return;
        }
        foreach ($return_goods as $rg) {
            $order_goods = OrderGoodsModel::getByRecId($rg['rec_id']);
            $data = [
                'is_repay' => ($order_goods['return_scanning_time'] > 0) ? 2 : 0,  //是否归还  0-未还  2-已还
                'is_end' => 1,  //订单是否完成  0-未完成  1-已完成
            ];

            //更新商品状态
            if ($order_goods['is_repay'] != $data['is_repay'] || $order_goods['is_end'] != $data['is_end']) {
                $res = $this->mOrderGoods->where('rec_id', $rg['rec_id'])->update($data);
                if (!$res) {
                    throw new Exception('还书对应订单商品状态更新失败');
                }
            }

            //删除还书信息
            $del_res = $this->mReturnGoods->where('id', $rg['id'])->delete();
            if (!$del_res) {
                throw new Exception('还书信息删除失败');
            }
        }
    }


    /**
     * 处理订单调拨单
     * 更新订单商品对应调出门店库存信息
     * @param int $order_id 订单ID
     * @param int $user_id 用户ID
     * @throws Exception
     * @author yangliang
     * @date 2020/12/4 10:15
     */
    private function changeOrderStockallocation(int $order_id, int $user_id): void
    {
        $sas = $this->mStockallocation->alias('sal')
            ->field('sal.sn, sal.outstoreid, sal.confirmtime, sald.*')
            ->leftjoin('stockallocation_detail sald', 'sal.id = sald.sa_id')
            ->where('sal.order_id', $order_id)
            ->whereIn('sal.status', [1, 2])
            ->select();
        if (empty($sas)) {
            return;
        }
        $t = strtotime('2020-03-23 00:00:00');
        foreach ($sas as $v) {
            $goods = GoodsModel::getByGoodsId($v['goods_id']);
            $old_stock = $this->mGoodsStock->getValueStock($v['outstoreid'], ['goods_id' => $v['goods_id']], 'stock');
            if ($v['confirmtime'] < $t) {  //小程序上线之前订单
                if ($v['status'] != 1) {
                    continue;
                }

                //更新库存，现有增加
                $stock_res = $this->mGoodsStock->incDataStock($v['outstoreid'], $v['confirm_num'], $v['goods_id']);
                if (!$stock_res) {
                    throw new Exception('调拨单商品<<' . $goods['goods_name'] . '>>库存更新失败');
                }

                $stock_info['update_stock'] = 7;  //更新现有库存
                $stock_info['beizhu'] = sprintf('用户取消订单(原订单)(小程序)同时取消调拨单,调出门店现有库存增加%d', $v['confirm_num']);
            } else {  //小程序上线之后订单

                //更新库存，总库存增加
                $count_res = $this->mGoodsStock->incDataCountStock($v['outstoreid'], $v['confirm_num'], $v['goods_id']);
                //更新库存，现有库存增加
                $stock_res = $this->mGoodsStock->incDataStock($v['outstoreid'], $v['confirm_num'], $v['goods_id']);
                if (!$count_res || !$stock_res) {
                    throw new Exception('调拨单商品<<' . $goods['goods_name'] . '>>库存更新失败');
                }

                $stock_info['update_stock'] = 2;  //更新总库存
                $stock_info['beizhu'] = sprintf('用户取消订单(小程序)同时取消调拨单,调出门店总库存增加%d', $v['confirm_num']);
            }

            $stock_info['old_stock'] = $old_stock;
            $stock_info['new_stock'] = $old_stock + $v['confirm_num'];
            $stock_info['log_type'] = 1;  //调拨单

            $goods_info = [
                'goods_id' => $goods['goods_id'],
                'goods_sn' => $goods['goods_sn'],
                'goods_name' => $goods['goods_name'],
                'goods_num' => $v['confirm_num'],
            ];

            $user_info = [
                'user_id' => $user_id
            ];
            $log = $this->mOrderStockLog->createOrderStockLogData($v['outstoreid'], $v['sn'], $goods_info, $user_info, $stock_info);
            if (!$log) {
                throw new Exception('调拨单商品<<' . $goods['goods_name'] . '>>库存日志添加失败');
            }

            $sa_info = $this->mStockallocation->where('id', $v['sa_id'])->find();
            //存在调拨单并且非无效状态，则更新调拨单状态
            if (!empty($sa_info) && $sa_info['status'] != -1) {
                //更新调拨单状态为已取消
                $sa_res = $this->mStockallocation->where('id', $v['sa_id'])->update(['status' => -1]);
                if (!$sa_res) {
                    throw new Exception('调拨单状态更新失败');
                }
            }
        }

    }


    /**
     * 订单回退年卡配送次数
     * @param int $order_id 订单ID
     * @throws Exception
     * @author yangliang
     * @date 2020/12/4 10:30
     */
    private function changeReturnCardNum(int $order_id): void
    {
        $user_card_log = UserCardLogsModel::getUserCardIdByOrderIdAndType($order_id, 1);
        if (empty($user_card_log['user_card_id'])) {
            return;
        }

        $user_card = UserCardsModel::getById($user_card_log['user_card_id']);
        if (empty($user_card)) {
            return;
        }

        //如果年卡到期时间未过期则更新用户年卡信息
        if ($user_card['end_time'] > time()) {
            $user_card_res = $this->sUserCard->updateUserCard($user_card_log['user_card_id'], $order_id, 1);
            if ($user_card_res['code'] != 200) {
                throw new Exception('年卡信息更新失败');
            }
        }
    }


    /**
     * 更新用户订单赠品
     * @param int $order_id 订单ID
     * @author yangliang
     * @date 2020/12/4 10:38
     */
    private function changeOrderPub(int $order_id): void
    {
        $pub = OrderPubModel::getByOrderId($order_id);

        if (empty($pub)) {
            return;
        }

        //更新用户赠品
        $this->mOrderPub->where('order_id', $order_id)->update(['order_id' => 0, 'is_end' => 0, 'update_time' => time()]);
    }


    /**
     * 取消订单发送小程序订阅消息
     * @param int $user_id 用户ID
     * @param array $order 订单信息
     * @param array $order_goods 订单商品信息
     * @param string $note 备注信息
     * @author yangliang
     * @date 2020/12/4 14:46
     */
    private function sendSubscribeMsg(int $user_id, array $order, array $order_goods, string $note): void
    {
        $user = $this->mUser->getOneUser($user_id);
        if (empty($user['smart_openid'])) {
            return;
        }

        $str = '';
        foreach ($order_goods as $v) {
            $str .= sprintf('<%s>', $v['goods_name']);
        }

        $data = [
            'order_id' => $order['order_id'],
            'order_sn' => $order['order_sn'],
            'count_price' => $order['count_price'],
            'goods_name' => (iconv_strlen($str) > 20) ? iconv_substr($str, 0, 17) . '...' : $str,
            'remark' => sprintf('您的订单已取消，原因：%s', $note),
        ];

        $res = $this->sSmartSend->cancelOrderMessage($user['smart_openid'], $data);
        if (!$res) {
            Log::error('订单取消成功发送小程序订阅消息失败');
        }
    }


    /**
     * 取消订单发送模板消息
     * @param int $user_id 用户ID
     * @param array $order 订单信息
     * @param array $order_goods 订单商品信息
     * @param int $shipping_code 配送方式  此处主要区分是否京东  0-表示按表字段意思    1-表示京东
     * @author yangliang
     * @date 2020/12/4 15:03
     */
    private function sendTemplateMsg(int $user_id, array $order, array $order_goods, int $shipping_code = 0): void
    {
        if (empty($user_id)) {
            return;
        }

        $order_goods = json_decode(json_encode($order_goods), true);
        $data = [
            'order_id' => $order['order_id'],
            'order_sn' => $order['order_sn'],
            'goods_name' => implode(',', array_column($order_goods, 'goods_name')),
            'shipping_code_text' => ($shipping_code == 0) ? OrderModel::$shippingCodeMap[$order['shipping_code']] : OrderModel::$shippingCodeMap[4],
            'add_time' => date('Y-m-d H:i', $order['add_time'])
        ];

        $res = $this->sSendMessage->cancelOrderMessage($user_id, $data);
        if (!$res) {
            Log::error('订单取消成功发送公众号模板消息失败');
        }
    }
}