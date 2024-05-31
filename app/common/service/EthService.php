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
    }

}