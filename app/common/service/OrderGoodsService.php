<?php


namespace app\common\service;


use app\common\model\CartModel;
use app\common\model\GoodsModel;
use app\common\model\GoodsReadModel;
use app\common\model\GoodsStockModel;
use app\common\model\JdOrderGoodsModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\ReturnGoodsModel;
use app\common\model\StockLackModel;
use app\common\model\UserModel;
use think\facade\Db;

class OrderGoodsService extends ComService
{
    /**
     * 处理订单中的数书籍数据
     * @param array $orderData
     * @param int $userId
     * @param array $userCardData
     * @param $orderGoods
     * @param $orderId
     * @param $jd_v2_array
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     *
     * 可能性有哪些呢？
     * ①全部是自有书籍$orderGoods
     * ②全部是JD书籍，$jd_v2_array，此时$orderGoods为空（此时会把，生成的订单删掉）
     * ③混合类型
     */
    public function createData(array $orderData, int $userId, array $userCardData, &$orderGoods, &$orderId, &$jd_v2_array)
    {
        $orderGoods = [];
        $jd_v2_array = [];
        $orderId = $orderData['order_id'];

        //初始化
        $goodsModel = new GoodsModel();
        $orderModel = new OrderModel();

        //获取购物车选中商品
        $cartGoods = (new CartModel())->where(['user_id' => $userId, 'selected' => 1])->select()->toArray();
        if (!$cartGoods) {
            return $this->error(100, '请选择购物车中书籍后下单');
        }
        $thirdPartyOrder = ThirdPartyOrderService::checkUserOrder($userId);
        //由于会出现拆单 这个时候 order_goods表不会在这个写入数据
        foreach ($cartGoods as $key => $val) {
            $goodsData = $goodsModel->field('jd_sku,is_jd,is_buy')->where('goods_sn', $val['goods_sn'])->findOrEmpty()->toArray();
            //判断是否是购买书籍
            if ($goodsData['is_buy'] == 1) {
                (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                return $this->error(2020, '书籍<<' . $val['goods_name'] . '>>库存已不足,请借阅其他书籍', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
            }
            // 判断只有年卡会员才能借来源为JD的书籍
            if (!$userCardData) {
                if ($goodsData['is_jd'] == 1) {
                    return $this->error(3001, '很遗憾！《 ' . $val['goods_name'] . ' 》书籍仅供年卡用户借阅，您可以办理年卡后借阅。');
                }
            }

            // 判断已添加购物车书籍是否可借
            $isBorrowedRes = (new CartService())->isBorrowed($val['goods_id'], $userId, true, 1);
            if ($isBorrowedRes['code'] == 20003 || $isBorrowedRes['code'] == 10161) {
                if (!$thirdPartyOrder) {
                    return $this->error($isBorrowedRes['code'], $isBorrowedRes['message']);
                }
                $jd_v2_array[] = $val['goods_id'];
                continue;
            } elseif ($isBorrowedRes['code'] != 200) {
                return $this->error($isBorrowedRes['code'], $isBorrowedRes['message']);
            }

            // 开启仓储调拨，门店 2020年7月29日 09:24:18
            if ($orderData['shipping_code'] == 1 || $orderData['shipping_code'] == 2) {
                // 判断库存是否足够
                // 总现有库存
                $totalStock = (new CartService())->getOneTotalStock($val['goods_id'],$orderData['store_id']);
                if ($totalStock <= config('self_config.safe_stock')) {
                    if (config('self_config.is_jd') == 1) {
                        if (!$userCardData) {
                            (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                            return $this->error(2020, '书籍<<' . $val['goods_name'] . '>>库存已不足,请借阅其他书籍', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                        } else {
                            $jd_v2_array[] = $val['goods_id'];
                            continue;// 记录完成 跳出本次循环
                        }
                    } else {
                        //记录无货流水
                        StockLackModel::create(['user_id' => $userId, 'goods_id' => $val['goods_id'], 'create_time' => time()]);
                        (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                        return $this->error(2020, '书籍<<' . $val['goods_name'] . '>>库存已不足,请借阅其他书籍', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                    }

                }
            } else {
                // 单独配送，只用仓储库存，仓储中心现有库存
                $stock = (new GoodsStockModel())->getValueStock(0, ['goods_id' => $val['goods_id']], 'stock');
                if ($stock <= config('self_config.safe_stock')) {
                    if (config('self_config.is_jd') == 1) {
                        if ($thirdPartyOrder) {
                            $jd_v2_array[] = $val['goods_id'];
                            continue;// 记录完成 跳出本次循环
                        }
                        if (!$userCardData) {
                            (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                            return $this->error(2020, '书籍<<' . $val['goods_name'] . '>>库存已不足,请借阅其他书籍', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                        } else {
                            // 专业快递需要判断是不是京东的单 如果是京东的单 可以继续走下去 如果不是 提示用户库存不足
                            if ($goodsData['jd_sku'] == 0) {
                                (new UserLackService())->beforeAdd($userId, $val['goods_id'], 1);
                                return $this->error(2020, '书籍<<' . $val['goods_name'] . '>>库存已不足,请借阅其他书籍', ['goods_id' => $val['goods_id'], 'goods_name' => $val['goods_name']]);
                            }
                        }
                    }

                }
            }
            $orderGoods[$key]['order_id'] = $orderId;
            $orderGoods[$key]['user_id'] = $userId;
            $orderGoods[$key]['goods_id'] = $val['goods_id'];
            $orderGoods[$key]['goods_sn'] = $val['goods_sn'];
            $orderGoods[$key]['goods_name'] = $val['goods_name'];
            $orderGoods[$key]['goods_num'] = $val['goods_num'];
        }
        //2020-9-23 新增如果没有商品 删除该订单
        if (!$orderGoods) {
            $resOrderDel = $orderModel->where('order_id', $orderId)->delete();
            if (!$resOrderDel) {
                return $this->error(0, '订单删除失败');
            }
            $orderId = 0;
        }
        return $this->success(200);
    }

    /**
     * JD订单商品数据的创建
     * @param array $orderData
     * @param int $userId
     * @param array $jd_v2_array
     * @param int $storeId
     * @param $orderGoods
     * @param $third
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function jdOrderGoods(array $orderData, int $userId, array $jd_v2_array, int $storeId, &$orderGoods, &$third)
    {
        $goodsModel = new GoodsModel();
        $orderId = $orderData['order_id'];
        $orderGoods = [];

        $whereCartGoods['user_id'] = $userId;
        $whereCartGoods['selected'] = 1;
        if (count($jd_v2_array) > 1) {
            $cartGoods = (new CartModel())->whereIn('goods_id', implode(',', $jd_v2_array))->where($whereCartGoods)->select()->toArray();
        } else {
            $whereCartGoods['goods_id'] = $jd_v2_array[0];
            $cartGoods = (new CartModel())->where($whereCartGoods)->select()->toArray();
        }
        if (!$cartGoods) {
            return $this->error(0, '请选择购物车商品');
        }

        // 封装订单商品数据
        $jdGoodsData = [];
        $thirdPartyOrder = ThirdPartyOrderService::checkUserOrder($userId);
        $thirdPartyData = [];
        $third = [];
        foreach ($cartGoods as $key => $val) {
            $temporaryData['order_id'] = $orderGoods[$key]['order_id'] = $orderId;
            $temporaryData['user_id'] = $orderGoods[$key]['user_id'] = $userId;
            $temporaryData['goods_id'] = $orderGoods[$key]['goods_id'] = $val['goods_id'];
            $temporaryData['goods_sn'] = $orderGoods[$key]['goods_sn'] = $val['goods_sn'];
            $temporaryData['goods_name'] = $orderGoods[$key]['goods_name'] = $val['goods_name'];
            $temporaryData['goods_num'] = $orderGoods[$key]['goods_num'] = $val['goods_num'];
            $temporaryData['is_neg_stock'] = $orderGoods[$key]['is_neg_stock'] = 1;
            $temporaryData['is_jd'] = $orderGoods[$key]['is_jd'] = 1;
            $temporaryData['order_sn'] = $orderData['order_sn'];
            // 判断已添加购物车书籍是否可借
            $isBorrowedRes = (new CartService())->isBorrowed($val['goods_id'], $userId, true, 1);
            if ($isBorrowedRes['code'] == 20003 || $isBorrowedRes['code'] == 10161) {
                if (!$thirdPartyOrder) {
                    return $this->error($isBorrowedRes['code'], $isBorrowedRes['message']);
                }
                $thirdPartyData[] = $temporaryData;
                $third[] = $val['goods_id'];
                continue;
            } elseif ($isBorrowedRes['code'] != 200) {
                return $this->error($isBorrowedRes['code'], $isBorrowedRes['message']);
            }
            $goodsOneData = $goodsModel->field('jd_sku,goods_type')->where('goods_sn', $val['goods_sn'])->find();
            if ($goodsOneData['jd_sku'] > 0) {
                $jdGoodsData[$key]['skuId'] = $goodsOneData['jd_sku'];
                $jdGoodsData[$key]['num'] = 1;
                $resJdCreateData = (new JdOrderGoodsModel())->createJdOrderGoods($userId, $orderId, $orderData['order_sn'], $storeId, $goodsOneData['jd_sku'], 1);
                if (!$resJdCreateData) {
                    return $this->error(0, '写入京东采购失败');
                }
            } else {
                if (!$thirdPartyOrder) {
                    return $this->error(3020, '该书籍<<' . $val['goods_name'] . '>> 暂无库存。');
                }
                $thirdPartyData[] = $temporaryData;
                $third[] = $val['goods_id'];
                continue;
            }
        }
        if ($thirdPartyData) {//如果有 则进行第三方库写入
            $resCreate = (new ThirdPartyOrderService())->create($thirdPartyData, $userId, $orderId);
            if ($resCreate['code'] != 200) {

                return $this->error(0, '写入记录失败');
            }
        }
        $resOrderGoods = (new OrderGoodsModel())->createOrderGoods($orderGoods);
        if (!$resOrderGoods) {
            return $this->error(0, '写入京东记录失败');
        }

        $resOrderGoodsRead = $this->orderGoodsRead($orderGoods, $userId);
        if ($resOrderGoodsRead['code'] != 200) {
            return $resOrderGoodsRead;
        }
        return $this->success();
    }

    /**
     * 更新阅读数量以及删除购物车中已下单的书籍
     * @param array $orderGoods
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderGoodsRead(array $orderGoods, int $userId)
    {
        foreach ($orderGoods as $v) {
            // 删除购物车已选中商品
            $cartDelete = (new CartModel())->where(['goods_id' => $v['goods_id'], 'selected' => 1, 'user_id' => $userId])->delete();
            if (!$cartDelete) {
                return $this->error(0, '购物车商品删除失败');
            }
            // 更新商品阅读量
            // 判断是否有次商品阅读量信息
            $resGoodsReadDate = (new GoodsReadModel())->where('goods_id', $v['goods_id'])->find();
            if (!$resGoodsReadDate) {//如果没有 创建
                (new GoodsReadModel())->insert(['goods_id' => $v['goods_id']]);
            }
            $goodsReadResult = (new GoodsReadModel())->setIncGoodsRead($v['goods_id'], $v['goods_num']);
            if (!$goodsReadResult) {
                return $this->error(0, '商品阅读量更新失败');
            }
        }
        return $this->success();
    }


    /**
     * 借阅中的书籍
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/18 15:27
     */
    public function getBorrowGoods(int $user_id)
    {
        $res = OrderGoodsModel::getBorrowGoodsByUserId($user_id);
        if (!empty($res)) {
            foreach ($res as $k => $v) {
                $res[$k] = (new GoodsService())->getGoodsAttrs($v, $user_id);
            }
        }

        return $this->success(200, '获取成功', $res);
    }


    /**
     * 只还不借
     * @param int $user_id
     * @param int $rec_id
     * @return array
     * @author yangliang
     * @date 2021/2/22 14:53
     */
    public function repayGoods(int $user_id, int $rec_id)
    {
        $user = (new UserModel())->getOneUser($user_id);
        if (empty($user)) {
            return $this->error(100, '用户不存在');
        }

        $order_goods = OrderGoodsModel::getByRecId($rec_id);
        if (empty($order_goods)) {
            return $this->error(100, '还书信息不存在');
        }

        $return_goods = ReturnGoodsModel::getReturnGoodsByRecId($rec_id);
        if (!empty($return_goods)) {
            return $this->error(100, '您的确认归还申请已提交');
        }

        Db::startTrans();
        try {
            $return_goods_data = [
                'rec_id' => $rec_id,
                'user_id' => $user_id,
                'order_id' => 0, // 归还列表中未确认
                'goods_id' => $order_goods['goods_id'],
                'goods_name' => $order_goods['goods_name'],
                'goods_sn' => $order_goods['goods_sn'],
                'goods_sum' => $order_goods['goods_num'],
                'addtime' => time()
            ];

            $return_goods_res = ReturnGoodsModel::create($return_goods_data);
            if (!$return_goods_res) {
                throw new \Exception('还书信息添加失败');
            }

            // 更新订单商品表状态is_repay=3只还不借
            OrderGoodsModel::where('rec_id', $rec_id)->update(['is_repay' => 3, 'is_end' => 1]);
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '确认归还成功', ['rec_id' => $rec_id]);
    }

    public function checkData(array $orderData, int $userId, array $userCardData, &$orderGoods, &$orderId, &$noStoreGoodsArray, &$selectList,$storeId = 0)
    {
        $orderGoods = [];
        //初始化
        $goodsModel = new GoodsModel();
        //获取购物车选中商品
        $cartGoods = (new CartModel())->where('user_id', $userId)->whereIn('goods_id', $selectList)->select()->toArray();
        if (!$cartGoods) {
            return $this->error(100, '请选择购物车中书籍后下单');
        }

        //由于会出现拆单 这个时候 order_goods表不会在这个写入数据
        foreach ($cartGoods as $key => $val) {
            $goodsData = $goodsModel->field('jd_sku,is_jd,is_buy')->where('goods_sn', $val['goods_sn'])->findOrEmpty()->toArray();
            if(!$goodsData){
                return errorArray('查询失败');
            }
            //判断是否是购买书籍
            if ($goodsData['is_buy'] == 1) {
                $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                self::delSelectData($selectList,$val['goods_id']);
                continue;
            }
            // 判断只有年卡会员才能借来源为JD的书籍
            if (!$userCardData) {
                if ($goodsData['is_jd'] == 1) {
                    $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                    self::delSelectData($selectList,$val['goods_id']);
                    continue;
                }
            }

            // 判断已添加购物车书籍是否可借
            $isBorrowedRes = (new CartService())->isBorrowed($val['goods_id'], $userId, true, 1);
            if ($isBorrowedRes['code'] == 20003 || $isBorrowedRes['code'] == 10161) {
                $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                self::delSelectData($selectList,$val['goods_id']);
                continue;
            } elseif ($isBorrowedRes['code'] != 200) {
                $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                self::delSelectData($selectList,$val['goods_id']);
                continue;
            }

            // 开启仓储调拨，门店 2020年7月29日 09:24:18
            if ($orderData['shipping_code'] == 1 || $orderData['shipping_code'] == 2) {
                // 判断库存是否足够
                // 总现有库存
                $totalStock = (new CartService())->getOneTotalStock($val['goods_id'],$storeId);
                if ($totalStock <= config('self_config.safe_stock')) {
                    if (config('self_config.is_jd') == 1) {
                        $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                        self::delSelectData($selectList,$val['goods_id']);
                        continue;// 记录完成 跳出本次循环
                    } else {
                        $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                        self::delSelectData($selectList,$val['goods_id']);
                        continue;// 记录完成 跳出本次循环
                    }

                }
            } else {
                $isOrder = ToolsService::searchOrder($userId);
                // 单独配送，只用仓储库存，仓储中心现有库存
                $totalStock = (new GoodsStockModel())->getValueStock(0, ['goods_id' => $val['goods_id']], 'stock');
                if ($totalStock <= config('self_config.safe_stock')) {
                    if (!$isOrder && $goodsData['jd_sku'] > 0) {
                        $resPostJdCount = (new CartService())->postJDCount($goodsData['jd_sku'], $val['goods_name'], $userId);
                        if ($resPostJdCount['code'] != 200) {
                            $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                            self::delSelectData($selectList,$val['goods_id']);
                            continue;// 记录完成 跳出本次循环
                        }
                    }else{
                        $noStoreGoodsArray[] = ['goods_id' => $val['goods_id'], 'stock' => 0];
                        self::delSelectData($selectList,$val['goods_id']);
                        continue;// 记录完成 跳出本次循环
                    }
                }
            }
            $orderGoods[$key]['order_id'] = $orderId;
            $orderGoods[$key]['user_id'] = $userId;
            $orderGoods[$key]['goods_id'] = $val['goods_id'];
            $orderGoods[$key]['goods_sn'] = $val['goods_sn'];
            $orderGoods[$key]['goods_name'] = $val['goods_name'];
            $orderGoods[$key]['goods_num'] = $val['goods_num'];
        }
        return $this->success();
    }

    protected function delSelectData(&$selectList, $goodsId)
    {
        foreach ($selectList as $k => $v) {
            if ($v == $goodsId) {
                unset($selectList[$k]);
                $selectList = array_merge($selectList);
            }
        }
    }
}