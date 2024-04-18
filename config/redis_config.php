<?php

return [
    'host' => env('redis.host', 'localhost'),
    'port' => env('redis.port', '6379'),
    'password' => env('redis.password', ''),
    'select' => env('redis.select', '0'), // 使用哪一个 db，默认为 db0
    'timeout' => 0, // redis连接的超时时间
    'persistent' => false // 是否是长连接
];