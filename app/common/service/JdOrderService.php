<?php


namespace app\common\service;

use app\common\model\GoodsModel;
use app\common\model\OrderModel;
use app\common\model\OrderPubModel;
use app\common\model\PickUpModel;
use app\common\model\StoresModel;
use app\common\model\UserAddressModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\model\UserPubGoodsModel;
use app\common\model\UserPubModel;
use app\common\model\UserPubTypeModel;
use Exception;

class JdOrderService extends ComService
{
    /**
     * 创建jd订单
     * @param array $params
     * @param int $userId
     * @param array $jd_v2_array
     * @param int $resultBook
     * @param int $is_shop
     * @param int $thisOrderId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function create(array $params, int $userId, array $jd_v2_array, int $resultBook, int $is_shop, int $thisOrderId)
    {
        $userCardsModel = new UserCardsModel();
        $order = new OrderModel();
        $userModel = new UserModel();
        $stores = new StoresModel();
        $pickups = new PickUpModel();
        $address = UserAddressModel::getAddress($userId);
        if (!$address) {
            return $this->error(0, '请输入配送地址');
        }
        if (!$address['address']) {
            return $this->error(0, '请输入详细配送地址');
        }
        $recIds = $params['rec_ids'] ?? '';
        $orderSn = $orderData['order_sn'] = ToolsService::createOrderSn();// 生成订单编号
        $orderData['user_id'] = $userId;
        $orderData['shipping_code'] = $params['shipping_code'] ?? 1;// 配送方式
        $orderData['order_status'] = 1;// 订单状态
        $orderData['pay_id'] = 0;
        $orderData['pay_status'] = 1;
        $orderData['consignee'] = $address['consignee'];
        $orderData['mobile'] = $address['mobile'];
        $orderData['address'] = $address['province'] . $address['city'] . $address['district_name'] . $address['address'];// 默认配送地址
        $orderData['store_id'] = 0;// 门店ID默认0
        $orderCount = $order->getCount($userId);
        $orderData['is_new'] = $orderCount > 0 ? 0 : 1;// 是否新用户
        $orderData['shipping_price'] = 0;
        $orderData['count_price'] = 0;
        $orderData['pickup_id'] = 0;
        $orderData['add_time'] = time();
        //获取到用户年卡信息
        $userCardsData = $userCardsModel->getOneCards($userId);
        // 获取用户信息
        $userData = $userModel->getOneUser($userId);
        $storeName = '睿鼎少儿图书馆';
        // 配送方式
        $storeId = $params['store_id'];
        $thirdPartyOrder = ThirdPartyOrderService::checkUserOrder($userId);//2020-1-11 如果首单 修改库为 45
        if($thirdPartyOrder){
            $storeId = 45;
        }
        switch ($orderData['shipping_code']) {
            case 1:// 到馆自取
                if (!$storeId) {
                    return $this->error(10160, '请选择门店');
                }
                $orderData['store_id'] = $storeId;
                $store = $stores->getStoresByStoreId($storeId);
                $orderData['address'] = $store['address'];
                $storeName = $store['name'];
                break;
            case 2:// 集中配送
                if ($userData['pickup_id'] > 0) {
                    $pickup = $pickups->getPickUpByPickUpId($userData['pickup_id'], 'pickup_id,pickup_name,is_lock');
                    if (!$pickup || $pickup['is_lock'] == 1) {
                        return $this->error(10240, '您选择的配送点已撤销,请更改配送点或选择其他配送方式');
                    }
                    $orderData['pickup_id'] = $pickup['pickup_id'];
                    $orderData['address'] = $pickup['pickup_name'];
                } else {
                    // 判断是否绑定配送点
                    return $this->error(10160, '您还未绑定配送点,请联系客服绑定');
                }
                break;
            case 3:// 单独配送
                if($thirdPartyOrder){
                    $orderData['store_id'] = $storeId;
                }
                //2020-09-24 增加首单用户需要免费 其他收费
                if ($orderCount > 0) {
                    $orderData['order_status'] = 0;
                    $orderData['shipping_price'] = config('self_config.order_shipping_price');
                    $orderData['count_price'] = config('self_config.order_shipping_price');
                    $orderData['pay_status'] = 0;
                }
                break;
            default:
                break;
        }
        try {
            // 1.创建订单
            $orderData['shipping_price'] = 0;
            $orderData['count_price'] = 0;
            $orderData['pickup_id'] = isset($orderData['pickup_id']) ? $orderData['pickup_id'] : 0;
            $orderData['add_time'] = time();
            $orderData['is_jd'] = 1;
            $orderData['source'] = 2;
            $orderData['is_return'] = $recIds ? 1 : 0;
            $orderData['order_status'] = 1;
            $orderData['province_code'] = $address['province_code'];
            $orderData['city_code'] = $address['city_code'];
            $orderData['district_name_code'] = $address['district_name_code'];
            $orderData['twon_code'] = $address['twon_code'];
            $orderData['user_card_id'] = $userCardsData['id'];
            $orderData['owner_order_id'] = $thisOrderId ?? 0;
            $orderData['order_id'] = $orderId = $order->insert($orderData, true);//创建订单 返回订单id
            if ($orderId) {
                // 1.1 添加订单日志
                $resOrderLog = (new LogService())->orderLog(['order_id' => $orderId, 'order_status' => $orderData['order_status']], ['user_id' => $userId, 'user_name' => $userData['user_name']], '用户添加订单', '创建订单', 0);

                if (!$resOrderLog) {
                    return $this->error(0, '订单日志添加失败');
                }
                // 1.2更新门店信息
                if ($userData['store_id'] != $orderData['store_id']) {
                    $user_update = $userModel->where('user_id', $userId)->save(['store_id' => $orderData['store_id']]);
                    if ($user_update === false) {
                        return $this->error(0, '用户门店ID更新失败');
                    }
                }
            } else {
                return $this->error(0, '订单添加失败');
            }
            $third = [];
            // 2.创建订单商品数据，整合进来的代码-创建订单数据
            $resJdOrderGoods = (new OrderGoodsService())->jdOrderGoods($orderData, $userId, $jd_v2_array, (int)$storeId, $orderGoods,$third);
            if ($resJdOrderGoods['code'] != 200) {
                return $resJdOrderGoods;
            }
            //3。库存，没有问题 开始降低库存
            $resStore = (new StoreService())->jdUpdateStore($userId, (int)$orderId, $storeId, $orderGoods, $userCardsData, $jd_v2_array, $orderData, $orderSn,$third);
            if ($resStore['code'] != 200) {//降低库存失败
                return $resStore;
            }
            // 4.创建还书商品数据
            if ($recIds && $resultBook != 1) {
                $returnGoodsResult = (new ReturnGoodsService())->createReturnGoods((array)$recIds, (int)$orderId);
                if ($returnGoodsResult['code'] != 200) {
                    return $this->error($returnGoodsResult['code'], $returnGoodsResult['message']);
                }
            }
            // 5.更新订单状态为书不齐
            $orderUpdateResult = (new OrderModel())->updateOrder($orderId);
            if (!$orderUpdateResult) {
                return $this->error(0, '调拨单对应订单状态更新失败');
            }
            if ($is_shop != 1) {//如果 自有订单没有奖励 那么在京东进行奖励
                // 5.判断是否新用户赠送阅币

                //TODO 2021-4-27 关闭阅币赠送

                if ($orderData['is_new'] == 1) {
                    // 新用户赠送积分
                    $pointsData['points'] = config('self_config.order_points');
                    $pointsData['status'] = 1;
                    $pointsData['ptype'] = 3;
                    $pointsData['pnote'] = sprintf('新用户下单赠送%s阅币', $pointsData['points']);
                    $pointsData['wechat_message'] = sprintf('下单赠送%s阅币', $pointsData['points']);
                    (new PointsService())->handlePoints(['user_id' => $userId], $pointsData, 1);
                }
                // 6.更新年卡信息
                $userCard = (new UserCardsModel())->where('user_id', $userId)->order('id', 'desc')->find();
                if ($userCard) {
                    //$depositExpireDateTimeStamp = strtotime(config('self_config.deposit_expire_date'));
                    $depositExpireDateTimeStamp = $userData['user_deposit_expire_time'];
                    if ($userCard['is_lock'] == 1) {
                        if (time() > $depositExpireDateTimeStamp) {
                            return $this->error(-1, '您的年卡暂未激活');
                        }
                    } else {
                        $isUseUserCardRes = (new UserCardService())->isUseUserCard($userCard, null, $userId);
                        if ($isUseUserCardRes['code'] != 200) {
                            return $this->error($isUseUserCardRes['code'], $isUseUserCardRes['message']);
                        }
                        // 配送方式为集中配送或者单独配送则更新年卡信息
                        $userCardsResult = (new UserCardService())->updateUserCard($userCard['id'], $orderId);
                        if ($userCardsResult['code'] != 200) {
                            return $this->error(0, '年卡信息更新失败');
                        }
                    }
                }
                // 7.判断是否需要添加赠品备注
                $userPubTypeIds = (new UserPubTypeModel())->where('is_closed', 0)->column('id');
                if ($userPubTypeIds) {
                    $userPubTypeIdsWhere['user_id'] = $userId;
                    $userPubs = (new UserPubModel())->where($userPubTypeIdsWhere)->whereIn('type_id', implode(',', $userPubTypeIds))->select();
                    if ($userPubs) {
                        foreach ($userPubs as $userPub) {
                            // 未分配订单并且已审核的朋友圈上传截图记录才可以添加赠品信息
                            if ($userPub['order_id'] == 0 && $userPub['status'] == 2) {
                                $userPubGoods = (new UserPubGoodsModel())->where(['user_pub_id' => $userPub['id'], 'is_end' => 0])->select();
                                if ($userPubGoods) {
                                    $orderPubData['order_id'] = $orderId;
                                    $orderPubData['user_id'] = $userId;
                                    foreach ($userPubGoods as $upg) {
                                        $goodsInfo = (new GoodsModel())->field('goods_sn,goods_name')->where('goods_id', $upg['goods_id'])->find();
                                        $orderPubData['user_pub_goods_id'] = $upg['id'];
                                        $orderPubData['goods_id'] = $upg['goods_id'];
                                        $orderPubData['goods_sn'] = $goodsInfo['goods_sn'];
                                        $orderPubData['goods_name'] = $goodsInfo['goods_name'];
                                        $orderPubData['goods_num'] = $upg['goods_num'];
                                        $orderPubData['create_time'] = $orderPubData['update_time'] = time();
                                        // 9.1.添加赠品备注
                                        (new OrderPubModel())->insert($orderPubData);
                                        // 9.2.更新赠品记录完成状态
                                        (new UserPubGoodsModel())->where('id', $upg['id'])->update(['is_end' => 1, 'update_time' => time()]);
                                    }
                                }
                                // 9.3.更新用户截图记录
                                (new UserPubModel())->where('id', $userPub['id'])->update(['order_id' => $orderId, 'update_time' => time()]);
                            }
                        }
                    }
                }
            }
            // 9.下单成功发送小程序订阅消息
            if ($userData['smart_openid']) {
                if ($orderGoods) {//如果有自由订单发送模版消息
                    (new OrderService())->sendCreateOrderMessage($orderId, $storeName, $orderData, $userData);
                }
            }
            // 10.向公众号发送消息
            if ($orderGoods) {
                (new SendMessageService())->sendOrderMessage($userId, $orderData);
            }
            return $this->success(200, '成功', ['order_id' => $orderId]);
        } catch (Exception $ex) {
            return $this->error(0, '服务器异常 错误：' . $ex->getMessage());
        }

    }

}