<?php

namespace app\controller;

use app\BaseController;
use app\common\service\AdminService;
use think\App;

class Admin extends BaseController
{
    protected AdminService $adminService;

    public function __construct(App $app,AdminService $adminService)
    {
        parent::__construct($app);
        $this->adminService = $adminService;
    }
    public function getGoodsList(){
        var_dump(111);
        return $this->requestData($this->adminService->getGoodsList());
    }
    public function getGoodsInfo(){
        return $this->requestData($this->adminService->getGoodsInfo(input('id')));
    }
    public function insertGoods(){
        return $this->requestData($this->adminService->insertGoods(input('post.')));
    }
    public function updateGoods(){
        return $this->requestData($this->adminService->updateGoods(input('post.')));
    }
    public function delGoods(){
        return $this->requestData($this->adminService->updateGoods(input('id')));
    }
    public function orderList(){
        return $this->requestData($this->adminService->orderList());
    }
    public function orderInfo(){
        return $this->requestData($this->adminService->orderInfo(input('id')));
    }
    public function delOrder(){
        return $this->requestData($this->adminService->delOrder(input('id')));
    }
    public function coinList(){
        return $this->requestData($this->adminService->coinList());
    }
    public function delCoin(){
        return $this->requestData($this->adminService->delCoin(input('id')));
    }

}