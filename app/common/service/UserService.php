<?php

namespace app\common\service;
use Elliptic\EC;
use EthTool\Credential;
use kornrunner\Keccak;
use xtype\Ethereum\Client as EthereumClient;

require_once  __DIR__ .'/../../../extend/eth_spt/vendor/autoload.php';
use Web3\Eth;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3\Web3;
use Web3\Utils;
use Elliptic\EC;
use EthTool\Credential;
use kornrunner\Keccak;
use xtype\Ethereum\Client as EthereumClient;
class UserService extends ComService
{

    /**创建钱包地址
     * @param int $userId
     * @return array
     * @throws \Exception
     */
    private function createAddress(int $userId): array
    {
        $ec = new EC('secp256k1');
        $keyPair = $ec->genKeyPair();
        $privateKey = $keyPair->getPrivate()->toString(16,2);
        $publicKey = $keyPair->getPublic()->encode('hex');
        $address = '0x' . substr(Keccak::hash(substr(hex2bin($publicKey),1),256),24);
        return [$privateKey,$publicKey,$address];
    }

    public function addAddress(array $params){
        $userId = $params['user_id'];
        if($params['type'] == 1){//创建钱包

        }else{//导入钱包

        }
    }

    /**
     * 交易
     */
    public function sendTrade(){

    }

    /**
     * 发送 ERC-20 代币方法
     * @param string $prv_key 打款私钥
     * @param string $contract 合约地址
     * @param string $to_token 收款地址
     * @param double $amount 转账的金额
     * @param int $decimals 小数点位数
     * @return mixed
     */
    public function send_erc20_token($prv_key, $contract, $to_token, $amount, $decimals)
    {
        $sender = Credential::fromKey($prv_key);
        $send_address = $sender->getAddress();

        // 将汇率转换成字符串
        $decimals_str = "";
        while (strlen($decimals_str) < $decimals) {
            $decimals_str .= "0";
        }

        // 处理收款地址字符串
        $to_address = str_replace("0x", "", $to_token);

        // 链接以太坊节点
        $client = new EthereumClient([
            'base_uri' => $this->infura_endpoint,
            'timeout' => 30,
        ]);

        // 导入钱包私钥
        $client->addPrivateKeys([$prv_key]);

        // 创建自己的交易
        $trans = [
            "from" => $send_address,
            "to" => $contract,
            "value" => "0x0",
        ];
        // 写入收款地址
        $trans['data'] = "0x" . "a9059cbb"
            . "000000000000000000000000$to_address";

        // 写入金额
        $num = Utils::toHex($amount . $decimals_str);
        while (strlen($num) != 64) {
            $num = "0" . $num;
        }
        $trans['data'] .= $num;

        // 设定你的手续费
        $gasPrice = $client->eth_gasPrice();
        $trans['gasPrice'] = $gasPrice;
        $gas = 3000000000000000 / hexdec($gasPrice);
        $trans['gas'] = dechex($gas);
        $trans['nonce'] = $client->eth_getTransactionCount($send_address, 'pending');
        $txid = $client->sendTransaction($trans);

        $receipt = $client->eth_getTransactionReceipt($txid);
        return $txid;
    }

    public function getOrder($address){
        $apikey = "7G6TT4NRQCGKIESRT89EV5FGXD4IPR7WT6"; //99999999
        $contract = "0xdAC17F958D2ee523a2206206994597C13D831ec7"; //usdt合约地址0xdAC17F958D2ee523a2206206994597C13D831ec7    ls 合约地址0x66270133996890558b126c19cd2f8a4b502243fb
        $url = "http://api.etherscan.io/api?module=account&action=tokentx&contractaddress=$contract&address=$address&sort=asc&apikey=$apikey";
        // var_dump($url);
        $data = $this->getCurl($url);
        return $data;
    }

    /**
     * 远程curl请求(GET)
     */
    public function getCurl($url)
    {
        $curl = curl_init();
        //设置抓取的url
        curl_setopt($curl, CURLOPT_URL, $url);
        //设置获取的信息以文件流的形式返回，而不是直接输出。
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //不验证证书
        //执行命令
        $data = curl_exec($curl);
        //关闭URL请求
        curl_close($curl);
        //显示获得的数据
        return $data;
    }



}