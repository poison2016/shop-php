<?php

namespace app\controller;

use app\BaseController;
use app\common\service\GoodsService;
use app\validate\FilterValid;
use think\App;
use think\facade\Db;
use think\Response;

class Goods extends BaseController
{
    private GoodsService $goodsService;
    public function __construct(App $app,GoodsService $goodsService)
    {
        parent::__construct($app);
        $this->goodsService = $goodsService;
    }


    public function list(){
        return $this->requestData($this->goodsService->getList());
    }

    public function info(Response $request){
        $params['id'] = (int)input('id','');
        $rule = [
            'id' => ['must', '', '商品不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->goodsService->getGoodsInfo($params['id']));
    }

}