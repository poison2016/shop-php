<?php

namespace app\common\service;

use app\common\model\UserAddressLogModel;
use IEXBase\TronAPI\Exception\TronException;
use IEXBase\TronAPI\Provider\HttpProvider;
use IEXBase\TronAPI\Tron;
use think\facade\Config;
use think\facade\Log;
define('TRX_TO_SUN', 1000000);

class TrxService extends ComService
{

    protected $tron;
    protected UserAddressLogModel $addressLogModel;

    /**配置
     * @throws \IEXBase\TronAPI\Exception\TronException
     */
    public function __construct(UserAddressLogModel $addressLogModel)
    {
        $config = Config::get('tron');
        $fullNode = new HttpProvider($config['full_node']);
        $solidityNode = new HttpProvider($config['solidity_node']);
        $eventServer = new HttpProvider($config['event_server']);
        $this->tron = new Tron($fullNode, $solidityNode, $eventServer);
        $this->addressLogModel = $addressLogModel;

    }

    /**交易
     * @param string $toAddress 发送给谁
     * @param double $amount 发送金额
     * @param string $prvKey 支付秘钥
     * @param string $meAddress 支付地址
     * @return array|\think\response\Json
     */
    public function transfer($toAddress, $amount, $prvKey, $meAddress, $userId = '123')
    {
        $this->tron->setAddress($meAddress);
        $this->tron->setPrivateKey($prvKey);
        $contract = $this->tron->contract('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');

        // 预估交易消耗
        $estimate = $contract->estimateEnergyUsage([
            'function' => 'transfer',
            'params' => [
                'to' => $toAddress,
                'value' => $amount
            ]
        ]);

// 获取估算的能量和带宽消耗
        $energyUsage = $estimate['energyUsage'];
        $bandwidthUsage = $estimate['bandwidthUsage'];

// 假设每 1 TRX 购买 1,000 能量
        $trxPerEnergy = 1 / 1000;

// 计算所需 TRX
        $trxNeededForEnergy = $energyUsage * $trxPerEnergy;

// 计算总费用
        $totalTrxNeeded = $trxNeededForEnergy; // 可以忽略带宽费用，因为带宽消耗较小

        echo "交易 10 USDT 需要大约 " . $totalTrxNeeded . " TRX";
        exit();

// 设置 Fee Limit
        $feeLimitInSun = ceil($totalTrxNeeded * TRX_TO_SUN);
        $result = $contract->transfer($toAddress, $amount);
        if ($result['result']) {
            $this->addressLogModel->insert([
                'user_id' => $userId,
                'address' => $meAddress,
                'txid' => $result['txid'],
                'money' => $amount,
                'create_time' => time()
            ]);
            return successArray(['txid' => $result['txid']], '交易中');
        }
        return errorArray('交易失败');
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

            $this->tron->setAddress($address);
            $contract = $this->tron->contract('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
            $transactions = $contract->getTransactions($address);
            $transactiond = $transactions['data'];
            // 处理交易记录
            $processedTransactions = [];
            foreach ($transactiond as $transaction) {
                $raddress = $transaction['from'];
                $toAddress = $transaction['to'];
                $name = $transaction['token_info']['symbol'];

                $amount = $transaction['value'] / 1e6; // 转换为 TRX 单位

                $timestamp = date('Y-m-d H:i:s', $transaction['block_timestamp'] / 1000);
                if($toAddress == $address){
                    $processedTransactions[] = [
                        'address' => $raddress,
                        'time' => $timestamp,
                        'amount' =>  $amount,
                        'amount_type' => 1,
                        'type'=>$name,
                    ];
                } elseif ($raddress == $address) {
                    // 转入交易
                    $processedTransactions[] = [
                        'address' => $toAddress,
                        'time' => $timestamp,
                        'amount' => $amount,
                        'amount_type' => 0,
                        'type'=>$name,
                    ];
                }
            }
            return successArray(['list'=>$processedTransactions]);
        } catch (\Exception $e) {
            Log::error('Get Transactions Error: ' . $e->getMessage());
            return errorArray($e->getMessage());
        }
    }

    /**获取币种余额
     * @param $address 查询地址
     * @param string $privateKey
     * @return array
     * @throws \IEXBase\TronAPI\Exception\TRC20Exception
     * @throws \IEXBase\TronAPI\Exception\TronException
     */
    public function getBalance($address,$privateKey = ''){
        $this->tron->setPrivateKey($privateKey);
        $this->tron->setAddress($address);
        $balance = $this->tron->getBalance($address,true);
        $contract = $this->tron->contract('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
        $balances = $contract->balanceOf();
        return successArray(['balance'=>$balance,'usdt_balance'=>$balances]);
    }

}