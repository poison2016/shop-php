<?php


namespace app\common\service;

use app\common\model\GoodsModel;
use app\common\model\GoodsStockModel;
use app\common\model\JdOrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\OrderStockLogModel;
use app\common\model\PickUpModel;
use app\common\model\StoresModel;

class StoreService extends ComService
{
    /**
     *
     * @param $val
     * @param int $userId
     * @param int $orderId
     * @param int $storeId
     * @param array $userCardData
     * @param string $orderSn
     * @param int $shipping_code
     * @param int $pay_status
     * @param array $orderData
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 张镇镇
     * @date date
     */
    public function upStore($val, int $userId, int $orderId, int $storeId, array $userCardData, string $orderSn, int $shipping_code, int $pay_status, array $orderData)
    {
        $goodsStore = new GoodsStockModel();
        $orderStockLog = new OrderStockLogModel();
        $val['shipping_code'] = $shipping_code;
        $val['pay_status'] = $pay_status;
        $userInfo['user_id'] = $userId;
        $is_neg_stock = 0;
        $goodsId = $val['goods_id'];
        $goodsNum = $val['goods_num'];
        $goodsInfo['goods_id'] = $goodsId;
        $goodsInfo['goods_sn'] = $val['goods_sn'];
        $goodsInfo['goods_name'] = $val['goods_name'];
        $goodsInfo['goods_num'] = $goodsNum;
        $stockInfo['update_stock'] = -1;
        if ($storeId > 0) {
            $stockInfo['beizhu'] = '用户分店下单(小程序),现有库存减少' . $goodsNum;
        } else {
            $stockInfo['beizhu'] = '用户下单(小程序),现有库存减少' . $goodsNum;
        }
        $goodsId = $goodsInfo['goods_id'];
        $goodsSn = $goodsInfo['goods_sn'];
        $goodsNum = (int)$goodsInfo['goods_num'];
        $goodsName = $goodsInfo['goods_name'];
        $cirData = [];// 调拨单信息
        // 如果商品不存在 返回的是 null
        $stock = $goodsStore->getValueStock($storeId, ['goods_id' => $goodsId], 'stock');
        // 各大门店可以获取，所有库存
        if ($storeId > 0) {
            if (null !== $stock) {
                $oldStoreStock = $stock;//门店现有库存
                if ($oldStoreStock >= $goodsNum) {// 门店库存足够,则更新库存
                    $resultDecDataStock = $goodsStore->DecDataStock($storeId, $goodsNum, $goodsId);//降低库存
                    if (!$resultDecDataStock) {
                        return $this->error(0, '库存更新失败');
                    }
                    // 记录库存变动日志
                    $stockInfo['old_stock'] = $oldStoreStock;
                    $stockInfo['new_stock'] = (int)$oldStoreStock - (int)$goodsNum;//新库存
                    $resultCreateOrderStockLogData = $orderStockLog->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $stockInfo);
                    if (!$resultCreateOrderStockLogData) {
                        return $this->error(0, '订单库存日志添加失败');
                    }
                } else {//库存不够
                    $outStoreId = $this->getOutStoreId($goodsId, $goodsNum, $storeId);
                    if ($outStoreId === false) {
                        if (!$userCardData) {
                            (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                            return $this->error(2020, '书籍<<' . $goodsName . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                        } else {
                            return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                        }
                    }

                    $cirData['outstoreid'] = $outStoreId;
                    $cirData['detail'] = [
                        'goods_id' => $goodsId,
                        'goods_sn' => $goodsSn,
                        'goods_num' => $goodsNum,
                        'goods_name' => $goodsName,
                        'sal_num' => $goodsNum
                    ];
                }
            } else {//不存在该商品库存信息,则创建库存信息并创建调拨单
                $result = (new GoodsStockModel())->createDataStock($storeId, ['goods_id' => $goodsId]);
                if (!$result) {
                    return $this->error(0, '门店商品库存生成失败');
                }
                // 获取调出门店ID
                $outStoreId = $this->getOutStoreId($goodsId, $goodsNum, $storeId);
                if ($outStoreId === false) {
                    if (!$userCardData) {
                        (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                        return $this->error(2020, '书籍<<' . $goodsName . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                    } else {
                        return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                    }
                }
                $cirData['outstoreid'] = $outStoreId;
                $cirData['detail'] = [
                    'goods_id' => $goodsId,
                    'goods_sn' => $goodsSn,
                    'goods_num' => $goodsNum,
                    'goods_name' => $goodsName,
                    'sal_num' => $goodsNum
                ];
            }
        } else {//集中配送或者专业快递订单
            $oldStock = $stock;
            if ($oldStock < $goodsNum) {
                // 如果是单独配送，考虑到时效问题，不下调拨单
                // 只有几种配送，才送
                if ($val['shipping_code'] && $val['shipping_code'] == 2) {//等于2的时候是集中配送
                    // 2021年3月25日 10:58:52 收到需求，需要暂停仓储调拨调入
                    $canAllocationIn = config('self_config.store_center_can_allocation_in'); // 仓储中心是否可以调入
                    $pickUpInfo = (new PickUpModel())->where('pickup_id', $orderData['pickup_id'])->find();
                    if ($pickUpInfo && $canAllocationIn) {
                        // 判断调拨时间，是否来的及
                        $timeCheckFlg = $this->timeCheck($pickUpInfo['week']);
                        if (!$timeCheckFlg) {
                            if (!$userCardData) {
                                (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                                return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                            } else {
                                return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                            }
                        }
                        // 判断库存是否充足
                        $outStoreId = $this->getOutStoreIdByCenter($goodsId, $goodsNum);
                        if ($outStoreId === false) {
                            if (!$userCardData) {
                                (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                                return $this->error(2020, '书籍<<' . $goodsName . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                            } else {
                                return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                            }
                        }
                        $cirData['outstoreid'] = $outStoreId;
                        $cirData['detail'] = [
                            'goods_id' => $goodsId,
                            'goods_sn' => $goodsSn,
                            'goods_num' => $goodsNum,
                            'goods_name' => $goodsName,
                            'sal_num' => $goodsNum
                        ];
                    } else {
                        if (!$userCardData) {
                            (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                            return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                        } else {
                            return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                        }
                    }

                } else {
                    // 专业快递
                    if (!$userCardData) {
                        (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                        return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                    } else {
                        $isOrder = ToolsService::searchOrder($userId);
                        if($isOrder){
                            if (config('self_config.is_jd') == 0) {
                                (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                                return $this->error(20003, '书籍<<' . $goodsInfo['goods_name'] . '>> 库存不足');
                            }
                        }
                        if(!config('self_config.is_end_jd')){//关闭首单京东
                            (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                            return $this->error(20003, '书籍<<' . $goodsInfo['goods_name'] . '>> 库存不足');
                        }
                        //专业快递 库存不足的时候 进行处理 直接降库存 写入库存日志 标识书不齐
                        //判断该书是否是京东仓储的书  是 写入记录表
                        $checkUserOrder = ThirdPartyOrderService::checkUserOrder($userId);
                        if ($checkUserOrder) {//如果不是首单用户 进入该判断
                            return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                        }
                        $jd_sku_num = (new GoodsModel())->where('goods_id', $goodsId)->value('jd_sku');
                        if ($jd_sku_num == 0) {
                            (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                            return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                        }
                        $resultGoodsStock = (new GoodsStockModel())->DecDataStock($storeId, $goodsNum, $goodsId);
                        if (!$resultGoodsStock) {
                            return $this->error(0, '订单商品库存数量修改失败');
                        }
                        // 记录库存变动日志
                        $stockInfo['old_stock'] = $oldStock;
                        $stockInfo['new_stock'] = (int)$oldStock - (int)$goodsNum;
                        $stockInfo['beizhu'] = 'JD-' . $stockInfo['beizhu'];
                        $resultCreateOrderStockLog = $orderStockLog->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $stockInfo);
                        if (!$resultCreateOrderStockLog) {
                            return $this->error(0, '订单库存日志添加失败');
                        }
                        //在这里进行京东采购表写入
                        $resJdOrderGoods = (new JdOrderGoodsModel())->createJdOrderGoods($userId, $orderId, $orderSn, $storeId, $jd_sku_num, $val['pay_status']);
                        if (!$resJdOrderGoods) {
                            return $this->error(0, '写入京东采购单失败');
                        }
                        //修改订单为书不齐状态
                        (new OrderModel())->updateOrder($orderId);
                        //修改orderGoods为书不齐状态
                        $is_neg_stock = $goodsId;
                    }
                }
            } else {
                // 仓储中心库存减少现有库存
                $resultGoodsStock = (new GoodsStockModel())->DecDataStock($storeId, $goodsNum, $goodsId);
                if (!$resultGoodsStock) {
                    return $this->error(0, '订单商品库存数量修改失败');
                }
                $stockInfo['old_stock'] = $oldStock;
                $stockInfo['new_stock'] = (int)$oldStock - (int)$goodsNum;
                $resultGoodsStockOne = (new OrderStockLogModel())->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $stockInfo);
                if (!$resultGoodsStockOne) {
                    return $this->error(0, '订单库存日志添加失败');
                }
            }
        }
        $resultData = array(
            'cirData' => $cirData ?? [],
            'is_neg_stock' => $is_neg_stock ?? 0,
        );
        return $this->success(200, '成功', $resultData);
    }

    public function jdUpdateStore(int $userId, int $orderId, int $storeId, array $orderGoods, array $userCardData, array $jd_v2_array, array $orderData, string $orderSn, array $third)
    {
        $goodsStore = new GoodsStockModel();
        $orderStockLog = new OrderStockLogModel();
        $userInfo['user_id'] = $userId;
        // 调拨数据
        $cirDatas['applyuserid'] = $userId;
        $cirDatas['confirmuserid'] = $userId;
        $cirDatas['order_id'] = $orderId;
        $cirDatas['is_buy'] = 0;// 租售方式:租借
        $cirDatas['types'] = 1;// 调拨方式:自动
        $cirDatas['instoreid'] = $storeId;// 调入仓库ID
        $cirDatas['detail'] = [];
        $is_book_true = false;
        foreach ($orderGoods as $key => $val) {
            $goodsId = $val['goods_id'];
            $goodsNum = $val['goods_num'];
            $goodsInfo['goods_id'] = $goodsId;
            $goodsInfo['goods_sn'] = $val['goods_sn'];
            $goodsInfo['goods_name'] = $val['goods_name'];
            $goodsInfo['goods_num'] = $goodsNum;
            $stockInfo['update_stock'] = -1;
            if ($storeId > 0) {
                $title = "JD";
                if (in_array($val['goods_id'], $third)) {
                    $title = "中转仓";
                }
                $stockInfo['beizhu'] = $title . '-用户下单(小程序),现有库存减少' . $goodsNum;
            } else {
                $stockInfo['beizhu'] = 'JD-用户分店下单(小程序),现有库存减少' . $goodsNum;
            }
            $goodsId = $goodsInfo['goods_id'];
            $goodsSn = $goodsInfo['goods_sn'];
            $goodsNum = $goodsInfo['goods_num'];
            $goodsName = $goodsInfo['goods_name'];
            $cirData = [];// 调拨单信息
            $stock = $goodsStore->getValueStock($storeId, ['goods_id' => $goodsId], 'stock');
            if ($storeId > 0) {
                if ($stock === null) {
                    $result = (new GoodsStockModel())->createDataStock($storeId, ['goods_id' => $goodsId]);
                    if (!$result) {
                        return $this->error(0, '门店商品库存生成失败');
                    }
                }
                //写到了这里
                $oldStoreStock = $stock ?? 0;//门店现有库存
                $resultDecDataStock = $goodsStore->DecDataStock($storeId, $goodsNum, $goodsId);//降低库存
                if (!$resultDecDataStock) {
                    return $this->error(0, '门店库存更新失败');
                }
                // 记录库存变动日志
                $stockInfo['old_stock'] = $oldStoreStock;
                $stockInfo['new_stock'] = $oldStoreStock - $goodsNum;//新库存
                $resultCreateOrderStockLogData = $orderStockLog->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $stockInfo);
                if (!$resultCreateOrderStockLogData) {
                    return $this->error(0, '订单库存日志添加失败');
                }
            } else {//集中配送或者专业快递订单
                $oldStock = $stock;
                if ($oldStock < $goodsNum) {
                    // 如果是单独配送，考虑到时效问题，不下调拨单
                    // 只有几种配送，才送
                    if ($orderData['shipping_code'] && $orderData['shipping_code'] == 2) {//等于2的时候是集中配送
                        $resultDecDataStock = $goodsStore->DecDataStock($storeId, $goodsNum, $goodsId);//降低库存
                        if (!$resultDecDataStock) {
                            return $this->error(0, '门店库存更新失败');
                        }
                        $pickUpInfo = (new PickUpModel())->where('pickup_id', $orderData['pickup_id'])->find();
                        // 记录库存变动日志
                        $oldStoreStock = $stock ?? 0;//门店现有库存
                        $stockInfo['old_stock'] = $oldStoreStock;
                        $stockInfo['new_stock'] = $oldStoreStock - $goodsNum;//新库存
                        $resultCreateOrderStockLogData = $orderStockLog->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $stockInfo);
                        if (!$resultCreateOrderStockLogData) {
                            return $this->error(0, '订单库存日志添加失败');
                        }
                    } else {
                        if ($orderData['shipping_code'] == 3) {
                            $resultDecDataStock = $goodsStore->DecDataStock($storeId, $goodsNum, $goodsId);//降低库存
                            if (!$resultDecDataStock) {
                                return $this->error(0, '门店库存更新失败');
                            }
                            // 记录库存变动日志
                            $oldStoreStock = $stock ?? 0;//门店现有库存
                            $stockInfo['old_stock'] = $oldStoreStock;
                            $stockInfo['new_stock'] = $oldStoreStock - $goodsNum;//新库存
                            $resultCreateOrderStockLogData = $orderStockLog->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $stockInfo);
                            if (!$resultCreateOrderStockLogData) {
                                return $this->error(0, '订单库存日志添加失败');
                            }
                            //更新库存结束
                            $is_book_true = true;
                        }
                    }
                } else {
                    // 仓储中心库存减少现有库存
                    $resultGoodsStock = (new GoodsStockModel())->DecDataStock($storeId, $goodsNum, $goodsId);
                    if (!$resultGoodsStock) {
                        return $this->error(0, '订单商品库存数量修改失败');
                    }
                    $stockInfo['old_stock'] = $oldStock;
                    $stockInfo['new_stock'] = (int)$oldStock - (int)$goodsNum;
                    $resultGoodsStockOne = (new OrderStockLogModel())->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $stockInfo);
                    if (!$resultGoodsStockOne) {
                        return $this->error(0, '订单库存日志添加失败');
                    }
                }
            }
            $updateStockResult['data'] = $cirData;
            if ($updateStockResult['data']) {
                $cirGoods = $updateStockResult['data'];
                $cirDatas['detail'][$cirGoods['outstoreid']][] = $cirGoods['detail'];
            }
        }
        $resultData = array(
            'jd_v2_array' => $jd_v2_array,
            'is_book_true' => $is_book_true,
            'orderGoods' => $orderGoods,
            'cirData' => $cirData ?? [],
        );
        return $this->success(200, '成功', $resultData);
    }

    /**
     * 获取调拨门店id
     * @param $goodsId
     * @param $goodsNum
     * @param $storeId
     * @return bool|int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOutStoreId($goodsId, $goodsNum, $storeId)
    {
       // $storeModel = new StoresModel();
       // $goodsStockModel = new GoodsStockModel();
//        $storeInfo = $storeModel->getStoresByStoreId($storeId);
//        // 获取调入门店附近所有门店
//        $nearStores = $storeModel->getNearStores($storeInfo['longitude'], $storeInfo['latitude']);
//        // 收集满足条件的库存
//        $maxStockId = 0;
//        $maxStockNum = 0;
//        foreach ($nearStores as $near) {
//            $nearStock = $goodsStockModel->getValueStock($near['id'], ['goods_id' => $goodsId], 'stock');
//            $nearStock = $nearStock === null ? 0 : $nearStock;
//            if ($nearStock >= $goodsNum) {
//                // 按照副本量调拨
//                if ($nearStock > $maxStockNum) {
//                    $maxStockId = $near['id'];
//                    $maxStockNum = $nearStock;
//                }
//            }
//        }
//        if ($maxStockId != 0) {
//            return $maxStockId;
//        }
        if(config('self_config.is_open_store') == 0){
            return false;
        }
        $stock = (new GoodsStockModel())->getValueStock(0, ['goods_id' => $goodsId], 'stock');
        $stock = $stock === null ? 0 : $stock;
        if ($stock >= $goodsNum) {
            return 0;
        }

        return false;
    }

    /**
     * 根据传进来的周几，判断是否满足安全调拨条件
     * @param $week
     * @return bool
     */
    public function timeCheck($week)
    {
        // 时间判断：配送点配送时间 - 下单时间 >=  安全调拨时间
        $nowWeek = date("w"); // 注意0是星期日 // 我们自己的7又是周日，以此类推
        $nowWeek = $nowWeek == 0 ? 7 : $nowWeek;
        // 如果配送时间已过，说明还要一个礼拜的时间
        if ($nowWeek < $week) {
            // 如果当前星期几 < 配送点 那么判断差值即可
            // 剩余的时间转化为小时 和 安全调拨时间进行比对
            $day = $week - $nowWeek - 1; // -1 的缘故是当天不算
        } else {
            // >= ,既本周剩余时间 + 下周的富裕时间（ 7 - $nowWeek + $week ）
            $day = 7 - $nowWeek + $week - 1;
        }
        //2021-3-19 增加判断如果是前一天  超过了 当天的7点零5分 可以下单 但是属于下周的单
        $timeWeek = strtotime(date('Y-m-d 07:06:00'));
        if ($day == 0 && time() > $timeWeek) {
            return true;
        }
        if ($day <= config('self_config.allocation_time')) {
            return false;
        }

        return true;
    }


    /**
     * 获取仓储中心，可以调拨的门店信息
     * @param $goodsId
     * @param $goodsNum
     * @param int $storeId
     * @return bool|int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOutStoreIdByCenter($goodsId, $goodsNum, $storeId = 4)
    {
        $storeModel = new StoresModel();
        $goodsStockModel = new GoodsStockModel();
        $storeInfo = $storeModel->getStoresByStoreId($storeId);
        // 获取调入门店附近所有门店
        $nearStores = $storeModel->getNearStores($storeInfo['longitude'], $storeInfo['latitude']);
        // 收集满足条件的库存
        $maxStockId = 0;
        $maxStockNum = 0;
        foreach ($nearStores as $near) {
            $nearStock = $goodsStockModel->getValueStock($near['id'], ['goods_id' => $goodsId], 'stock');
            $nearStock = $nearStock === null ? 0 : $nearStock;
            if (((int)$nearStock - (int)config('self_config.allocation_safe_stock')) >= $goodsNum) {
                // 按照副本量调拨
                if ($nearStock > $maxStockNum) {
                    $maxStockId = $near['id'];
                    $maxStockNum = $nearStock;
                }
            }
        }
        if ($maxStockId != 0) {
            return $maxStockId;
        }
        return false;
    }
    public function checkStore($val, int $userId, int $storeId, array $userCardData, int $shipping_code, int $pay_status,$pickupId)
    {
        $goodsStore = new GoodsStockModel();
        $orderStockLog = new OrderStockLogModel();
        $val['shipping_code'] = $shipping_code;
        $val['pay_status'] = $pay_status;
        $userInfo['user_id'] = $userId;
        $is_neg_stock = 0;
        $goodsId = $val['goods_id'];
        $goodsNum = $val['goods_num'];
        $goodsInfo['goods_id'] = $goodsId;
        $goodsInfo['goods_sn'] = $val['goods_sn'];
        $goodsInfo['goods_name'] = $val['goods_name'];
        $goodsInfo['goods_num'] = $goodsNum;
        $stockInfo['update_stock'] = -1;
        if ($storeId > 0) {
            $stockInfo['beizhu'] = '用户分店下单(小程序),现有库存减少' . $goodsNum;
        } else {
            $stockInfo['beizhu'] = '用户下单(小程序),现有库存减少' . $goodsNum;
        }
        $goodsId = $goodsInfo['goods_id'];
        $goodsNum = (int)$goodsInfo['goods_num'];
        $goodsName = $goodsInfo['goods_name'];
        // 如果商品不存在 返回的是 null
        $stock = $goodsStore->getValueStock($storeId, ['goods_id' => $goodsId], 'stock');
        // 各大门店可以获取，所有库存
        if ($storeId > 0) {
            if (null !== $stock) {
                $oldStoreStock = $stock;//门店现有库存
                if ($oldStoreStock >= $goodsNum) {// 门店库存足够,则更新库存

                } else {//库存不够
                    $outStoreId = $this->getOutStoreId($goodsId, $goodsNum, $storeId);
                    if ($outStoreId === false) {
                            return $this->error(2020, '书籍<<' . $goodsName . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                    }
                }
            } else {//不存在该商品库存信息,则创建库存信息并创建调拨单
                // 获取调出门店ID
                $outStoreId = $this->getOutStoreId($goodsId, $goodsNum, $storeId);
                if ($outStoreId === false) {
                    if (!$userCardData) {
                        return $this->error(2020, '书籍<<' . $goodsName . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                    } else {
                        return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                    }
                }
            }
        } else {//集中配送或者专业快递订单
            $oldStock = $stock;
            if ($oldStock < $goodsNum) {
                // 如果是单独配送，考虑到时效问题，不下调拨单
                // 只有几种配送，才送
                if ($val['shipping_code'] && $val['shipping_code'] == 2) {//等于2的时候是集中配送
                    // 2021年3月25日 10:58:52 收到需求，需要暂停仓储调拨调入
                    $canAllocationIn = config('self_config.store_center_can_allocation_in'); // 仓储中心是否可以调入
                    $pickUpInfo = (new PickUpModel())->where('pickup_id', $pickupId)->find();
                    if ($pickUpInfo && $canAllocationIn) {
                        // 判断调拨时间，是否来的及
                        $timeCheckFlg = $this->timeCheck($pickUpInfo['week']);
                        if (!$timeCheckFlg) {
                            if (!$userCardData) {
                                return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                            } else {
                                return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                            }
                        }
                        // 判断库存是否充足
                        $outStoreId = $this->getOutStoreIdByCenter($goodsId, $goodsNum);
                        if ($outStoreId === false) {
                            if (!$userCardData) {
                                return $this->error(2020, '书籍<<' . $goodsName . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                            } else {
                                return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                            }
                        }
                    } else {
                        if (!$userCardData) {
                            return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                        } else {
                            return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                        }
                    }

                } else {
                    // 专业快递
                    if (!$userCardData) {
                        return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                    } else {
                        //专业快递 库存不足的时候 进行处理 直接降库存 写入库存日志 标识书不齐
                        //判断该书是否是京东仓储的书  是 写入记录表
                        $checkUserOrder = ThirdPartyOrderService::checkUserOrder($userId);
                        if ($checkUserOrder) {//如果不是首单用户 进入该判断
                            return $this->error(101, '', ['jd_v2_array' => $val['goods_id']]);
                        }
                        $jd_sku_num = (new GoodsModel())->where('goods_id', $goodsId)->value('jd_sku');
                        if ($jd_sku_num == 0) {
                            return $this->error(2020, '书籍<<' . $goodsInfo['goods_name'] . '>>库存不足', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                        }
                        $isOrder = ToolsService::searchOrder($userId);
                        if ($isOrder) {
                            return $this->error(20003, '书籍<<' . $goodsInfo['goods_name'] . '>> 库存不足');
                        }
                    }
                }
            }
        }
        return $this->success(200, '成功', []);
    }

}