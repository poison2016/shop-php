<?php

namespace app\common\service;

use IEXBase\TronAPI\Provider\HttpProvider;
use IEXBase\TronAPI\Tron;
use think\facade\Config;
use think\facade\Log;

class TrxService extends ComService
{
    protected $tron;

    /**配置
     * @throws \IEXBase\TronAPI\Exception\TronException
     */
    public function __construct()
    {
        $config = Config::get('tron');
        $fullNode = new HttpProvider($config['full_node']);
        $solidityNode = new HttpProvider($config['solidity_node']);
        $eventServer = new HttpProvider($config['event_server']);
        $this->tron = new Tron($fullNode, $solidityNode, $eventServer);

    }

    /**交易
     * @param string $toAddress 发送给谁
     * @param double $amount 发送金额
     * @param string $prvKey 支付秘钥
     * @return array|\think\response\Json
     */
    public function transfer($toAddress, $amount,$prvKey)
    {
        $this->tron->setPrivateKey($prvKey);
        try {
            $transaction = $this->tron->getTransactionBuilder()->sendTrx($toAddress, $amount);
            $signedTransaction = $this->tron->signTransaction($transaction);
            $response = $this->tron->sendRawTransaction($signedTransaction);
            return successArray(['ret'=>$response]);
        } catch (\Exception $e) {
            Log::error('TRX Transfer Error: ' . $e->getMessage());
            return errorArray($e->getMessage());
        }
    }

    /**获取交易记录
     * @param string $address 钱包地址
     * @param int $limit 多少列 默认为50
     * @param int $start 开始 默认为0
     * @return array
     */
    public function getTrxList($address, int $limit = 50, int $start = 0): array
    {
        try {
            $transactions = $this->tron->getManager()->request('v1/accounts/' . $address . '/transactions', [
                'limit' => $limit,
                'start' => $start
            ]);
            return successArray(['data'=>$transactions]);
        } catch (\Exception $e) {
            Log::error('Get Transactions Error: ' . $e->getMessage());
            return errorArray($e->getMessage());
        }
    }

    /**获取币种余额
     * @param string $address 钱包地址
     * @param string $privateKey 钱包秘钥
     * @return array
     */
    public function getBalance($address,$privateKey = ''): array
    {
        $this->tron->setPrivateKey($privateKey);
//        try {
            $balance = $this->tron->getManager()->request('wallet/getaccount', [
                'address' => $this->tron->address2HexString($address)
            ]);

            // 返回余额信息
            $balanceData = isset($balance['balance']) ? $balance['balance'] : 0;
            // 获取 USDT 余额
            $usdtContractAddress = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'; // USDT TRC20 合约地址
            $usdtContractAbi = Config::get('tron')['abi']; // USDT 合约的 ABI，请确保此处填写正确的 ABI
        $usdtContractAbi = json_decode($usdtContractAbi,true);
            $contractCall = $this->tron->getTransactionBuilder()->triggerSmartContract(
                $usdtContractAbi,
                'balanceOf(address)',
                10000000, // feeLimit
                0, // callValue
                [
                    [
                        'type' => 'address',
                        'value' => $this->tron->address2HexString($address)
                    ],
                ],
                $this->tron->address2HexString($address) // payerAddress
            );
            if (isset($contractCall['constant_result'][0])) {
                $usdtBalanceHex = $contractCall['constant_result'][0];
                $usdtBalance = hexdec($usdtBalanceHex) / 1e6; // 将余额转换为可读格式
            } else {
                $usdtBalance = 0;
            }
            return successArray(['trx_balance'=>$balanceData,'usdt_balance'=>$usdtBalance]);
//        } catch (\Exception $e) {
//            Log::error('Get Balance Error: ' . $e->getMessage());
//            return errorArray($e->getMessage());
//        }
    }

}