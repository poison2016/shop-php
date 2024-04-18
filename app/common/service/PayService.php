<?php


namespace app\common\service;

use app\common\ConstLib;
use app\common\exception\ApiException;
use app\common\model\CardsModel;
use app\common\model\FamilyActivityModel;
use app\common\model\FamilyActivityOrderModel;
use app\common\model\FamilyActivityPartnerDiscountModel;
use app\common\model\GoodsLossDetailModel;
use app\common\model\GoodsLossModel;
use app\common\model\GoodsModel;
use app\common\model\GoodsStockModel;
use app\common\model\GoodStockDetailsModel;
use app\common\model\JdOrderGoodsModel;
use app\common\model\JulActivityChannelUserCommissionModel;
use app\common\model\JulActivityChannelUserModel;
use app\common\model\JulActivityChannelUserSettleModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\PartnerLogModel;
use app\common\model\PayLogModel;
use app\common\model\ReturnGoodsModel;
use app\common\model\StoreActivityInfoModel;
use app\common\model\StoreActivityOrderModel;
use app\common\model\StoreActivitySignUpLogsModel;
use app\common\model\StoreActivitySignUpModel;
use app\common\model\UserCardChangeLogModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardReviewModel;
use app\common\model\UserCardsModel;
use app\common\model\UserCardsOperationLogsModel;
use app\common\model\UserCouponModel;
use app\common\model\UserModel;
use app\common\model\UserPartnerModel;
use app\common\model\UsersLogModel;
use app\common\Tools\ALiYunSendSms;
use app\common\traits\CacheTrait;
use app\job\SendSmsJob;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;
use think\facade\Log as LogSave;
use think\facade\Queue;
use think\Model;

/**
 *用于小程序支付-回调
 * Class PayService
 * @package app\common\service
 * @author Poison
 * @date 2020/12/16 9:32 上午
 */
class PayService extends ComService
{
    use CacheTrait;

    private $payList = [
        'order',// 借书订单支付配送费
        'partner',// 储值卡充值
        'card',// 年卡办理
        'goods',// 借书赔付
        'activity',// 门店活动
        'family',//亲子活动
        'phone',//话费充值
        'deposit',//实体卡押金支付
        'jul_activity_deposit', //七月活动补交押金
        'jul_activity_dc', //七月活动押金转年卡
    ];
    private $payNameList = [
        'order' => '借书订单支付配送费',// 借书订单支付配送费
        'partner' => '储值卡充值',// 储值卡充值
        'card' => '年卡办理',// 年卡办理
        'goods' => '借书赔付',// 借书赔付
        'activity' => '门店活动',// 门店活动
        'family' => '亲子活动',//亲子活动
        'phone' => '话费充值',//话费充值
        'deposit' => '实体卡押金支付',//实体卡押金支付
        'jul_activity_deposit' => '七月活动补交押金',
        'jul_activity_dc' => '七月活动押金转年卡'
    ];

    public function pay($request, $userId = 0)
    {
        $userInfo = (new UserModel())->getOneUser($userId);
        if (!$userInfo || !$userInfo['smart_openid']) {
            return $this->error(100, "用户不存在|openId为空");
        }
        $request['openid'] = $userInfo['smart_openid'];// 微信小程序openid
        // 用来区分 支付模块
        $type = $request['type'] ?? '';
        if (!$type) {
            return $this->error(100, "支付模块不能为空");
        }
        if (!in_array($type, $this->payList)) {
            return $this->error(100, "支付模块不存在");
        }
        switch ($type) {
            case 'order':// 订单支付
                return (new OrderService())->getPayment($request);
                break;
            case 'partner':// 储值卡充值
                return (new UserPartnerService())->getUserPartnerPayment($request, $userId);
                break;
            case 'card':// 办理年卡
                return (new UserCardService())->getUserCardPayment($request, $userId);
                break;
            case 'goods':// 订单赔付
                return (new OrderService())->getGoodsPayment($request);
                break;
            case 'family'://亲子活动
                return (new FamilyActivityOrderService())->getPayment($request);
                break;
            case 'phone'://话费充值
                return (new ChargingService())->pay($request);
        }

        return $this->error(100, '未找到相关模块');
    }

    /**支付异步回调
     * @param $request
     * @return bool
     * @author Poison
     * @date 2020/12/17 2:44 下午
     */
    public function paymentCallback($request)
    {
        if ($request['result_code'] == 'SUCCESS' && $request['return_code'] == 'SUCCESS') {
            // 用来区分 支付模块
            $attach = $request['attach'];
            // 订单号
            $outTradeNo = $request['out_trade_no'];
            // 判断redis锁是否有效
            $redisLock = $this->getCache($outTradeNo);
//            if (!$redisLock) {
//                LogSave::channel('pay')->info('支付回调失败,out_trade_no:' . $outTradeNo);
//                return true;
//            }
            // redis有效则删除
            if ($redisLock == $outTradeNo) {
                $this->getRedis()->del($outTradeNo);
            }
            if($request['total_fee'] == 1){
                $ding = new ComService();
                try{
                    $pay_type_name = strstr($attach, 'jul_activity_dc_') ? $this->payNameList['jul_activity_dc'] : $this->payNameList[$attach];
                    $ding->sendDing(' 【报警】 订单号:'.$outTradeNo.' 支付类型:'. $pay_type_name .' 支付金额：0.01，请及时查看!','',config('ding_config.work_hook'),true);
                }catch (\ErrorException $ex){
                    $ding->sendDingError('支付0.01元通知失败',$ex);
                }
            }

            switch ($attach) {

                case 'card':
                    $t = time();
                    return $this->userCardHandle((string)$outTradeNo, (int)$t);
                    break;
                case 'order':
                    return $this->orderHandle($outTradeNo);
                    break;
                case 'partner':
                    return $this->userPartnerHandle($outTradeNo);
                    break;
                case 'goods':
                    return $this->goodsHandle($outTradeNo);
                    break;
                case 'activity':
                    return $this->activityHandle($outTradeNo);
                    break;
                case 'family':
                    return $this->familyHandle($outTradeNo, (float)$request['total_fee']);
                    break;
                case 'phone':
                    return (new ChargingService())->callBack($outTradeNo);
                    break;
                case 'deposit':
                    return (new EntityCardService())->entityNotify($outTradeNo, time());
                    break;
                case 'jul_activity_deposit':  //0元升级年卡，699年卡权益免费得活动9.9元次卡领取赠送时长补交699年卡押金
                    return (new JulActivityService())->activityDepositNotify($outTradeNo);
                    break;
                default:
                    //0元升级年卡，699年卡权益免费得活动,过期押金，生成699新年卡
                    if(strstr($attach, 'jul_activity_dc_')){
                        $arr = explode('_', $attach);
                        return (new JulActivityService())->activityDepositCardNotify($outTradeNo, $arr[3] ?? 0);
                    }
                    return false;
                    break;
            }
        } else {
            return false;
        }
    }

    /**手动支付回调
     * @param $request
     * @return array
     * @author Poison
     * @date 2020/12/17 2:43 下午
     */
    public function manualNotify($request)
    {
        $type = $request['type'];
        $outTradeNo = $request['out_trade_no'];
        if (!$outTradeNo) {
            LogSave::channel('pay')->info('out_trade_no不能为空');
            return $this->error(0, 'out_trade_no不能为空');
        }
        $t = $request['time'] ?? time();
        switch ($type) {
            case 'card'://年卡
                $res = $this->userCardHandle($outTradeNo, $t);
                break;
            case 'order'://快递费
                $res = $this->orderHandle($outTradeNo);
                break;
            case 'partner'://储值卡
                $res = $this->userPartnerHandle($outTradeNo);
                break;
            case 'goods'://书籍赔付
                $res = $this->goodsHandle($outTradeNo);
                break;
            case 'activity':
                $res = $this->activityHandle($outTradeNo);
                break;
            default:
                return $this->error(100, '参数错误');
                break;
        }
        if (!$res) {
            return $this->error(100, '操作失败');
        }
        return $this->success(200, '操作成功');
    }

