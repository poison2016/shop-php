<?php
/**
 * ConstCode.php
 * 系统异常状态码
 * 1、订单相关状态码：2XXXX
 * author yangliang
 * date 2021/8/18 11:55
 */

namespace app\common;


class ConstCode
{

    /**
     * 存在未归还订单
     */
    const ORDER_UNRECEIVED = 20009;

    /**
     * 存在异常订单未处理
     */
    const ORDER_UNHANDLED_EXCEPTION = 20010;

    /**
     * 存在未归还订单
     */
    const ORDER_UNREPAY = 20011;

    /**
     * 弹窗code，无特殊业务，只做小程序弹框提醒
     */
    const ALERT_CODE = 20000;
}