<?php


namespace app\common\service;

use app\common\model\OrderGoodsModel;
use app\common\model\ReturnGoodsModel;

class ReturnGoodsService extends ComService
{
    public function createReturnGoods(array $recIds, int $orderId, int $is_jd = 0)
    {
        $orderGoodsModel = new OrderGoodsModel();
        $returnGoodsModel = new ReturnGoodsModel();
        $returnGoods = [];
        $orderGoods = $orderGoodsModel->whereIn('rec_id', implode(',', $recIds))->select();
        foreach ($orderGoods as $key => $val) {
            $result = $returnGoodsModel->where('rec_id', $val['rec_id'])->find();
            // 如果有已申请只还不借并且未完成的还书信息,则删除
            if ($result && $val['is_repay'] == 3) {
                (new ReturnGoodsModel())->where('id', $result['id'])->delete();
            }
            $returnGoods[$key]['order_id'] = $orderId;
            $returnGoods[$key]['rec_id'] = $val['rec_id'];
            $returnGoods[$key]['user_id'] = $val['user_id'];
            $returnGoods[$key]['goods_id'] = $val['goods_id'];
            $returnGoods[$key]['goods_name'] = $val['goods_name'];
            $returnGoods[$key]['goods_sn'] = $val['goods_sn'];
            $returnGoods[$key]['goods_sum'] = $val['goods_num'];
            $returnGoods[$key]['addtime'] = time();
            $returnGoods[$key]['is_jd'] = $is_jd;
            // 更新订单商品状态
            $orderGoodsUpdate = $orderGoodsModel->where('rec_id', $val['rec_id'])->update(['is_repay' => 1, 'is_end' => 1]);
            if (!$orderGoodsUpdate) {
                return $this->error(10130, '订单商品状态更新失败');
            }
        }
        if ($returnGoods) {
            $returnGoodsIds = $returnGoodsModel->insertReturnGoods($returnGoods);
            if (count($returnGoods) != $returnGoodsIds) {
                return $this->error(10110, '还书信息添加失败');
            }
        }
        return $this->error(200, '还书信息添加成功');
    }

}