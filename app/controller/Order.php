<?php

namespace app\controller;

use app\BaseController;
use app\common\service\OrderService;
use app\Request;
use app\validate\FilterValid;
use think\App;
use think\Response;

class Order extends BaseController
{
    protected OrderService $orderService;
    public function __construct(App $app,OrderService $orderService)
    {
        parent::__construct($app);
        $this->orderService = $orderService;
    }

    public function create(Request $request){
        $params['goods_id'] = (int)input('goods_id','');
        $params['user_id'] = (int)$request->comUserId;
        $params['number'] = (int)input('number','');
        $rule = [
            'goods_id' => ['must', '', '商品不能为空'],
            'user_id' => ['must', '', '请登录'],
            'number' => ['must', '', '数量不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->orderService->createOrder($params));
    }


}