<?php

namespace app\common\service;

class EthService extends ComService
{
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

    public function payUsdt(){

    }


}