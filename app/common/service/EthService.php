<?php

namespace app\common\service;
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

        $web3 = new Web3(new HttpProvider(new HttpRequestManager('http://localhost:8545')));

        $usdtContractAddress = '0xdac17f958d2ee523a2206206994597c13d831ec7'; // USDT 合约地址
        $usdtDecimals = 6; // USDT 代币的小数位数
// 转账数量（例如，发送 100 USDT）
        $amountInWei = bcmul($amount, bcpow('10', $usdtDecimals));

// 构建交易数据
        $contract = new Contract($web3->getProvider(), [], file_get_contents('path_to_abi.json')); // 从 ABI 文件加载合约
        $transactionData = $contract->getData('transfer', $to, $amountInWei);

// 获取账户 nonce
        $nonce = $web3->eth->getTransactionCount($from, 'pending')->val();

// 创建交易对象
        $transaction = [
            'nonce' => '0x' . dechex($nonce),
            'from' => $from,
            'to' => $usdtContractAddress,
            'value' => '0x0',
            'gas' => '0x186a0', // gas 限制
            'gasPrice' => '0x12a05f200', // gas 价格
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
        $signedTransaction = $web3->eth->accounts->signTransaction($transaction, $privateKey, $v, $r, $s);

// 发送签名的交易
        $web3->eth->sendRawTransaction($signedTransaction, function ($err, $tx) {
            if ($err !== null) {
                echo 'Error: ' . $err->getMessage();
                return;
            }
            echo 'Transaction Hash: ' . $tx;
        });











    }


}