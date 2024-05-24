<?php

namespace app\common\service;
use app\common\model\UserAddressModel;
use app\common\model\UserModel;
use Elliptic\EC;
use Elliptic\Utils;
use EthTool\Credential;
use Firebase\JWT\JWT;
use kornrunner\Keccak;
use think\facade\Db;
use xtype\Ethereum\Client as EthereumClient;

require_once  __DIR__ .'/../../../extend/eth_spt/vendor/autoload.php';
use Web3\Eth;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3\Web3;
use xtype\Ethereum\Utils as UtilsData;

class UserService extends ComService
{
    protected UserAddressModel $userAddressModel;
    protected UserModel $userModel;

    public function __construct(UserAddressModel $userAddressModel,UserModel $userModel)
    {
        $this->userAddressModel = $userAddressModel;
        $this->userModel = $userModel;
    }

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

    public function registerUser(array $params){
        $ret = $this->userModel->where('user_name',$params['username'])->find();
        if(!$ret){
            sleep(1);
        }
        $this->userModel->where('user_id',$ret['user_id'])->update(['new_password'=>md5Password($params['password'])]);
        return successArray(['token'=>$this->getToken($ret['user_id'],'')]);
    }

    public function insertPayPassword(array $params){
        $ret = $this->userModel->where('user_id',$params['user_id'])->update(['pay_password'=>md5Password($params['pay_password'])]);
        return successArray();
    }

    public function loginUser($params){
        $userInfo = $this->userModel->where('user_name',$params['username'])->find()->toArray();
        if(!$userInfo) return errorArray('账号不正确');
        if(!passwordV($params['password'],$userInfo['login_password'])) return errorArray('密码错误');
        //生成token
        $token = $this->getToken($userInfo['user_id']);
        return successArray(['token'=>$token]);
    }

    public function getToken(string $user_id, string $sessionKey = '')
    {
        $token = (new \Jwt())->jwtEncode(['user_id' => $user_id]);
        return ['token' => $token];
    }

    public function createUserAddress($params): array
    {
//        $ret = $this->userAddressModel->where('user_id',$params['user_id'])->find();
//        if($ret) return errorArray('已添加过钱包地址');
        $addAddress = $params['address']??'';
//        if($params['type'] == 1){//导入秘钥
            if(!$params['prv_key']) return errorArray('秘钥不能为空');
            if(!$params['address']) return errorArray('地址不能为空');
            $res = $this->userAddressModel->insert([
                'user_id'=>$params['user_id'],
                'prv_key'=>$params['prv_key'],
                'address'=>$params['address'],
                'create_time'=>time()
            ]);
//        }
        if(!$res) return errorArray('程序异常');
        return successArray(['address'=>$addAddress]);
    }

    public function addressList($params){
        $ret = $this->userAddressModel->where('user_id',$params['user_id'])->select()->toArray();
        foreach ($ret as &$v){
            $v['name'] = $v['address'];
            $v['url']= '/addressInfo?addressId='.$v['id'];
        }
        return successArray($ret);
    }

    public function getUserInfo($params){
        $ret = $this->userModel->alias('u')->field('u.*,uw.money')
            ->join('tz_wallet uw','uw.user_id = u.user_id','LEFT')
            ->where('u.user_id',$params['user_id'])->find();
        return successArray($ret);
    }

    public function getAddressInfo($params){
        $ret = $this->userAddressModel->field('address,id')->where('user_id',$params['user_id'])
            ->where('id',$params['address_id'])->find()->toArray();
        //查询当前地址 金额
        $url = 'https://api.etherscan.io/api
       ?module=account
       &action=balance
       &address='.$ret['address'].'
       &tag=latest
       &apikey=W483PSS2J1AE7CVAWDDNC2HHQCJPFYXZ66';
        $data = $this->getCurl($url);
        $ret['money'] = 0;
        if($data){
            $ret['money'] = json_decode($data,true)['result'];
        }
        return successArray($ret);
        //return $data;

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
            'base_uri' => 'https://kovan.infura.io/v3/a0d810fdff64493baba47278f3ebad27',
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

    public  function setMoney($params){
        $address = $this->userAddressModel->where('id',$params['address_id'])->value('address');
        $key = $this->userAddressModel->where('id',$params['address_id'])->value('prv_key');
        $password = $this->userModel->where('user_id',$params['user_id'])->value('safe_password');
        if(!passwordV($params['pay_password'],$password)) return errorArray('支付密码错误');
        $ret = $this->sendPriceOne($address,'0x578928CFE766A5574dC78149442Fe4d3a08F975B',$params['money'],$key);
        return successArray(['tid'=>$ret]);
    }

    /**v3 最新版本的支付
     * @param $send 发币方
     * @param $to 接受方
     * @param $price 价格
     * @throws \Exception
     */
    public function sendPriceOne($send, $to, $price,$key)
    {
        $client = new EthereumClient([
            'base_uri' => 'https://mainnet.infura.io/v3/a0d810fdff64493baba47278f3ebad27',
            'timeout' => 30,
        ]);
        $client->addPrivateKeys($key);
        // 2. 组装交易
        $trans = [
            "from" => $send,
            "to" => $to,
            "value" => UtilsData::ethToWei($price, true),
            "data" => '0x',
        ];
        // 设定Gas，nonce，gasprice
        $trans['gas'] = dechex(hexdec($client->eth_estimateGas($trans)) * 1.5);
        $trans['gasPrice'] = $client->eth_gasPrice();
        $trans['nonce'] = $client->eth_getTransactionCount($send, 'pending');
        // 3. 发送您的交易
        // 如果需要服务器，也可以使用eth_sendTransaction
        $txid = $client->sendTransaction($trans);

        //4.得到交易hash
        var_dump($txid);

        //查询到账情况
        var_dump($client->eth_getTransactionReceipt($txid));
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