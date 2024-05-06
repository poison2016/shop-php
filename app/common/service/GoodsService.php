<?php

namespace app\common\service;

use think\facade\Db;

class GoodsService extends ComService
{
    public function getList(){
        $data = Db::name('shop_goods')->where($this->getWhere())->paginate(tp_page())->each(function ($item){

        });
        return successArray($data);
    }

    public function getGoodsInfo(int $id){
        $data = Db::name('shop_goods')->where($this->getWhere())->where('id',$id)->find();
        return successArray($data);
    }

    public function getWhere(){
        return [
            'is_show'=>1,
            'is_delete'=>0,
        ];
    }

}