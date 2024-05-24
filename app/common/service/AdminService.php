<?php

namespace app\common\service;

use app\common\model\GoodsModel;
use app\common\model\OrderBonusModel;
use app\common\model\OrderModel;
use app\common\model\UserAddressModel;
use think\facade\Db;

class AdminService extends ComService
{
    protected GoodsModel $goodsModel;
    protected OrderModel $orderModel;
    protected OrderBonusModel $orderBonusModel;
    protected UserAddressModel $userAddressModel;
    public function __construct(GoodsModel $goodsModel,OrderModel $orderModel,OrderBonusModel $orderBonusModel
    ,UserAddressModel $userAddressModel)
    {
        $this->goodsModel = $goodsModel;
        $this->orderModel = $orderModel;
        $this->orderBonusModel = $orderBonusModel;
        $this->userAddressModel = $userAddressModel;
    }

    public function getGoodsList(){
        return successArray($this->goodsModel->paginate(tp_page()));
    }
    public function getGoodsInfo($id){
        $data = $this->goodsModel->where('id',$id)->find();
        return successArray($data);
    }

    public function insertGoods($params){
        $ret = $this->goodsModel->insert($params);
        if(!$ret) return errorArray('添加失败');
        return successArray(['id'=>$ret]);
    }

    public function updateGoods($params){
        $id = $params['id'];
        $ret = $this->goodsModel->where('id',$id)->update($params);
        if(!$ret) return errorArray('修改失败');
        return successArray(['id'=>$ret]);
    }

    public function delGoods($id){
        $ret = $this->goodsModel->where('id',$id)->delete();
        if(!$ret) return errorArray('删除失败');
        return successArray(['id'=>$id]);
    }

    public function orderList(){
        $data =  $this->orderModel->alias('o')->field('o.*,g.contract_name,g.img,g.yield,g.revenue_type')
            ->join('t_goods g','g.id = o.contract_id','LEFT')
            ->paginate(tp_page(15));
        return successArray($data);
    }
    public function orderInfo($id){
        $data = $this->orderModel->alias('o')
            ->field('o.*,g.contract_name,g.img,g.yield,g.revenue_type')
            ->join('t_goods g','g.id = o.contract_id','LEFT')
            ->where('o.id',$id)
            ->find();
            $data['order_list'] = $this->orderBonusModel->where('order_id',$id)->select()->toArray();
            return successArray($data);

    }

    public function delOrder($id){
        $ret = $this->orderModel->where('id',$id)->delete();
        if(!$ret) return errorArray('删除失败');
        return successArray(['id'=>$id]);
    }

    public function coinList(){
        $data = $this->userAddressModel
            ->paginate(tp_page(15))->each(function (&$item){
                $item['username'] = Db::name('tz_user')->where('user_id',$item['user_id'])->value('user_name');
            });
        return successArray($data);
    }

    public function delCoin($id){
        $ret = $this->userAddressModel->where('id',$id)->delete();
        if(!$ret) return errorArray('删除失败');
        return successArray(['id'=>$id]);
    }

}