    /**年卡支付回调
     * @param string $outTradeNo
     * @param int $t
     * @return bool
     * @author Poison
     * @date 2020/12/17 10:46 上午
     */
    public function userCardHandle(string $outTradeNo, int $t)
    {
        //获取支付前记录
        $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 5])->findOrEmpty()->toArray();
//        if (!$payLog) {
//            LogSave::channel('pay')->info('年卡支付记录不存在,out_trade_no:' . $outTradeNo);
//            self::sendDingError('年卡支付记录不存在,out_trade_no:', $outTradeNo);
//            return false;
//        }
//        // 判断是否为已支付 0  未支付 1 已支付
//        if ($payLog['is_paid'] == 1) {
//            LogSave::channel('pay')->info('年卡支付记录已支付,out_trade_no:' . $outTradeNo);
//            self::sendDingError('年卡支付记录已支付,out_trade_no:', $outTradeNo);
//            return true;
//        }
//        //查询年卡记录表
        $userCardDetailsId = substr($outTradeNo, 2, strpos($outTradeNo, 'C') - 2);
        $userCardDetails = (new UserCardDetailsModel())->where('id', $userCardDetailsId)->findOrEmpty()->toArray();
//        if (!$userCardDetails) {//没有写入
//            LogSave::channel('pay')->info('年卡明细信息不存在,out_trade_no:' . $outTradeNo);
//            self::sendDingError('年卡明细信息不存在,out_trade_no:', $outTradeNo);
//            return false;
//        }

        $isActivity = 0;//是否开启活动 0不开启
        //2020-11-5 增加判断 是否在指定日期内
        if ($userCardDetails['is_double'] == 1) {
            $isActivity = 1;
        }
        try {
            $userId = $userCardDetails['user_id'];
            $user = (new UserModel())->getOneUser($userId);
            // 1.添加用户年卡信息
            $cards = (new CardsModel())->where('id', $userCardDetails['card_id'])->findOrEmpty()->toArray();
            $outTradeNoArr = explode('-', $outTradeNo);
            $beforeUserCardId = 0;
            if (count($outTradeNoArr) > 1) {
                if ($outTradeNoArr[1] == 'GRADE') {

                } else {
                    $beforeUserCardId = $outTradeNoArr[1];
                }
            }
            //2020-11-5 年卡没有激活 进行日志记录
            if ($isActivity == 1) {
                $userCardsData['is_activity'] = 1;
                $userCardsData['is_double'] = 2;
                if ($user['grade'] > 0) {
                    //用户日志中记录 该用户参加了双倍 但是没有激活
                    //写入用户年卡操作日志
                    UsersLogModel::addUsersLog([
                        'user_id' => $userId,
                        'act_note' => '"用户 双倍活动中 购买年卡，未激活"',
                        'add_time' => time(),
                        'act_type' => 4,
                        'user_name' => $user['nickname'] ?? $user['user_name']
                    ]);
                }
            }
            // 获取redis中的值 处理拼团活动
            $outTradeNoArray = $this->getCache('card_group' . $outTradeNo);
            $outTradeNoArrayPing = $this->getCache('card_open' . $outTradeNo);
            if ($outTradeNoArrayPing || $outTradeNoArray) {//如果订单号中包含特定的 增加一个月的使用期限
                $userCardsData['is_group'] = 1;//是否拼团
            }

            $isActivate = $this->getCache('deposit_upgrade_card_activate_' . $userId) ?? 0;
            //1.写入年卡信息
            $userCardsData['user_id'] = $userId;
            $userCardsData['card_id'] = $userCardDetails['card_id'];
            $userCardsData['surplus_ship_num'] = $cards['ship_num'];
            $userCardsData['is_offline_borrow_all'] = $cards['is_offline_borrow_all'];
            if ($isActivate == 1) {//如果是押金转年卡直接开通
                $userCardsData['start_time'] = $t;
                $userCardsData['end_time'] = $t + $userCardDetails['card_open_time'];
                $userCardsData['is_lock'] = 0;
            } else {
                $userCardsData['start_time'] = $user['grade'] > 0 ? 0 : $t;
                $userCardsData['end_time'] = $user['grade'] > 0 ? 0 : $t + $userCardDetails['card_open_time'];
                $userCardsData['is_lock'] = $user['grade'] > 0 ? 1 : 0;
            }
            //查询未过期的年卡
            $openCardTime = $userCardDetails['card_open_time'];
            $isReview = false;
            $outEndTime = 0;
            $detailData = [];
            //处理续费
            if (self::getCache('renew_card_pay_' . $userId) == $userId) {
                //原始卡
                $userCardInfo = UserCardsModel::getOneCards($userId);
                if(!$userCardInfo){//如果没有查出来 去查有没有押金
                    $userCardInfo = UserCardsModel::getByUserCardInfo($userId);
                }
                if ($userCardInfo) {
                    $resUserCard = (new UserCardsModel())->updateUserCards(['is_expire' => 2, 'is_lock' => 0, 'update_time' => time(),'end_time'=>time(),'start_time'=>$userCardInfo['create_time']], ['id' => $userCardInfo['id']]);
                    if (!$resUserCard) {
                        self::sendDingError('out_trade_no:' . $outTradeNo, '旧年卡过期失败');
                        return false;
                    }
                    //记录年卡过期时长变动日志
                    $old_card = CardsModel::getById($userCardInfo['card_id']);
                    $old_card_detail = UserCardDetailsModel::getByUserCardId($userCardInfo['id']);
                    UserCardChangeLogModel::create([
                        'user_id' => $userId,
                        'user_card_id' => $userCardInfo['id'],
                        'card_id' => $userCardInfo['card_id'],
                        'card_name' => $old_card['name'],
                        'change_duration' => sprintf('-%d天', ceil((($userCardInfo['is_lock'] == 1) ? $old_card_detail['card_open_time'] : ($userCardInfo['end_time'] - time())) / 86400)),
                        'change_name' => '年卡续费_过期原卡',
                        'change_reason' => 3,
                        'relation_mobile' => $user['mobile'],
                        'change_low_start_time' => $userCardInfo['start_time'],
                        'change_low_end_time' => $userCardInfo['end_time'],
                        'change_new_start_time' => $userCardInfo['create_time'],
                        'change_new_end_time' => time(),
                        'create_time' => time()
                    ]);
                    $isReview = true;
                    //写入过期日志
                    LogService::userLog($user['user_name'], $userId, '用户购买新年卡 旧年卡失效，押金+剩余时长转移到新年卡', 0, 5, 0);
                    $userCardsData['surplus_ship_num'] += $userCardInfo['surplus_ship_num'];

                    $detailData = UserCardDetailsModel::getByUserCardId($userCardInfo['id'] ?? 0);
                    $outEndTime = $detailData['card_open_time'] / 86400;
                    if ($userCardsData['is_lock'] == 0 || $userCardInfo['end_time'] > 0) {//如果未锁定 或之前年卡处于激活状态 自动激活年卡
                        $outEndTime = $userCardInfo['end_time'];
                        $userCardsData['is_lock'] = 0;
                        $userCardsData['start_time'] = $t;
                        self::outDeposit($outEndTime,$endTimes);
                        $userCardsData['end_time'] = $userCardDetails['card_open_time'] + $t + ($endTimes < 0 ? 0 : $endTimes);

                    }
                    LogService::userCardOperationLog($userId, $userCardInfo['id'], sprintf("转出年卡【ID：%s】,扣除剩余时长%s天,转出年卡押金【¥%s】至新年卡", $userCardInfo['id'], $outEndTime, $detailData['card_deposit']));
                    $newOpenTime = $detailData['pay_time'] + $detailData['card_open_time'] - time();
                    $newOpenTime = $newOpenTime < 0 ? 0 : $newOpenTime;
                    $openCardTime += $newOpenTime;
                }
            }
            $userCardsData['before_user_card_id'] = $beforeUserCardId;
            $userCardsData['create_time'] = $t;
            $userCardsData['update_time'] = $t;
            $userCardsData['card_type'] = $cards['card_type'];
            $userCardsData['experience_card_id'] = $userCardDetails['experience_card_id'];
            $userCardsData['is_offline_borrow_all'] = $cards['is_offline_borrow_all'];
            $userCardId = (new UserCardsModel())->insert($userCardsData, true);
            if (!$userCardId) {
                LogSave::channel('pay')->info('用户年卡信息添加失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('用户年卡信息添加失败,out_trade_no:' . $outTradeNo, '');
                return false;
            }
            $outDeposit = self::getCache('out_card_deposit_message_' . $userId);
            if ($isReview) {
                //写入续费表
                $payMoney = $outDeposit?$payLog['order_amount'] : $payLog['order_amount'] + $userCardDetails['card_deposit'];
                $resReview = (new UserCardReviewModel())->insertReview($userId, $userCardDetails['card_id'],$userCardDetails['money'], $payMoney, $userCardId, $outTradeNo);
                if (!$resReview) {
                    LogSave::channel('pay')->info('写入续费表失败,out_trade_no:' . $outTradeNo);
                    self::sendDingError('写入续费表失败,out_trade_no:' . $outTradeNo, '');
                    return false;
                }
                //记录年卡续费时长变动日志
                UserCardChangeLogModel::create([
                    'user_id' => $userId,
                    'user_card_id' => $userCardId,
                    'card_id' => $userCardDetails['card_id'],
                    'card_name' => $cards['name'],
                    'change_duration' => sprintf('+%d天', ceil((($userCardsData['is_lock'] == 1) ? $openCardTime : ($userCardsData['end_time'] - $userCardsData['start_time'])) / 86400)),
                    'change_name' => '年卡续费',
                    'change_reason' => 3,
                    'relation_mobile' => $user['mobile'],
                    'change_low_start_time' => $userCardInfo['start_time'],
                    'change_low_end_time' => $userCardInfo['end_time'],
                    'change_new_start_time' => $userCardsData['start_time'],
                    'change_new_end_time' => $userCardsData['end_time'],
                    'create_time' => time()
                ]);
                LogService::userCardOperationLog($userId, $userCardId, sprintf("转入年卡【ID：%s】,增加年卡时长%s天,转入年卡押金【¥%s】", $userCardId, $outEndTime, $detailData['card_deposit']));
            }
            // 2.更新年卡明细支付状态:支付金额,支付时间,用户年卡信息ID,交易订单编号
            $userCardDetailsUpdate = (new UserCardDetailsModel())->where('id', $userCardDetailsId)->update(['pay_money' => $payLog['order_amount'], 'pay_time' => time(), 'user_card_id' => $userCardId, 'out_trade_no' => $outTradeNo,'card_open_time'=>$openCardTime]);
            if (!$userCardDetailsUpdate) {
                LogSave::channel('pay')->info('年卡明细状态更新失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '年卡明细状态更新失败');
                return false;
            }
            //2.0.1 处理押金转年卡支付后 移除押金记录
            $isDepositUpgradeCard = $this->getCache('deposit_upgrade_card_' . $userId) ?? 0;
            if ($isDepositUpgradeCard == 1) {
                if ($this->getCache('deposit_add_100_' . $userId) > 0) {
                    $payLog['order_amount'] += 100;
                }
                $resDeposit = (new DepositService())->deleteData((int)$userId, 2, $outTradeNo, $payLog['order_amount'], $cards['price']);
                if ($resDeposit['code'] != 200) {
                    self::sendDingError('out_trade_no:' . $outTradeNo, $resDeposit['message']);
                    return false;
                }
            }
            //2.1赠送年卡时长 2021-2-1 关闭赠送
//            (new UserCardService())->cardGiveTime($userId,$userCardDetails['card_id'],$userCardId);
            // 3.更新支付记录支付状态
            $payLogUpdate = (new PayLogModel())->where('id', $payLog['id'])->update(['is_paid' => 1, 'paid_time' => time()]);
            if (!$payLogUpdate) {
                LogSave::channel('pay')->info('支付记录状态更新失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '支付记录状态更新失败');
                return false;
            }
            //3.1 修改上一张押金清空
            if ($outDeposit) {
                (new UserCardDetailsModel())->update(['card_deposit_status' => 2, 'transfer_card_deposit' => $detailData['card_deposit'] ?? 0, 'transfer_deposit_id' => $userCardId, 'update_time' => time()], ['user_card_id' => $outDeposit]);
                LogService::userLog($user['user_name'], $userId, '用户旧年卡押金转移到新年卡', 0, 5, 0);
            }
            //记录年卡操作日志
            UserCardsOperationLogsModel::create([
                'user_id' => $userId,
                'user_type' => 2,
                'user_card_id' => $userCardId,
                'log_info' => sprintf('用户【ID：%d】支付购买%s,支付单号【%s】', $userId, $cards['name'], $outTradeNo),
                'create_time' => $t,
            ]);

            //非续卡，正常开卡记录时长变动日志
            if (!$isReview) {
                UserCardChangeLogModel::create([
                    'user_id' => $userId,
                    'user_card_id' => $userCardId,
                    'card_id' => $userCardDetails['card_id'],
                    'card_name' => $cards['name'],
                    'change_duration' => sprintf('+%d天', ceil($openCardTime / 60 / 60 / 24)),
                    'change_name' => '正常办卡',
                    'change_reason' => 1,
                    'relation_mobile' => $user['mobile'],
                    'change_low_start_time' => 0,
                    'change_low_end_time' => 0,
                    'change_new_start_time' => $userCardsData['start_time'],
                    'change_new_end_time' => $userCardsData['end_time'],
                    'create_time' => time()
                ]);
            }

            //处理拼团信息
            $resUserCardGroup = (new UserCardService())->setPayActivity($outTradeNo, $userId, $userCardId, $payLog['order_amount'], $userCardDetailsId);
            if ($resUserCardGroup['code'] == 100) {
                self::sendDingError('拼团失败 ---》回调异常', $resUserCardGroup['message']);
            }
            //4.处理购买年卡赠送押金时长 2020-12-23
            //2021-2-1 关闭赠送
//            $resDeposit = (new DepositService())->UserDeposit($user,$userCardDetails['money']);
//            LogSave::channel('pay')->info('处理年卡送押金执行完成');
            //5.处理掌灯人数据
            $resBusinessServiceData = (new BusinessService())->InsertUSer($userId);
            if ($resBusinessServiceData['code'] != 200) {
                self::sendDingError('out_trade_no:' . $outTradeNo, $resBusinessServiceData['message']);
            }
            LogSave::channel('pay')->info('掌灯人处理完成');
            //获取是否到达指定时间 发送活动短信
            $activityStartTime = strtotime('2021-3-6 09:00:00');
            $activityEndTime = strtotime('2021-3-12 23:59:59');
            if($activityStartTime < $t && $activityEndTime > $t){
                (new ALiYunSendSms())->sendSms($user['mobile'],config('ali_config.send_msg.pay_card_send'),['card_name'=>$cards['name']]);
            }

            //体验卡
            if($userCardDetails['experience_card_id'] > 0){
                $experience_card_detail = UserCardDetailsModel::getByUserCardId($userCardDetails['experience_card_id']);
                //更新体验卡为已转年卡
                UserCardDetailsModel::where('user_card_id', $userCardDetails['experience_card_id'])->update(['is_buy_card' => 2, 'card_deposit' => 0, 'update_time' => time()]);
                UserCardsModel::where('id', $userCardDetails['experience_card_id'])->update(['is_buy_card' => 2, 'update_time' => time(), 'is_expire' => 2, 'end_time' => time()]);
                //记录日志
                (new UserCardsOperationLogsModel())->insertAll([
                    [
                        'user_id' => $userId,
                        'user_type' => 2,
                        'user_card_id' => $userCardDetails['experience_card_id'],
                        'log_info' => sprintf('体验卡转年卡【ID：%d】，扣除体验卡剩余时长%d天，转移体验卡押金【¥ %.2f】至新年卡', $userCardId, $userCardDetails['experience_add_card_days'], $experience_card_detail['card_deposit']),
                        'create_time' => time(),
                    ],
                    [
                        'user_id' => $userId,
                        'user_type' => 2,
                        'user_card_id' => $userCardId,
                        'log_info' => sprintf('体验卡【ID：%d】转年卡，新增时长%d天，转入押金【¥ %.2f】', $userCardDetails['experience_card_id'], $userCardDetails['experience_add_card_days'], $experience_card_detail['card_deposit']),
                        'create_time' => time(),
                    ]
                ]);
            }

            //处理使用优惠券
            if($userCardDetails['coupon_id'] > 0){
                $use_coupon = UserCouponModel::where('id', $userCardDetails['coupon_id'])->update(['is_used' => 1, 'used_time' => time()]);
                if (!$use_coupon) {
                    LogSave::channel('pay')->info('年卡使用优惠券状态更新失败,out_trade_no:' . $outTradeNo);
                    self::sendDingError('out_trade_no:' . $outTradeNo, '年卡使用优惠券状态更新失败');
                    return false;
                }
            }

            //处理使用体验卡
            if($userCardDetails['experience_id'] > 0){
                $use_experience = UserCouponModel::where('id', $userCardDetails['experience_id'])->update(['is_used' => 1, 'used_time' => time()]);
                if(!$use_experience){
                    LogSave::channel('pay')->info('年卡使用体验卡状态更新失败,out_trade_no:' . $outTradeNo);
                    self::sendDingError('out_trade_no:' . $outTradeNo, '年卡使用体验卡状态更新失败');
                    return false;
                }
            }
            //处理购买年卡赠送积分
            if($cards['card_type'] == 1){
                (new PointsService())->vipGivePoints($userId,$userCardId);
            }
            //删除限制掌灯人入口查看
            $this->getRedis()->del('lock_business_look_' . $userId);

            //七月邀请买赠活动（0元升级年卡，699年卡权益免费得）
            self::checkJulActivity($cards['id'], $userCardId, $userId);

            //6.成功后叮叮通知
            // TODO 2020-09-01 回调中加入叮叮通知
            $cardsCount = (new UserCardsModel())->where('user_id', $userId)->count();
            if ($user['grade'] == 0 && $cardsCount <= 1) {
                $serviceText = "有新用户办卡，请及时回访 \n\n 手机号：" . $user['mobile'];
                self::sendDing($serviceText, '', config('ding_config.card_ok_send'));
            }
            //通知完成
            // 5.充值成功发送小程序订阅消息
            if ($user['smart_openid']) {
                $chargeInfo['user_id'] = $user['user_id'];
                if ($user['mobile']) {
                    $chargeInfo['mobile'] = substr($user['mobile'], 0, 3) . '****' . substr($user['mobile'], 7);
                }
                //2021-4-23 修改通知消息
                $amount = $payLog['order_amount'] + $userCardDetails['card_deposit'];

                if($outDeposit || $this->getCache('deposit_add_100_' . $userId)){
                    $amount = $payLog['order_amount'];
                    $this->getRedis()->del('out_card_deposit_message_' . $userId);
                }
                $chargeInfo['pay_time'] = date('Y-m-d H:i');
                $chargeInfo['amount'] = $amount . '元';
                $chargeInfo['remark'] = $user['grade'] > 0 ? '您的年卡尚未激活' : '您的年卡过期时间' . date('Y-m-d', $t + $cards['card_duration']);
                if($isActivate){
                    if($payLog['order_amount'] == 0){
                        $chargeInfo['amount'] = 0;
                    }
                    $chargeInfo['remark'] = '您的年卡过期时间' . date('Y-m-d', $t + $cards['card_duration']);
                }
                $smartSendResult = (new SmartSendService())->cardOkSendMessage($user['smart_openid'], $chargeInfo, 1);
                if (!$smartSendResult) {
                    LogSave::channel('pay')->info('年卡办理成功发送小程序提醒失败,out_trade_no:' . $outTradeNo);
                }
            }
            $this->getRedis()->del('renew_card_pay_' . $userId);
            LogSave::channel('pay')->info('走完了 返回');
            return true;
        } catch (\Exception $ex) {
            self::sendDingError('回调手动抛出异常,订单号:' . $outTradeNo, $ex);
            return false;
        }
    }
    protected function outDeposit(&$outEndTime,&$endTimes){
        $endTimes = $outEndTime - time();
        if($endTimes <= 0){
            $outEndTime = 0;
        }else{
            if(($endTimes / 86400) < 1){
                $outEndTime = 0;
            }else{
                $outEndTime = floor($endTimes / 86400);
            }
        }
    }

    /**订单支付回调
     * @param $outTradeNo 支付单号
     * @return bool 返回值
     * @author Poison
     * @date 2020/12/17 3:07 下午
     */
    public function orderHandle($outTradeNo)
    {
        // 订单支付记录
        $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 1])->findOrEmpty()->toArray();
        if (!$payLog) {
            self::sendDingError('订单支付记录不存在,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('订单支付记录不存在,out_trade_no:' . $outTradeNo);
            return false;
        }
        // 判断订单支付记录是否为已支付:0未支付,1已支付
        if ($payLog['is_paid'] == 1) {
            self::sendDingError('订单支付记录已支付,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('订单支付记录已支付,out_trade_no:' . $outTradeNo);
            return true;
        }
        //启动事务
        Db::startTrans();
        try {
            $orderId = $payLog['order_id'];
            $orderData['order_status'] = 1;
            $orderData['pay_status'] = 1;
            $orderData['pay_time'] = time();
            $resOrder = (new OrderModel())->where('order_id', $orderId)->update($orderData);
            if (!$resOrder) {
                self::sendDingError('out_trade_no:' . $outTradeNo, '订单状态更新失败');
                LogSave::channel('pay')->info('订单状态更新失败,out_trade_no:' . $outTradeNo);
                Db::rollback();
                return false;
            }
            $orderInfo = OrderModel::getOneOrderData(['order_id' => $payLog['order_id']], 'order_id,user_id,order_status');
            $userInfo['user_id'] = $orderInfo['user_id'];
            $userInfo['user_name'] = (new UserModel())->getOneUser($orderInfo['user_id'], 1, 'user_name');
            $orderLogResult = OrderService::createLog($orderInfo, $userInfo, '用户支付订单费用并修改订单状态', '更新订单状态', 0);
            if (!$orderLogResult) {
                Db::rollback();
                LogSave::channel('pay')->info('订单日志添加失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '订单日志添加失败');
                return false;
            }
            // 更新支付日志状态为已支付
            $payLogResult = (new PayLogModel())->where('id', $payLog['id'])->update(['is_paid' => 1, 'paid_time' => time()]);
            if (!$payLogResult) {
                Db::rollback();
                LogSave::channel('pay')->info('支付日志状态更新失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '支付日志状态更新失败');
                return false;
            }
            //2020-9-24 更新京东订单
            (new JdOrderGoodsModel())->where('order_id', $orderId)->update(['status' => 1, 'update_time' => time()]);
            Db::commit();
            return true;
        } catch (\Exception $ex) {
            self::sendDingError('手动抛出异常,out_trade_no:' . $outTradeNo, $ex);
            Db::rollback();
            return false;
        }
    }

    /**充值卡充值回调
     * @param $outTradeNo
     * @return bool
     * @author Poison
     * @date addTime
     */
    public function userPartnerHandle($outTradeNo)
    {
        // 订单支付记录
        $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 3])->findOrEmpty()->toArray();
        if (!$payLog) {
            self::sendDingError('储值卡支付记录不存在,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('储值卡支付记录不存在,out_trade_no:' . $outTradeNo);
            return false;
        }
        // 判断订单支付记录是否为已支付:0未支付,1已支付
        if ($payLog['is_paid'] == 1) {
            self::sendDingError('储值卡支付记录已支付,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('储值卡支付记录已支付,out_trade_no:' . $outTradeNo);
            return true;
        }
        $userId = $payLog['user_id'];
        //启动事务
        Db::startTrans();
        try {
            // 更新储值卡充值记录状态
            $partnerLogResult = (new PartnerLogModel())->update(['can_use' => 1], ['id' => $payLog['order_id']]);
            if (!$partnerLogResult) {
                Db::rollback();
                self::sendDingError('out_trade_no:' . $outTradeNo, '储值卡充值记录更新失败');
                LogSave::channel('pay')->info('储值卡充值记录更新失败,out_trade_no:' . $outTradeNo);
                return false;
            }
            // 判断是否有充值赠送金额
            $partnerLogExtra = (new PartnerLogModel())->where('p_id', $payLog['order_id'])->findOrEmpty()->toArray();
            if ($partnerLogExtra) {
                // 更新储值卡充值赠送记录状态
                $partnerLogExtraResult = (new PartnerLogModel())->update(['can_use' => 1], ['p_id' => $payLog['order_id']]);
                if (!$partnerLogExtraResult) {
                    Db::rollback();
                    LogSave::channel('pay')->info('储值卡充值赠送记录更新失败,out_trade_no:' . $outTradeNo);
                    self::sendDingError('out_trade_no:' . $outTradeNo, '储值卡充值赠送记录更新失败');
                    return false;
                }
            }
            // 充值金额
            $partnerAmount = $payLog['order_amount'];
            // 赠送金额
            $partnerAmountExtra = $partnerLogExtra['amount'];
            // 更新用户储值卡金额
            $userPartnerResult = (new UserPartnerModel())->where('user_id', $userId)->inc('amount', $partnerAmount + $partnerAmountExtra)->update();
            if (!$userPartnerResult) {
                Db::rollback();
                LogSave::channel('pay')->info('用户储值卡金额更新失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '用户储值卡金额更新失败');
                return false;
            }
            // 更新储值卡支付记录
            $payLogResult = (new PayLogModel())->update(['is_paid' => 1, 'paid_time' => time()], ['id' => $payLog['id']]);
            if (!$payLogResult) {
                Db::rollback();
                LogSave::channel('pay')->info('储值卡充值支付记录更新失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '储值卡充值支付记录更新失败');
                return false;
            }
            Db::commit();
            $user = (new UserModel())->getOneUser((int)$userId);
            // 充值成功发送小程序订阅消息
            if ($user['smart_openid']) {
                $chargeInfo['user_id'] = $user['user_id'];
                if ($user['mobile']) {
                    $chargeInfo['mobile'] = substr($user['mobile'], 0, 3) . '****' . substr($user['mobile'], 7);
                }
                $chargeInfo['pay_time'] = date('Y-m-d H:i');
                $chargeInfo['amount'] = $partnerAmount . '元';
                $remark = '您的储值卡充值' . intval($partnerAmount) . '元';
                if ($partnerAmountExtra) {
                    $remark .= '，赠送' . intval($partnerAmountExtra) . '元';
                }
                $chargeInfo['remark'] = $remark;
                $smartSendResult = (new SmartSendService())->cardOkSendMessage($user['smart_openid'], $chargeInfo);
                if (!$smartSendResult) {
                    LogSave::channel('pay')->info('储值卡充值成功发送小程序提醒失败,out_trade_no:' . $outTradeNo);
                }
            }
            return true;
        } catch (\Exception $ex) {
            Db::rollback();
            self::sendDingError('手动抛出异常,out_trade_no:' . $outTradeNo, $ex);
            return false;
        }
    }

    /**订单赔付回调处理
     * @param $outTradeNo
     * @return bool
     * @author Poison
     * @date 2020/12/18 9:08 下午
     */
    private function goodsHandle($outTradeNo)
    {
//        LogSave::channel('pay')->info('我进来了,out_trade_no:' . $outTradeNo);
        // 订单支付记录
        $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 4])->findOrEmpty()->toArray();
        if (!$payLog) {
            self::sendDingError('赔付支付记录不存在,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('赔付支付记录不存在,out_trade_no:' . $outTradeNo);
            return false;
        }
        // 判断订单支付记录是否为已支付:0未支付,1已支付
        if ($payLog['is_paid'] == 1) {
            self::sendDingError('赔付支付记录已支付,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('赔付支付记录不存在,out_trade_no:' . $outTradeNo);
            return true;
        }
        $userId = $payLog['user_id'];
        //启动事务
        Db::startTrans();
        try {
            $tmp = explode('-', $outTradeNo);
            $recId = $tmp[0];
            $orderId = $tmp[1];
            $goodsId = $tmp[2];
            $orderGoodsModel = new OrderGoodsModel();
            $goodsInfo = $orderGoodsModel->where('rec_id', $recId)->findOrEmpty()->toArray();
            $userId = $goodsInfo['user_id'];
            $goodsNum = $goodsInfo['goods_num'];
            $order = (new OrderModel())->field('order_sn,store_id, is_exception, order_status')->where('order_id', $orderId)->findOrEmpty()->toArray();
            $storeId = $order['store_id'];
            // 1.更新订单商品状态:是否归还-已归还,是否完成-已完成,是否赔付-已赔付
            $orderGoodsData['is_repay'] = 2;// 已归还
            $orderGoodsData['is_end'] = 1;// 已完成
            $orderGoodsData['is_paid'] = 1;// 已赔付
            $orderGoodsData['is_lack'] = 0;  //缺书已赔付
            $orderGoodsData['return_scanning_time'] = time();
            $orderGoodsResult = $orderGoodsModel->update($orderGoodsData, ['rec_id' => $recId]);
            //书籍赔付后更新还书记录为已还
           ReturnGoodsModel::where('rec_id', $recId)->update(['is_end' => 1, 'end_time' => time()]);
            //记录订单日志
            $user = (new UserModel())->getOneUser($userId);
            $order_log = (new LogService())->orderLog(
                ['order_id' => $orderId, 'order_status' => $order['order_status']],
                ['user_id' => $userId, 'user_name' => $user['user_name']],
                '【小程序】用户赔付书籍：'.$goodsInfo['goods_name'], '书籍赔付', 9);
            
            if (!$orderGoodsResult || !$order_log) {
                Db::rollback();
                self::sendDingError('out_trade_no:' . $outTradeNo, '订单商品状态更新失败');
                LogSave::channel('pay')->info('订单商品状态更新失败,out_trade_no:' . $outTradeNo);
                return false;
            }
            $goodsCode = $orderGoodsModel->where('rec_id', $recId)->value('good_code');
            // 2.判断书籍小码是否存在,存在则更新一书一码表状态和库存信息,否则不做操作
            if ($goodsCode) {
                // 2.1.更新一书一码表状态:是否锁定-锁定,是否赔付-是,赔付时间
                $goodsStockDetailsData['is_lock'] = 2;
                $goodsStockDetailsData['is_paid'] = 1;
                $goodsStockDetailsData['paid_time'] = time();
                $gsdResult = (new GoodStockDetailsModel())->updateDataDetails($storeId, $goodsStockDetailsData, ['good_id' => $goodsId, 'good_code' => $goodsCode]);
                if (!$gsdResult) {
                    Db::rollback();
                    self::sendDingError('out_trade_no:' . $outTradeNo, '订单商品对应一书一码状态更新失败');
                    LogSave::channel('pay')->info('订单商品对应一书一码状态更新失败,out_trade_no:' . $outTradeNo);
                    return false;
                }
                // 2.2.减少赔付商品对应门店总库存
                $countStockResult = (new GoodsStockModel())->decDataCountStock($storeId, $goodsNum, $goodsId);
                if ($countStockResult) {
                    $stock = (new GoodsStockModel())->getValueStock($storeId, ['goods_id' => $goodsId], 'stock');
                    $stockInfo = ['update_stock' => 2, 'old_stock' => $stock, 'new_stock' => $stock, 'beizhu' => '用户赔付书籍总库存减少' . $goodsNum];
                    // 记录库存变动日志
                    $logResult = (new LogService())->stockLog($goodsInfo, ['user_id' => $userId], $stockInfo, $storeId, $order['order_sn']);
                    if (!$logResult) {
                        Db::rollback();
                        LogSave::channel('pay')->info('库存日志添加失败,out_trade_no:' . $outTradeNo);
                        self::sendDingError('out_trade_no:' . $outTradeNo, '库存日志添加失败');
                        return false;
                    }
                } else {
                    Db::rollback();
                    LogSave::channel('pay')->info('总库存更新失败,out_trade_no:' . $outTradeNo);
                    self::sendDingError('out_trade_no:' . $outTradeNo, '总库存更新失败');
                    return false;
                }
            }
            // 3.更新支付日志状态为已支付
            $payLogResult = (new PayLogModel())->update(['is_paid' => 1, 'paid_time' => time()], ['id' => $payLog['id']]);
            if (!$payLogResult) {
                Db::rollback();
                LogSave::channel('pay')->info('支付日志状态更新失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '支付日志状态更新失败');
                return false;
            }
            $price = (new GoodsModel())->where('goods_id', $goodsId)->value('price');
            // 4.添加报损单
            // 4.1.创建报损单
            $goodsLossData['sn'] = ToolsService::createLossSn();
            $goodsLossData['is_buy'] = 0;
            $goodsLossData['store_id'] = $storeId;
            $goodsLossData['admin_id'] = $userId;
            $goodsLossData['admin_id_role'] = '用户';
            $goodsLossData['title'] = '用户赔付,订单编号:' . $order['order_sn'];
            $goodsLossData['reason'] = 5;
            $goodsLossData['price'] = $price;
            $goodsLossData['number'] = $goodsNum;
            $goodsLossData['out_trade_no'] = $outTradeNo;
            $goodsLossData['create_time'] = $goodsLossData['update_time'] = time();
            $goodsLossId = (new GoodsLossModel())->insert($goodsLossData, true);
            if ($goodsLossId) {
                // 4.2.创建报损单详情
                $goodsLossDetailData['goods_loss_id'] = $goodsLossId;
                $goodsLossDetailData['goods_id'] = $goodsId;
                $goodsLossDetailData['price'] = $price;
                $goodsLossDetailData['number'] = $goodsInfo['goods_num'];
                $goodsLossDetailData['good_code'] = $goodsCode;
                $goodsLossDetailData['create_time'] = $goodsLossDetailData['update_time'] = time();
                $goodsLossDetailId = (new GoodsLossDetailModel())->insert($goodsLossDetailData, true);
                if (!$goodsLossDetailId) {
                    Db::rollback();
                    LogSave::channel('pay')->info('报损单详细信息添加失败,out_trade_no:' . $outTradeNo);
                    self::sendDingError('out_trade_no:' . $outTradeNo, '报损单详细信息添加失败');
                    return false;
                }
            } else {
                Db::rollback();
                LogSave::channel('pay')->info('报损单添加失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '报损单添加失败');
                return false;
            }

            //处理异常订单(订单书籍全部赔付更新为已完成)
            self::orderCompensateException($orderId, $userId, $outTradeNo, $order['is_exception']);
            Db::commit();
            return true;
        } catch (\Exception $ex) {
            Db::rollback();
            self::sendDingError('手动抛出异常,out_trade_no:' . $outTradeNo, $ex);
            return false;
        }
    }
    public function activityHandle($outTradeNo){
        // 订单支付记录
        $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 9])->order('id','DESC')->findOrEmpty()->toArray();
        if (!$payLog) {
            self::sendDingError('赔付支付记录不存在,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('赔付支付记录不存在,out_trade_no:' . $outTradeNo);
            return false;
        }
        // 判断订单支付记录是否为已支付:0未支付,1已支付
        if ($payLog['is_paid'] == 1) {
            self::sendDingError('赔付支付记录已支付,out_trade_no:', $outTradeNo);
            LogSave::channel('pay')->info('赔付支付记录不存在,out_trade_no:' . $outTradeNo);
            return true;
        }
        $userId = $payLog['user_id'];
        Db::startTrans();
        try {
            //先修改订单支付记录
            $payLogResult = (new PayLogModel())->update(['is_paid' => 1, 'paid_time' => time()], ['id' => $payLog['id']]);
            if (!$payLogResult) {
                Db::rollback();
                LogSave::channel('pay')->info('门店活动支付回调 支付记录更新失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '门店活动支付回调支付记录更新失败');
                return false;
            }
            //查询数据

            $SignUpData = StoreActivityOrderModel::getOrderModelByOutTradeNo($outTradeNo);
            if(!$SignUpData){
                Db::rollback();
                LogSave::channel('pay')->info('门店活动支付回调 查询记录失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '门店活动支付回调 查询记录失败');
                return false;
            }
            $resUp = (new StoreActivityOrderModel())->where('id',$SignUpData['id'])->update(['pay_time'=>time(),'order_status'=>1,'update_time'=>time()]);
            if(!$resUp){
                Db::rollback();
                LogSave::channel('pay')->info('门店活动支付回调 修改用户报名表失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '门店活动支付回调 修改用户报名表失败');
                return false;
            }
            $array = (new StoreActivitySignUpModel())->where(['order_id'=>$SignUpData['id'],'is_delete'=>0])->select()->toArray();

            //TODO 2021-9-22 降低数量
            $resStoreActivityInfo = (new StoreActivityInfoModel())->where('id',$SignUpData['activity_id'])->dec('surplus_num',count($array))->update();
            if(!$resStoreActivityInfo){
                Db::rollback();
                return errorArray('创建订单失败 错误编码:STORE_ACTIVITY_10001');
            }

            foreach ($array as $v){
                (new StoreActivitySignUpLogsModel())->insert([
                    'user_id'=>$userId,
                    'create_time'=>time(),
                    'log_info'=>'孩子:'.$v['child_name'] .'支付成功',
                    'store_activity_sign_up_id'=>$v['id'],
                    'user_type'=>2
                ]);
            }
            Db::commit();
            return true;
        }catch (\Exception $ex){
            Db::rollback();
            self::sendDingError('门店活动支付回调-手动抛出异常,out_trade_no:' . $outTradeNo, $ex);
            return false;
        }
    }


    /**
     * 亲子活动支付回调
     * @param string $outTradeNo  交易单号
     * @param float $total_fee  支付金额
     * @return bool
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author: yangliang
     * @date: 2021/4/27 17:14
     */
    public function familyHandle(string $outTradeNo, float $total_fee){
        Db::startTrans();
        try {
            //获取支付前记录
            $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 50])->findOrEmpty()->toArray();
            if (!$payLog) {
                throw new ApiException(sprintf('亲子活动支付记录不存在,out_trade_no:%s', $outTradeNo), $outTradeNo);
            }

            // 判断是否为已支付 0  未支付 1 已支付
            if ($payLog['is_paid'] == 1) {
                throw new ApiException(sprintf('亲子活动支付记录已支付,out_trade_no:%s', $outTradeNo), $outTradeNo);
            }

            //更新支付日志为已支付
            $pay_log_res = PayLogModel::where('out_trade_no', $outTradeNo)->update(['is_paid' => 1, 'paid_time' => time()]);
            if(!$pay_log_res){
                throw new ApiException(sprintf('亲子活动订单支付日志更新失败，out_trade_no:%s', $outTradeNo), $outTradeNo);
            }

            //支付成功后业务
            (new FamilyActivityOrderService())->payCallback($outTradeNo, sprintf('%.2f', $total_fee / 100));

        }catch (ApiException $e){
            Db::rollback();
            LogSave::channel('pay')->info($e->getMessage());
            self::sendDingError($e->getMessage(), $e->getArgv());
            return false;
        }

        Db::commit();
        return true;
    }


    /**
     * 七月邀请买赠活动处理
     * 需求：
     *     一个用户只能领取一次赠送时长
     *     活动期间超72小时未与上级死绑（未购卡）更新邀请记录为无效，变更为自然渠道
     *     活动期间邀请记录超72小时未死绑，购卡不赠送时长
     *     活动期间内，购任意年卡关系死绑
     *     活动期间内，未购买指定年卡不赠送时长
     *     活动期间内，下级购买指定年卡直接赠送年卡时长（下级）
     *     活动期间内，下级购买指定年卡，根据上级当前年卡或押金身份预发放时长，（年卡用户送年卡时长、押金用户送押金时长）
     *         注意：此处为预发放时长，需用户手动领取，领取时会重新判断用户身份进行发放。最终以用户领取身份为准发放时长
     *     规则：
     *         押金用户、会员：
     *             邀请好友购9.9元次卡，赠送30天时长
     *             邀请好友购699年卡，赠送180天时长，好友赠送30天时长
     *         9.9元次卡：
     *             邀请好友购9.9元次卡，赠送699年卡30天时长
     *             邀请好友购699年卡，赠送699年卡时长30天，好友赠送30天时长
     * @param int $card_id  年卡ID
     * @param int $user_card_id  用户年卡ID
     * @param int $user_id  用户ID
     * @return bool
     * @author: yangliang
     * @date: 2021/7/15 19:23
     */
    private function checkJulActivity(int $card_id, int $user_card_id, int $user_id): bool
    {
        Db::startTrans();
        try {
            $jul_activity_conf = \config('self_config.jul_activity');
            //验证用户绑定关系
            $relation = JulActivityChannelUserModel::getEffectiveByUserId($user_id);
            //验证活动有效时间，购卡未在活动时间内，不处理、
            //不存在关系，不处理
            if (time() < strtotime($jul_activity_conf['activity_start_time']) || time() > strtotime($jul_activity_conf['activity_end_time']) ||
                empty($relation) || $relation['father_id'] == 0) {
                throw new \Exception('不符合活动条件');
            }

            //记录购卡分佣
            self::julActivityCommission($user_id, $user_card_id, $card_id, $relation['channel_id']);

            //一个用户只能发放一次活动赠送时长
            if($relation['user_give_time'] > 0 || $relation['father_give_time'] > 0){
                throw new \Exception('用户已发放活动时长');
            }

            //存在关系，若超过72小时后，未死绑，将用户渠道置为自然渠道。（被动触发，原则关系72小时内未死绑，都视为无效）
            if (($relation['create_time'] + $jul_activity_conf['keep_time'] * 60 * 60) < time() && $relation['status'] != '2') {
                //超出72小时购卡后，触发用户为自然渠道更新
                $relation_res = JulActivityChannelUserModel::where('id', $relation['id'])->update(['status' => 0, 'channel_id' => 1, 'update_time' => time()]);
                if (!$relation_res) {
                    $msg = '用户购卡后回调处理七月邀请买赠活动，超72小时无效，更新自然渠道失败，用户年卡ID：' . $user_card_id;
                    throw new \Exception($msg, -1);
                }
                throw new \Exception('关系已超保护期，无缘活动');
            }

            //活动时间内，购任意卡关系死绑
            if(intval($relation['status']) == '1'){
                $bind_res = JulActivityChannelUserModel::where('id', $relation['id'])->update(['user_card_id' => $user_card_id, 'status' => '2', 'bind_time' => time(), 'update_time' => time()]);
                if(!$bind_res){
                    throw new \Exception('0元升级年卡，699年卡权益免费得关系绑定失败', -1);
                }
            }


            if(!in_array($card_id, $jul_activity_conf['activity_buy_card_id'])){
                throw new \Exception('未购买活动指定年卡，不赠送时长');
            }

            //活动赠送记录（上级）
            $parent_user = (new UserModel())->getOneUser($relation['father_id']);


            $buyers_gift = 0;
            //购买特殊卡下级不赠送时长
            if($card_id != $jul_activity_conf['secondary_card']['card_id']) {
                //下级购卡赠送时长
                $buyers_gift = $jul_activity_conf['buyers_gift'] * 60 * 60;
                //下级购卡后直接赠送年卡时长
                //更新用户年卡时长
                $user_card = UserCardsModel::getById($user_card_id);
                //年卡未激活，增加年卡时长
                if ($user_card['is_lock'] == 1) {
                    $uc_res = UserCardsModel::where('id', $user_card_id)->inc('give_card_time', $buyers_gift)->update();
                } else {
                    $uc_res = UserCardsModel::where('id', $user_card_id)->inc('give_card_time', $buyers_gift)->inc('end_time', $buyers_gift)->update();
                }

                $ucd_res = UserCardDetailsModel::where('user_card_id', $user_card_id)->inc('card_open_time', $buyers_gift)->update();
                if (!$ucd_res || !$uc_res) {
                    throw new \Exception('0元升级年卡，699年卡权益免费得下级赠送时长失败，用户年卡ID：' . $user_card_id . '，赠送时长：' . $buyers_gift, -1);
                }

                //记录年卡变动时长
                $card = CardsModel::getById($card_id);
                UserCardChangeLogModel::create([
                    'user_id' => $user_id,
                    'user_card_id' => $user_card_id,
                    'card_id' => $card_id,
                    'card_name' => $card['name'],
                    'change_duration' => sprintf('+%d 天', ceil($buyers_gift / 60 / 60 / 24)),
                    'change_name' => '0元升级年卡，699年卡权益免费得',
                    'change_reason' => 7,
                    'relation_mobile' => $parent_user['mobile'],
                    'change_low_start_time' => ($user_card['is_lock'] == 1) ? 0 : $user_card['start_time'],
                    'change_low_end_time' => ($user_card['is_lock'] == 1) ? 0 : $user_card['end_time'],
                    'change_new_start_time' => ($user_card['is_lock'] == 1) ? 0 : $user_card['start_time'],
                    'change_new_end_time' => ($user_card['is_lock'] == 1) ? 0 : ($user_card['end_time'] + $buyers_gift),
                    'create_time' => time()
                ]);

                //更新邀请记录下级赠送时长
                $child_gift_res = JulActivityChannelUserModel::where('id', $relation['id'])->update([
                    'user_give_type' => $card_id,
                    'user_give_time' => $buyers_gift,
                    'update_time' => time(),
                    'status' => 2,  //关系：死绑
                    'bind_time' => time(),  //绑定时间
                ]);
                if(!$child_gift_res){
                    throw new \Exception('0元升级年卡，699年卡权益免费得下级赠送时长失败，用户年卡ID：' . $user_card_id . '，赠送时长：' . $buyers_gift, -1);
                }
            }

            //2021.7.24  新规则邀请两个用户才核算
            $un_settle = JulActivityChannelUserModel::getUnSettleByFatherId($relation['father_id']);
            //未满足核算条件
            if(empty($un_settle)){
                $is_gift_res = JulActivityChannelUserModel::where('id', $relation['id'])->update(['is_gift' => 1, 'update_time' => time()]);
                if (!$is_gift_res) {
                    $msg = '用户购卡后回调处理七月邀请买赠活动，未满足核算记录标识创建失败，用户年卡ID：' . $user_card_id;
                    throw new \Exception($msg, -1);
                }
                throw new \Exception('未满足核算记录创建');
            }

            //验证上级用户当前身份
            $parent_user_card = UserCardsModel::getByUserId($relation['father_id']);

            $data = [
                'user_card_id' => $user_card_id,
                'status' => 2,  //关系：死绑
                'bind_time' => time(),  //绑定时间
                'update_time' => time(),
                'is_gift' => 1,
            ];

            //核算记录
            $settle = [
                'user_id' => $relation['father_id'],
                'user_card_id' => $parent_user_card['id'] ?? 0,
                'status' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'user_num' => 2
            ];

            //2021.7.24  V3版本，1老带2新，1老赠送699年卡时长360天
            //年卡 + 押金用户
            if (!empty($parent_user_card) && $parent_user_card['is_refund'] == 2 &&
                $parent_user['grade'] > 0 && time() < $parent_user['user_deposit_expire_time']) {
                if($parent_user_card['card_id'] == 2){  //899年卡（原699年卡），直接加时长
                    $settle['father_give_type'] = 2;
                    //赠送时长
                    $settle['father_give_time'] = $jul_activity_conf['new_gift_time'] * 60 * 60;
                }else{  //其他年卡直接升级至699年卡(原499年卡)
                    $settle['father_give_type'] = 7;
                    //年卡转增时长
                    $settle['card_give_time'] = $parent_user_card['end_time'] - $parent_user_card['start_time'];
                    //赠送时长（年卡赠送时长 + 原年卡剩余时长）
                    $settle['father_give_time'] = $jul_activity_conf['new_gift_time'] * 60 * 60;
                }

                //押金剩余时长
                $deposit_time = ($parent_user['user_deposit_expire_time'] - time());
                //押金转增时长
                $settle['deposit_give_time'] = $deposit_time;
            }elseif (!empty($parent_user_card) && $parent_user_card['is_refund'] == 2) {  //年卡用户
                if($parent_user_card['card_id'] == 2){  //899年卡（原699年卡），直接加时长
                    $settle['father_give_type'] = 2;
                }else{  //其他年卡直接升级至699年卡(原499年卡)
                    $settle['father_give_type'] = 7;
                    //年卡转增时长
                    $settle['card_give_time'] = $parent_user_card['end_time'] - $parent_user_card['start_time'];
                }
                $settle['father_give_time'] = $jul_activity_conf['new_gift_time'] * 60 * 60;
            }elseif($parent_user['grade'] > 0 && time() < $parent_user['user_deposit_expire_time']){  //押金用户
                //押金用户赠送699年卡时长（原499年卡）
                $settle['father_give_type'] = 7;
                //押金剩余时长
                $deposit_time = ($parent_user['user_deposit_expire_time'] - time());
                //年卡赠送时长 + 押金剩余时长
                $settle['father_give_time'] = $jul_activity_conf['new_gift_time'] * 60 * 60;
                //押金转增时长
                $settle['deposit_give_time'] = $deposit_time;
            }

            //创建核算记录
            $settle_res = JulActivityChannelUserSettleModel::create($settle);
            if(!$settle_res){
                throw new \Exception('0元升级年卡，699年卡权益免费得核算记录创建失败，关系ID：'.$relation['id'], -1);
            }

            //更新死绑关系、上下级赠送时长记录
            $data['settle_id'] = $settle_res->id;
            $jacu_res = JulActivityChannelUserModel::where('id', $relation['id'])->update($data);
            $prev_res = JulActivityChannelUserModel::where('id', $un_settle['id'])->update(['settle_id' => $settle_res->id, 'update_time' => time()]);

            if(!$jacu_res || !$prev_res){
                throw new \Exception('0元升级年卡，699年卡权益免费得关系、上下级赠送时长记录更新失败，关系ID：'.$relation['id'], -1);
            }

        }catch (\Exception $e){
            if($e->getCode() == -1) {
                Db::rollback();
                LogSave::channel('pay')->info($e->getMessage());
                self::sendDingError($e->getMessage(), '');
                return false;
            }else{
                Db::commit();
                return true;
            }
        }

        Db::commit();
        return true;
    }


    /**
     * 七月活动创建用户分佣记录
     * @param int $user_id
     * @param int $user_card_id
     * @param int $card_id
     * @param int $channel_id
     * @throws \Exception
     * @author: yangliang
     * @date: 2021/7/29 15:03
     */
    private function julActivityCommission(int $user_id, int $user_card_id, int $card_id, int $channel_id){
        $arr = self::getJulActivityRelation($user_id, $user_id, $user_card_id, $card_id, [], 1, $channel_id);
        if(!empty($arr)){
            $res = JulActivityChannelUserCommissionModel::insertAll($arr);
            if(!$res){
                throw new \Exception('分佣记录创建失败');
            }
        }
    }


    /**
     * 七月活动查询用户上级信息
     * @param int $user_id 用户ID
     * @param int $child_user  下级用户
     * @param int $user_card_id 用户年卡ID
     * @param int $card_id 年卡ID
     * @param array $arr
     * @param int $depth 关系深度
     * @param int $channel_id  用户渠道
     * @return array
     * @author: yangliang
     * @date: 2021/7/29 15:02
     */
    private function getJulActivityRelation(int $user_id, int $child_user, int $user_card_id, int $card_id, array $arr, int $depth, int $channel_id): array
    {
        $relation = JulActivityChannelUserModel::getPartentUserByUserId($child_user);
        if(!empty($relation)){
            $arr[] = [
                'user_id' => $user_id,
                'parent_id' => $relation['father_id'],
                'depth' => $depth,
                'user_card_id' => $user_card_id,
                'card_id' => $card_id,
                'user_channel_id' => $channel_id,
                'parent_channel_id' => $relation['father_channel_id'],
                'create_time' => time()
            ];

            return self::getJulActivityRelation($user_id, $relation['father_id'], $user_card_id, $card_id, $arr,$depth + 1, $channel_id);
        }

        return $arr;
    }


    /**
     * 订单赔付异常订单处理（正常订单整单赔付进行状态更新）
     * @param int $order_id
     * @param int $user_id
     * @param string $outTradeNo
     * @param int $is_exception
     * @return false
     * @author: yangliang
     * @date: 2021/8/5 10:24
     */
    private function orderCompensateException(int $order_id, int $user_id, string $outTradeNo, int $is_exception){
        //订单书籍
        $order_goods = OrderGoodsModel::getByOrderId($order_id);
        $unpaid_num = 0;    //未赔付书籍
        $lack_num = 0;    //缺书书籍
        $repay_num = 0;    //已还书籍
        $paid_num = 0;    //已赔付书籍
        $order_goods_num = count($order_goods);    //订单书籍数量

        foreach ($order_goods as $og){
            //未赔付书籍数量
            if(($og['is_damage'] == 1 && $og['is_paid'] == 0) || ($og['is_repay'] == 0 && $og['is_paid'] == 0)){
                $unpaid_num++;
            }

            //缺书书籍数量
            if($og['is_lack'] == 1){
                $lack_num++;
            }

            //已还书籍数量
            if($og['is_repay'] == 2 && $og['is_end'] == 1){
                $repay_num++;
            }

            //已赔付书籍数量
            if(($og['is_damage'] == 1 && $og['is_paid'] == 1) || ($og['is_repay'] == 2 && $og['is_paid'] == 1)){
                $paid_num++;
            }
        }

        //无未赔付书籍，无缺书，且还书数量加赔付数量等于订单书籍数量，则取消订单异常标识，更新订单为已完成，更新订单为有还书操作，可配书（不影响专业快递待处理订单显示）
        if($unpaid_num  < 1 && $lack_num < 1 && ($repay_num + $paid_num) >= $order_goods_num){
            $user = (new UserModel())->getOneUser($user_id);
            $order_res = OrderModel::where('order_id', $order_id)->update(['is_exception' => 0, 'order_status' => 9, 'confirm_time' => time(), 'is_can_deal' => 0, 'is_return_book' => 0]);
            $order_log = (new LogService())->orderLog(['order_id' => $order_id, 'order_status' => 9], ['user_id' => 0, 'user_name' => $user['user_name']], '异常订单赔付后无异常，更新订单为已完成，有归还操作，可配书', '', 9);

            //更新专业快递待处理订单为可配书
            OrderModel::where('user_id', $user_id)
                ->where('order_status', 1)
                ->where('shipping_code', 3)
                ->where('is_can_deal', 0)
                ->update(['is_can_deal' => 1]);
            
            if (!$order_res || !$order_log) {
                Db::rollback();
                LogSave::channel('pay')->info('订单赔付后处理异常失败,out_trade_no:' . $outTradeNo);
                self::sendDingError('out_trade_no:' . $outTradeNo, '订单赔付后处理异常失败');
                return false;
            }
        }
    }
}