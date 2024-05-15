<?php
// 应用公共文件
use app\common\service\ComService;
use Endroid\QrCode\QrCode;

/**
 * CURL GET || post请求  （兼容XML数据提交）
 * @param string $url 请求地址
 * @param bool $isPostRequest 默认true是POST请求，否则是GET请求
 * @param array $data 请求的参数
 * @param array $header 请求头参数
 * @param array $certParam array  ['cert_path']    ['key_path']
 * @return array|string
 */
function curl_http($url, $isPostRequest = false, $data = [], $header = [], $certParam = [])
{
    $curlObj = curl_init(); // 启动一个CURL会话
    //如果是POST请求
    if ($isPostRequest) {
        if (is_array($data) || is_object($data)) {
            $data = http_build_query($data);
        }
        curl_setopt($curlObj, CURLOPT_POST, 1); // 发送一个常规的Post请求
        curl_setopt($curlObj, CURLOPT_POSTFIELDS, $data); // Post提交的数据包
    } else {  //get请求检查是否拼接了参数，如果没有，检查$data是否有参数，有参数就进行拼接操作
        $getParamStr = '';
        if (!empty($data) && is_array($data)) {
            $tmpArr = [];
            foreach ($data as $k => $v) {
                $tmpArr[] = $k . '=' . $v;
            }
            $getParamStr = implode('&', $tmpArr);
        }
        //检查链接中是否有参数
        $url .= strpos($url, '?') !== false ? '&' . $getParamStr : '?' . $getParamStr;
    }
    curl_setopt($curlObj, CURLOPT_URL, $url); // 要访问的地址
    //检查链接是否https请求
    if (strpos($url, 'https') !== false) {
        //设置证书
        if (!empty($certParam) && isset($certParam['cert_path']) && isset($certParam['key_path'])) {
            curl_setopt($curlObj, CURLOPT_SSL_VERIFYPEER, 0); // 对认证证书来源的检查
            curl_setopt($curlObj, CURLOPT_SSL_VERIFYHOST, 2); // 从证书中检查SSL加密算法是否存在
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($curlObj, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($curlObj, CURLOPT_SSLCERT, $certParam['cert_path']);
            curl_setopt($curlObj, CURLOPT_SSLKEYTYPE, 'PEM');
            curl_setopt($curlObj, CURLOPT_SSLKEY, $certParam['key_path']);
        } else {
            curl_setopt($curlObj, CURLOPT_SSL_VERIFYPEER, 0); // 对认证证书来源的检查
            curl_setopt($curlObj, CURLOPT_SSL_VERIFYHOST, 0); // 从证书中检查SSL加密算法是否存在
        }
    }
    // 模拟用户使用的浏览器
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        curl_setopt($curlObj, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
    }
    curl_setopt($curlObj, CURLOPT_FOLLOWLOCATION, 1); // 使用自动跳转
    curl_setopt($curlObj, CURLOPT_AUTOREFERER, 1); // 自动设置Referer
    curl_setopt($curlObj, CURLOPT_TIMEOUT, 30); // 设置超时限制防止死循环
    curl_setopt($curlObj, CURLOPT_HEADER, 0); // 显示返回的Header区域内容
    curl_setopt($curlObj, CURLOPT_HTTPHEADER, $header);   //设置头部
    curl_setopt($curlObj, CURLOPT_RETURNTRANSFER, 1); // 获取的信息以文件流的形式返回
    $result = curl_exec($curlObj); // 执行操作
    if (curl_errno($curlObj)) {
        $result = 'error: ' . curl_error($curlObj);//捕抓异常
    }
    curl_close($curlObj); // 关闭CURL会话
    if (!xml_parser($result)) {
        return json_decode($result, true);  // 返回数据，json格式
    } else {
        return $result;  //XML
    }
}

function tp_page(int $listRows = 10, int $page = 1): array
{
    return [
        'list_rows' => (int)input('list_rows', $listRows),
        'page' => (int)input('page', $page),
    ];
}


/**
 * array转xml
 * @param array $arr
 * @return string
 */
function arrayToXml(array $arr): string
{
    $xml = '<xml>';
    foreach ($arr as $key => $val) {
//        if (is_numeric($val)) {
        $xml .= sprintf('<%s>%s</%s>', $key, $val, $key);
//        } else {
//            $xml .= sprintf('<%s><![CDATA[%s]]></%s>', $key, $val, $key);
//        }
    }
    $xml .= '</xml>';

    return $xml;
}


/**
 * xml转array
 * @param string $xml
 * @return array
 */
function xmlToArray(string $xml): array
{
    //禁止引用外部xml实体
    libxml_disable_entity_loader(true);
    $values = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    return $values;
}


/**
 * 获取当前访问用户的IP
 * @param bool $isInt
 * @return false|int|string
 */
function getClientIP($isInt = false)
{
    $sip = '';
    foreach (array('HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR') as $k) {
        if (isset($_SERVER[$k])) {
            $ip = filter_var($_SERVER[$k], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if (!empty($ip)) {
                $sip = $ip;
                break;
            }
        }
    }

    if ($isInt) {
        if (empty($sip)) {
            return 0;
        }
        return ip2long($sip);
    }
    return $sip;
}

/**
 * 生成随机字符串
 * @param int $length 生成的字符串长度
 * @return string
 */
function create_rand_str($length)
{
    $letters_init = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $letters = str_shuffle($letters_init);
    $total = strlen($letters) - $length;
    $start = mt_rand(0, $total);
    $rand_str = substr($letters, $start, $length);
    return $rand_str;
}

/**
 * 生成签名
 * @param $data
 * @param string $signType
 * @param string $app_key
 * @return string
 * @throws Exception
 */
function makeSign($data, $signType = "MD5", $app_key)
{
    $data = array_filter($data);
    //签名步骤一：按字典序排序参数
    ksort($data);
    $string = http_build_query($data);
    $string = urldecode($string);
    //签名步骤二：在string后加入KEY
    $string = $string . "&key=" . $app_key;
    //签名步骤三：MD5加密或者HMAC-SHA256
    if ($signType == "MD5") {
        $string = md5($string);
    } else if ($signType == "HMAC-SHA256") {
        $string = hash_hmac("sha256", $string, $app_key);
    } else {
        trace('支付日志:签名类型不支持', 'error');
        throw new Exception("签名类型不支持！");
    }
    //签名步骤四：所有字符转为大写
    $result = strtoupper($string);
    return $result;
}

/**
 * 自定义xml验证函数
 * @param $str
 * @return bool|mixed
 * @author yangliang
 * @date 2020/12/15 17:21
 */
function xml_parser($str)
{
    $xml_parser = xml_parser_create();
    if (!xml_parse($xml_parser, $str, true)) {
        xml_parser_free($xml_parser);
        return false;
    } else {
        return (json_decode(json_encode(simplexml_load_string($str)), true));
    }
}

function getDataTime(){
        return date('Y-m-d H:i:s',time());
}

/**
 * 获取商品图片完整路径
 * @param $goods_img
 * @param $width
 * @param $height
 * @return array|string
 */
function getGoodsImg($goods_img, $width = 0, $height = 0)
{
    if (!$goods_img) {
        return config('ali_config.domain') . '/public/images/goods_default.jpg';
    }
    $img = substr($goods_img, 0, 4);
    $result = $goods_img;
    if ($img != "http") {
        $result = config('ali_config.domain') . $goods_img;
    }
    if ($width > 0 && $height > 0) {
        $result .= '?x-oss-process=image/resize,m_fill,w_' . $width . ',h_' . $height;
    }

    return $result;
}

/**冒泡排序
 * @param $arr
 * @param $name
 * @return mixed
 */
function quickSort($arr, $name)
{
    $len = count($arr);
    for ($i = 0; $i < $len - 1; $i++) {//循环对比的轮数
        for ($j = 0; $j < $len - $i - 1; $j++) {//当前轮相邻元素循环对比
            if ($arr[$j][$name] < $arr[$j + 1][$name]) {//如果前边的大于后边的
                $tmp = $arr[$j];//交换数据
                $arr[$j] = $arr[$j + 1];
                $arr[$j + 1] = $tmp;
            }
        }
    }
    return $arr;
}


/**
 * 过滤特殊字符,删除空格与回车,去除特殊字符
 * @param $str
 * @param int $status 是否过滤特殊字符:0否1是
 * @return string|string[]
 */
function replaceChar($str, $status = 0)
{
    if ($status == 1) {
        $regex = "/\/|\~|\!|\@|\#|\\$|\%|\^|\&|\*|\(|\)|\_|\+|\{|\}|\:|\<|\>|\?|\[|\]|\,|\.|\/|\;|\'|\`|\-|\=|\\\|\|/";
        $str = preg_replace($regex, "", $str);
    }
    $preg = [" ", "　", "\t", "\n", "\r"];
    return str_replace($preg, '', $str);
}


/**
 * 生成二维码
 * @param $data
 * @param null $filePath
 * @param int $size
 * @return string|void
 */
function createQrCode($data, $filePath = null, $size = 300)
{
    $qrCode = new QrCode($data);
    $qrCode->setSize($size);
    if (empty($filePath)) {
        $result = $qrCode->writeString();
    } else {
        $result = $qrCode->writeFile($filePath);
    }
    return $result;
}


/**
 * 获取指定时间距现在多久
 * @param int|string $the_time 时间
 * @return string
 */
function time_tran($the_time)
{
    $now_time = time();
    $show_time = is_int($the_time) ? $the_time : strtotime($the_time);
    $dur = $now_time - $show_time;
    if ($dur < 0) {
        return $the_time;
    }
    if ($dur < 60) {
        return $dur . '秒前';
    }
    if ($dur < 3600) {
        return floor($dur / 60) . '分钟前';
    }
    if ($dur < 86400) {
        return floor($dur / 3600) . '小时前';
    }
    if ($dur < 259200) {//3天内
        return floor($dur / 86400) . '天前';
    } else {
        return $the_time;
    }
}


/**返回成功的信息
 * @param array $data
 * @param string $message
 * @param int $code
 * @return array
 */
function successArray($data = [], $message = '提交成功', $code = 200)
{
    return ['code' => $code, 'message' => $message, 'data' => $data];
}

/**返回失败的消息
 * @param string $message
 * @param int $code
 * @return array
 */
function errorArray($message = '提交失败', $code = 100)
{
    return ['code' => $code, 'message' => $message];
}

/**
 * 计算日期
 * @param $date
 * @param string $t
 * @param int $n
 * @return array
 */
function get_date($date, $t = 'd', $n = 0)
{

    if ($t == 'd') {
        $firstday = date('Y-m-d 00:00:00', strtotime("$n day"));
        $lastday = date("Y-m-d 23:59:59", strtotime("$n day"));
    } elseif ($t == 'w') {
        if ($n != 0) {
            $date = date('Y-m-d', strtotime("$n week"));
        }
        $lastday = date("Y-m-d 23:59:59", strtotime("$date Sunday"));
        $firstday = date("Y-m-d 00:00:00", strtotime("$lastday -6 days"));
    } elseif ($t == 'm') {
        if ($n != 0) {
            $date = date('Y-m-d', strtotime("$n months"));
        }
        $firstday = date("Y-m-01 00:00:00", strtotime($date));
        $lastday = date("Y-m-d 23:59:59", strtotime("$firstday +1 month -1 day"));
    }
    return array($firstday, $lastday);
}

/**获取上级id
 * @param $topId
 * @return int
 */
function getTopId($topId): int
{
    $topIdString = ComService::getDecode($topId);
    $topId = $topIdArray = json_decode($topIdString, TRUE);
    if (is_array($topIdArray)) {
        $topId = $topIdArray['top_id'];
    }
    return $topId ?? 0;
}


/**
 * RSA2签名算法
 * @param string $data 签名参数
 * @param string $pri_key_path 私钥路径
 * @return string
 */
function sign(string $data, string $pri_key_path): string
{
    $priKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
        wordwrap(file_get_contents($pri_key_path), 64, "\n", true) .
        "\n-----END RSA PRIVATE KEY-----";
    $res = openssl_get_privatekey($priKey);

    openssl_sign(arrayToString($data), $sign, $res, OPENSSL_ALGO_SHA256);
    $sign = base64_encode($sign);
    return $sign;
}


/**
 * 拼接数组为字符串，k="v"&k="v"
 * @param $data
 * @return string
 */
function arrayToString($data): string
{
    $d = array();
    foreach ($data as $k => $v) {
        $d[] = sprintf('%s="%s"', $k, $v);
    }
    $s = implode('&', $d);
    return $s;
}


/**
 * RSA2验签算法
 * @param $data
 * @param $sign
 * @param $pub_key_path
 * @return bool
 */
function checkSign($data, $sign, $pub_key_path): bool
{
    $priKey = "-----BEGIN PUBLIC KEY-----\n" .
        wordwrap(file_get_contents($pub_key_path), 64, "\n", true) .
        "\n-----END PUBLIC KEY-----";
    $res = openssl_get_publickey($priKey);
    $result = FALSE;
    if (isset($data['sign'])) {
        unset($data['sign']);
    }
    $result = (openssl_verify(arrayToString($data), base64_decode($sign), $res, OPENSSL_ALGO_SHA256) === 1);
    //释放资源
    openssl_free_key($res);
    return $result;
}