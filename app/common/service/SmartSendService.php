<?php


namespace app\common\service;


use app\common\ConstLib;
use app\common\model\UserLevelModel;
use app\common\model\UserModel;
use app\common\traits\SmartTrait;

class SmartSendService extends ComService
{
    use SmartTrait;

    /**
     * 取消订单成功发送小程序订阅消息(预约取消通知)
     * @param $openId
     * @param $order
     * @return bool
     *
     *参数实例
     * $smartOrder['order_id'] = $orderId;
     * $smartOrder['order_sn'] = $order['order_sn'];
     * $smartOrder['count_price'] = $order['count_price'];
     * $str = '';
     * foreach ($orderGoods AS $og) {
     * $str .= '<' . $og . '>';
     * }
     * if (iconv_strlen($str) > 20) {
     * $smartOrder['goods_name'] = iconv_substr($str, 0, 17) . '...';
     * } else {
     * $smartOrder['goods_name'] = $str;
     * }
     * $smartOrder['remark'] = '您的订单' . $order['order_sn'] . '已取消';
     *
     */
    public function cancelOrderMessage($openId, $order)
    {
        if (!$openId) {
            return false;
        }

        $data = [
            'page' => 'pages/packageTwo/orderDetail/orderDetail?id=' . $order['order_id'],
            'data' => [
                'character_string1' => ['value' => $order['order_sn']],
                'amount3' => ['value' => $order['count_price']],
                'thing2' => ['value' => $order['goods_name']],
                'thing4' => ['value' => $order['remark']],
            ]
        ];

        $templateId = config('wx_config.send_msg')['smart']['cancel_order'];
        $this->send($openId, $templateId, $data);
    }


    /**
     * 下单成功发送小程序订阅消息(借书成功通知)
     * @param $smartOpenid
     * @param $order
     * @return bool|mixed
     * @throws \Throwable
     */
    public function orderMessage($smartOpenid, $order)
    {
        if (!$smartOpenid) {
            return false;
        }

        $data = [
//            'page' => 'pages/packageOne/index/index',
            'page' => 'pages/packageTwo/orderDetail/orderDetail?id=' . $order['order_id'],
            'data' => [
                'name1' => ['value' => $order['store_name']],
                'thing2' => ['value' => $order['goods_name']],
                'time4' => ['value' => $order['add_time']],
                'thing5' => ['value' => $order['remark']]
            ]
        ];
        return $this->send($smartOpenid, config('wx_config.send_msg.smart.create_order'), $data);
    }

    /**年卡或者储值卡办理小程序模版通知
     * @param $smartOpenid
     * @param $chargeInfo
     * @param int $status
     * @return bool|mixed|string
     * @author Poison
     * @date 2020/12/17 5:32 下午
     */
    public function cardOkSendMessage($smartOpenid,$chargeInfo,$status = 0){
        $page = 'pages/packageTwo/chuZhiKaUseList/chuZhiKaUseList';
        $title = '储值卡充值';
        if ($status == 1) {
            $title = '年卡办理';
            $page = 'pages/packageThree/vipCard/vipCard';
        }

        if (!$smartOpenid) {
            return false;
        }

        $data = [
            'page' => $page,
            'data' => [
                'thing1' => ['value' => $title],
                'character_string2' => ['value' => $chargeInfo['mobile']],
                'time3' => ['value' => $chargeInfo['pay_time']],
                'amount4' => ['value' => $chargeInfo['amount']],
                'thing5' => ['value' => $chargeInfo['remark']],
//                'thing5' => '您的储值卡充值3000元，赠送500元',
//                'thing5' => '您的年卡过期时间2021-04-12',
            ]
        ];
        return $this->send($smartOpenid, config('wx_config.send_msg.smart.card_ok'), $data);
    }


    /**
     * 心愿书单提交成功发送小程序订阅消息(心愿书单提交成功通知)
     * @param $smartOpenid
     * @param $goods
     * @param $page
     * @return bool|mixed|string
     * @author yangliang
     * @date 2021/2/22 11:25
     */
    public function lackBookMessage($smartOpenid, $goods, $page){
        if (!$smartOpenid) {
            return false;
        }

        $data = [
            'page' => $page,
            'data' => [
                'thing11' => ['value' => $goods['name']],
                'thing12' => ['value' => $goods['author']],
                'thing13' => ['value' => $goods['publish']],
                'thing10' => ['value' => $goods['remark']]
            ]
        ];
        return $this->send($smartOpenid, config('wx_config.send_msg.smart.lack_book'), $data);
    }


    /**
     * 确认收货发送小程序订阅消息(心愿书单提交成功通知)
     * @param $smartOpenid
     * @param $order
     * @return bool|mixed|string
     * @author yangliang
     * @date 2021/2/22 14:39
     */
    public function accessOrderMessage($smartOpenid, $order){
        if (!$smartOpenid) {
            return false;
        }
        $data = [
            'page' => 'pages/packageTwo/orderDetail/orderDetail?id='.$order['order_id'],
            'data' => [
                'character_string1' => ['value' => $order['order_sn']??$order['order_id']],
                'phrase2' => ['value' => $order['status']],
                'date3' => ['value' => $order['time']],
                'thing4' => ['value' => $order['remark']]
            ]
        ];
        return $this->send($smartOpenid, config('wx_config.send_msg.smart.access_order'), $data);
    }
}
