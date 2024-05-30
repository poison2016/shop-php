<?php
declare (strict_types = 1);

namespace app\command;

use IEXBase\TronAPI\Provider\HttpProvider;
use IEXBase\TronAPI\Tron;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;

class GetTrxMoney extends Command
{
    protected $tron;
    protected function configure()
    {
        // 指令配置
        $this->setName('get_trx_money')
            ->setDescription('获取指定地址 链上的交易记录');
    }

    protected function execute(Input $input, Output $output)
    {
        $config = Config::get('tron');
        $fullNode = new HttpProvider($config['full_node']);
        $solidityNode = new HttpProvider($config['solidity_node']);
        $eventServer = new HttpProvider($config['event_server']);
        $this->tron = new Tron($fullNode, $solidityNode, $eventServer);
        $this->tron->setAddress($address);
        $contract = $this->tron->contract('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
        $transactions = $contract->getTransactions($address);
    }
}
