<?php

namespace app\common\service;

use app\common\model\GoodsModel;
use app\common\model\OrderBonusModel;
use app\common\model\OrderModel;
use app\common\model\UserAddressLogModel;
use app\common\model\UserAddressModel;
use think\facade\Db;
use think\facade\Filesystem;
use think\facade\Request;

class AdminService extends ComService
{
    protected GoodsModel $goodsModel;
    protected OrderModel $orderModel;
    protected OrderBonusModel $orderBonusModel;
    protected UserAddressModel $userAddressModel;
    protected UserAddressLogModel $userAddressLogModel;
    public function __construct(GoodsModel $goodsModel,OrderModel $orderModel,OrderBonusModel $orderBonusModel
    ,UserAddressModel $userAddressModel,UserAddressLogModel $userAddressLogModel)
    {
        $this->goodsModel = $goodsModel;
        $this->orderModel = $orderModel;
        $this->orderBonusModel = $orderBonusModel;
        $this->userAddressModel = $userAddressModel;
        $this->userAddressLogModel = $userAddressLogModel;
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

    public function getAdminAddressList(){
        return successArray(Db::name('t_admin_address')->paginate(tp_page()));
    }
    public function getAdminAddressInfo($id){
        $data = Db::name('t_admin_address')->where('id',$id)->find();
        return successArray($data);
    }

    public function insertAdminAddress($params){
        $params['create_time'] = time();
        $params['type'] = (int)$params['type'];
        $ret = Db::name('t_admin_address')->insert($params);
        if(!$ret) return errorArray('添加失败');
        return successArray(['id'=>$ret]);
    }

    public function updateAdminAddress($params){
        $id = $params['id'];
        $params['create_time'] =time();
        $ret = Db::name('t_admin_address')->where('id',$id)->update($params);
        if(!$ret) return errorArray('修改失败');
        return successArray(['id'=>$ret]);
    }

    public function delAdminAddress($id){
        $ret = Db::name('t_admin_address')->where('id',$id)->delete();
        if(!$ret) return errorArray('删除失败');
        return successArray(['id'=>$id]);
    }

    public function orderList(){
        $data =  $this->orderModel->alias('o')->field('o.*,g.contract_name,g.img,g.yield,g.revenue_type,u.user_name')
            ->join('t_goods g','g.id = o.contract_id','LEFT')
            ->join('tz_user u','u.user_id = o.user_id','LEFT')
            ->paginate(tp_page(15))->each(function ($item){
                $item['status_str'] = $item['status']?'已完成':'未完成';
                return $item;
            });
        return successArray($data);
    }
    public function orderInfo($id){
        $data = $this->orderModel->alias('o')
            ->field('o.*,g.contract_name,g.img,g.yield,g.revenue_type')
            ->join('t_goods g','g.id = o.contract_id','LEFT')
            ->where('o.id',$id)
            ->find();
        $data['status_str'] = $data['status']?'是':'否';
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

    public function uploadImg(){
        $file = Request::file('image');
        if (!$file) {
            return errorArray('没有上传文件');
        }
        // 上传文件到指定目录
        $savename = Filesystem::disk('public')->putFile('uploads', $file);
        if (!$savename) {
            return errorArray('文件上传失败');
        }
        // 获取完整URL
        $domain = env('update_img');
        $url = $domain . '/storage/' . $savename;
        return successArray(['url'=>$url]);
    }

}