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

    public function uploadImg(){
// 获取表单上传文件
        $file = Request::file('image');

        if (!$file) {
            return json(['error' => '没有上传文件'], 400);
        }

        // 验证文件类型和大小
        $validate = [
            'size' => 20097152, // 2MB
            'ext' => 'jpg,png,gif'
        ];

        // 检查文件是否符合验证规则
        $result = $this->validate(['file' => $file], ['file' => $validate]);
        if (true !== $result) {
            return json(['error' => $result], 100);
        }

        // 上传文件到指定目录
        $savename = Filesystem::disk('public')->putFile('uploads', $file);

        if (!$savename) {
            return json(['error' => '文件上传失败'], 100);
        }

        // 获取完整URL
        $domain = Request::domain();
        $url = $domain . '/storage/' . $savename;

        return json(['url' => $url], 200);
    }

}