<?php


namespace app\common\service;

use app\common\ConstLib;
use app\common\model\UserLevelModel;
use app\common\model\UserModel;
use app\common\traits\SmartTrait;
use Jormin\Dingtalk\Dingtalk;

class SendMessageService extends ComService
{
    use SmartTrait;

    public function sendOrderMessage($userId, $order)
    {
        $user = (new UserModel())->getOneUser($userId);
        if (!$user) {
            return false;
        }
        if (!$user['openid']) {
            return false;
        }
        $first = '亲爱的睿鼎会员' . $user['user_name'] . '您好,您已成功下单,订单地址是:' . $order['address'] . ',我们马上为您挑选图书,配送前系统会发送送书微信提醒,请注意查收。【睿鼎图书】孩子的专属图书馆';
        $data = [
            'miniprogram' => [
                'appid' => config('wx_config.base.smart.appid'),
//                'pagepath' => 'pages/packageOne/index/index'
                'page' => 'pages/packageTwo/orderDetail/orderDetail?id=' . $order['order_id']
            ],
            'data' => [
                'first' => array('value' => $first, 'color' => '#000000'),
                'keyword1' => array('value' => $order['order_sn'], 'color' => '#8EC31E'),
                'keyword2' => array('value' => '点击查看书籍详情', 'color' => '#CC0000'),
                'keyword3' => array('value' => $order['count_price'] ?? 0, 'color' => '#CC0000'),
                'remark' => array('value' => '配送书籍如未及时领取,订单将会自动取消,再次借书需重新下单', 'color' => '#CC0000'),
            ],
        ];
        $this->send($user['openid'], config('wx_config.send_msg.crete_order'), $data, 1);
    }

    /**积分变动模版消息通知
     * @param $userId
     * @param $points_info
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function sendPointsMessage($userId, $points_info)
    {
        $user = (new UserModel())->getOneUser($userId);
        if (!$user['openid']) {
            return false;
        }

        $first = '亲爱的睿鼎会员' . $user['user_name'] . '您好,您的阅币变动如下【睿鼎图书】孩子的专属图书馆';
        $data = [
            'page' => 'pages/packageTwo/showYueBi/showYueBi',
            'data' => [
                'first' => array('value' => $first, 'color' => '#000000'),
                'keyword1' => array('value' => $user['user_name'], 'color' => '#8EC31E'),
                'keyword2' => array('value' => $points_info['content'], 'color' => '#8EC31E'),
                'keyword3' => array('value' => $points_info['points'], 'color' => '#8EC31E'),
                'remark' => array('value' => '感谢您的支持,点击查看阅币余额', 'color' => '#CC0000'),
            ],
        ];
        $templateId = config('wx_config.send_msg.points');
        $this->send($user['openid'], $templateId, $data, 1);
    }


    /**
     * 取消订单模板消息
     * @param $user_id
     * @param $order
     * @return bool
     * @author yangliang
     * @date 2020/12/4 15:01
     */
    public function cancelOrderMessage(int $user_id, array $order){
        $user = (new UserModel())->getOneUser($user_id);
        if(empty($user_id)){
            (new Dingtalk(config('ding_config.error_hook')))->sendText(sprintf("订单取消成功发送微信提醒,用户ID:%d 数据为空", $user_id));
            return false;
        }

        if(empty($user['openid'])){
            (new Dingtalk(config('ding_config.error_hook')))->sendText(sprintf("订单取消成功发送微信提醒,用户ID:%d openId为空", $user_id));
            return false;
        }

        $first = '亲爱的睿鼎会员'. $user['user_name'] .'您好,您的订单已取消【睿鼎图书】孩子的专属图书馆';
        $data = [
            'miniprogram' => [
                'appid' => config('wx_config.base')['smart']['appid'],
                'page' => 'pages/packageTwo/orderDetail/orderDetail?id='. $order['order_id']
            ],
            'data' => [
                'first' => array('value' => $first, 'color' => '#000000'),
                'keyword1' => array('value' => $order['order_sn'], 'color' => '#8EC31E'),
                'keyword2' => array('value' => $order['goods_name'], 'color' => '#8EC31E'),
                'keyword3' => array('value' => $order['shipping_code_text'], 'color' => '#8EC31E'),
                'keyword4' => array('value' => $order['add_time'], 'color' => '#8EC31E'),
                'remark' => array('value' => '配送书籍如未及时领取,订单将会自动取消,再次借书需重新下单', 'color' => '#CC0000'),
            ],
        ];

        return $this->send($user['openid'], config('wx_config.send_msg')['cancel_order'], $data, 1);
    }



