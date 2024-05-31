<?php

namespace app\common\service;

class EthService extends ComService
{
    public function getEthMoney($address){
        $url = 'https://api.etherscan.io/api
       ?module=account
       &action=balance
       &address='.$address.'
       &tag=latest
       &apikey=I258Q362FE5J2YQN7RQF5XES8MZVN7D8KM';
        $ret = curl_http($url);
        var_dump($ret);
        if(!$ret) return 0;
        $res = json_decode($ret,true);
        var_dump($ret);
        return $res['result'];
    }
    public function getUsdtMoney($address){
        $url = 'https://api.etherscan.io/api
           ?module=account
           &action=tokenbalance
           &contractaddress=0xdAC17F958D2ee523a2206206994597C13D831ec7
           &address='.$address.'
           &tag=latest&apikey=I258Q362FE5J2YQN7RQF5XES8MZVN7D8KM';
        $ret = curl_http($url);
        if(!$ret) return 0;
        $res = json_decode($ret,true);
        return $res['result'];
    }

    public function getOrderList($address){
        $url = 'https://api.etherscan.io/api
           ?module=account
           &action=tokentx
           &contractaddress=0xdAC17F958D2ee523a2206206994597C13D831ec7
           &address='.$address.'
           &page=1
           &offset=100
           &startblock=0
           &endblock=27025780
           &sort=asc
           &apikey=YourApiKeyToken';
        $ret = curl_http($url);
        if(!$ret) return 0;
        $res = json_decode($ret,true);
        return $res['result'];
    }

    public function payUsdt(){

    }


}