<?php

namespace app\common\service;
use app\common\Tools\EthUsdtJson;
use Elliptic\EC;
use GuzzleHttp\Client;
use kornrunner\Keccak;
use think\facade\Db;
use Web3\Web3;
use Web3\Contract;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use phpseclib\Math\BigInteger;
use xtype\Ethereum\Utils;
use Web3p\EthereumTx\Transaction;
use xtype\Ethereum\Client as EthereumClient;
class EthService extends ComService
{
    protected $apiUrl;
    protected $apiKey;
    protected $contractAddress;
//    public function __construct()
//    {
//        / // Etherscan API URL
//        $this->apiUrl = 'https://api.etherscan.io/api';
//
//        // 你的 Etherscan API 密钥
//        $this->apiKey = 'I258Q362FE5J2YQN7RQF5XES8MZVN7D8KM';
//
//        // USDT 合约地址
//        $this->contractAddress = '0xdac17f958d2ee523a2206206994597c13d831ec7';
//    }
    public function getEthMoney($address){
        $url = 'https://api.etherscan.io/api?module=account&action=balance&address='.$address.'&tag=latest&apikey=I258Q362FE5J2YQN7RQF5XES8MZVN7D8KM';
        $ret = getCurlData($url);
        if(!$ret || $ret['status'] != 1) return 0;
        return $ret['result'] != 0?bcdiv($ret['result'], '1000000000000000000', 18):0;
    }
    public function getUsdtMoney($address){
        $url = 'https://api.etherscan.io/api?module=account&action=tokenbalance&contractaddress=0xdAC17F958D2ee523a2206206994597C13D831ec7&address='.$address.'&tag=latest&apikey=I258Q362FE5J2YQN7RQF5XES8MZVN7D8KM';
        $ret = getCurlData($url);
        if(!$ret || $ret['status'] != 1) return 0;
        return $ret['result'] != 0?bcdiv($ret['result'], '1000000', 6):0;
    }

    public function getOrderList($address){
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
            return successArray(['list'=>$processedTransactions]);
        }else{
            return  [];
        }

    }

    public function payUsdt($from,$to,$privateKey,$amount,$userId){
        $client = new EthereumClient([
            'base_uri' => 'https://mainnet.infura.io/v3/0b2cd0fcd60645829fc70e438f7fa505',
            'timeout' => 10,
        ]);

        $client->addPrivateKeys([$privateKey]);

        // 2. 组装交易
        $trans = [
            "from" => $from,
            "to" => $to,
            "value" => Utils::ethToWei($amount, true),
            "data" => '0x',
        ];
        // 设定Gas，nonce，gasprice
        $trans['gas'] = dechex(hexdec($client->eth_estimateGas($trans)) * 1.0);
        $trans['gasPrice'] = $client->eth_gasPrice();
        $trans['nonce'] = $client->eth_getTransactionCount('0xdac17f958d2ee523a2206206994597c13d831ec7', 'pending');
        // 3. 发送您的交易
        // 如果需要服务器，也可以使用eth_sendTransaction
        $txid = $client->sendTransaction($trans);

        //4.得到交易hash
        var_dump($txid);

        //查询到账情况
        var_dump($client->eth_getTransactionReceipt($txid));
    }

    public function payUsdt1($from,$to,$privateKey,$amount,$userId){

        $infuraProjectId = '0b2cd0fcd60645829fc70e438f7fa505';
        $infuraUrl = "https://mainnet.infura.io/v3/$infuraProjectId";
        $web3 = new Web3(new HttpProvider(new HttpRequestManager($infuraUrl)));
        $usdtContractAddress = '0xdac17f958d2ee523a2206206994597c13d831ec7'; // USDT 合约地址
        $usdtDecimals = 6; // USDT 代币的小数位数

        $abi = json_decode(EthUsdtJson::getJson(), true);

// 转账数量（例如，发送 100 USDT）
        $amountInWei = bcmul($amount, bcpow('10', $usdtDecimals));
// 构建交易数据
        $contract = new Contract($web3->getProvider(), json_decode($abi['result'], true));
        $transactionData = $contract->at($usdtContractAddress)->getData('transfer', $to, new BigInteger($amountInWei));
        var_dump('准备发起');

// 获取账户 nonce
        try {
            $web3->eth->getTransactionCount($from, 'pending', function ($err, $nonce) use ($web3, $from, $to, $usdtContractAddress, $transactionData, $privateKey, $userId, $amount) {
                if ($err !== null) {
                    echo 'Error: ' . $err->getMessage();
                    return;
                }
                var_dump('准备就绪');

                // 将 nonce 转换为整数
                $nonceValue = $nonce->toString();

                $transaction = [
                    'nonce' => '0x' . dechex($nonceValue),
                    'from' => $from,
                    'to' => $usdtContractAddress,
                    'value' => '0x0',
                    'gas' => '0x5208', // gas 限制
                    'gasPrice' => '0x4a817c800', // gas 价格
                    'data' => $transactionData,
                ];

                // 签名交易
                $web3->eth->accounts->signTransaction($transaction, $privateKey, function ($err, $signedTx) use ($web3, $userId, $from, $amount) {
                    if ($err !== null) {
                        echo 'Error: ' . $err->getMessage();
                        return;
                    }
                    var_dump('签名通过');

                    // 发送签名的交易
                    $web3->eth->sendRawTransaction($signedTx, function ($err, $tx) use ($userId, $from, $amount) {
                        if ($err !== null) {
                            echo 'Error: ' . $err->getMessage();
                            return;
                        }
                        var_dump('获取到交易的');
                        var_dump($tx);
                        Db::name('tz_user_address_log')->insert([
                            'user_id' => $userId,
                            'address' => $from,
                            'txid' => $tx,
                            'money' => $amount,
                            'create_time' => time()
                        ]);
                        return successArray(['tx' => $tx]);
                    });
                });
            });
        } catch (\Error $exception) {
            var_dump($exception->getMessage());
            return errorArray('账户余额不足');
        }










    }


}