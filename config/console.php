<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'SendPrice' => 'app\command\SendPrice',
        'get_trx_money' => 'app\command\GetTrxMoney',
        'get_eth_money' => 'app\command\GetEthMoney',
    ],
];
