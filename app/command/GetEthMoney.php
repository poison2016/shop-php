<?php
declare (strict_types = 1);

namespace app\command;

use app\common\service\EthService;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use app\common\Tools\EthUsdtJson;
use BI\BigInteger;
use Elliptic\EC;
use GuzzleHttp\Client;
use kornrunner\Keccak;
use think\facade\Db;
use Web3\Web3;
use Web3\Contract;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3\Utils;
use Web3p\EthereumTx\Transaction;
use xtype\Ethereum\Client as EthereumClient;

class GetEthMoney extends Command
{

    protected function configure()
    {
        // 指令配置
        $this->setName('get_eth_money')
            ->setDescription('获取以太坊交易列表并充值');
    }

    protected function execute(Input $input, Output $output)
    {
        $data = Db::name('t_admin_address')->where('type',2)->select();
        foreach ($data as $item){
            $url = 'https://api.etherscan.io/api?module=account&action=tokentx&contractaddress=0xdAC17F958D2ee523a2206206994597C13D831ec7&address='.$item['address'].'&page=1&offset=50&startblock=0&endblock=27025780&sort=desc&apikey=I258Q362FE5J2YQN7RQF5XES8MZVN7D8KM';
            $ret = getCurlData($url);
            if(!$ret) return [];
            if($ret['status'] == 1){
                $result = array_reverse($ret['result']);
                foreach ($result as $v){
                    if(strtolower($v['to']) == strtolower($item['address'])){
                        var_dump($v['hash']);
                        $ret = Db::name('tz_user_address_log')->where(['address'=>$v['from'],'txid'=>$v['hash']])->find();
                        if(!$ret) continue;
                        if($ret['is_ok'] == 1){
                            echo '未查询到新的充值 已中断'.PHP_EOL;exit();
                        }
                        $money = $v['value']!= 0?bcdiv($v['value'], '1000000', 6):0;
                        Db::name('tz_user_address_log')->where('id',$ret['id'])->update(['is_ok'=>1,'money'=>(double)$money]);
                        $wallet = Db::name('tz_wallet')->where('user_id',$ret['user_id'])->find();
                        Db::name('tz_wallet')->where('user_id',$ret['user_id'])->update(['money'=>$wallet['money']+$money]);
                        echo "充值成功".PHP_EOL;
                    }
                }
            }
        }

    }
}
