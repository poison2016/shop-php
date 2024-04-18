<?php
/**
 *
 * @author yangliang
 * @date 2020/11/9 14:21
 */

namespace app\common;


class ConstLib
{
    const CART_MAX_COUNT = 50;// 购物车最大数量
    const SAFE_STOCK = 0;// 安全库存限制数量

    const DEV_IP = '47.99.142.67';  //测试服IP
    const ONLINE_IP = '47.98.36.129';  //线上IP

    const GROUP_REFUND_WARNING_PRICE = 333;  //团购退款预警金额
    const GROUP_REFUND_PRICE = 100;  //团购退款金额
    const GROUP_OPEN_REFUND_PRICE = 66;  //开团退款金额


    /*** 设置从这个位置后修改阅读人数 ***/
    const LOOK_USER = 183462;
    const LOOK_USER_TWO = 207649;



    const SERVICE_PHONE = '029-85799903';// 客服电话

    const GOODS_LIST_EXPIRE = 20 * 60;// 商品列表缓存过期时间,单位:小时 修改为30分钟

    const MOBILE_PAGE_LIMIT = 10;// 免费借书每页数量

    const DEPOSIT_MAX_BOOKS = 9;// 押金用户最大借书数量

    const ORDER_SHIPPING_PRICE = 10;// 订单配送费用(单位:元)

    /** 书评审核通知手机号**/
    const DING_DING_COMMENT_MOBILE = [15129399224];

    const COMMENT_LIST_EXPIRE = 24 * 60 * 60 * 30;// 与我相关的数据列表缓存过期时间,单位:30天

    const COMMENT_LIMIT = 20;// 书评列表数量

    const GOODS_END_BOOK = 15;//书评过期时间





    const GOODS_COMMENT_MAX = 3;  //同一本书同一用户最大评论数


    const FAMILY_ACTIVITY_ORDER_UNPAY = 0;  //亲子活动待支付订单状态

    const FAMILY_ACTIVITY_PARTNER_MAX_DISCOUNT = 4;  //股东自然年内最大使用减免次数

    const FAMILY_ACTIVITY_CANCEL_ORDER_TIMESTAMP = 1800;  //亲子活动未支付取消订单时长（秒）

    const FAMILY_ACTIVITY_ORDER_QUEUE_NAME = 'activity_order';  //亲子活动订单队列名称

    const FAMILY_ACTIVITY_UNPAY_ORDER_TIMESTAMP = 1200;  //亲子活动未支付订单提醒短信时长（秒）
}