<?php


namespace app\common\service;


use app\BaseController;
use app\common\Tools\ALiYunGreen;

class TestService extends ComService
{

    protected $aLiYunGreen;

    public function __construct()
    {
        $this->aLiYunGreen = new ALiYunGreen();
    }

    public function textScan(string $text)
    {
        $tasks = [];
        $tasks[] = [
            "dataId" => time() . rand(10000, 99999),
            "content" => $text
        ];

//        $tasks[] = [
//            "dataId" => time() . rand(10000, 99999),
//            "content" => $text . 'aaaaa'
//        ];
        $res = $this->aLiYunGreen->scanCheck($tasks);
        return $this->success(200, '成功', $res);
    }
}