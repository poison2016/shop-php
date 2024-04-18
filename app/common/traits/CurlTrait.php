<?php


namespace app\common\traits;


trait CurlTrait
{
    /**
     * 发送http curl请求
     * @param $url
     * @param array $data
     * @param string $method
     * @return bool|string
     */
    function curl_request($url, $data = [], $method = 'GET')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // 判断请求方式
        if (strtolower($method) == 'post') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $output = curl_exec($ch);
        $status = curl_getinfo($ch);
        curl_close($ch);
        if (intval($status["http_code"]) == 200) {
            return $output;
        } else {
            return false;
        }
    }
}
