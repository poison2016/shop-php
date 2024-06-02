<?php

namespace app\controller;

use app\BaseController;
use app\common\service\AdminService;
use think\App;
use think\facade\Filesystem;
use think\facade\Request;

class Admin extends BaseController
{
    protected AdminService $adminService;

    public function __construct(App $app,AdminService $adminService)
    {
        parent::__construct($app);
        $this->adminService = $adminService;
    }
    public function getGoodsList(){
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
        return $this->requestData($this->adminService->delGoods(input('id')));
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

    public function getAdminAddressList(){
        return $this->requestData($this->adminService->getAdminAddressList());
    }
    public function getAdminAddressInfo(){
        return $this->requestData($this->adminService->getAdminAddressInfo(input('id')));
    }
    public function insertAdminAddress(){
        return $this->requestData($this->adminService->insertAdminAddress(input('post.')));
    }
    public function updateAdminAddress(){
        return $this->requestData($this->adminService->updateAdminAddress(input('post.')));
    }
    public function delAdminAddress(){
        return $this->requestData($this->adminService->delAdminAddress(input('id')));
    }


    public function uploadImg(){
        return $this->requestData($this->adminService->uploadImg());
    }

}