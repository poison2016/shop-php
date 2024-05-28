<?php

namespace app\common\service;

use IEXBase\TronAPI\Exception\TronException;
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
     * @param string $meAddress 支付地址
     * @return array|\think\response\Json
     */
    public function transfer($toAddress, $amount,$prvKey,$meAddress)
    {
        $this->tron->setAddress($meAddress);
        $this->tron->setPrivateKey($prvKey);
        $contract = $this->tron->contract('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
        $result = $contract->transfer($toAddress,$amount);
        var_dump($result);
    }

    public function transferNew($toAddress, $amount,$prvKey,$meAddress){
        $this->tron->setAddress($meAddress);
        $this->tron->setPrivateKey($prvKey);
        // 检查账户能量
        $accountInfo = $this->tron->getAccount();
        $energy = $accountInfo['energy'] ?? 0;

        if ($energy < $this->calculateRequiredEnergy($amount)) {
            // 增加能量
            $this->increaseEnergy();
        }

        // 创建交易并设置 Fee_limit
        $transaction = $this->tron->getTransactionBuilder()->sendTrx($toAddress, $amount);
        $transaction['raw_data']['fee_limit'] = 10000000; // 设置适当的 Fee_limit

        // 签署交易
        $signedTransaction = $this->tron->signTransaction($transaction);

        // 广播交易
        $response = $this->tron->sendRawTransaction($signedTransaction);
        var_dump($response);
    }

    protected function trxToSun($amount)
    {
        return $amount * 1000000;
    }

    protected function calculateRequiredEnergy($amount)
    {
        // 估算所需的能量，具体逻辑根据实际情况调整
        // 此处仅为示例，假设每笔交易需要 5000 能量
        return 5000;
    }

    protected function increaseEnergy($address)
    {
        try {
            $freezeAmount = 1000; // 冻结 1000 TRX 增加能量
            $duration = 3;  // 冻结天数，最少为 3 天

            $freezeTransaction = $this->tron->getTransactionBuilder()->freezeBalance($this->trxToSun($freezeAmount), $duration, 'ENERGY',$address);

            // 签署交易
            $signedFreezeTransaction = $this->tron->signTransaction($freezeTransaction);

            // 广播交易
            $response = $this->tron->sendRawTransaction($signedFreezeTransaction);

            return $response;
        } catch (TronException $e) {
            throw new \Exception('Failed to increase energy: ' . $e->getMessage());
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
            // 使用 GET 方法请求交易记录
            $endpoint = 'v1/accounts/' . $address . '/transactions/trc20';
            $params = [
                'limit' => $limit,
                'start' => $start,
            ];
            // 拼接完整的 URL
            $url = 'https://api.trongrid.io/' . $endpoint . '?' . http_build_query($params);
            // 使用 Guzzle 进行 GET 请求
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', $url);
            // 解析响应内容
            $transactionsData = json_decode($response->getBody(), true);
            $transactions = $transactionsData['data'] ?? [];
            // 处理交易记录
            $processedTransactions = [];
            foreach ($transactions as $transaction) {
                $raddress = $transaction['from'];
                $toAddress = $transaction['to'];
                $name = $transaction['token_info']['symbol'];

                $amount = $transaction['value'] / 1e6; // 转换为 TRX 单位

                $timestamp = date('Y-m-d H:i:s', $transaction['block_timestamp'] / 1000);
                if($toAddress == $address){
                    $processedTransactions[] = [
                        'address' => $raddress,
                        'time' => $timestamp,
                        'amount' => '+' . $amount,
                        'type'=>$name,
                    ];
                } elseif ($raddress == $address) {
                    // 转入交易
                    $processedTransactions[] = [
                        'address' => $toAddress,
                        'time' => $timestamp,
                        'amount' => '-' . $amount,
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
        return successArray(['trx_balance'=>$balance,'usdt_balance'=>$balances]);
    }


}