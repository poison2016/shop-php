<?php


namespace app\common\service;


use app\common\model\GoodsStockModel;
use app\common\model\OrderStockLogModel;
use app\common\model\StockallocationDetailModel;
use app\common\model\StockallocationModel;
use app\common\model\StoresModel;

class CirculationService extends ComService
{
    public function create(array $cirData)
    {
//        try {
        $orderSn = $cirData['order_sn'];
        $userInfo['user_id'] = $cirData['applyuserid'];
        $storeId = (int)$cirData['instoreid'];

        $store = StoresModel::getById($storeId);
        $cirData['allocation_type'] = !empty($store) ? $store['allocation_type'] : 1;  //调拨类型  1-自有调拨  2-快递调拨
        // 创建调拨单和对应商品
        $saDetail = $cirData['detail'];
        foreach ($saDetail as $key => $val) {
            $outStoreId = $cirData['outstoreid'] = $key;
            // 调拨单
            $saResult = (new StockallocationModel())->createStockAllocaction($cirData);
            if (!$saResult) {
                return $this->error(0, '调拨单添加失败');
            }
            foreach ($val as $g) {
                $goodsId = $goodsInfo['goods_id'] = (int)$g['goods_id'];
                $goodsNum = $goodsInfo['goods_num'] = $g['goods_num'];
                $goodsInfo['goods_sn'] = $g['goods_sn'];
                $goodsInfo['goods_name'] = $g['goods_name'];

                $salNum = (float)$g['sal_num'];// 调拨数量
                $sdData = [];
                $sdData['sa_id'] = $saResult;
                $sdData['goods_id'] = $goodsId;
                $sdData['goods_num'] = $goodsNum;// 申请调拨数量
                $sdData['confirm_num'] = $salNum;// 确认调拨数量
                $resStockallocationDetail = (new StockallocationDetailModel())->addDetail($sdData);
                if (!$resStockallocationDetail) {
                    return $this->error(0, '调拨单商品添加失败');
                }
                // 更新调出和调入库存
                $oldOutStoreStock = (new GoodsStockModel())->getStockByGoodsId($goodsId, $outStoreId);// 调出门店原现有库存
                $oldInStoreStock = (new GoodsStockModel())->getStockByGoodsId($goodsId, $storeId);// 调入门店原现有库存

                // 调出门店减少总库存,减少现有库存
                $outResult = (new GoodsStockModel())->DecDataStock($outStoreId, $salNum, $goodsId);
                if (!$outResult) {
                    return $this->error(10110, '调出门店库存更新失败');
                }
                // 记录调出门店库存变动日志
                $outStockInfo['old_stock'] = $oldOutStoreStock;
                $outStockInfo['new_stock'] = $oldOutStoreStock - $goodsNum;// 调出门店现有库存
                $outStockInfo['update_stock'] = -1;
                $outStockInfo['log_type'] = 1;// 调拨单
                $outStockInfo['beizhu'] = '用户下单(小程序),调拨';
                $outStockInfo['store_id'] = $outStoreId;
                $saSn = (new StockallocationModel())->where('id', $saResult)->value('sn');
                $outOslResult = (new OrderStockLogModel())->createOrderStockLogData((int)$outStoreId, $saSn, (array)$goodsInfo, (array)$userInfo, (array)$outStockInfo);
                if (!$outOslResult) {
                    return $this->error(0, '调出门店库存日志添加失败');
                }

                // 调入门店减少现有库存
                $result = (new GoodsStockModel())->DecDataStock($storeId, [['stock', (int)$salNum]], $goodsId);
                if (!$result) {
                    return $this->error(10110, '调入门店库存更新失败');
                }

                // 记录调入门店库存变动日志
                $inStockInfo['old_stock'] = $oldInStoreStock;
                $inStockInfo['new_stock'] = $oldInStoreStock - $goodsNum;// 调入门店现有库存
                $inStockInfo['update_stock'] = -1;
                $inStockInfo['beizhu'] = '用户下单(小程序),现有库存减少' . $salNum;
                $inStockInfo['store_id'] = $storeId;
                $inOslResult = (new OrderStockLogModel())->createOrderStockLogData($storeId, $orderSn, $goodsInfo, $userInfo, $inStockInfo);
                if (!$inOslResult) {
                    return $this->error(0, '调入门店库存日志添加失败');
                }
            }
        }
        return $this->success(200, '调拨单添加成功');
//        } catch (Exception $ex) {
//            Db::rollback();
//            return $this->error(0, '服务器异常:位置-> 调拨单 异常--> ' . $ex->getMessage());
//        }
    }
}