    /**
     * 订单收货成功发送微信提醒(订单收货通知)
     * @param int $user_id
     * @param $order  用户订单详情:order_od订单ID,order_sn订单编号,count_price订单总金额
     * @return bool|mixed|string
     * @author yangliang
     * @date 2021/2/22 14:32
     */
    public function deliveryMessage(int $user_id, $order) {
        $user = (new UserModel())->getOneUser($user_id);
        if (!$user) {
           // $this->sendDingError(sprintf("订单签收成功发送微信提醒,用户ID:%d 数据为空", $user_id), '');
            return false;
        }

        if (!$user['openid']) {
            //$this->sendDingError(sprintf('订单签收成功发送微信提醒,用户ID:%d openId为空', $user_id), '');
            return false;
        }

        $first = '亲爱的睿鼎会员' . $user['user_name'] . '您好,您的图书已签收,愿孩子阅读愉快！【睿鼎图书】孩子的专属图书馆';
        $data = [
            'miniprogram' => [
                'appid' => config('wx_config.base.smart.appid'),
                'page' => 'pages/packageTwo/orderDetail/orderDetail?id='. $order['order_id']
            ],
            'data' => [
                'first' => array('value' => $first, 'color' => '#000000'),
                'keyword1' => array('value' => $order['order_sn'], 'color' => '#8EC31E'),
                'keyword2' => array('value' => $order['count_price'], 'color' => '#8EC31E'),
                'keyword3' => array('value' => '点击查看订单详情', 'color' => '#8EC31E'),
                'remark' => array('value' => '感谢您的支持,如有疑问请咨询在线客服或拨打' . ConstLib::SERVICE_PHONE, 'color' => '#CC0000'),
            ],
        ];
        return $this->send($user['openid'], config('wx_config.send_msg.wechat.delivery'), $data);
    }


    /**
     * 发货
     * @param int $user_id
     * @param $order
     * @param int $is_class
     * @return bool|mixed|string
     * @author: yangliang
     * @date: 2021/6/3 17:54
     */
    public function sendShipMessage(int $user_id, $order){
        $user = (new UserModel())->getOneUser($user_id, 1, 'user_name,mobile,openid,grade,smart_openid');
        if(empty($user)){
            return false;
        }

        $keyword3_val = $user['user_name'];
        if ($user['mobile']) {
            $keyword3_val .= '-' . $user['mobile'];
        }

        if($user['smart_openid']){
            $address = self::setGoodsName($order['address']);
            $sub_data = [
                'page' => 'pages/packageTwo/orderDetail/orderDetail?id='.$order['order_id'],
                'data' =>[
                    'character_string1' => ['value' => $order['order_sn']],  //订单编号
                    'time3' => ['value' => is_numeric($order['add_time'])?date('Y-m-d H:i:s', $order['add_time']):$order['add_time']],  //下单时间
                    'thing4' => ['value' => $keyword3_val],  //收货人
                    'thing5' => ['value' => $address]  //收货地址
                ]
            ];

            return $this->send($user['smart_openid'], config('wx_config.send_msg.smart.ship'), $sub_data);
        }
    }


    /**
     * 设置商品名称
     * @param $goods_name
     * @return mixed|string
     * @author: yangliang
     * @date: 2021/6/3 17:50
     */
    public function setGoodsName($goods_name){
        if (iconv_strlen($goods_name) > 20) {
            $goods_name = iconv_substr($goods_name, 0, 17) . '...';
        }

        return $goods_name;
    }
}