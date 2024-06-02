<?php

namespace app\common\service;
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
            return successArray($processedTransactions);
        }else{
            return  [];
        }

    }

    public function payUsdt($from,$to,$privateKey,$amount,$userId){
        $infuraProjectId = '0b2cd0fcd60645829fc70e438f7fa505';
        $infuraUrl = "https://mainnet.infura.io/v3/$infuraProjectId";
        $web3 = new Web3(new HttpProvider(new HttpRequestManager($infuraUrl)));
        $usdtContractAddress = '0xdac17f958d2ee523a2206206994597c13d831ec7'; // USDT 合约地址
        $usdtDecimals = 6; // USDT 代币的小数位数


        $abi = json_decode(EthUsdtJson::getJson(), true);

// 检查方法是否存在


// 转账数量（例如，发送 100 USDT）
        $amountInWei = bcmul($amount, bcpow('10', $usdtDecimals));
// 构建交易数据
        $contract = new Contract($web3->getProvider(), json_decode($abi['result'], true));
        $transactionData = $contract->at($usdtContractAddress)->getData('transfer', $to, $amountInWei);
// 获取账户 nonce
        try {
        $web3->eth->getTransactionCount($from, 'pending', function ($err, $nonce) use ($web3, $from, $to, $usdtContractAddress, $transactionData, $privateKey) {
            if ($err !== null) {
                echo 'Error: ' . $err->getMessage();
                return;
            }
            // 创建交易对象
            // 确保 nonce 是整数
//            if ($nonce instanceof BigInteger) {
//                $nonce = hexdec($nonce->toString()); // 转换为十进制整数
//            }
//            var_dump($nonce);
//            $ass = json_decode(json_encode($nonce),true);
//            var_dump($ass);exit();
//            $num = json_decode(json_encode($ass),true)['num'];


            $nonceValue = hexdec($nonce->toString());
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
            $ec = new EC('secp256k1');
            $keccak = new Keccak(256);
            $hash = $keccak->hash(hex2bin($transaction['data']), 256);
            $privateKey = str_pad($privateKey, 64, '0', STR_PAD_LEFT);
            $signature = $ec->sign($hash, $privateKey, 'hex', ['canonical' => true]);
            $r = $signature->r->toString('hex');
            $s = $signature->s->toString('hex');
            $v = $signature->recoveryParam + 27;
            // 使用 PHP Web3 库签名交易

                $web3->eth->accounts->signTransaction($transaction, $privateKey, function ($err, $signedTx) use ($web3) {
                    if ($err !== null) {
                        return errorArray($err->getMessage());
                    }

                    // 发送签名的交易
                    $web3->eth->sendRawTransaction($signedTx, function ($err, $tx) {
                        if ($err !== null) {
                            return errorArray($err->getMessage());
                        }
                        Db::name('')->insert([
                            'user_id'=> $userId,
                            'address'=>$meAddress,
                            'txid'=>$result['txid'],
                            'money'=>$amount,
                            'create_time'=>time()
                        ]);
                        return successArray(['tx'=>$tx]);
                    });
                    return successArray('交易中');
                });

        });
        return successArray('交易中');
        }catch (\Error $exception){
            return errorArray('账户余额不足');
        }










    }


}