<?php
/**
 * LogisticsService.php
 * 物流相关
 * author yangliang
 * date 2021/5/25 14:39
 */

namespace app\common\service;


use app\common\ConstCode;
use app\common\exception\ApiException;
use app\common\model\CardsModel;
use app\common\model\LogisticsDeliveryModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderLogModel;
use app\common\model\OrderModel;
use app\common\model\OrderWaybillModel;
use app\common\model\OrderWaybillPathModel;
use app\common\model\ReturnGoodsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use Exception;
use think\facade\Db;
use think\facade\Log;
use think\Model;
use wechat\Logistics;

class LogisticsService extends ComService
{

    /**
     * 创建物流订单
     * @param array $params
     * @return array
     * @author: yangliang
     * @date: 2021/5/25 15:54
     */
    public function reservationReturnOrder(array $params): array
    {
        //待还订单书籍信息
        $rd_order_goods = [];
        //订单商品总数
        $goods_count = 0;
        //订单商品名称
        $goods_name = [];

        //已存在还书单，预约还书与原订单解绑，更改为三方物流还书
        $return_goods_rec = [];

        //包裹商品信息
        $cargo = [];

        Db::startTrans();
        try {
            //验证预约上门时间
            if ($params['visiting_time'] > 0 && $params['visiting_time'] < time()) {
                throw new Exception('预约上门时间异常');
            }

            //验证地区
            $user_card = UserCardsModel::getByUserId($params['user_id']);
            //899年卡支持全国除西藏、新疆外京东免费借/还
            if(!empty($user_card) && $user_card['card_id'] == 2){
                if (strstr($params['sender_province'], '西藏') == true || strstr($params['sender_province'], '新疆') == true) {
                    throw new Exception('西藏、新疆地区暂不支持上门取件服务，请您使用其他方式邮寄还书', ConstCode::ALERT_CODE);
                }
            //其他卡支持陕西省内除榆林外的免费京东快递借/还
            }elseif (strstr($params['sender_province'], '陕西') == false || (strstr($params['sender_province'], '陕西') == true && strstr($params['sender_city'], '榆林') == true)) {
                $card = CardsModel::getById($user_card['card_id'] ?? 0);
                throw new Exception(sprintf('“%s”仅支持陕西省内（除榆林）的上门取件服务，请您使用其他方式邮寄还书', $card['name'] ?? '押金用户'), ConstCode::ALERT_CODE);
            }

            //获取还书是多订单还是单订单
            $order_ids = array_unique(array_column($params['order_goods'], 'order_id'));
            $order_ids_num = count($order_ids);

            //生成唯一单号
            $order_sn = OrderWaybillModel::getWaybillOrderSn($params['order_goods'][0]['order_sn'], ($order_ids_num > 1) ? 1 : 0);

            $waybill_record = OrderWaybillModel::getByOrderSnAndType($order_sn, 2);
            if (!empty($waybill_record) && !in_array($waybill_record['status'], [-1, 8])) {
                throw new Exception('已提交还书信息，请勿重复提交');
            }

            //验证待还书籍信息
            foreach ($params['order_goods'] as $ogv) {
                if (empty($ogv['order_id']) || empty($ogv['rec_id']) || empty($ogv['goods_id']) || empty($ogv['order_sn'])) {
                    throw new Exception('书籍信息异常');
                }

                //验证订单书籍是否未还
                $order_goods = OrderGoodsModel::getByOrderIdAndRecIdAndGoodsId($ogv['order_id'], $ogv['rec_id'], $ogv['goods_id'], $params['user_id']);
                if (empty($order_goods)) {
                    throw new Exception('未还书籍信息不存在');
                }

                if ($order_goods['is_paid'] == 1) {  //已赔付书籍不处理
                    continue;
                }

                //验证是否存在还书信息
                $return_goods = ReturnGoodsModel::getReturnGoodsByRecId($ogv['rec_id']);
                //存在还书信息不处理
                if (!empty($return_goods) && $return_goods['is_end'] == 1) {
                    continue;
                } elseif (!empty($return_goods) && $return_goods['is_end'] == 0) {  //存在还书单
                    $return_goods_rec[] = [
                        'return_goods_id' => $return_goods['id'],
                        'borrow_order_id' => $ogv['order_id']
                    ];
                    $goods_name[] = $order_goods['goods_name'];
                    $order_goods['name'] = mb_substr($order_goods['goods_name'], 0, 122 / 3, 'utf-8') . '...';
                    $order_goods['count'] = $order_goods['goods_num'];
                    $cargo[] = $order_goods;
                    $goods_count++;
                    continue;
                }

                $goods_name[] = $order_goods['goods_name'];
                $order_goods['name'] = mb_substr($order_goods['goods_name'], 0, 122 / 3, 'utf-8') . '...';
                $order_goods['count'] = $order_goods['goods_num'];
                $rd_order_goods[] = $order_goods;
                $cargo[] = $order_goods;
                $goods_count++;
            }

            if ($goods_count < 1) {
                throw new Exception('暂无符合还书信息');
            }

            //下单用户
            $user = (new UserModel())->getOneUser($params['user_id']);

            //获取配送快递信息
            if (isset($params['delivery_id']) && !empty($params['delivery_id'])) {
                $delivery = LogisticsDeliveryModel::getByDeliveryId($params['delivery_id']);
                if (empty($delivery)) {
                    throw new Exception('快递信息不存在，请更新物流列表');
                }
            } else {
                $delivery = LogisticsDeliveryModel::getByDefault();
                if (empty($delivery)) {
                    throw new Exception('默认快递不存在，请先设置');
                }
            }

            //服务类型
            if (!empty($delivery['service_type'])) {
                $service = json_decode($delivery['service_type'], true);
                $service = is_array($service) ? $service[0] : $service;
            } else {
                $service = '';
            }

            $receiver = config('jd_address_config');
            $params['custom_remark'] = !empty($params['custom_remark']) ? ($params['custom_remark'].'---'.$goods_count.'本') : ($goods_count.'本');
            $data = [
                'add_source' => 0,  //订单来源，0为小程序订单，2为App或H5订单，填2则不发送物流服务通知
                'order_id' => $order_sn,  //订单ID，须保证全局唯一，不超过512字节
                'openid' => $user['smart_openid'],  //用户openid，当add_source=2时无需填写（不发送物流服务通知）
                'delivery_id' => $delivery['delivery_id'],  //快递公司ID
                'biz_id' => $delivery['biz_id'],  //快递客户编码或者现付编码
                'expect_time' => $params['visiting_time'],  //预约上门揽件时间
                'custom_remark' => $params['custom_remark'].'----请用防水袋加缓冲气泡纸包装',  //备注信息
                'sender' => [  //发件人信息
                    'name' => $params['sender_name'],  //发件人姓名，不超过64字节
                    'mobile' => $params['sender_mobile'],  //发件人手机号码
                    'province' => $params['sender_province'],  //发件人省份
                    'city' => $params['sender_city'],  //发件人市/地区
                    'area' => $params['sender_area'],  //发件人区/县
                    'address' => $params['sender_address'],  //发件人详细地址,不超过512字节
                ],
                'receiver' => [  //收件人信息
                    'name' => $receiver['receiver_name'],  //收件人姓名，不超过64字节
                    'tel' => $receiver['receiver_tel'],  //收件人座机号码， 不超过32字节
                    'mobile' => $receiver['receiver_mobile'],  //收件人手机号码
                    'province' => $receiver['receiver_province'],  //收件人省份
                    'city' => $receiver['receiver_city'],  //收件人地区/市
                    'area' => $receiver['receiver_area'],  //收件人区/县
                    'address' => $receiver['receiver_address'],  //收件人详细地址,不超过512字节
                ],
                'cargo' => [  //包裹信息，将传递给快递公司
                    'count' => $goods_count,  //包裹数量, 需要和detail_list size保持一致
                    'weight' => 1,  //包裹总重量，单位是千克(kg)
                    'space_x' => 20,  //包裹长度，单位厘米(cm)
                    'space_y' => 10,  //包裹宽度，单位厘米(cm)
                    'space_z' => 10,  //包裹高度，单位厘米(cm)
                    'detail_list' => $cargo,  //包裹中商品详情列表
                ],
                'shop' => [  //商品信息，会展示到物流服务通知和电子面单中
                    'wxa_path' => 'pages/packageSixe/expressDetail/expressDetail?type=2&returnSn=' . $order_sn . '&borrowSn=&borrowType=线上借阅',  //商家小程序的路径，建议为订单页面
                    'img_url' => (isset($rd_order_goods[0]['original_img']) && !empty($rd_order_goods[0]['original_img'])) ? $this->getGoodsImg($rd_order_goods[0]['original_img']) : 'https://wx.qlogo.cn/mmhead/Q3auHgzwzM60DLqiakzDnu7BnbFsrUvFD2whdsSJ1hmgiav3pDvfIpxw/0',  //商品缩略图 url
                    'goods_name' => mb_substr(implode('&', $goods_name), 0, 125 / 3, 'utf-8') . '...',  //商品名称, 不超过128字节
                    'goods_count' => $goods_count,  //商品数量
                ],
                'insured' => [  //保价信息
                    'use_insured' => 0,  //是否保价，0 表示不保价，1 表示保价
                    'insured_value' => 0,  //保价金额，单位是分，比如: 10000 表示 100 元
                ],
                'service' => (object)$service,  //服务类型
            ];

            Log::info('-----下单参数====' . json_encode($data, JSON_UNESCAPED_UNICODE));
            $res = Logistics::getInstance()->addOrder($data);
            Log::info('-----返回结果====' . json_encode($res, JSON_UNESCAPED_UNICODE));
            if (isset($res['errcode']) && $res['errcode'] != 0) {
                //access_token 过期重试, system error 重试
                if ('42001' == $res['errcode'] || '-1' == $res['errcode']) {
                    return self::reservationReturnOrder($params);
                }
                self::sendDingError('三方物流预约还书下单失败，用户ID：' . $params['user_id'] . '，参数：' . json_encode($params, JSON_UNESCAPED_UNICODE), json_encode($res, JSON_UNESCAPED_UNICODE), config('ding_config.exception_mobile'));
                throw new Exception(OrderWaybillModel::$waybillCode[$res['errcode']] ?? '三方物流下单失败');
            }

            //更新订单物流单号
            $order_res = OrderWaybillModel::create([
                'order_id' => 0,
                'order_sn' => $order_sn,
                'user_id' => $params['user_id'],
                'delivery_id' => $delivery['delivery_id'],
                'waybill_id' => $res['waybill_id'],
                'type' => 2,
                'status' => 0,
                'consignee' => config('jd_address_config.receiver_name'),
                'mobile' => config('jd_address_config.receiver_mobile'),
                'province' => config('jd_address_config.receiver_province'),
                'city' => config('jd_address_config.receiver_city'),
                'area' => config('jd_address_config.receiver_area'),
                'address' => config('jd_address_config.receiver_address'),
                'remark' => $params['custom_remark'] ?? '',
                'create_time' => time(),
                'update_time' => time(),
                'expect_time' => $params['visiting_time'],
            ]);
            if (!$order_res) {
                throw new Exception('订单物流单号更新失败，订单ID：' . $params['order_id'] . '，快递公司ID：' . $delivery['delivery_id'] . '，运单号：' . $res['waybill_id']);
            }
            $returnGoodsData = [];
            if (!empty($rd_order_goods)) {
                foreach ($rd_order_goods as $vue) {
                    $returnGoodsData[] = [
                        'rec_id' => $vue['rec_id'],
                        'user_id' => $vue['user_id'],
                        'goods_id' => $vue['goods_id'],
                        'goods_name' => $vue['name'],
                        'goods_sn' => $vue['goods_sn'],
                        'goods_sum' => $vue['goods_num'],
                        'addtime' => time(),
                        'is_reception' => 0,
                        'borrow_order_id' => $vue['order_id'],
                        'waybill_id' => $res['waybill_id'],
                        'delivery_id' => $delivery['delivery_id'],
                        'order_waybill_id' => $order_res->id,
                    ];
                }
                $resCount = (new ReturnGoodsModel())->insertAll($returnGoodsData);
                if ($resCount < count($returnGoodsData)) {
                    throw new Exception('订单还书单更新失败，请联系客服处理');
                }
            }

            //预约还书商品已存在return_goods表，表示之前已预约还书，解除原还书单关系，更新为三方还书
            if (!empty($return_goods_rec)) {
                foreach ($return_goods_rec as $rgv) {
                    ReturnGoodsModel::where('id', $rgv['return_goods_id'])->update([
                        'order_id' => 0,
                        'borrow_order_id' => $rgv['borrow_order_id'],
                        'waybill_id' => $res['waybill_id'],
                        'delivery_id' => $delivery['delivery_id'],
                        'order_waybill_id' => $order_res->id
                    ]);
                }
            }
            $userInfo = (new UserModel())->getOneUser($params['user_id']);
            foreach ($order_ids as $vv) {
                $order = OrderModel::getByOrderId($vv);
                (new LogService())->orderLog($order, ['user_id' => 0, 'user_name' => $userInfo['user_name']], '用户通过小程序预约还书', '预约还书', 8);
                //更新订单有还书操作
                OrderModel::where('order_id', $vv)->update(['is_return_book' => 1]);
            }
        } catch (Exception $e) {
            Db::rollback();
            return $this->error($e->getCode() ?? 100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '下单成功', ['order_sn' => $res['order_id'], 'waybill_id' => $res['waybill_id']]);
    }

    public function storeReturnOrder(array $params): array
    {
        //待还订单书籍信息
        $rd_order_goods = [];
        //订单商品总数
        $goods_count = 0;
        //订单商品名称
        $goods_name = [];
        //已存在还书单，预约还书与原订单解绑，更改为三方物流还书
        $return_goods_rec = [];

        //包裹商品信息
        $cargo = [];

        Db::startTrans();
        try {

            //获取还书是多订单还是单订单
            $order_ids = array_unique(array_column($params['order_goods'], 'order_id'));
            $order_ids_num = count($order_ids);

            //生成唯一单号
            $order_sn = OrderWaybillModel::getWaybillOrderSn($params['order_goods'][0]['order_sn'], ($order_ids_num > 1) ? 1 : 0);

            //验证待还书籍信息
            foreach ($params['order_goods'] as $ogv) {
                if (empty($ogv['order_id']) || empty($ogv['rec_id']) || empty($ogv['goods_id']) || empty($ogv['order_sn'])) {
                    throw new Exception('书籍信息异常');
                }

                //验证订单书籍是否未还
                $order_goods = OrderGoodsModel::getByOrderIdAndRecIdAndGoodsId($ogv['order_id'], $ogv['rec_id'], $ogv['goods_id'], $params['user_id']);
                if (empty($order_goods)) {
                    throw new Exception('未还书籍信息不存在');
                }

                if ($order_goods['is_paid'] == 1) {  //已赔付书籍不处理
                    continue;
                }

                //验证是否存在还书信息
                $return_goods = ReturnGoodsModel::getReturnGoodsByRecId($ogv['rec_id']);
                //存在还书信息不处理
                if (!empty($return_goods) && $return_goods['is_end'] == 1) {
                    continue;
                } elseif (!empty($return_goods) && $return_goods['is_end'] == 0) {  //存在还书单
                    $return_goods_rec[] = [
                        'return_goods_id' => $return_goods['id'],
                        'borrow_order_id' => $ogv['order_id']
                    ];
                    $goods_name[] = $order_goods['goods_name'];
                    $order_goods['name'] = mb_substr($order_goods['goods_name'], 0, 122 / 3, 'utf-8') . '...';
                    $order_goods['count'] = $order_goods['goods_num'];
                    $cargo[] = $order_goods;
                    $goods_count++;
                    continue;
                }

                $goods_name[] = $order_goods['goods_name'];
                $order_goods['name'] = mb_substr($order_goods['goods_name'], 0, 122 / 3, 'utf-8') . '...';
                $order_goods['count'] = $order_goods['goods_num'];
                $rd_order_goods[] = $order_goods;
                $cargo[] = $order_goods;
                $goods_count++;
            }

            if ($goods_count < 1) {
                throw new Exception('暂无符合还书信息');
            }

            //下单用户
            $user = (new UserModel())->getOneUser($params['user_id']);

            $returnGoodsData = [];
            if (!empty($rd_order_goods)) {
                foreach ($rd_order_goods as $vue) {
                    $returnGoodsData[] = [
                        'rec_id' => $vue['rec_id'],
                        'user_id' => $vue['user_id'],
                        'goods_id' => $vue['goods_id'],
                        'goods_name' => $vue['name'],
                        'goods_sn' => $vue['goods_sn'],
                        'goods_sum' => $vue['goods_num'],
                        'is_reception' => 1,
                        'addtime' => time(),
                        'borrow_order_id' => $vue['order_id'],
                        'waybill_id' => '',
                        'delivery_id' => '',
                        'order_waybill_id' => 0,
                    ];
                }
                $resCount = (new ReturnGoodsModel())->insertAll($returnGoodsData);
                if ($resCount < count($returnGoodsData)) {
                    throw new Exception('订单还书单更新失败，请联系客服处理');
                }
            }

            //预约还书商品已存在return_goods表，表示之前已预约还书，解除原还书单关系，更新为三方还书
            if (!empty($return_goods_rec)) {
                foreach ($return_goods_rec as $rgv) {
                    ReturnGoodsModel::where('id', $rgv['return_goods_id'])->update([
                        'order_id' => 0,
                        'borrow_order_id' => $rgv['borrow_order_id'],
                        'waybill_id' => '',
                        'delivery_id' => '',
                        'order_waybill_id' => 0
                    ]);
                }
            }
            $userInfo = (new UserModel())->getOneUser($params['user_id']);
            foreach ($order_ids as $vv) {
                $order = OrderModel::getByOrderId($vv);
                (new LogService())->orderLog($order, ['user_id' => 0, 'user_name' => $userInfo['user_name']], '用户通过小程序预约门店还书', '预约还书', 8);
                //更新订单有还书操作
                OrderModel::where('order_id', $vv)->update(['is_return_book' => 1]);
            }
        } catch (Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '下单成功', ['order_sn' => '', 'waybill_id' => '']);
    }


    /**
     * 查看物流
     * @param array $params
     * @return array
     * @author: yangliang
     * @date: 2021/5/26 14:48
     */
    public function getLogistics(array $params): array
    {

        $order_waybill = OrderWaybillModel::getByOrderSnAndType($params['waybill_order_sn'], $params['type']);
        $orderWaybillPath = OrderWaybillPathModel::getDataByOrderWaybillId($order_waybill['waybill_id']);
        if (!empty($orderWaybillPath['path_item_list'])) {
            $order_waybill['path_item_list'] = json_decode($orderWaybillPath['path_item_list'], true);
            $order_waybill['path_item_num'] = $orderWaybillPath['path_item_num'];
            foreach ($order_waybill['path_item_list'] as &$v) {
                $v['action_time'] = date('Y-m-d H:i:s', $v['action_time']);
            }
        }else{
            //查询京东轨迹
            $res = (new \JdOrder())->dynamictraceinfo($order_waybill['waybill_id']);
            if($res['code'] == 100){
                $order_waybill['jd_path'] = $res['data'];
            }
        }
        $order_waybill['order_sn'] = ($order_waybill['type'] == '1') ? $order_waybill['order_sn'] : (explode('-', $order_waybill['order_sn'])[1] ?? '');  //订单号
        $delivery = LogisticsDeliveryModel::getByDeliveryId($order_waybill['delivery_id']);
        $order_waybill['delivery_name'] = $delivery['delivery_name']??'京东快递';  //快递公司
        $order_waybill['create_time'] = date('Y-m-d H:i:s', $order_waybill['create_time']);  //创建时间


        return $this->success(200, '获取成功', $order_waybill);
    }


    /**
     * 取消还书
     * @param array $params
     * @return array
     * @author: yangliang
     * @date: 2021/5/27 10:05
     */
    public function cancelReturn(array $params): array
    {
        Db::startTrans();
        try {
            //取消还书，取消用户线上借阅订单
            $return_logistic = ReturnGoodsModel::getWaybillByOrderId($params['order_id']);
            if (empty($return_logistic)) {
                $return_logistic_two = ReturnGoodsModel::getReturnByOrderId($params['order_id']);
                if (empty($return_logistic_two)) {
                    throw new Exception('归还物流信息不存在');
                } else {
                    if ($return_logistic_two['is_reception'] == 1) {//取消门店还书
                        return self::cancelStoreReturn($params);
                    }
                }
                throw new Exception('归还物流信息不存在');
            }

            if ($return_logistic['status'] > 0 && !in_array($return_logistic['status'], [2, 8])) {
                throw new Exception('该订单物流已揽件不可取消还书');
            }

            $un_end = ReturnGoodsModel::getByBorrowOrderIdAndIsEnd($params['order_id'], 1);
            if (!empty($un_end)) {
                throw new Exception('还书信息存在已还或无效书籍');
            }

            $res = Logistics::getInstance()->cancelOrder([
                'order_id' => $return_logistic['order_sn'],
                'delivery_id' => $return_logistic['delivery_id'],
                'waybill_id' => $return_logistic['waybill_id']
            ]);
            if ($res['errcode'] != 0) {
                throw new Exception($res['errmsg']);
            }

            //还书订单信息
            $return_order = OrderModel::getByOrderId($params['order_id']);
            //删除还书信息
            $return_goods = ReturnGoodsModel::where('borrow_order_id', $params['order_id'])->where('is_end', '<>', 1)->delete();
            //更新还书物流状态为已取消
            $order_waybill = OrderWaybillModel::where('id', $return_logistic['id'])->update(['status' => 8, 'update_time' => time()]);

            //存在借书订单，一并取消
            $order = OrderModel::getByUserIdAndOrderStatus($return_logistic['user_id'], 1);
            $user = (new UserModel())->getOneUser($return_logistic['user_id']);
            if (!empty($order)) {
                foreach ($order as $v) {
                    $cancel_res = (new OrderService())->cancelOrder($return_logistic['user_id'], $v['order_id'], 1);
                    if ($cancel_res['code'] == 100) {
                        throw new Exception($cancel_res['message']);
                    }
                    //记录订单日志
                    (new LogService())->orderLog(['order_id' => $v['order_id'], 'order_status' => $v['order_status']], ['user_id' => 0, 'user_name' => $user['user_name']], sprintf('用户取消订单：%s 预约还书，取消此用户借书订单', $params['order_id']), '', 9);
                }
            }
            //更新此订单无还书操作
            OrderModel::where('order_id', $params['order_id'])->update(['is_return_book' => 0]);
            //记录订单日志
            $log = (new LogService())->orderLog(['order_id' => $return_order['order_id'], 'order_status' => $return_order['order_status']], ['user_id' => 0, 'user_name' => $user['user_name']], '用户取消预约还书', '', 9);
            if (!$return_goods || !$order_waybill || !$log) {
                throw new Exception('取消还书失败');
            }
        } catch (Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();

        return $this->success(200, '取消成功');
    }

    /**取消门店
     * @param array $params
     * @return array
     * @author Poison
     * @date 2021/8/20 4:47 下午
     */
    public function cancelStoreReturn(array $params): array
    {
        try {
            //取消还书，取消用户线上借阅订单
            $return_logistic = ReturnGoodsModel::getReturnByOrderId($params['order_id']);
            if (empty($return_logistic)) {
                throw new Exception('归还信息不存在');
            }

            $un_end = ReturnGoodsModel::getByBorrowOrderIdAndIsEnd($params['order_id'], 1);
            if (!empty($un_end)) {
                throw new Exception('还书信息存在已还或无效书籍');
            }

            //还书订单信息
            $return_order = OrderModel::getByOrderId($params['order_id']);
            //删除还书信息
            $return_goods = ReturnGoodsModel::where('borrow_order_id', $params['order_id'])->where('is_end', '<>', 1)->delete();
            //更新还书物流状态为已取消

            //存在借书订单，一并取消
            $order = OrderModel::getByUserIdAndOrderStatus($return_logistic['user_id'], 1);
            $user = (new UserModel())->getOneUser($return_logistic['user_id']);
            if (!empty($order)) {
                foreach ($order as $v) {
                    $cancel_res = (new OrderService())->cancelOrder($return_logistic['user_id'], $v['order_id'], 1);
                    if ($cancel_res['code'] == 100) {
                        throw new Exception($cancel_res['message']);
                    }
                    //记录订单日志
                    (new LogService())->orderLog(['order_id' => $v['order_id'], 'order_status' => $v['order_status']], ['user_id' => 0, 'user_name' => $user['user_name']], '用户取消门店预约还书，取消借书单', '', 9);
                }
            }
            //更新此订单无还书操作
            OrderModel::where('order_id', $params['order_id'])->update(['is_return_book' => 0]);
            //记录订单日志
            $log = (new LogService())->orderLog(['order_id' => $return_order['order_id'], 'order_status' => $return_order['order_status']], ['user_id' => 0, 'user_name' => $user['user_name']], '用户取消门店预约还书', '', 9);
            if (!$return_goods || !$log) {
                throw new Exception('取消还书失败');
            }
        } catch (Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();

        return $this->success(200, '取消成功');
    }


    /**
     * 批量获取运单数据
     * @param array $order_list 订单列表(最多100个)
     * @return array
     * @throws ApiException
     * @author: yangliang
     * @date: 2021/5/28 11:19
     */
    public function batchGetOrder(array $order_list): array
    {
        $res = Logistics::getInstance()->batchGetOrder($order_list);
        if ($res['errcode'] != 0) {
            return $this->error(100, $res['errmsg']);
        }

        if (!empty($res['order_list'])) {
            foreach ($res['order_list'] as &$v) {
                $v['print_html'] = base64_decode($v['print_html']);
            }
        }

        return $this->success(200, '获取成功', $res['order_list']);
    }


    /**
     * 物流消息推送业务
     * @param $params
     * @return array
     * @author: yangliang
     * @date: 2021/6/4 14:07
     */
    public function wechatMsgPush($params): array
    {
        Db::startTrans();
        try {
            Log::channel('queue')->info('物流轨迹推送消息：' . json_encode($params, JSON_UNESCAPED_UNICODE));
            $order_waybill = OrderWaybillModel::getByDeliveryIdAndWaybillId($params['delivery_id'], $params['waybill_id']);
            if (empty($order_waybill)) {
                return $this->error(100, '物流信息不存在');
            }

            if (!isset($params['actions_arr']['ActionTime'])) {
                $action = $params['actions_arr'][0];
            } else {
                $action = $params['actions_arr'];
            }

            $path_item_num = 0;
            $path_item_list = '';
            //查询运单物流轨迹
            $res = Logistics::getInstance()->getPath([
                'order_id' => $params['order_id'],
                'delivery_id' => $params['delivery_id'],
                'waybill_id' => $params['waybill_id']
            ]);
            if (isset($res['path_item_num']) && $res['path_item_num'] > 0) {
                $path_item_num = $res['path_item_num'];
                $path_item_list = json_encode($res['path_item_list']);
            }

            //获取相关订单ID
            $order_ids = ($order_waybill['type'] == 1) ? [$order_waybill['order_id']] : array_column(ReturnGoodsModel::getOrderByOrderWaybillId($order_waybill['id']), 'borrow_order_id');
            $log_order = OrderModel::getByIds($order_ids);

            //更新物流实时状态
            Log::channel('queue')->info('物流轨迹推送消息==物流写入内容==>' . json_encode($res, JSON_UNESCAPED_UNICODE));
            if (!empty($res['path_item_list'])) {
                Log::channel('queue')->info('更新运单状态');
                OrderWaybillModel::where('id', $order_waybill['id'])->update(['status' => OrderWaybillModel::$actionTypeToStatus[$res['path_item_list'][0]['action_type']], 'update_time' => time()]);

                //存在轨迹信息，更新，否则创建!
                if(!empty(OrderWaybillPathModel::getCountByWaybillId($params['waybill_id']))){
                    Log::channel('queue')->info('存在运单轨迹，更新');
                    OrderWaybillPathModel::where('waybill_id', $params['waybill_id'])->update(['path_item_num' => $path_item_num, 'path_item_list' => $path_item_list]);
                }else{
                    Log::channel('queue')->info('不存在运单轨迹，创建');
                    OrderWaybillPathModel::create([
                        'order_waybill_id' => $order_waybill['id'],
                        'waybill_id' => $params['waybill_id'],
                        'path_item_num' => $path_item_num,
                        'path_item_list' => $path_item_list,
                        'create_time' => time()
                    ]);
                }
            }

            if ('100001' == $action['ActionType'] && $order_waybill['type'] == 1) {  //揽件成功，借书
                //更新当前订单为已发货
                self::ship($order_waybill['order_id']);
            } elseif ('100001' == $action['ActionType'] && $order_waybill['type'] == 2) {  //揽件成功，还书

                //揽件后预约还书状态更新为0，防止订单全部赔付后新订单待处理不显示
                OrderModel::whereIn('order_id', $order_ids)->update(['is_return_book' => 0]);

                $orders = OrderModel::field('order_id, order_status, user_id')
                    ->where('user_id', $order_waybill['user_id'])
                    ->where('order_status', 1)
                    ->where('shipping_code', 3)
                    ->where('is_can_deal', 0)
                    ->select()->toArray();

                if (!empty($orders)) {
                    $order_can_ids = array_column($orders, 'order_id');
                    //更新用户借书信息为可配书状态
                    $order_res = OrderModel::whereIn('order_id', $order_can_ids)->update(['is_can_deal' => 1]);
                    foreach ($orders as $oci){
                        //记录订单日志
                        (new LogService())->orderLog(
                            ['order_id' => $oci['order_id'], 'order_status' => $oci['order_status']],
                            ['user_id' => $oci['user_id'], 'user_name' => '系统'],
                            sprintf('还书物流单【%s】已揽件，该订单开始进入内部处理流程', $params['waybill_id']),
                            '',
                            9
                        );
                    }

                    if (!$order_res) {
                        throw new Exception('订单借书状态更新失败');
                    }
                }
            } elseif ('100002' == $action['ActionType'] && $order_waybill['type'] == 2) {  //还书揽件失败，取消订单
                if(!empty($log_order)) {
                    foreach ($log_order as $lv) {
                        //还书订单信息
                        $return_order = OrderModel::getByOrderId($lv['order_id']);
                        //删除还书信息
                        ReturnGoodsModel::where('borrow_order_id', $lv['order_id'])->where('is_end', '<>', 1)->delete();
                        //更新还书物流状态为已取消
                        OrderWaybillModel::where('id', $order_waybill['id'])->update(['status' => 8, 'update_time' => time()]);

                        //存在借书订单，一并取消
                        $order = OrderModel::getByUserIdAndOrderStatus($order_waybill['user_id'], 1);
                        if (!empty($order)) {
                            foreach ($order as $v) {
                                $cancel_res = (new OrderService())->cancelOrder($order_waybill['user_id'], $v['order_id'], 1);
                                if ($cancel_res['code'] == 100) {
                                    throw new Exception($cancel_res['message']);
                                }
                                //记录订单日志
                                (new LogService())->orderLog(['order_id' => $v['order_id'], 'order_status' => $v['order_status']], ['user_id' => $v['user_id'], 'user_name' => '系统'], sprintf('订单：%s预约还书揽件失败，取消此用户借书订单', $lv['order_id']), '取消订单', 9);
                            }
                        }
                        //更新此订单无还书操作
                        OrderModel::where('order_id', $lv['order_id'])->update(['is_return_book' => 0]);
                        //记录订单日志
                        (new LogService())->orderLog(['order_id' => $return_order['order_id'], 'order_status' => $return_order['order_status']], ['user_id' => $return_order['user_id'], 'user_name' => '系统'], '预约还书揽件失败，取消还书', '取消还书', 9);
                    }
                }
            } elseif ('300003' == $action['ActionType'] && $order_waybill['type'] == 1) {  //借书已签收，订单收货
                //更新物流运单签收时间
                OrderWaybillModel::where('id', $order_waybill['id'])->update(['sign_time' => $action['ActionTime']]);
                //确认收货
                (new OrderService())->deliveryOrder($order_waybill['user_id'], $order_waybill['order_id']);
            } elseif ('300003' == $action['ActionType'] && $order_waybill['type'] == 2) {  //还书已签收
                //更新物流运单签收时间
                OrderWaybillModel::where('id', $order_waybill['id'])->update(['sign_time' => $action['ActionTime']]);
            }
        } catch (Exception $e) {
            Db::rollback();
            $this->sendDingError('微信物流助手消息推送处理失败, 参数：' . json_encode($params, JSON_UNESCAPED_UNICODE), $e);
            return $this->error(100, $e->getMessage());
        }
        Db::commit();

        return $this->success(200, 'success');
    }


    /**
     * 发货
     * @param $order_id
     * @return false
     * @throws Exception
     * @author: yangliang
     * @date: 2021/6/3 17:56
     */
    private function ship(int $order_id)
    {
        $order = OrderModel::getByOrderId($order_id);
        if (empty($order) || $order['shipping_code'] != 3 || $order['order_status'] == 4) {
            return false;
        }

        // 更新订单:订单状态已发货,发货时间,配送员
        $order_update = OrderModel::where('order_id', $order_id)
            ->update(array('order_status' => 4, 'shipping_time' => time()));
        if (!$order_update) {
            throw new Exception('订单更新失败');
        }

        $log = OrderLogModel::create([
            'order_id' => $order_id,
            'action_user' => 1,
            'user_name' => 'system',
            'order_status' => 4,
            'action_note' => '专业快递揽件发货',
            'log_time' => time(),
            'status_desc' => '订单发货',
            'action_type' => 7,
            'is_show' => 1
        ]);

        if (!$log) {
            throw new Exception('订单日志添加失败');
        }

        $order_info['shipper_info'] = '-';
        (new SendMessageService())->sendShipMessage($order['user_id'], $order);
    }
}