<?php

declare (strict_types=1);

namespace app\common\service;


use app\common\ConstCode;
use app\common\ConstLib;
use app\common\model\CardsModel;
use app\common\model\CartModel;
use app\common\model\BuyOrderLogModel;
use app\common\model\CatModel;
use app\common\model\GoodsCollectModel;
use app\common\model\GoodsModel;
use app\common\model\GoodsStockModel;
use app\common\model\JdOrderGoodsModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderLogModel;
use app\common\model\OrderModel;
use app\common\model\OrderPubModel;
use app\common\model\OrderStockLogModel;
use app\common\model\OrderWaybillModel;
use app\common\model\PickUpModel;
use app\common\model\ReturnGoodsModel;
use app\common\model\SelfCheckLogModel;
use app\common\model\SelfCheckModel;
use app\common\model\StockallocationModel;
use app\common\model\StockLackModel;
use app\common\model\StoresModel;
use app\common\model\UserCardLogsModel;
use app\common\model\UserLevelModel;
use app\common\model\UserModel;
use app\common\model\UserAddressModel;
use app\common\model\UserCardsModel;
use app\common\model\UserPubGoodsModel;
use app\common\model\UserPubModel;
use app\common\model\UserPubTypeModel;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\facade\Db;
use wechat\Pay;
use think\facade\Log;

class OrderService extends ComService
{

    /**
     * @param array $params
     * @param int $userId
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function createOrder(array $params, int $userId): array
    {
        $userCardsModel = new UserCardsModel();
        $orderModel = new OrderModel();
        $userModel = new UserModel();
        $stores = new StoresModel();
        $pickups = new PickUpModel();
        $address = UserAddressModel::getAddress($userId);
        $recIds = $params['rec_ids'];
        $orderSn = $orderData['order_sn'] = ToolsService::createOrderSn();// 生成订单编号
        $userData = $userModel->getOneUser($userId);
        // 获取用户信息
        if (!$userData) {
            return $this->error(0, '用户信息不存在');
        }
        $orderData['user_id'] = $userId;
        $orderData['shipping_code'] = $params['shipping_code'];// 配 送方式
        $orderData['order_status'] = 1;// 订单状态
        $orderData['pay_id'] = 0;
        $orderData['pay_status'] = 1;
        $orderData['consignee'] = $address['consignee']??$userData['user_name'];
        $orderData['mobile'] = $address['mobile']??$userData['mobile'];
        $orderData['address'] = ($address['province'] ?? '') . ($address['city'] ?? '') . ($address['district_name'] ?? '') . ($address['address'] ?? '');// 默认配送地址
        $orderData['province'] = $address['province'];
        $orderData['city'] = $address['city'];
        $orderData['area'] = $address['district_name'];
        $orderData['receiver_address'] = $address['address'];
        $orderData['smart_openid'] = $userData['smart_openid'];
        $orderData['store_id'] = 0;// 门店ID默认0
        $orderData['shipping_price'] = 0;
        $orderData['count_price'] = 0;
        $orderData['pickup_id'] = 0;
        $orderData['add_time'] = time();
        $orderCount = $orderModel->getCount($userId);
        $orderData['is_new'] = $orderCount > 0 ? 0 : 1;// 是否新用户
        $storeName = '睿鼎少儿图书馆';
        //获取到用户年卡信息
        $userCardsData = $userCardsModel->getOneCards($userId);
        // 获取用户信息
        $userData = $userModel->getOneUser($userId);
        if (!$userData) {
            return $this->error(0, '用户信息不存在');
        }

        //验证是否可以下单
        $resAbnormalOrder = self::getThisAbnormalOrder($userId);
        if($resAbnormalOrder['code'] != 200){
            return $resAbnormalOrder;
        }

        // 配送方式
        $storeId = (int)$params['store_id'];
        switch ($orderData['shipping_code']) {
            case 1:// 到馆自取
                if (!$storeId) {
                    return $this->error(10160, '请选择门店');
                }
                $orderData['store_id'] = $storeId;
                $store = $stores->getStoresByStoreId($storeId);
                $orderData['address'] = $store['address'];
                break;
            case 2:// 集中配送
                 return self::error(100,"“集中配送”服务暂时停止，您可选择“专业快递”或“到馆自取”。");
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
                $resCheckAddress = self::checkAddress($address, $userCardsData['card_id'] ?? 0);
                if ($resCheckAddress['code'] != 200) {
                    return $resCheckAddress;
                }
                if (!$address) {
                    return $this->error(0, '请输入配送地址');
                }
                if (!$address['address']) {
                    return $this->error(0, '请输入详细配送地址');
                }

                if(mb_strlen($address['province'], 'utf-8') > 16){
                    return $this->error(0, '省份不能超过16个字');
                }
                if(mb_strlen($address['city'], 'utf-8') > 16){
                    return $this->error(0, '城市不能超过16个字');
                }
                if(mb_strlen($address['district_name'], 'utf-8') > 16){
                    return $this->error(0, '区/县不能超过16个字');
                }
                if(mb_strlen($address['address'], 'utf-8') > 128){
                    return $this->error(0, '详细地址不能超过128个字');
                }
//                if ($orderCount > 0) {
//                    $orderData['order_status'] = 0;
//                    $orderData['shipping_price'] = config('self_config.order_shipping_price');
//                    $orderData['count_price'] = config('self_config.order_shipping_price');
//                    $orderData['pay_status'] = 0;
//                }
                break;
            default:
                break;
        }
        /** 记录订单中是否有还书数据 */
        $resultBook = 0;
        /** 是否已执行过积分赠送逻辑 */
        $point_is_give = 0;

