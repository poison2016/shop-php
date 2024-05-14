<?php

namespace app\common\service;

use app\common\model\GoodsModel;
use think\facade\Db;

class GoodsService extends ComService
{
    protected GoodsModel $goodsModel;

    public function __construct(GoodsModel $goodsModel)
    {
        $this->goodsModel = $goodsModel;
    }

    public function getList(){
        $data = $this->goodsModel->field('id,img,contract_name,contract_name_en,projected_revenue,yield,revenue_type,amount,term')->where($this->getWhere())->select()->toArray();
        foreach ($data as $k=> $v){
            if($v['revenue_type'] == 1){
                $data[$k]['term'].='小时';
            }else{
                $data[$k]['term'].='天';
            }
        }
        return successArray($data);
    }

    public function getGoodsInfo(int $id){
        $data = $this->goodsModel->where($this->getWhere())->where('id',$id)->find();
        if($data['revenue_type'] == 1){
            $data['term'].='小时';
            $data['revenue_type_name'] = '小时';
        }else{
            $data['term'].='天';
            $data['revenue_type_name'] = '天';
        }
        $data['banner_item'] = explode(',',$data['banner']);

        return successArray($data);
    }

    public function getWhere(){
        return [
            'state'=>['=',0],
        ];
    }

}