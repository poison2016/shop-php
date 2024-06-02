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
        $url = 'https://api.etherscan.io/api?module=account&action=tokentx&contractaddress=0xdAC17F958D2ee523a2206206994597C13D831ec7&address='.$address.'&page=1&offset=50&startblock=0&endblock=27025780&sort=asc&apikey=I258Q362FE5J2YQN7RQF5XES8MZVN7D8KM';
        $ret = getCurlData($url);
        if(!$ret) return [];
        if($ret['status'] == 1){
            $processedTransactions = [];
            foreach ($ret['result'] as $v){
                if(strtolower($v['to']) == strtolower($address)){
                    $processedTransactions[] = [
                        'address' => $v['from'],
                        'time' => date('Y-m-d H:i:s',$v['timeStamp']),
                        'amount' =>  $v['value']!= 0?bcdiv($v['value'], '1000000', 6):0,
                        'amount_type' => 1,
                        'type'=>$v['tokenSymbol'],
                    ];
                } elseif (strtolower($v['from']) == strtolower($address)) {
                    // 转入交易
                    $processedTransactions[] = [
                        'address' => $v['to'],
                        'time' => date('Y-m-d H:i:s',$v['timeStamp']),
                        'amount' => $v['value']!= 0?bcdiv($v['value'], '1000000', 6):0,
                        'amount_type' => 0,
                        'type'=>$v['tokenSymbol'],
                    ];
                }
            }
            return successArray($processedTransactions);
        }else{
            return  [];
        }
    }
}
