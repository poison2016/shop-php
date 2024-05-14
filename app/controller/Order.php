<?php

namespace app\controller;

use app\BaseController;
use app\common\model\GoodsModel;
use app\common\model\UserModel;
use app\common\service\GoodsService;
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
        $params['user_id'] = $request->comUserId;
        $params['user_id'] = env('server_env')?'80b4dfe19a6586731a4906b548559d29':$request->comUserId;
        $params['number'] = (int)input('number','');
        $params['pay_password'] = input('pay_password','');
        $rule = [
            'goods_id' => ['must', '', '商品不能为空'],
            'user_id' => ['must', '', '请登录'],
            'number' => ['must', '', '数量不能为空'],
            'pay_password' => ['must', '', '支付密码不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->orderService->createOrder($params));
    }

    public function list(Request $request){
        $params['user_id'] = (int)$request->comUserId;
        $rule = [
            'user_id' => ['must', '', '请登录'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->orderService->getList($params));
    }

    public function info(Request $request){
        $params['user_id'] = (int)$request->comUserId;
        $params['order_id'] = (int)input('order_id',0);
        $rule = [
            'user_id' => ['must', '', '请登录'],
            'order_id' => ['must', '', '订单id不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->orderService->getInfo($params['user_id'],$params['order_id']));
    }


}