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
use think\facade\Db;

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
        $data = Db::name('t_admin_address')->where('type',1)->select();
        foreach ($data as $v){
            $this->tron->setAddress($v['address']);
            $contract = $this->tron->contract('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
            $transactions = $contract->getTransactions($v['address'],200);
            if(empty($transactions['data'])) continue;
            foreach ($transactions['data'] as $item){
                if($item['to'] == $v['address']){//接受币
                    $ret = Db::name('tz_user_address_log')->where(['address'=>$item['from'],'txid'=>$item['transaction_id']])->find();
                    if(!$ret) continue;
                    if($ret['is_ok'] == 1){
                        echo '未查询到新的充值 已中断'.PHP_EOL;exit();
                    }
                    var_dump($item);
                    var_dump($v['value']);exit();
                    $money = $v['value']!= 0?bcdiv($v['value'], '1000000', 6):0;
                    Db::name('tz_user_address_log')->where('id',$ret['id'])->update(['is_ok'=>1,'money'=>(double)$money]);
                    $wallet = Db::name('tz_wallet')->where('user_id',$ret['user_id'])->find();
                    Db::name('tz_wallet')->where('user_id',$ret['user_id'])->update(['money'=>$wallet['money']+$money]);
                    //将余额充值到数据库
                }
            }
        }

    }
}
