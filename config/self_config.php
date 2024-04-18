<?php


return [
    // 订单配送费用(单位:元)
    'order_shipping_price' => 10,

    // 安全库存限制数量
    'safe_stock' => 0,

    // 京东安全库存
    'jd_stock' => 0,

    // 测试请求域名
    'host' => 'https://test.rd029.com',

    // 请求正式请求域名
    'host_api' => 'https://www.rd029.com',

    // 将订单取消提交的地址
    'jd_select' => '/admin/Jdvop/getStock',

    // 仓储调拨门店书籍安全时间配置 下单时间与配送时间的差值 单位 天
    'allocation_time' => 1,

    // 调拨安全库存
    'allocation_safe_stock' => 0,

    // 押金过期日期
    'deposit_expire_date' => '2021-01-20 23:59:59',

    // 新用户下单获取积分
    'order_points' => 100,

    // 用户手上最大持有数量
    'user_order_number' => 13,

    // 限制下单后最上时间
    'user_order_out_time' => 60 * 60 * 24 * 10,

    //用户年卡开通写入结束时间
    'user_card_end_time' => 60 * 60 * 24 * 365,

    //商品分页数据
    'goods_limit' => 10,

    //年卡剩余配送次数 初始值
    'user_card_surplus_ship_num' => 9999,

    //设置是否只判断门店库存
    'is_store_type' => 1,

    //设置是否开启年卡用户首单不限制下单-开启第三方库
    'third_party_order' => 0,

    //设置加密字符串的key
    'secret_key' => 'RD029',

    //CDN地址
    'cdn_address' => 'https://cdn.rd029.com',

    //团购活动结束时间
    'card_group_out_time' => 60 * 60 * 24 * 1,

    //团购活动分页
    'card_group_page' => 10,

    //需要写入开团人的userId 以,分割
    'open_card_group_user_id' => "216502",
    /**
     * 2020-12-16 重构掌灯人回调
     *  掌灯人
     */
    //设置掌灯人 自然人渠道顶级
    'nature_person' => 170091,

    //设置公司顶级
    'company' => 15108,

    //设置自然人渠道
    'nature_person_id' => 38,

    //购买季卡渠道
    'season_card_channel' => [45, 46, 47, 64, 65],

    //设置掌灯人支付价格399
    'card_price_399' => 399,

    //设置掌灯人支付价格499
    'card_price_499' => 499,

    //掌灯人分页
    'business_page' => 10,

    //设置指定人群邀请下级支付价格为设定价格
    'special_user_pay_card' => [],

    //验证卡信息接口
    'check_bank_url' => 'admin/distribution_user_infos/checkIdentity',
    /**
     * 商品
     */
//赔付商品价格比例
    'pay_goods_ratio' => 0.8,

    //盗版图书危害
    'pirated_books' => 'https://mp.weixin.qq.com/s/bPX8-gNG4ZY2afl7rhYcEg',

    //睿鼎社区店宣传
    'community_promotion' => 'https://mp.weixin.qq.com/s/V3aomb1ZmYTEghq_m--2zw',


    // 押金用户最大借书数量
    'deposit_max_books' => 9,

    //购物车最大数量
    'cart_max_count' => 50,

    //展示标签的规则
    'tags_id_array' => [18, 19, 20, 21, 227],

    /**
     * 年卡
     */
    //是否开启年卡多年限购买
    'is_card_info' => true,
    //押金转年卡加时长
    'deposit_transform_card' => 60 * 60 * 24 * 30 * 6,

    //获取不同年卡不同状态下给予的押金时长
    'pay_card_give_deposit' => [
        'low' => [
            '99' => 60 * 60 * 24 * 30 * 7,
            '199' => 60 * 60 * 24 * 30 * 8,
            '299' => 60 * 60 * 24 * 30 * 9,
            '399' => 60 * 60 * 24 * 30 * 10,
            '599' => 60 * 60 * 24 * 30 * 11,
        ],
        'new' => [
            '99' => 60 * 60 * 24 * 30 * 1,
            '199' => 60 * 60 * 24 * 30 * 2,
            '299' => 60 * 60 * 24 * 30 * 3,
            '399' => 60 * 60 * 24 * 30 * 4,
            '599' => 60 * 60 * 24 * 30 * 5,
        ],
    ],
    //有年卡有押金用户赠送押金时长
    'user_give_deposit_10' => 60 * 60 * 24 * 30 * 10,

    //具有掌灯人分销的年卡ID 如果需要增加 在此处增加
    'business_card_id' => [2, 7],

    //实体卡 特殊渠道商用户ID
    'entity_card_special_id' => [2, 6],

    //特殊渠道2
    'entity_card_special_two_id' => [4],

    // 特殊卡对应的年卡id
    'special_card_id' => 7,

    // 特殊卡对应的年卡id2
    'special_card_two_id' => 12,

    //押金转年卡是否支付押金
    'deposit_up_card_is_give' => 1,

    //设置团购支付超时时间
    'card_group_end_time' => 6 * 60,

    //退款金额
    'card_group_out_price' => 100,

    //拼团活动参与卡的id
    'open_group_card_id' => 2,

    //设置团购最低价格
    'card_group_low_price' => 399,

    'share_link' => "链接: https://pan.baidu.com/s/1xPJAxfWRj9Fr-Gi2mokqDQ \n提取码: ymu4",  //分享链接

    // 商品列表(猜你喜欢)缓存过期时间,单位:小时
    'goods_list_fav_expire' => 1 * 60 * 60,

    //设置安全库存
    'index_limit_stock' => 5,

    //购物车安全库存
    'cart_left_limit' => 5,

    //设置看飞结束首页列表显示的数量
    'index_limit' => 8,

    //客服电话
    'service_phone' => '029-85799903',

    //年龄分类列表缓存过期时间,单位:小时
    'age_cats_expire' => 24 * 60 * 60,

    //首页四方格分类显示数量
    'index_cats_small_limit' => 2,

    //首页分类列表缓存过期时间
    'index_cats_list_expire' => 1 * 60 * 60,

    //首页推荐分类显示数量
    'index_cats_limit' => 10,

    //学校分类列表缓存过期时间,单位:小时
    'school_cats_expire' => 24 * 60 * 60,

    //首页数据缓存时间
    'goods_list_expire' => 30 * 60,
    /**
     * 订单相关
     */
    'is_jd' => 0,//是否开启京东

    'is_end_jd' => false,//是否开启专业快递首单

    'is_open_store' => 1,//是否启动仓储 调拨
    /**
     * 优惠券
     */
    'coupon_limit' => 10,//优惠券分页

    'coupon_select_start_time' => 1615343767,//查询开始时间

    'store_center_can_allocation_in' => true, // 仓储中心是否可以调拨入货

    /**
     * 设置书单信息
     */

    'book_cat_list' =>
        [
            'new' => [
                980, 981, 982, 983, 984, 985, 986, 987
            ],
            'low' => [
                988, 989, 990, 991, 992, 993, 994, 995
            ]
        ],
    /**
     * 书评-图文验证
     */
    //错误码对应信息
    'text_check_error_message' => [
        'normal'=>'正常',
        'spam'=>'文字含垃圾信息',
        'ad'=>'其他广告',
        'politics'=>'涉政',
        'terrorism'=>'暴恐',
        'abuse'=>'辱骂',
        'porn'=>'色情',
        'flood'=>'灌水',
        'contraband'=>'违禁',
        'meaningless'=>'无意义',
        'sexy'=>'性感',
        'bloody'=>'血腥',
        'explosion'=>'爆炸烟光',
        'outfit'=>'特殊装束',
        'logo'=>'特殊标识',
        'weapon'=>'武器',
        'violence'=>'打斗',
        'crowd'=>'聚众',
        'parade'=>'游行',
        'carcrash'=>'车祸现场',
        'flag'=>'旗帜',
        'location'=>'地标',
        'others'=>'其他',
        'qrcode'=>'含二维码',
        'programCode'=>'含小程序码',
    ],


    //实名认证信息接口
    'verify_id' => 'admin/api/verifyId',

    'activity_order_template_code' => 'SMS_215990100',  //亲子活动20分钟未支付订单短信提醒模板
    'activity_order_pay_template_code' => 'SMS_215990101',  //亲子活动订单支付后短信提醒模板
    'activity_order_pay_template_teacher' => '小美',  //亲子活动订单支付后短信提醒模板老师
    'activity_order_pay_template_number' => '17792758672',  //亲子活动订单支付后短信提醒模板老师微信

    /**
     * 积分设置
     */
    'is_open_points'=>true,//是否开启积分邀请/赠送

    'bind_points'=> 200,//绑定赠送积分

    'vip_points'=>3000,//购买会员赠送积分


    'rsa2_pri_key' => root_path().'/extend/rsa/private.txt',  //RSA2签名私钥
    'rsa2_pub_key' => root_path().'/extend/rsa/public.txt',  //RSA2签名公钥

    /**
     * 购买话费
     */
    'charging_host'=>'http://121.42.252.122:9122/vcorders',

    'charging_pay_address'=>'/huafei/singleRecharge',//支付

    'charging_pay_order_status'=>'/huafei/getOrderStatus',//查询

    'charging_channel'=>'dQghO6q8A18U5Zkc',//编码

    'charging_ivs'=>'ua9ZWKJR6VGFMlgy',//向量

    'charging_version'=>'1.0.0',//协议版本号

    //七月邀请买赠活动相关配置
    'jul_activity' => [
        'activity_start_time' => '2021-07-18 00:00:00',  //活动开始时间
        'activity_end_time' => '2021-09-30 23:59:59',  //活动结束时间
        'receive_start_time' => '2021-07-18 00:00:00',  //领奖开始时间
        'receive_end_time' => '2021-09-30 23:59:59',  //领奖结束时间
        'keep_time' => 72,  //关系保护时间（关系锁定期），单位：小时
        'activity_buy_card_id' => [2, 7],  //被邀请者参与活动门槛，购买年卡ID
        'inviter_gift' => [  //邀请者活动奖励时长，根据邀请者身份决定，分别为：押金会员、年卡会员（押金 + 年卡）、9.9元次卡
            'deposit' => 180 * 24,  //押金赠送时长，单位：小时
            'card' => 180 * 24,  //年卡赠送时长，单位：小时
            'secondary_card' => 90 * 24,  //9.9元次卡赠送，699年卡时长，单位：小时
        ],
        'buyers_gift' => 30 * 24,  //购买者赠送时长，单位：小时
        'secondary_card' => [  //特殊次卡
            'card_id' => 13,  //9.9元次卡ID
            'gift_card_id' => 2,  //赠送年卡ID
            'card' => 30 * 24, //邀请好友办理9.9元次卡赠送当前年卡时长，单位：小时
        ],
        'new_gift_time' => 360 * 24,  //V3版本赠送年卡时长，单位：小时
        'v3_time' => '2021-07-26 00:00:00',  //V3版本上线时间，领取时长根据此时间进行新老规则执行，此时间之前执行老的领取规则。
    ]

];