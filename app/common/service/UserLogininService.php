<?php


namespace app\common\service;


use app\common\traits\CacheTrait;

class UserLogininService extends ComService
{
    use CacheTrait;

    /**
     * 向redis中添加数据
     * @param $data
     * @return array
     */
    public function addData($data)
    {
        $key = 'user_loginin_' . $data['userid'];
        $info = $this->getCache($key);
        if (false === $info) {
            $this->setCache($key, json_encode([$data], JSON_UNESCAPED_UNICODE));
        } else {
            $info = json_decode($info);
            $info[] = $data;
            $this->setCache($key, json_encode($info, JSON_UNESCAPED_UNICODE));
        }
        return $this->success();
    }
}