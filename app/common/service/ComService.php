<?php


namespace app\common\service;


use app\common\traits\CacheTrait;
use think\facade\Db;
use think\facade\Log;
use think\Response;

class ComService
{
    use CacheTrait;
    //TODO 失败返回
    public function error(int $code = 100, string $message = "网络异常", $data = [])
    {
        if (!$code) {
            $code = 100;
        }
        return array('code' => $code, 'message' => $message, 'data' => $data);
    }

    //TODO 成功返回

    public function success(int $code = 200, string $message = "请求成功", $data = [])
    {
        return array('code' => $code, 'message' => $message, 'data' => $data);
    }








    public function getGoodsImg($goods_img, $width = 400, $height = 400)
    {
        if (!$goods_img) {
            return config('ali_config.domain') . '/public/images/goods_default.jpg';
        }
        $img = substr($goods_img, 0, 4);
        $result = $goods_img;
        if ($img != "http") {
            $result = config('ali_config.domain') . $goods_img;
        }
//        if ($width > 0 && $height > 0) {
//            $result .= '?x-oss-process=image/resize,m_fill,w_' . $width . ',h_' . $height;
//        }
        return $result;
    }


    /**
     * 转换INDEX
     * @param $array
     * @param $key
     * @return array
     */
    public function changeIndex($array, $key)
    {
        $res = array();

        foreach ($array as $row) {
            $res[$row[$key]] = $row;
        }

        return $res;
    }

    /**
     * 系统加密方法
     * @param string $data 要加密的字符串
     * @param string $key 加密密钥
     * @param int $expire 过期时间 单位 秒
     * @return string
     */
    public static function getEncode($data, $key = '', $expire = 0)
    {
        $key = md5(empty($key) ? config('self_config.secret_key') : $key);
        $data = base64_encode($data);
        $x = 0;
        $len = strlen($data);
        $l = strlen($key);
        $char = '';

        for ($i = 0; $i < $len; $i++) {
            if ($x == $l)
                $x = 0;
            $char .= substr($key, $x, 1);
            $x++;
        }

        $str = sprintf('%010d', $expire ? $expire + time() : 0);

        for ($i = 0; $i < $len; $i++) {
            $str .= chr(ord(substr($data, $i, 1)) + (ord(substr($char, $i, 1))) % 256);
        }
        return str_replace(array(
            '+',
            '/',
            '='
        ), array(
            '-',
            '_',
            ''
        ), base64_encode($str));
    }

    /**
     * 系统解密方法
     * @param string $data 要解密的字符串 （必须是get_encode方法加密的字符串）
     * @param string $key 加密密钥
     * @return string
     */
    public static function getDecode($data, $key = '')
    {
        $key = md5(empty($key) ? config('self_config.secret_key') : $key);
        $data = str_replace(array(
            '-',
            '_'
        ), array(
            '+',
            '/'
        ), $data);
        $mod4 = strlen($data) % 4;
        if ($mod4) {
            $data .= substr('====', $mod4);
        }
        $data = base64_decode($data);
        $expire = substr($data, 0, 10);
        $data = substr($data, 10);

        if ($expire > 0 && $expire < time()) {
            return '';
        }
        $x = 0;
        $len = strlen($data);
        $l = strlen($key);
        $char = $str = '';

        for ($i = 0; $i < $len; $i++) {
            if ($x == $l)
                $x = 0;
            $char .= substr($key, $x, 1);
            $x++;
        }

        for ($i = 0; $i < $len; $i++) {
            if (ord(substr($data, $i, 1)) < ord(substr($char, $i, 1))) {
                $str .= chr((ord(substr($data, $i, 1)) + 256) - ord(substr($char, $i, 1)));
            } else {
                $str .= chr(ord(substr($data, $i, 1)) - ord(substr($char, $i, 1)));
            }
        }
        return base64_decode($str);
    }


    // 冒泡排序

 function bubble_sort($arr,$key)
  {
      $len = count($arr);
      for ($i = 0; $i < $len -1; $i++) {//循环对比的轮数
          for ($j = 0; $j < $len - $i - 1; $j++) {//当前轮相邻元素循环对比
              if ($arr[$j][$key] < $arr[$j + 1][$key]) {//如果前边的小于后边的
                  $tmp = $arr[$j];//交换数据
                  $arr[$j] = $arr[$j + 1];
                 $arr[$j + 1] = $tmp;
             }
         }
     }
     return $arr;
 }

    /**过滤特殊字符串
     * @param $str
     * @param int $status
     * @return string|string[]
     */
    public static function replaceChar($str, $status = 0){
        if ($status == 1) {
            $regex = "/\/|\~|\!|\@|\#|\\$|\%|\^|\&|\*|\(|\)|\_|\+|\{|\}|\:|\<|\>|\?|\[|\]|\,|\.|\/|\;|\'|\`|\-|\=|\\\|\|/";
            $str = preg_replace($regex,"",$str);
        }
        $preg = [" ", "　", "\t", "\n", "\r"];
        return str_replace($preg, '', $str);
    }

    /**过滤表情符号
     * @param $text
     * @param string $replaceTo
     * @return string|string[]|null
     */
    public static function filterEmoji($text, $replaceTo = ''){
        $clean_text = "";
        $clean_text = preg_replace("#(\\\ud[0-9a-f]{3})#i",$replaceTo,$text);
        $clean_text = preg_replace_callback( '/./u',
            function (array $match) use ($replaceTo) {
                return strlen($match[0]) >= 4 ? $replaceTo : $match[0];
            },
            $clean_text);
        // Match Emoticons
        $regexEmoticons = '/[\x{1F600}-\x{1F64F}]/u';
        $clean_text = preg_replace($regexEmoticons, $replaceTo, $clean_text);
        // Match Miscellaneous Symbols and Pictographs
        $regexSymbols = '/[\x{1F300}-\x{1F5FF}]/u';
        $clean_text = preg_replace($regexSymbols, $replaceTo, $clean_text);
        // Match Transport And Map Symbols
        $regexTransport = '/[\x{1F680}-\x{1F6FF}]/u';
        $clean_text = preg_replace($regexTransport, $replaceTo, $clean_text);
        // Match Miscellaneous Symbols
        $regexMisc = '/[\x{2600}-\x{26FF}]/u';
        $clean_text = preg_replace($regexMisc, $replaceTo, $clean_text);
        // Match Dingbats
        $regexDingbats = '/[\x{2700}-\x{27BF}]/u';
        $clean_text = preg_replace($regexDingbats, $replaceTo, $clean_text);
        return $clean_text;
    }

}