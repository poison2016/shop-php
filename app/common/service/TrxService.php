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
    public function transfer($toAddress, $amount,$prvKey,$meAddress)
    {
        $this->tron->setAddress($meAddress);
        $this->tron->setPrivateKey($prvKey);
        $contract = $this->tron->contract('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
        $result = $contract->transfer($toAddress,$amount);
        var_dump($result);
    }

    /**获取交易记录
     * @param string $address 钱包地址
     * @param int $limit 多少列 默认为50
     * @param int $start 开始 默认为0
     * @return array
     */
    public function getTrxList($address, int $limit = 10, int $start = 0): array
    {
        try {
            // 使用 GET 方法请求交易记录
            $endpoint = 'v1/accounts/' . $address . '/transactions';
            $params = [
                'limit' => $limit,
                'start' => $start
            ];
            // 拼接完整的 URL
            $url = 'https://api.trongrid.io/' . $endpoint . '?' . http_build_query($params);
            // 使用 Guzzle 进行 GET 请求
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', $url);
            // 解析响应内容
            $transactions = json_decode($response->getBody(), true);
            $transactions = $transactionsData['data'] ?? [];
            var_dump($transactions);exit();
            // 处理交易记录
            $processedTransactions = [];
            foreach ($transactions as $transaction) {
                $tx = $transaction['raw_data']['contract'][0]['parameter']['value'];
                $amount = $tx['amount'] / 1e6; // 转换为 TRX 单位
                $timestamp = date('Y-m-d H:i:s', $transaction['block_timestamp'] / 1000);

                if ($tx['owner_address'] == $this->tron->address2HexString($address)) {
                    // 转出交易
                    $processedTransactions[] = [
                        'address' => $this->tron->hexString2Address($tx['to_address']),
                        'time' => $timestamp,
                        'amount' => '-' . $amount
                    ];
                } elseif ($tx['to_address'] == $this->tron->address2HexString($address)) {
                    // 转入交易
                    $processedTransactions[] = [
                        'address' => $this->tron->hexString2Address($tx['owner_address']),
                        'time' => $timestamp,
                        'amount' => '+' . $amount
                    ];
                }
            }
            return successArray(['data'=>$transactions]);
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