<?php


namespace app\validate;


use app\common\exception\ApiException;
use think\Validate;

class FilterValid extends Validate
{

    public static function filterData(array $var, array $filter){
        foreach ($filter as $k => $v){
            $message = [];
            if(isset($var[$k]) == false){
                throw new ApiException('参数错误', ['k' => $k]);
            }

            if(is_array($v[1])){
                $v[1] = implode(',', $v[1]);
            }

            $rule = $v[0].':'.$v[1];
            if(isset($v[2]) && !empty($v[2])){
                $message = is_array($v[2])?$v[2]:[$v[2]];
            }else{
                $message[] = '参数错误';
            }

            $res = \validate()->checkItem($k, $var[$k], $rule, $var, '', $message);
            if(true !== $res){
                throw new ApiException($res, ['k' => $k, 'v' => $var[$k]]);
            }
        }
    }
}