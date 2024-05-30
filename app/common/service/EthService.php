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
       &apikey=W483PSS2J1AE7CVAWDDNC2HHQCJPFYXZ66';
        $ret = curl_http($url);
        var_dump($ret);
    }

}