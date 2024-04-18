<?php


namespace app\common\exception;


use think\Exception;

class ApiException extends Exception
{
    protected $argv = array();

    /**
     * 初始化异常
     *
     * @param string $msg 异常信息描述
     * @param array $argv 异常上下文信息
     */
    public function __construct($msg, $argv = null)
    {
        parent::__construct($msg);
        if (isset($argv['code'])) {
            $this->code = $argv['code'];
            unset($argv['code']);
        }
        $this->argv = $argv;
    }

    /**
     * 获取上下文信息
     *
     * @return array
     */
    public function getArgv()
    {
        return $this->argv;
    }
}