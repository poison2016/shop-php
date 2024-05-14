<?php

namespace app\common\service;

use think\facade\Db;

class GoodsService extends ComService
{
    public function getList(){
        $data = Db::name('goods')->field('id,img,contract_name,contract_name_en,projected_revenue,yield,revenue_type,amount,term')->where($this->getWhere())->select()->toArray();
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
        $data = Db::name('goods')->where($this->getWhere())->where('id',$id)->find();
        return successArray($data);
    }

    public function getWhere(){
        return [
            'state'=>1,
        ];
    }

}