<?php

/**
 * 阿里云OSS图片上传相关
 */
use think\facade\Config;
use OSS\Core\OssException;
use OSS\OssClient;

class OssUpload
{

    /**
     * @var
     */
    private static $_ins;

    /**
     * @var mixed
     */
    private $conf;

    private function __construct()
    {
        $this->conf = Config::get('ali_config.oss');
    }

    private function __clone()
    {
        // TODO: Implement __clone() method.
    }

    public static function getInstance():OssUpload
    {
        if(null === static::$_ins){
            static::$_ins = new static();
        }

        return static::$_ins;
    }


    /**
     * 阿里云OSS图片上传
     * @param string $oss_path  图片路径
     * @param string $content  图片内容
     * @return array
     * @author yangliang
     * @date 2021/2/18 14:05
     */
    public function upload(string $oss_path, string $content):array
    {
        try {
            $oss_client = new OssClient($this->conf['accessKeyId'], $this->conf['accessKeySecret'], $this->conf['endPoint']);
            $res = $oss_client->putObject($this->conf['bucket'], $oss_path, $content);
            return ['status' => 1, 'message' => '上传成功', 'data' => $res['info'], 'success' => true];
        }catch (OssException $e){
            return ['status' => 0, 'message' => '调用阿里云接口出错：'.$e->getMessage()];
        }
    }
}