        //开启事务
        Db::startTrans();
        try {
            //2020.12.22 by yangliang 新增订单来源
            $orderData['source'] = 2;    //1-一键借还书    2-小程序
            $orderData['user_card_id'] = isset($userCardsData['id']) ? $userCardsData['id'] : 0;

            //验证用户订单是否可配书(存在已预约还书订单未揽收订单不可配书)
            $orderData['is_can_deal'] = (OrderModel::getNotpaidNum($userId) > 0) ? 0 : 1;

            // 创建订单
            $delete_order_id = $orderData['order_id'] = $orderId = $orderModel->insert($orderData, true);//创建订单 返回订单id
            if ($orderId) {
                //1.1 添加订单日志
                $resOrderLog = (new LogService())->orderLog(['order_id' => $orderId, 'order_status' => $orderData['order_status']], ['user_id' => $userId, 'user_name' => $userData['user_name']], '用户添加订单', '创建订单', 0);
                if (!$resOrderLog) {
                    Db::rollback();
                    return $this->error(0, '订单日志添加失败');
                }
                //1.2 更新门店信息
                if ($userData['store_id'] != $orderData['store_id']) {
                    $user_update = $userModel->where('user_id', $userId)->save(['store_id' => $orderData['store_id']]);
                    if ($user_update === false) {
                        Db::rollback();
                        return $this->error(0, '用户门店ID更新失败');
                    }
                }
            } else {
                Db::rollback();
                return $this->error(0, '订单添加失败');
            }
            // 写入商品订单信息
            $resultOrderGoods = (new OrderGoodsService())->createData($orderData, $userId, $userCardsData, $orderGoods, $orderId, $jd_v2_array);
            if ($resultOrderGoods['code'] != 200) {//商品处理失败 返回给用户
                Db::rollback();
                return $resultOrderGoods;
            }

            // 创建调拨单数据
            $cirDatas['applyuserid'] = $userId;
            $cirDatas['confirmuserid'] = $userId;
            $cirDatas['order_id'] = $orderId;
            $cirDatas['is_buy'] = 0;// 租售方式:租借
            $cirDatas['types'] = 1;// 调拨方式:自动
            $cirDatas['instoreid'] = $storeId;// 调入仓库ID
            $cirDatas['detail'] = [];
            /** 单独配送时才会启用*/
            $isNegStock = [];//
            foreach ($orderGoods as $key => $val) {
                //没有问题 开始降低库存
                $resStore = (new StoreService())->upStore($val, $userId, (int)$orderId, $storeId, $userCardsData, $orderSn, (int)$params['shipping_code'], (int)$orderData['pay_status'], $orderData);
                if ($resStore['code'] != 200) {//降低库存失败
                    if ($resStore['code'] == 101) {
                        //开始给jd_v2_array 负值
                        $jd_v2_array[] = $resStore['data']['jd_v2_array'];
                        unset($orderGoods[$key]);
                    } else {
                        Db::rollback();
                        return $resStore;
                    }
                } else {
                    if ($resStore['data']['is_neg_stock']) {
                        $isNegStock[] = $resStore['data']['is_neg_stock'];
                    }
                    if ($resStore['data']['cirData']) {
                        $cirGoods = $resStore['data']['cirData'];
                        $cirDatas['detail'][$cirGoods['outstoreid']][] = $cirGoods['detail'];
                    }
                }
            }
            // 判断是否需要创建调拨单
            if (count($cirDatas['detail']) > 0) {
                $cirGoods = $cirDatas;
                //创建调拨单数据
                if ($cirGoods) {
                    $cirGoods['order_sn'] = $orderData['order_sn'];
                    // 3.1创建调拨单和调拨单商品
                    $cirResult = (new CirculationService())->create($cirGoods);
                    if ($cirResult['code'] != 200) {
                        Db::rollback();
                        return $this->error($cirResult['code'], $cirResult['message']);
                    }

                    // 3.2更新订单状态为书不齐
                    $orderUpdateResult = (new OrderModel())->updateOrder($orderId);
                    if (!$orderUpdateResult) {
                        Db::rollback();
                        return $this->error(0, '调拨单对应订单状态更新失败');
                    }
                }
            }
            //如果有自有订单
            if ($orderGoods) {
                $orderGoodsIds = (new OrderGoodsModel())->createOrderGoods($orderGoods);
                if ($orderGoodsIds != count($orderGoods)) {
                    Db::rollback();
                    return $this->error(0, '订单商品添加失败');
                }
                // 更新阅读数量以及删除购物车中已下单的书籍
                $resOrderGoodsRead = (new OrderGoodsService())->orderGoodsRead($orderGoods, $userId);
                if (!$resOrderGoodsRead) {
                    Db::rollback();
                    return $resStore;
                }
                //修改订单商品缺书书不齐状态
                if ($isNegStock) {
                    $resOrderGoodsIsNegStock = (new OrderGoodsModel())->updateOrderGoods($isNegStock, $orderId);
                    if (!$resOrderGoodsIsNegStock) {
                        Db::rollback();
                        return $this->error(0, '订单商品缺书失败');
                    }
                }

                // 4.创建还书商品数据
                if ($recIds) {
                    $returnGoodsResult = (new ReturnGoodsService())->createReturnGoods((array)$recIds, (int)$orderId);
                    if ($returnGoodsResult['code'] != 200) {
                        Db::rollback();
                        return $this->error($returnGoodsResult['code'], $returnGoodsResult['message']);
                    }
                    $resultBook = 1;
                }

                $point_is_give = 1;

                // 5.判断是否新用户赠送阅币
                if ($orderData['is_new'] == 1) {
                    // 新用户赠送积分
                    $pointsData['points'] = config('self_config.order_points');
                    $pointsData['status'] = 1;
                    $pointsData['ptype'] = 3;
                    $pointsData['pnote'] = sprintf('新用户下单赠送%s阅币', $pointsData['points']);
                    $pointsData['wechat_message'] = sprintf('下单赠送%s阅币', $pointsData['points']);
                    $userPointsResult = (new PointsService())->handlePoints(['user_id' => $userId], $pointsData, 1);
                    if ($userPointsResult['code'] != 200) {
                        Db::rollback();
                        return $userPointsResult;
                    }
                }

                // 6.更新年卡信息
                if ($userCardsData) {
                    $depositExpireDateTimeStamp = $userData['user_deposit_expire_time'];
                    if ($userCardsData['is_lock'] == 1) {
                        if (time() > $depositExpireDateTimeStamp) {
                            Db::rollback();
                            return $this->error(-1, '您的年卡暂未激活');
                        }
                    } else {
                        $isUseUserCardRes = (new UserCardService())->isUseUserCard($userCardsData, null, $userId);
                        if ($isUseUserCardRes['code'] != 200) {
                            Db::rollback();
                            return $this->error($isUseUserCardRes['code'], $isUseUserCardRes['message']);
                        }

                        // 更新年卡借阅信息
                        $userCardsResult = (new UserCardService())->updateUserCard($userCardsData['id'], $orderId);
                        if ($userCardsResult['code'] != 200) {
                            Db::rollback();
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
            $isJdOrder = 0;
            //新增 自检 如果orderGoods为空 删除订单
            if (!$orderGoods) {
                $isJdOrder = 1;
                (new OrderModel())->where('order_id', $delete_order_id)->delete();
                $orderId = 0;
            }
            $jd_order_id = 0;
            if ($userCardsData) {
                if ($jd_v2_array) {
                    //2020-10-9 增加判断 是否包含没有jd_sku码的书 有 阻拦
                    $isOrder = ToolsService::searchOrder($userId);
                    $thirdPartyOrder = ThirdPartyOrderService::checkUserOrder($userId);
                    foreach ($jd_v2_array as $v) {
                        $jd_sku_number = (new GoodsModel())->field('jd_sku,goods_name')->where('goods_id', $v)->find();
                        if (config('self_config.is_jd') == 0) {
                            Db::rollback();
                            (new UserLackService())->beforeAdd($userId, $v, 1);
                            return $this->error(20003, '书籍<<' . $jd_sku_number['goods_name'] . '>> 库存不足');
                        }
                        if (!$thirdPartyOrder) {
                            if ($jd_sku_number['jd_sku'] == 0) {
                                Db::rollback();
                                (new UserLackService())->beforeAdd($userId, $v, 1);
                                return $this->error(2020, '书籍 《' . $jd_sku_number['goods_name'] . "》，暂无库存!", ['goods_id' => $v, 'goods_name' => $jd_sku_number['goods_name']]);
                            }
                            if ($isOrder) {
                                Db::rollback();
                                (new UserLackService())->beforeAdd($userId, $v, 1);
                                return $this->error(20003, '书籍<<' . $jd_sku_number['goods_name'] . '>> 库存不足');
                            }
                        }
                    }
                    $res_jd_order = (new JdOrderService())->create($params, $userId, $jd_v2_array, $resultBook, $point_is_give, (int)$orderId);
                    if ($res_jd_order['code'] != 200) {
                        Db::rollback();
                        return $res_jd_order;
                    }
                    $jd_order_id = $res_jd_order['data']['order_id'];
                }
            }

            //取消用户异常订单可借阅
            UserModel::where('user_id', $userId)->where('is_exception_borrow', 1)->update(['is_exception_borrow' => 0]);
            Db::commit();
            // 9.下单成功发送小程序订阅消息
            if ($userData['smart_openid']) {
                if ($orderGoods) {//如果有自由订单发送模版消息
                    $this->sendCreateOrderMessage($orderId, $storeName, $orderData, $userData);
                }
            }
            // 10.向公众号发送消息
            if ($orderGoods) {
                (new SendMessageService())->sendOrderMessage($userId, $orderData);
            }


            //处理前段显示拆单内容
            $resOrderId = null;
            if ($orderGoods) {
                $resOrderId = $orderId;
                if ($jd_order_id) {
                    $resOrderId .= "," . $jd_order_id;
                }
            } else {
                $resOrderId = $jd_order_id;
            }
            return $this->success(200, '订单创建成功', ['order_id' => $resOrderId, 'is_jd_order' => $isJdOrder]);
        } catch (\Throwable $ex) {
            Db::rollback();
            trace('下单异常:' . json_encode($ex->getMessage(), JSON_UNESCAPED_UNICODE), 'error');
            $this->sendDingError('创建订单异常 用户ID:' . $userId, $ex);
            return $this->error(0, '下单异常，请稍后下单');
        }
    }

    public function sendCreateOrderMessage($orderId, $storeName, $orderData, $userData)
    {
        $smartOrder['order_id'] = $orderId;
        $smartOrder['store_name'] = $storeName;
        $goodsNames = (new OrderGoodsModel())->field('goods_name')->where('order_id', $orderId)->select()->toArray();
        $str = '';
        foreach ($goodsNames as $gn) {
            $str .= '<' . $gn['goods_name'] . '>';
        }
        if (iconv_strlen($str) > 20) {
            $smartOrder['goods_name'] = iconv_substr($str, 0, 17) . '...';
        } else {
            $smartOrder['goods_name'] = $str;
        }
        $smartOrder['order_sn'] = $orderData['order_sn'];
        $smartOrder['add_time'] = date('Y-m-d H:i');
        if ($orderData['is_new'] == 1) {
            $remark = '睿鼎少儿【孩子的专属图书馆】';
        } else {
            $remark = isset($orderData['shipping_price']) && $orderData['shipping_price'] > 0 ? '您的订单费用' . $orderData['shipping_price'] . '元,请尽快付款' : '请记得带上您需要归还的书籍';
        }
        $smartOrder['remark'] = $remark;
        $smartSendResult = (new SmartSendService())->orderMessage($userData['smart_openid'], $smartOrder);
        if (!$smartSendResult) {
            // Logger::getInstance()->logMsg('订单创建成功发送小程序提醒失败');
        }
    }

    /**借书-用户手中书籍自检
     * @param int $userId
     * @return array
     */
    public function orderNumberCheck(int $userId): array
    {
        $userModel = new UserModel();
        Db::startTrans();
        try {
            //校验年卡
            $cart_goods = CartModel::getSelectedGoodsByUserId($userId);
            $goods_ids = array_column($cart_goods, 'goods_id');
            $check_card = (new UserCardService())->checkUserCardByUserId($userId, $goods_ids, 1);
            if ($check_card['code'] != 200) {
                return $this->error(2047, $check_card['message']);
            }

            //设置时间范围区间
            $start_time = strtotime('2020-06-24 00:00:0');
            $end_time = time() - (60 * 60 * 24 * 10);
            //查出来订单本数数量
            $userOrderCount = OrderGoodsModel::alias('og')
                    ->join('order o', 'og.order_id = o.order_id')
                    ->where('og.is_repay', 2)
                    ->where('og.is_end', 1)
                    ->where('og.is_paid', 0)
                    ->where('o.user_id', $userId)
                    ->where('og.return_scanning_time', 0)
                    ->where('o.add_time', '>=', $start_time)
                    ->where('o.add_time', '<', $end_time)
                    ->whereIn('o.order_status', [0, 1, 2, 3, 4, 9])
                    ->count() ?? 0;
            //查询用户标识
            $userData = $userModel->field('is_order_check,order_check_out_time')->where('user_id', $userId)->find();
            if ((int)$userData['is_order_check'] == 2) {//用户已被标识为不可借
                //判断小于设定 放开
                if ($userOrderCount < config('self_config.user_order_number')) {
                    $resCreateOrUpdate = $this->createOrUpdateSelfAndUser($userId, 0, $userOrderCount);
                    if ($resCreateOrUpdate['code'] == 100) {
                        Db::rollback();
                        return $resCreateOrUpdate;
                    }
                    Db::commit();//提交事务
                    return $this->success(200, '无异常');
                }
                Db::rollback();
                return $this->error(2046, '请先归还您手上的书籍后借阅', ['number' => $userOrderCount]);
            }
            if ($userOrderCount >= config('self_config.user_order_number')) {//如果大于了设置的最大下单量
                //查询用户
                if ($userData['is_order_check'] == 1 && ($userData['order_check_out_time'] + config('self_config.user_order_out_time')) > time()) {//如果用户已被设置的时间未过期 正常下单
                    Db::commit();
                    return $this->success(200, '无异常');
                }
                $resCreateOrUpdate = $this->createOrUpdateSelfAndUser($userId, 1, $userOrderCount);
                if ($resCreateOrUpdate['code'] == 100) {
                    Db::rollback();
                    return $resCreateOrUpdate;
                }

                //修改完成 提交事务 阻拦下单
                Db::commit();
                return $this->error(2046, '请先归还您手上的书籍后借阅', ['number' => $userOrderCount]);
            }

            //三方物流V2
            $res = self::checkShowExpress($userId);
            if($res['code'] != 200){
                Db::rollback();
                return $this->error($res['code'], $res['message']);
            }else{
                $is_show_express = $res['data']['is_show_express'];
                $store = $res['data']['store'];
            }

            Db::commit();//提交事务
            return $this->success(200, '无异常', ['is_show_express' => $is_show_express, 'store' => $store]);
        } catch (\Throwable $e) {
            Db::rollback();
            trace('用户手中书籍自检异常 用户ID:' . $userId . json_encode($e));
            $this->sendDingError('用户手中书籍自检异常 用户ID:' . $userId, $e);
            return $this->error(100, '借书异常，请稍后再试');
        }
    }

    protected function createOrUpdateSelfAndUser(int $userId, int $type, int $userOrderCount): array
    {
        $userModel = new UserModel();
        $selfCheckModel = new SelfCheckModel();
        //修改用户无法借阅
        $remark = "开启【停止借书】";
        if ($type == 1) {
            $resUserData = $userModel->where('user_id', $userId)->update(['is_order_check' => 2]);
        } else {
            $remark = "关闭【停止借书】";
            $resUserData = $userModel->where('user_id', $userId)->update(['is_order_check' => 1, 'order_check_out_time' => 0]);
        }
        if (!$resUserData) {
            return $this->error(100, '修改失败，请稍后再试');
        }
        if ($userOrderCount >= config('self_config.user_order_number')) {//如果大于设定 修改或写入
            $selfCheckData = $selfCheckModel->where('user_id', $userId)->find();
            if (!$selfCheckData) {//没有数据 写入数据
                $resSelfCheck = $selfCheckModel->insert(['user_id' => $userId, 'unpaid_num' => (int)$userOrderCount, 'create_time' => time(), 'update_time' => time()], true);
                if (!$resSelfCheck) {
                    return $this->error(100, '修改失败，请稍后再试');
                }
            } else {
                $resSelfCheck = $selfCheckModel->where('user_id', $userId)->update(['unpaid_num' => (int)$userOrderCount, 'update_time' => time()]);
                if (!$resSelfCheck) {
                    return $this->error(100, '修改失败，请稍后再试');
                }
            }
            $resSelfCheckLog = (new SelfCheckLogModel())->SelfLogInsert($userId, $remark, $userOrderCount);
            if (!$resSelfCheckLog) {
                return $this->error(100, '修改失败，请稍后再试');
            }
        } else {//修改降低数量
            $resSelfCheck = $selfCheckModel->where('user_id', $userId)->update(['unpaid_num' => (int)$userOrderCount, 'update_time' => time()]);
            if (!$resSelfCheck) {
                return $this->error(100, '修改失败，请稍后再试');
            }
            $resSelfCheckLog = (new SelfCheckLogModel())->SelfLogInsert($userId, $remark, $userOrderCount);
            if (!$resSelfCheckLog) {
                return $this->error(100, '修改失败，请稍后再试');
            }

        }
        return $this->success();
    }


    /**
     * 取消订单
     * @param int $user_id 用户ID
     * @param int $order_id 订单ID
     * @param int $is_auto 是否系统取消   0-否    1-是
     * @return array
     * @author yangliang
     * @date 2020/12/4 10:51
     */
    public function cancelOrder(int $user_id, int $order_id, int $is_auto = 0): array
    {
        Db::startTrans();
        try {
            $order = OrderModel::getByOrderId($order_id);

            if (empty($order)) {
                return $this->error(100, '订单信息不存在');
            }

            if ($order['user_id'] != $user_id) {
                return $this->error(100, '无权限进行此操作');
            }

            //京东快递待配送，不能取消订单
            if($order['order_status'] == 3 && $order['shipping_code'] == 3){
                return $this->error(ConstCode::ALERT_CODE, '取消失败！订单书籍已出库，正在等待京东揽件。');
            }

            //到馆自取待配送，不能取消订单
            if($order['order_status'] == 3 && $order['shipping_code'] == 1){
                return $this->error(ConstCode::ALERT_CODE, '取消失败！订单书籍已出库。');
            }

            //待付款、配书中、核验中不能取消订单
            if (!in_array($order['order_status'], [0, 1, 2])) {
                return $this->error(100, '订单当前状态不能取消');
            }

            $note = ($is_auto == 1) ? '系统取消订单' : '用户取消订单';

            event('CancelOrder', [
                'order' => $order,
                'user_id' => $user_id,
                'is_auto' => $is_auto,
                'note' => $note
            ]);

        } catch (Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '取消成功');
    }


    /**
     * 再来一单（将订单商品添加至购物车）
     * @param int $user_id
     * @param int $order_id
     * @return array
     * @author yangliang
     * @date 2020/12/23 11:59
     */
    public function borrowBook(int $user_id, int $order_id): array
    {
        $order = OrderModel::getByOrderId($order_id);
        if ($order['order_id'] != $order_id || $order['order_status'] != -1) {
            return $this->error(100, '订单不正确');
        }

        $order_goods = OrderGoodsModel::getByOrderId($order_id);
        if (empty($order_goods)) {
            return $this->error(100, '商品不存在');
        }

        Db::startTrans();
        try {
            foreach ($order_goods as $v) {
                //判断是否已添加过此书籍
                $cart_count = CartModel::getCountByUserIdAndGoodsId($user_id, $v['goods_id']);
                if ($cart_count > 0) {
                    continue;
                }

                //加入购物车
                $res = (new CartService())->addCart([
                    'userId' => $user_id,
                    'goods_id' => $v['goods_id'],
                    'referer_source' => 3
                ]);
            }
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '已将有库存的书籍添加成功');
    }

    public static function createLog($orderInfo, $userInfo, $actionNote, $statusDesc, $actionType, $isBuy = 0)
    {
        if (!$orderInfo['order_id'] || !$userInfo['user_id']) {
            return false;
        }
        $is_admin = $isBuy ? $userInfo['is_admin'] : 0;
        $orderLog['order_id'] = $orderInfo['order_id'];
        $orderLog['order_status'] = $orderInfo['order_status'];
        $orderLog['action_user'] = $userInfo['user_id'];
        $orderLog['user_name'] = $userInfo['user_name'];
        $orderLog['action_note'] = $actionNote;
        $orderLog['status_desc'] = $statusDesc;
        $orderLog['action_type'] = $actionType;
        $orderLog['log_time'] = time();
        $orderLog['is_admin'] = $is_admin;
        if ($isBuy == 1) {
            return (new BuyOrderLogModel())->insert($orderLog, true);
        }
        unset($orderLog['is_admin']);
        return (new OrderLogModel())->insert($orderLog, true);
    }

    /**订单运费支付
     * @param $request
     * @return array
     * @author Poison
     * @date 2020/12/19 3:02 下午
     */
    public function getPayment($request): array
    {
        $orderId = $request['order_id'];
        if (!$orderId) {
            return $this->error(10160, 'order_id不能为空');
        }
        $order = (new OrderModel())->getOneOrderData(['order_id' => $orderId], 'user_id,order_sn,shipping_price,order_status');
        if (!$order) {
            return $this->error(0, '此订单已无效不能支付');
        }
        if ($order['order_status'] == -1) {
            return $this->error(0, '此订单已无效不能支付');
        }

        $payOrderInfo['order_id'] = $orderId; // 用户升级表记录ID
        $payOrderInfo['order_type'] = 1;
        $payOrderInfo['order_amount'] = $order['shipping_price'];
        $payOrderInfo['out_trade_no'] = $order['order_sn'];
        $payLogResult = (new LogService())->payLog($payOrderInfo, 0, $order['user_id']);
        if (!$payLogResult) {
            return $this->error(0, "支付日志记录错误");
        }
        $payData['body'] = '睿鼎少儿订单配送费';
        $payData['attach'] = $request['type'];
        $payData['openid'] = $request['openid'];
        $payData['trade_type'] = 'JSAPI';
        $payData['out_trade_no'] = $order['order_sn'];
        $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $order['shipping_price'] * 100;
        $pay = Pay::getInstance();
        $result = $pay->unifiedorder($payData);
        if (!$result) {
            return $this->error(0, $result['message']);
        }
        return $this->success(200, "操作成功", json_encode($result));
    }

    /**借书赔付
     * @param $request
     * @return array
     * @author Poison
     * @date 2020/12/19 3:38 下午
     */
    public function getGoodsPayment($request): array
    {
        $orderId = $request['order_id'];
        if (!$orderId) {
            return $this->error(0, 'order_id不能为空');
        }

        $order = (new OrderModel())->getOneOrderData(['order_id' => $orderId], 'user_id,order_sn,store_id,order_status,order_id');
        if (!$order) {
            return $this->error(0, '订单信息不存在');
        }
        if ($order['order_status'] != 9) {
            return $this->error(0, '只有已完成订单才可以申请赔付');
        }
        $orderGoods = (new OrderGoodsModel())->field('rec_id,order_id,goods_id,goods_sn')->where('rec_id', $request['rec_id'])->findOrEmpty()->toArray();
        if (!$orderGoods) {
            return $this->error(0, '订单商品信息不存在');
        }
        if ($orderId != $orderGoods['order_id']) {
            return $this->error(0, '订单没有此对应订单商品');
        }
        //需求，三方物流还书需生成还书单，此处不限制赔付
//        $returnGoods = (new ReturnGoodsModel())->where('rec_id', $request['rec_id'])->findOrEmpty()->toArray();
//        if ($returnGoods) {
//            return $this->error(0, '此书籍已存在还书单中,需要进行线下赔付申请');
//        }

        // 赔付价格
        $price = (new GoodsModel())->where('goods_id', $orderGoods['goods_id'])->value('price');
        if ($price <= 0) {
            return $this->error(0, '此书籍需联系客服进行赔付');
        }
        if (!$order['user_id']) {
            return $this->error(0, '订单商品信息异常，请联系客服人员处理');
        }
        $payOrderInfo['order_id'] = $orderId; // 订单ID
        $payOrderInfo['order_type'] = 4;
        $payPrice = $price * (float)config('self_config.pay_goods_ratio');
        $payOrderInfo['order_amount'] = round($payPrice, 2);// 赔付金额
        $payOrderInfo['out_trade_no'] = $orderGoods['rec_id'] . '-' . $orderGoods['order_id'] . '-' . $orderGoods['goods_id'] . '-' . rand(10000, 99999);
        $payLogResult = (new LogService())->payLog($payOrderInfo, 0, $order['user_id']);
        if (!$payLogResult) {
            return $this->error(0, "支付日志记录错误");
        }

        $payData['body'] = '睿鼎少儿书籍赔付';
        $payData['attach'] = $request['type'];
        $payData['openid'] = $request['openid'];
        $payData['trade_type'] = 'JSAPI';
        $payData['out_trade_no'] = $payOrderInfo['out_trade_no'];
        $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $payOrderInfo['order_amount'] * 100;
        $pay = Pay::getInstance();
        $result = $pay->unifiedorder($payData);
        if (!$result) {
            return $this->error(0, $result['message']);
        }
        return $this->success(200, "操作成功", json_encode($result));
    }


    /**
     * 获取用户订单
     * @param int $user_id 用户ID
     * @param int $orderStatus 订单状态
     * @param int $page 分页页码
     * @return array
     * @author yangliang
     * @date 2021/2/20 11:40
     */
    public function getOrders(int $user_id, int $orderStatus, int $page): array
    {
        $list = OrderModel::getMyOrder($user_id, $orderStatus, $page);
        if (!empty($list)) {
            $list = $this->getOrderGoodsReturnGoods($list,$orderStatus);
            foreach ($list as &$lv) {
                // 订单状态
                if ($orderStatus == 3) {
                    // 待归还
                    $lv['order_status_text'] = '待归还';
                }
            }
        }

        return $this->success(200, 'success', $list);
    }


    /**
     * 获取订单书籍和还书书籍
     * @param $orders
     * @return array
     * @author yangliang
     * @date 2021/2/20 11:37
     */
    public function getOrderGoodsReturnGoods($orders,$orderStatus): array
    {
        if (empty($orders)) {
            return [];
        }

        foreach ($orders as &$v) {
            // 订单状态
            if($orderStatus == 0){
                $v['order_status'] = 0;
            }
            $v['order_status_text'] = OrderModel::$orderStatusMap[$v['order_status']];

            // 订单商品信息
            $order_goods = OrderGoodsModel::getOrderGoodsInfoByOrderId((int)$v['order_id']);
            foreach ($order_goods as &$ov) {
                $ov['original_img'] = $this->getGoodsImg($ov['original_img'], 400, 400);
            }
            $v['order_goods'] = $order_goods;

            // 订单还书信息
            $return_goods = ReturnGoodsModel::getReturnGoodsInfoByOrderId($v['order_id']);
            foreach ($return_goods as &$rv) {
                $rv['original_img'] = $this->getGoodsImg($rv['original_img'], 400, 400);
            }

            $v['return_goods'] = $return_goods;

            //是否显示取消还书按钮（还书揽件后不展示取消还书）
            $waybill_order_sn = OrderWaybillModel::getReturnOrderSnByOrderId($v['order_id']);
            $v['is_store_return'] = 2;  //预约还书类型  1-到馆  2-三方物流
            $isReturnBooks = true;//是否显示还书按钮
            $v['return_waybill_order_sn'] = '';
            $v['borrow_waybill_order_sn'] = '';
            if(empty($waybill_order_sn)){  //到馆还书
                $return_order = ReturnGoodsModel::getByBorrowOrderId($v['order_id']);
                $un_end_return_goods = ReturnGoodsModel::getByBorrowOrderIdAndIsEnd($v['order_id'], 0);
                //是否显示我要还书按钮  0-不显示  1-显示
                $v['is_show_return_books_btn'] = empty($return_order) ? 1 : 0;
                if($v['is_show_return_books_btn'] == 0){
                    $isReturnBooks = false;
                }

                //订单借书物流编号
                $borrow_waybill = OrderWaybillModel::getByOrderSnAndType($v['order_sn'], 1);
                $v['borrow_waybill_order_sn'] = $borrow_waybill['order_sn'] ?? '';

                //是否显示取消还书按钮  0-不显示  1-显示 (存在还书记录，且未完成显示取消按钮)
                $v['is_show_cancel_return_books'] = (!empty($return_order) && !empty($un_end_return_goods)) ? 1 : 0;
                $v['is_store_return'] = 1;
            }else {  //三方物流还书

                //订单借书物流编号
                $borrow_waybill = OrderWaybillModel::getByOrderSnAndType($v['order_sn'], 1);
                $v['borrow_waybill_order_sn'] = $borrow_waybill['order_sn'] ?? '';
                //订单还书物流编号
                $return_waybill = ReturnGoodsModel::getWaybillByOrderId($v['order_id']);
                $v['return_waybill_order_sn'] = $return_waybill['order_sn'] ?? '';

                $return_order = OrderWaybillModel::getByOrderSnAndType($waybill_order_sn['order_sn'] ?? '', 2);
                //是否显示我要还书按钮  0-不显示  1-显示
                $v['is_show_return_books_btn'] = (empty($return_order) || in_array($return_order['status'], ['-1', '2', '8'])) ? 1 : 0;
                //是否显示取消还书按钮  0-不显示  1-显示
                $v['is_show_cancel_return_books'] = (!empty($return_order) && $return_order['status'] == 0) ? 1 : 0;
            }


            //是否首次使用三方物流还书  0-否    1-是
            $user_return_num = OrderWaybillModel::getReturnNumByUserId($v['user_id']);

            $v['is_first_return_books'] = ($user_return_num > 0) ? 0 : 1;
            if(!$isReturnBooks){
                $v['is_first_return_books'] = 0;
            }
            //此订单是否存在还书记录
            $v['is_order_return_record'] = (!empty($return_waybill) && $return_waybill['status'] > -1) ? 1 : 0;
        }

        return $orders;
    }


    /**
     * 通过order_id获取订单信息
     * @param int $order_id 订单ID
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/20 17:50
     */
    public function getOrderInfo(int $order_id, int $user_id): array
    {
        $order = OrderModel::getByOrderId($order_id);
        if (empty($order)) {
            return $this->error(100, '暂无订单信息');
        }

        $order['order_status_text'] = OrderModel::$orderStatusMap[$order['order_status']];  //订单状态
        $order['shipping_code_text'] = OrderModel::$shippingCodeMap[$order['shipping_code']];  //配送方式

        $order_goods = OrderGoodsModel::getOrderGoodsInfoByOrderId($order_id);
        if (empty($order_goods)) {
            return $this->error(100, '暂无订单商品信息');
        }

        $order_unrepay_num = 0;  //订单未还书籍数量（不包含已赔付）
        foreach ($order_goods as &$v) {
            $v['order_sn'] = $order['order_sn'];
            $v['original_img'] = $this->getGoodsImg($v['original_img'], 400, 400);
            //2020-10-19 新增第一类标签
            //2020-10-19 新增是否收藏
            $goods_collect = GoodsCollectModel::getByUserIdAndGoodsId($user_id, $v['goods_id']);
            $v['is_collect'] = 0;
            if (!empty($goods_collect)) {
                $v['is_collect'] = $goods_collect;
            }

            $v['is_cart'] = 0;
            $cart_id = CartModel::getByUserIdAndGoodsId($user_id, $v['goods_id']);
            if (!empty($cart_id)) {
                $v['is_cart'] = $cart_id['id'];
            }

            $cat_name = CatModel::getByTypeAndGoodsId(0, $v['goods_id']);
            if (!empty($cat_name)) {
                $v['cat_name'] = CatModel::getById(intval($cat_name['parent_id']))['name'];
            } else {
                $v['cat_name'] = '';
            }

            //订单未还书籍数量
            if($v['is_repay'] == 0){
                $order_unrepay_num++;
            }
        }

        $order['order_goods'] = $order_goods;
        $order['order_unrepay_num'] = $order_unrepay_num;

        // 还书信息
        $return_goods = ReturnGoodsModel::getReturnGoodsInfoByOrderId($order_id);
        if (!empty($return_goods)) {
            foreach ($return_goods as &$gv) {
                $gv['original_img'] = $this->getGoodsImg($gv['original_img'], 400, 400);
            }
        }

        $order['return_goods'] = $return_goods;

        //是否显示取消还书按钮（还书揽件后不展示取消还书）
        $waybill_order_sn = OrderWaybillModel::getReturnOrderSnByOrderId($order_id);
        $v['is_store_return'] = 2;  //预约还书类型  1-到馆  2-三方物流
        if(empty($waybill_order_sn)){  //到馆还书
            $return_order = ReturnGoodsModel::getByBorrowOrderId($v['order_id']);
            $un_end_return_goods = ReturnGoodsModel::getByBorrowOrderIdAndIsEnd($v['order_id'], 0);
            //是否显示我要还书按钮  0-不显示  1-显示
            $v['is_show_return_books_btn'] = empty($return_order) ? 1 : 0;
            //是否显示取消还书按钮  0-不显示  1-显示 (存在还书记录，且未完成显示取消按钮)
            $v['is_show_cancel_return_books'] = (!empty($return_order) && !empty($un_end_return_goods)) ? 1 : 0;
            $v['is_store_return'] = 1;
        }else {  //三方物流还书
            $return_order = OrderWaybillModel::getByOrderSnAndType($waybill_order_sn['order_sn'] ?? '', 2);
            //是否显示我要还书按钮  0-不显示  1-显示
            $order['is_show_return_books_btn'] = (empty($return_order) || in_array($return_order['status'], [-1, 2, 8])) ? 1 : 0;
            //是否显示取消还书按钮  0-不显示  1-显示
            $order['is_show_cancel_return_books'] = (!empty($return_order) && $return_order['status'] == 0) ? 1 : 0;
        }

        //此订单是否存在还书记录
        $order['is_order_return_record'] = (!empty($return_order) && $return_order['status'] > -1) ? 1 : 0;

        //订单借书物流编号
        $borrow_waybill = OrderWaybillModel::getByOrderSnAndType($order['order_sn'], 1);
        $order['borrow_waybill_order_sn'] = $borrow_waybill['order_sn'] ?? '';
        //订单还书物流编号
        $return_waybill = ReturnGoodsModel::getWaybillByOrderId($order['order_id']);
        $order['return_waybill_order_sn'] = $return_waybill['order_sn'] ?? '';

        $order['store'] = StoresModel::getById($order['store_id']);

        //还书是否显示到馆自取  0-不显示  1-显示
        $order['is_show_store'] = 0;
        $address = UserAddressModel::getAddress($user_id);
        if(!empty($address) && strstr($address['province'], '陕西')){
            $order['is_show_store'] = 1;
        }
        return $this->success(200, 'success', $order);
    }


    /**
     * 获取用户订单数量
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/22 10:26
     */
    public function getOrdersCount(int $user_id): array
    {
        $list = [
            ['order_status' => 0, 'total' => 0],
            ['order_status' => 1, 'total' => 0],
            ['order_status' => 2, 'total' => 0],
            ['order_status' => 3, 'total' => 0],
            ['order_status' => 4, 'total' => 0],
        ];

        foreach ($list as &$v) {
            switch ($v['order_status']) {
                case 0:  // 待付款
                   // $v['total'] = OrderModel::getCountByUserIdAndStatus($user_id, [0]);
                    $v['total'] = OrderModel::getAbOrderCount($user_id);
                    break;
                case 1:  // 待发货
                    $v['total'] = OrderModel::getCountByUserIdAndStatus($user_id, [1, 2, 3]);
                    break;
                case 2:  // 待收货
                    $v['total'] = OrderModel::getCountByUserIdAndStatus($user_id, [4]);
                    break;
                case 3:  // 待归还
                    $orders = OrderModel::getNoReturnedByUserId($user_id);
                    $v['total'] = count($orders);
                    break;
                case 4:  // 已完成
                    $orders = OrderModel::getCompletedByUserId($user_id);
                    $v['total'] = count($orders);
                    break;
                default:
                    break;
            }
        }

        return $this->success(200, '获取成功', $list);
    }


    /**
     * 用户确认收货
     * @param int $user_id
     * @param int $order_id
     * @return array
     * @author yangliang
     * @date 2021/2/22 14:41
     */
    public function deliveryOrder(int $user_id, int $order_id): array
    {
        $user = (new UserModel())->getOneUser($user_id);
        if (empty($user)) {
            return $this->error(100, '用户不存在');
        }

        $order = OrderModel::getByOrderIdAndUserId($user_id, $order_id);
        if (empty($order)) {
            return $this->error(100, '无效的订单');
        }

        if ($order['order_status'] != 4) {
            return $this->error(100, '订单尚未发货,暂不能收货');
        }

        // 判断到馆自取订单是否书齐
        if ($order['shipping_code'] == 1 && $order['book_finshed'] != 1) {
            return $this->error(100, '订单书籍尚在配送中,暂不能收货');
        }

        Db::startTrans();
        try {

            // 1.更新订单状态为已收货
            $order_update = OrderModel::where('order_id', $order_id)->update(['order_status' => 9, 'confirm_time' => time()]);
            if (!$order_update) {
                throw new \Exception('订单状态更新失败');
            }

            // 2.记录订单日志
            $order_log_res = (new LogService())->orderLog(['order_id' => $order_id, 'order_status' => 9], ['user_id' => $user_id, 'user_name' => $user['user_name']], '用户确认收货', '订单收货', 6);
            if (!$order_log_res) {
                throw new \Exception('订单日志添加失败');
            }

            // 3.更新订单借书状态:is_repay=0在用户手中,is_end=1已完成
            $order_goods = OrderGoodsModel::getByOrderId($order_id);
            $rec_ids = [];
            foreach ($order_goods as $og) {
                // 判断订单书籍是否已归还,如果未归还则更新借书状态
                if ($og['is_repay'] != 2) {
                    $rec_ids[] = $og['rec_id'];
                }
            }

            if (!empty($rec_ids)) {
                $order_goods_update = OrderGoodsModel::whereIn('rec_id', $rec_ids)->update(['is_repay' => 0, 'is_end' => 1]);
                if (!$order_goods_update) {
                    throw new \Exception('订单商品状态更新失败');
                }
            }

            // 4.更新订单还书状态
            $return_goods = ReturnGoodsModel::getByOrderId($order_id);
            if (!empty($return_goods)) {
                // 4.1.更新订单还书状态:is_end=1已完成,end_time
                $return_goods_update = ReturnGoodsModel::where('order_id', $order_id)->update(['is_end' => 1, 'end_time' => time()]);
                if (!$return_goods_update) {
                    throw new \Exception('订单还书状态更新失败');
                }

//                // 4.2.更新还书对应借书状态:is_repay=2已归还,is_end=1已完成
//                $return_rec_ids = [];
//                foreach ($return_goods as $rg){
//                    if ($rg['rec_id']) {
//                        $return_rec_ids[] = $rg['rec_id'];
//                    }
//                }
//
//                if(!empty($return_rec_ids)){
//                    $rg_og_update = OrderGoodsModel::whereIn('rec_id', $return_rec_ids)->update(['is_repay' => 2, 'is_end' => 1]);
//                    if(!$rg_og_update){
//                        throw new \Exception('订单还书对应借书状态更新失败');
//                    }
//                }

                // 4.2.更新还书对应借书状态:is_repay=2已归还,is_end=1已完成
                foreach ($return_goods as $rg) {
                    if (!$rg['rec_id']) {
                        continue;
                    }
                    $rg_og_goods = OrderGoodsModel::where('rec_id', $rg['rec_id'])->find();
                    if ($rg_og_goods && $rg_og_goods['is_repay'] != 2) {
                        $rg_og_update = OrderGoodsModel::where('rec_id', $rg['rec_id'])->update(['is_repay' => 2, 'is_end' => 1]);
                        if (!$rg_og_update) {
                            throw new \Exception('订单还书对应借书状态更新失败');
                        }
                    }
                }
            }

            // 5.收货成功发送微信通知
            $send_res = (new SendMessageService())->deliveryMessage($user_id, $order);
            if ($send_res) {
                Log::info('订单签收成功发送微信提醒失败');
            }

            // 9.下单成功发送小程序订阅消息
            if ($user['smart_openid']) {
                $send_data = [
                    'order_id' => $order['order_sn'] ?? $order_id,
                    'status' => '确认收货',
                    'time' => date('Y-m-d H:i', time()),
                    'remark' => '如有疑问请咨询客服 85799903',
                ];

                $smart_res = (new SmartSendService())->accessOrderMessage($user['smart_openid'], $send_data);
                if (!$smart_res) {
                    Log::info('订单创建成功发送小程序提醒失败');
                }
            }
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '订单已签收', ['order_id' => $order_id]);
    }

    /**中转站
     * @param array $params
     * @return array
     * @author Poison
     * @date 2021/5/10 7:45 下午
     */
    public function checkOrder(array $params): array
    {
        if (!$params['select']) {
            return errorArray('暂无选中的商品');
        }
        if (!is_array($params['select'])) {
            $params['select'] = explode(',', $params['select']);
            if($params['no_select']){
                $params['no_select'] = explode(',', $params['no_select']);
            }
        }
        $params['select_list'] = $params['select'];
        $resData = self::checkOrderData($params);
        if ($resData['code'] != 200 && !empty($params['no_select'])) {
            (new ComService())->userTrace($params['user_id'],188 );
            $params['select_list'] = $params['no_select'];
            $resOneData = self::checkOrderData($params);
            //合并数据 重组
            $data['correct'] = array_merge($resData['data']['correct']??[], $resOneData['data']['correct'] ?? []);
            $data['error'] = array_merge($resData['data']['error']??[], $resOneData['data']['error'] ?? []);
            $data['type'] = 2;
            if($resOneData['code'] == 200){
                $resOneData['message'] = '部分书籍库存不足';
            }
            return $this->success(100, $resOneData['message'], $data);
        } else {//如果都有库存 直接返回给前端
            $resData['data']['type'] = 1;
            return $resData;
        }
    }

    public function checkOrderData(array $params): array
    {
        $userId = (int)$params['user_id'];
        $selectList = $params['select_list'];
        $userCardsModel = new UserCardsModel();
        $orderModel = new OrderModel();
        $userModel = new UserModel();
        $noStoreGoodsArray = [];
        $address = UserAddressModel::getAddress($userId);
        $recIds = $params['rec_ids'];
        $orderData['user_id'] = $userId;
        $orderData['shipping_code'] = $params['shipping_code'];// 配 送方式
        $orderData['order_status'] = 1;// 订单状态
        $orderData['pay_id'] = 0;
        $orderData['pay_status'] = 1;
        $orderData['consignee'] = $address['consignee'] ?? '';
        $orderData['mobile'] = $address['mobile'] ?? '';
        $orderData['store_id'] = 0;// 门店ID默认0
        $orderData['shipping_price'] = 0;
        $orderData['count_price'] = 0;
        $orderData['pickup_id'] = 0;
        $orderData['add_time'] = time();
        $orderCount = $orderModel->getCount($userId);
        $orderData['is_new'] = $orderCount > 0 ? 0 : 1;// 是否新用户
        //获取到用户年卡信息
        $userCardsData = $userCardsModel->getOneCards($userId);
        // 获取用户信息
        $userData = $userModel->getOneUser($userId);
        if (!$userData) {
            return $this->error(100, '用户信息不存在');
        }

        //验证是否可下单
        $resAbnormalOrder = self::getThisAbnormalOrder($userId);
        if($resAbnormalOrder['code']!=200){
            return $resAbnormalOrder;
        }

        // 配送方式
        $storeId = (int)$params['store_id'];
        switch ($orderData['shipping_code']) {
            case 1:// 到馆自取
                if (!$storeId) {
                    return $this->error(10160, '请选择门店');
                }
                break;
            case 2:// 集中配送
                 return self::error(100,"“集中配送”服务暂时停止，您可选择“专业快递”或“到馆自取”。");
//                if ($userData['pickup_id'] > 0) {
//                    $pickup = $pickups->getPickUpByPickUpId($userData['pickup_id'], 'pickup_id,pickup_name,is_lock');
//                    if (!$pickup || $pickup['is_lock'] == 1) {
//                        return $this->error(10240, '您选择的配送点已撤销,请更改配送点或选择其他配送方式');
//                    }
//                } else {
//                    // 判断是否绑定配送点
//                    return $this->error(10160, '您还未绑定配送点,请联系客服绑定');
//                }
                break;
            case 3:
                $resCheckAddress = self::checkAddress($address, $userCardsData['card_id'] ?? 0);
                if($resCheckAddress['code']!= 200){
                    return $resCheckAddress;
                }
                break;
            case 3:
                if (!$address) {
                    return $this->error(0, '请输入配送地址');
                }
                if (!$address['address']) {
                    return $this->error(0, '请输入详细配送地址');
                }
                break;
            default:
                break;
        }
        $pickupId = $userData['pickup_id'];
        //开启事务
        Db::startTrans();
        // 写入商品订单信息
        (new OrderGoodsService())->checkData($orderData, $userId, $userCardsData, $orderGoods, $orderId, $noStoreGoodsArray, $selectList,$storeId);
        foreach ($orderGoods as  $val) {
            //没有问题 开始降低库存
            $resStore = (new StoreService())->checkStore($val, $userId, $storeId, $userCardsData, (int)$params['shipping_code'], (int)$orderData['pay_status'], $pickupId);
            if ($resStore['code'] != 200) {//降低库存失败
                self::checkValue($selectList, $val['goods_id']);
                $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
            }
        }
        $okList = [];
        $goodsStore = new GoodsStockModel();
        foreach ($selectList as $v) {
            if ($orderData['shipping_code'] == 3) {
                $newStock = $goodsStore->getValueStock(0, ['goods_id' => $v], 'stock');
                if ($newStock == 0 || $newStock == null) {
                    $newStock = 5;
                }
                $okList[] = ['goods_id' => $v, 'stock' => $newStock];
            } else {
                $newStock = (new CartService())->getTotalStock($v);
                $okList[] = ['goods_id' => $v, 'stock' => $newStock == 0 ? 5 : $newStock];
            }

        }

        Db::rollback();
        if (!empty($noStoreGoodsArray)) {
            //记录库存不足流水
            foreach ($noStoreGoodsArray as $g){
                StockLackModel::create(['user_id' => $params['user_id'], 'goods_id' => $g['goods_id'], 'create_time' => time()]);
            }
            return $this->error(100, '部分书籍库存不足', ['error' => $noStoreGoodsArray, 'correct' => $okList]);
        }
        return $this->success(200, '验证通过', ['error' => $noStoreGoodsArray, 'correct' => $okList]);
    }

    /**验证
     * @param $selectData
     * @param $goodsId
     * @author Poison
     * @date 2021/5/10 6:22 下午
     */
    public function checkValue(&$selectData, $goodsId)
    {
        foreach ($selectData as $k => $vue) {
            if ($vue == $goodsId) {
                unset($selectData[$k]);
                $selectData = array_merge($selectData);
                break;
            }
        }

    }

    /**判断是否可以下单
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/6/4 3:30 下午
     */
    private function getThisAbnormalOrder(int $userId): array
    {
        //验证用户是否已设置忽略异常订单，忽略异常订单可正常下单
        $isExceptionBorrow = (new UserModel())->getOneUser($userId,1,'is_exception_borrow');
        if($isExceptionBorrow == 0){
            $resAbnormal = self::getAbnormalOrder($userId);
            if ($resAbnormal['code'] != 200) {
                //判断是否是第一次(没有使用三方物流还过书)
                $resWaybill = OrderWaybillModel::getThisWayBillOrder($userId);
                if (count($resWaybill) == 0) {
                    return $this->error(3020, '由于系统升级，同时也为了您将来更好的借阅体验，现需将手中所有未归还的书籍进行归还后下单。');
                }
                return $resAbnormal;
            }
        }
        $resCheckCart = self::checkCart($userId);
        if ($resCheckCart['code'] != 200) {
            return $resCheckCart;
        }

        return $this->success(200, 'success');
    }

    /**验证年卡
     * @param int $userId
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author Poison
     * @date 2021/6/4 3:31 下午
     */
    private function checkCart(int $userId){
        //获取购物车中选中的书籍
        $cartData = CartModel::getCartListByUserId($userId);
        $cartCount = count($cartData);
        //判断当前所选中的数据 是否等于年卡的借阅本书
        $userCard = UserCardsModel::getOneCards($userId);
        $userData = (new UserModel())->getOneUser($userId);
        if ($userCard) {
            return (new UserCardService())->isUseUserCard($userCard, 0, $userId);
//            //判断年卡是否激活
//            if($userCard['is_lock'] == 0){
//                //年卡存在 获取当前年卡信息
//                $resCards = CardsModel::getById($userCard['card_id']);
//                if (!$resCards) {
//                    return errorArray('未查询到当前年卡信息');
//                }
//                if ($cartCount < $resCards['book_num']) {
//                    return errorArray(sprintf('请至少选择%s本图书进行借阅', $resCards['book_num']));
//                }
//            }else{//判断是否是押金用户
//                if($userData['grade'] == 0){
//                    return errorArray('请先激活年卡后，借阅书籍');
//                }
//
//            }
//            return $this->success();
        }
        if($userData['grade'] > 0){//没有年卡 只有押金的用户
            if ($cartCount > ConstLib::DEPOSIT_MAX_BOOKS) {
                return $this->error(100, '您的借书数量已超过等级限制，您可以减少借阅本数，也可以选择办理年卡');
            }
            $cartNum = UserLevelModel::getByGrade($userData['grade'])['books'];
            if($cartCount < ceil($cartNum / 2)){
                return  errorArray(sprintf('请至少选择%s本图书进行借阅', ceil($cartNum / 2)));
            }
            return successArray();
        }
        return errorArray('请先办理年卡');
    }

    /**获取异常订单
     * @param $userId
     * @return array
     * @author Poison
     * @date 2021/5/28 10:17 上午
     */
   private function getAbnormalOrder($userId): array
    {
        //判断是否有未归还
        $resOrderGoods = OrderGoodsModel::getReturnGoodsByUserId($userId, 1);
        if (!$resOrderGoods) {
            return $this->success();
        }
        foreach ($resOrderGoods as $v) {
            if ($v['order_status'] < 4 && $v['order_status'] > -1) {//判断是否有未确认收货
                return $this->error(ConstCode::ORDER_UNRECEIVED, '您有未确认收货的订单，请先确认收货，并归还书籍后再进行新的借阅订单');
            }
        }

        //未申请还书
        $un_repay = OrderGoodsModel::getUnRepayByUserId($userId);
        if(!empty($un_repay)){
            return $this->error(ConstCode::ORDER_UNREPAY, '您有未归还的订单，请归还书籍后再进行新的借阅订单');
        }

         //新增判断
        $exceptionOrder = (new OrderModel())->where(['is_exception' => 1, 'user_id' => $userId])->select()->toArray();
        if(!empty($exceptionOrder)) {
            return $this->error(ConstCode::ORDER_UNHANDLED_EXCEPTION, '您有异常订单未处理，请处理异常订单后再进行新的借阅订单');
        }

        return $this->success(200);
    }

    /**判断地址是否可用
     * @param $address
     * @param $card_id
     * @return array
     * @author Poison
     * @date 2021/6/1 3:38 下午
     */
    protected function checkAddress($address, $card_id)
    {
        if($card_id == 2) {  //899年卡支持全国除西藏、新疆外京东免费借/还
            if (strstr($address['province'], '西藏') || strstr($address['province'], '新疆')) {
                return errorArray('抱歉，目前暂无法支持西藏、新疆地区配送，您可更换配送地址或联系客服处理', ConstCode::ALERT_CODE);
            }
        //其他年卡支持陕西省内除榆林外的免费京东快递借/还
        }elseif (!strstr($address['province'], '陕西') || (strstr($address['province'], '陕西') && strstr($address['city'], '榆林'))) {
            $card = CardsModel::getById($card_id);
            return errorArray(sprintf('抱歉，“%s”目前仅支持陕西省（除榆林）内地区配送，您可更换配送地址或联系客服处理', $card['name'] ?? '押金用户'), ConstCode::ALERT_CODE);
        }
        return $this->success();
    }


    /**
     * 获取用户未还异常订单书籍信息
     * @param int $user_id  用户ID
     * @return array
     * @author: yangliang
     * @date: 2021/6/9 17:03
     */
    public function getUnRepayExceptionOrder(int $user_id): array
    {
        $order_goods = OrderModel::getUnRepayExceptionOrder($user_id);
        if(!empty($order_goods)){
            foreach ($order_goods as &$v){
                $v['original_img'] = $this->getGoodsImg($v['original_img']);
            }
        }

        //是否显示到馆自取  0-不显示  1-显示
        $is_show_store = 0;
        $address = UserAddressModel::getAddress($user_id);
        if(!empty($address) && strstr($address['province'], '陕西')){
            $is_show_store = 1;
        }

        return $this->success(200, 'success', ['order_goods' => $order_goods, 'order_goods_count' => count($order_goods), 'is_show_store' => $is_show_store]);
    }


    /**
     * 异常订单详情
     * @param int $user_id  用户ID
     * @param int $order_id  订单ID
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author: yangliang
     * @date: 2021/6/16 11:18
     */
    public function exceptionOrderDetail(int $user_id, int $order_id): array
    {
        $order = OrderModel::getByOrderIdAndUserId($user_id, $order_id);
        if(empty($order) || $order['is_exception'] != 1){
            return $this->error(100, '订单信息异常');
        }

        //订单还书物流信息
        $return_logistic = ReturnGoodsModel::getWaybillByOrderId($order_id);
        if(!empty($return_logistic)){
            $order['waybill_id'] = $return_logistic['waybill_id'];
        }

        $resReturnGoods = ReturnGoodsModel::getReturnByOrderId($order_id);
        if($resReturnGoods){
            $order['is_reception'] = $resReturnGoods['is_reception'];
        }

        //订单书籍
        $order['order_goods'] = OrderGoodsModel::getOrderGoodsInfoByOrderId($order_id);
        if(!empty($order['order_goods'])){
            foreach ($order['order_goods'] as &$v){
                $v['original_img'] = $this->getGoodsImg($v['original_img'], 400, 400);

                $cat_name = CatModel::getByTypeAndGoodsId(0, $v['goods_id']);
                if (!empty($cat_name)) {
                    $v['cat_name'] = CatModel::getById(intval($cat_name['parent_id']))['name'];
                } else {
                    $v['cat_name'] = '';
                }
            }
        }

        //异常书籍
        $order['exception_goods'] = OrderGoodsModel::getExceptionGoods($order_id);
        if(!empty($order['exception_goods'])){
            foreach ($order['exception_goods'] as &$ev){
                $ev['original_img'] = $this->getGoodsImg($ev['original_img'], 400, 400);

                $cat_name = CatModel::getByTypeAndGoodsId(0, $ev['goods_id']);
                if (!empty($cat_name)) {
                    $ev['cat_name'] = CatModel::getById(intval($cat_name['parent_id']))['name'];
                } else {
                    $ev['cat_name'] = '';
                }
            }
        }

        //订单借书物流编号
        $borrow_waybill = OrderWaybillModel::getByOrderSnAndType($order['order_sn'], 1);
        $order['borrow_waybill_order_sn'] = $borrow_waybill['order_sn'] ?? '';
        //订单还书物流编号
        $return_waybill = ReturnGoodsModel::getWaybillByOrderId($order['order_id']);
        $order['return_waybill_order_sn'] = $return_waybill['order_sn'] ?? '';
        return $this->success(200, 'success', $order);
    }


    /**
     * 三方物流V2 部分用户不显示专业快递
     * 用户收货地址在门店服务范围内，不显示专业快递，只显示到馆自取
     * 用户收货地址在门店服务小区内，不显示专业快递，只显示到馆自取
     * @param int $userId  用户ID
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author: yangliang
     * @date: 2021/6/23 14:08
     */
    public function checkShowExpress(int $userId): array
    {
        //用户匹配到的门店
        $store = '';
        //是否显示专业快递  0-不显示  1-显示
        $is_show_express = 0;
        //用户收货地址匹配到的门店
        $coverage_community_store = '';
        //是否显示到馆自取  0-不显示  1-显示
        $is_show_store = 0;

        //验证用户是否有默认地址
        $user_address = UserAddressModel::getAddress($userId);
        if(empty($user_address) || !$user_address['lng'] || !$user_address['lat']){
            return $this->error(2047, '请选择默认地址');
        }

        //陕西省内用户显示到馆自取
        if(strstr($user_address['province'], '陕西')){
            $is_show_store = 1;
        }

        //获取用户收货地址是否可以使用专业快递
        $stores = StoresModel::getDistanceByShowAndType($user_address['lng'], $user_address['lat']);
        $isExpress = false;
        if(!empty($stores)){
            foreach ($stores as $sv){
                //用户收货地址在门店服务范围内
                if((float)$sv['coverage'] >= (float)$sv['distance'] * 1000){
                    $store = $sv;
                    break;
                }

                //门店服务小区匹配到用户收货地址
                $address = $user_address['province'].$user_address['city'].$user_address['district_name'].$user_address['address'];
                if(empty($coverage_community_store) && !empty($sv['coverage_community'])){
                    foreach (explode(',', $sv['coverage_community']) as $cv){
                        if(!$isExpress && strstr($address,'东方米兰国际城')){
                            $isExpress = true;
                        }
                        if(!empty($cv) && strstr($address, $cv)){
                            $coverage_community_store = $sv;
                        }
                    }
                }
            }
        }

        //用户不存在门店服务范围内并且用收货地址未匹配到门店服务小区，显示专业快递
        if(empty($store) && empty($coverage_community_store)){
            $is_show_express = 1;
        }else if(!empty($coverage_community_store)){
            $store = $coverage_community_store;
        }
        //陕西省外默认显示专业快递
        if($isExpress || !strstr($user_address['province'], '陕西')){
            $is_show_express = 1;
        }
        return $this->success(200, 'success', ['is_show_express' => $is_show_express, 'is_show_store' => $is_show_store, 'store' => $store]);
    }
}