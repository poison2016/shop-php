<?php


namespace app\common\service;

use app\command\CardGroupOut;
use app\common\model\BusinessOrganUserModel;
use app\common\model\BusinessRuleModel;
use app\common\model\CardGroupInfoModel;
use app\common\model\CardGroupLogModel;
use app\common\model\CardGroupModel;
use app\common\model\CardGroupRefundModel;
use app\common\model\CardGroupRobotModel;
use app\common\model\CardInviteModel;
use app\common\model\CardPriceSetModel;
use app\common\model\CardsModel;
use app\common\model\DepositUpgradeCardModel;
use app\common\model\GoodsModel;
use app\common\model\NewCardRefundDepositModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\DepositModel;
use app\common\model\PayLogModel;
use app\common\model\ReturnGoodsModel;
use app\common\model\UserCardChangeLogModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardLogsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserCardsOperationLogsModel;
use app\common\model\UserCouponModel;
use app\common\model\UserLevelModel;
use app\common\model\UserModel;
use app\common\Tools\ALiYunSendSms;
use app\common\traits\CacheTrait;
use think\facade\Db;
use app\common\model\UserUpgradeModel;
use think\facade\Log;
use wechat\Pay;
////////////////////////////////////////////////////////////////////
//                          _ooOoo_                               //
//                         o8888888o                              //
//                         88" . "88                              //
//                         (| ^_^ |)                              //
//                         O\  =  /O                              //
//                      ____/`---'\____                           //
//                    .'  \\|     |//  `.                         //
//                   /  \\|||  :  |||//  \                        //
//                  /  _||||| -:- |||||-  \                       //
//                  |   | \\\  -  /// |   |                       //
//                  | \_|  ''\---/''  |   |                       //
//                  \  .-\__  `-`  ___/-. /                       //
//                ___`. .'  /--.--\  `. . ___                     //
//              ."" '<  `.___\_<|>_/___.'  >'"".                  //
//            | | :  `- \`.;`\ _ /`;.`/ - ` : | |                 //
//            \  \ `-.   \_ __\ /__ _/   .-` /  /                 //
//      ========`-.____`-.___\_____/___.-`____.-'========         //
//                           `=---='                              //
//      ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^        //
//         佛祖保佑       永无BUG     永不修改     永不重构             //
//         许愿者：Poison                                           //
////////////////////////////////////////////////////////////////////
class UserCardService extends ComService
{
    use CacheTrait;

    public function isUseUserCard($userCard, $goodsNum = 0, $userId, $cart_num = 0)
    {
        $isUpdate = 0;
        $errorMessage = '您的年卡信息异常，暂时无法下单';
        $card = (new CardsModel())->where('id', $userCard['card_id'])->find()->toArray();
        if (!$card) {
            return $this->error(0, $errorMessage);
        }
        if (($userCard['is_expire'] != 1 || $userCard['end_time'] <= time()) && $userCard['is_lock'] == 0) {
            $isUpdate = 1;
            $errorMessage = '您的年卡已过期，暂时无法下单';
        }
        if ($userCard['surplus_ship_num'] == 0) {
            return $this->error(0, '您的年卡配送次数已用尽，暂时无法下单');
        }
        if ($isUpdate) {
            // 如果年卡已过期,更新过期状态
            $userCardUpdate = (new UserCardsModel())->where('id', $userCard['id'])->update(['is_expire' => 2, 'update_time' => time()]);
            if (!$userCardUpdate) {
                return $this->error(0, '年卡过期状态更新失败');
            }
            return $this->error(0, $errorMessage);
        }

        if ($userCard['refund_card_status'] == 1 || $userCard['refund_card_status'] == 2 || $userCard['refund_card_status'] == 3) {
            return $this->error(0, '您的年卡已退卡或正在退卡中');
        }

        //2021.8.16 整单借还，只要借阅书籍超过年卡可借书籍一半以上可下单
        if($cart_num > 0 && $cart_num < ceil($card['book_num'] / 2)){
            return $this->error(0, '请至少选择' . ceil($card['book_num']/2) . '本书借阅');
        }

        //三方物流有还书信息，可下一单
        $return_goods_num = ReturnGoodsModel::getCountByUserId($userId);
        $goodsNum = $goodsNum - (int)$return_goods_num;

        // 判断最多可借书数量
        if ($goodsNum > 0 && $goodsNum > $card['book_num']) {
            return $this->error(0, '您的年卡最多可借' . $card['book_num'] . '本书');
        }

        return $this->success(200, '', $card);
    }

    /**
     * 更新年卡借阅信息
     * @param $userCardId
     * @param $orderId
     * @param int $status
     * @return array
     */
    public function updateUserCard($userCardId, $orderId, $status = 0)
    {
        $order = OrderModel::getByOrderId($orderId);
        //2020.12.22  by yangliang 新增订单来源为线下（一键借还书）不扣除不返还配送次数
        if ($order['source'] == 1) {
            return $this->success(200, '成功');
        }

        //2020.12.22 by yangliang 新增下单时年卡ID，扣除返还次数至下单时年卡，而非新年卡
        $userCardId = ($order['user_card_id'] > 0) ? $order['user_card_id'] : $userCardId;

        // 1.更新用户年卡信息
        $surplusShipNum = (new UserCardsModel())->where('id', $userCardId)->value('surplus_ship_num');
        if ($status == 1) {
            $userCardsUpdateData = [['surplus_ship_num', 1, 1]];
            //2020.12.22 年卡配送次数用尽后不过期年卡
//            if ($surplusShipNum == 0) {
//                $userCardsUpdateData[] = ['is_expire', 1];
//                $userCardsUpdateData[] = ['update_time', time()];
//            }

            $surplusShipNum = $surplusShipNum + 1;
            $describe = '用户取消下单回退一次年卡配送次数';
            $actionType = 2;
        } else {
            $userCardsUpdateData = [['surplus_ship_num', 1, 0]];
            //卡内次数用完不过期年卡
//            if ($surplusShipNum == 1) {
//                $userCardsUpdateData[] = ['is_expire', 2];
//                $userCardsUpdateData[] = ['update_time', time()];
//            }

            $surplusShipNum -= 1;
            $describe = '用户下单扣除一次年卡配送次数';
            $actionType = 1;
        }

        if ($actionType == 1) {
            $userCardsUpdate = (new UserCardsModel())->where('id', $userCardId)->dec('surplus_ship_num')->update();
        } else {
            $userCardsUpdate = (new UserCardsModel())->where('id', $userCardId)->inc('surplus_ship_num')->update();
        }

        if (!$userCardsUpdate) {
            return $this->error(0, '用户年卡信息更新失败');
        }

        // 2.添加年卡配送次数信息
        $userCardLogsData = [
            'user_card_id' => $userCardId,
            'order_id' => $orderId,
            'surplus_ship_num' => $surplusShipNum,
            'describe' => $describe,
            'action_type' => $actionType,
            'create_time' => time(),
            'update_time' => time(),
        ];
        $userCardLogsResult = (new UserCardLogsModel())->insert($userCardLogsData);
        if (!$userCardLogsResult) {
            return $this->error(0, '用户年卡配送次数更新失败');
        }
        return $this->success(200, '成功');
    }


    /**
     * 年卡借阅图书验证
     * @param int $user_id 用户ID
     * @param array $goods_ids 图书ID
     * @param int $source 来源   0-添加购物车    1-其他
     * @return array
     * @author yangliang
     * @date 2020/12/19 11:11
     */
    public function checkUserCardByUserId(int $user_id, array $goods_ids, int $source = 0)
    {
        $user_card = UserCardsModel::getUserCardByUserId($user_id);

        //不存在年卡，验证用户押金，存在押金，使用押金权益
        if (empty($user_card) || $user_card['is_expire'] == 2 || $user_card['is_refund'] == 1) {
            $deposit_res = (new DepositService())->checkDepositUser($user_id, $goods_ids, 0);
            if ($deposit_res['code'] != 200) {
                return $this->error(100, $deposit_res['message']);
            }
            return $this->success(200, '验证成功');
        }

        //存在年卡，但未激活，验证用户押金，存在押金，使用押金权益
        if ($user_card['is_lock'] == 1) {
            $deposit_res = (new DepositService())->checkDepositUser($user_id, $goods_ids, 1);
            if ($deposit_res['code'] != 200) {
                return $this->error(100, $deposit_res['message']);
            }
            return $this->success(200, '验证成功');
        }

        //添加购物车无卡用户不校验（需求：允许加入购物，下单时校验能否借阅）
        if ($source == 0 && empty($user_card)) {
            return $this->success(200, 'success');
        }

        if (empty($user_card['c_id'])) {
            return $this->error(100, '此书籍仅供年卡用户借阅，您可以办理年卡后借阅。');
        }

        if ($user_card['is_expire'] != 1 || $user_card['is_refund'] == 1) {
            return $this->error(100, '您的年卡已过期，您可以重新办理年卡后借阅现在就去');
        }

        if ($user_card['surplus_ship_num'] == 0) {
            return $this->error(100, '您的借阅次数已用完，请重新办理年卡后借阅。');
        }

        $card_borrow[] = 1;  //年卡可借阅书籍种类：默认少儿童书

        if ($user_card['is_picture'] == 2) {  //绘本
            $card_borrow[] = 2;
        }

        if ($user_card['is_family'] == 2) {  //家长读物
            $card_borrow[] = 3;
        }

        if ($user_card['is_english'] == 2) {  //英文绘本
            $card_borrow[] = 4;
        }

        $goods_category = GoodsModel::getByGoodsIds($goods_ids);
        if (empty($goods_category)) {
            return $this->error(100, '商品品类不存在');
        }

        if (count($goods_category) > $user_card['book_num']) {
            return $this->error(100, '该订单已超过您的可借本数（' . $user_card['book_num'] . '本），请您筛选后重新下单。');
        }

        foreach ($goods_category as $v) {
            if (!in_array($v['good_category'], $card_borrow)) {
                $msg = sprintf('该书籍为"%s"，您目前的年卡类型不能借阅此书籍。', GoodsModel::$goods_category[$v['good_category']]);
                return $this->error(100, $msg);
            }
        }

        return $this->success(200, 'success');
    }

    /**年卡支付模块
     * @param array $params
     * @param int $userId
     * @date 2020/12/17 5:45 下午
     */
    public function getUserCardPayment(array $params, int $userId)
    {
        $t = time();
        //删除缓存
        self::delUserRedis($userId);
        $usersData = (new UserModel())->getOneUser($userId);
        $depositExpireDateTimeStamp = $usersData['user_deposit_expire_time'];  // 押金过期时间
        if ($usersData['is_out_pay'] == 1) {//2020-08-25 新增功能 如果用户被限制购卡 提示联系客服
            return $this->error(40001, '您已被限制购卡，如有疑问，请联系客服。');
        }
        $params['card_group_id'] = $params['card_group_id'] ?? 0;
        //TODO 验证是否是否克购买季卡
        $resCheckChannel = self::checkChannel((int)$userId, (int)$params['card_id']);
        if ($resCheckChannel['code'] != 200) {
            return $resCheckChannel;
        }
        $card = (new CardsModel())->where('id', $params['card_id'])->findOrEmpty()->toArray();
        $userCardData = UserCardsModel::getOneCards($userId);
        $userCardDetailsData['action_type'] = 1;
        if(!$userCardData){//如果是续费 屏蔽年卡check 只判断是否有可使用的年卡即可
            //TODO 验证是否可以购买年卡
            $resCheckCard = self::checkCard($userId,$t,$usersData,$depositExpireDateTimeStamp,$card);
            if($resCheckCard['code']!=200){
                return $resCheckCard;
            }
            //判断是否有押金没有退的
            //如果有年卡 判断 是否有年卡退押金
            $resOutDeposit = NewCardRefundDepositModel::getDataFindByUserId($userId);
            if ($resOutDeposit && $resOutDeposit['node'] != 3) {
                return errorArray('您有正在审批的退押金服务，请完成审批后再进行购卡。',3011);
            }
            $resUserCardData = UserCardsModel::getByUserCardInfo($userId);
            if($resUserCardData){
                self::setCache('renew_card_pay_'.$userId,$userId,30 * 60);
                $userCardDetailsData['action_type'] = 6;
            }
        }else{
          //设置redis 标记为新的规则
            self::setCache('renew_card_pay_'.$userId,$userId,30 * 60);
            $userCardDetailsData['action_type'] = 6;
        }

        //初始化数据
        $status = $params['status'] ?? 1;// 办理年卡类型:1正常办卡(默认)2押金转年卡3年卡升级
        $cardId = $params['card_id'];// 办理年卡ID
        $openid = $params['openid'];
        $type = $params['type'];
        $incData = [];
        //2021-4-26 增加单年卡多价格
        $userCardDetailsData['card_open_time'] = $card['card_duration'];
        if(config('self_config.is_card_info') && $params['card_info_id'] > 0){
           $cardSetData =  CardPriceSetModel::getOneData((int)$params['card_info_id']);
            $card['price'] = $cardSetData['price'];
            $userCardDetailsData['card_open_time'] = $cardSetData['year'];
        }
        //TODO 2020-09-15 调用地方太多 进行控件化处理
        $incData['card_name'] = $card['name'];
        $userDeposit = $card['card_deposit'];
        $shouldPrice = $cardPrice = $card['price'];

        $businessCardId = self::getBusinessCardId();//指定分佣Id
        if ($businessCardId == $cardId) {
            //控件化处理支付价格
            $priceData = CardService::getPrice($userId, $card, $params['is_type'] ?? 0);
            $cardPrice = $priceData['price'];
            //设置拼团
            if ($params['card_group_id'] > 0) {
                $groupInfoCount = self::getCountGroup((int)$userId);
                if ($groupInfoCount['code'] == 100) {
                    return self::error(100, $groupInfoCount['message']);
                }
                $cardActivityData = self::getPrice((int)$userId, (int)$params['card_group_id']);
                if (isset($cardActivityData['code']) && $cardActivityData['code'] == 100) {
                    return self::error(100, $cardActivityData['message']);
                }
                $cardPrice = $cardActivityData['price'];
            }
        }
        if ($params['open_league'] == 1) {//拼团模式---》开团
            $resGroupInfoCount = self::getCountGroup((int)$userId);
            if ($resGroupInfoCount['code'] == 100) {
                return self::error(100, $resGroupInfoCount['message']);
            }
            $cardPrice = $card['price'];//开团支付价格
        }
        $payPrice = $cardPrice;
        //获取用户是否有押金
        $isDeposit = 0;
        $isCardDeposit = false;//是否写入押金
        //判断用户上一张年卡是否有押金
        $outCardDeposit = UserCardsModel::getByUserCardInfo($userId);
        if (isset($outCardDeposit) && $outCardDeposit) {
            $newCardRefund = NewCardRefundDepositModel::getDataByUserId($userId,$outCardDeposit['id']);
            if(empty($newCardRefund)){
                self::setCache('out_card_deposit_message_'.$userId,$outCardDeposit['id'],config('self_config.card_group_end_time'));
                $userCardDetailsData['card_deposit'] = $outCardDeposit['card_deposit'];
                if(isset($outCardDeposit['card_deposit']) && $outCardDeposit['card_deposit'] > 0 && $outCardDeposit['transfer_card_deposit'] == 0){
                    if(($userDeposit - $outCardDeposit['card_deposit']) > 0){//如果上一个押金 小于当前年卡押金
                        $payPrice += $userDeposit - $outCardDeposit['card_deposit'];
                        $isCardDeposit = true;
                    }elseif (($userDeposit - $outCardDeposit['card_deposit']) < 0){//如果上一个年卡押金 大于当前年卡押金
                        $payPrice -= $outCardDeposit['card_deposit'] - $userDeposit;
                        $isCardDeposit = true;
                    }
                    goto a;
                }
            }
        }
        if ($status == 2 && config('self_config.deposit_up_card_is_give') != 1) {//跳过增加押金
            goto a;
        }
        $userCardDetailsData['card_deposit'] = $userDeposit;
        $payPrice = $cardPrice + $userDeposit;
        $isDeposit = 1;
        a:
        if($isCardDeposit){//如果有押金操作 重新负值价格 和 新的押金
            //$cardPrice = $payPrice;//这个钱计算到押金内 不计算到年卡支付金额内 所以屏蔽
            $userCardDetailsData['card_deposit'] = $userDeposit;
        }
        $incData['card_price'] = $cardPrice;
        if ($status == 2) {
            $resDeposit = (new DepositService())->depositPrice($userId, $payPrice);
            if ($resDeposit['code'] != 200) {
                return $resDeposit;
            }
            $payPrice = $resDeposit['data']['deposit_type'] == 0 ? $resDeposit['data']['price'] : 0;
            if ($isDeposit == 1) {//如果有押金
                if ($payPrice > 99) {
                    $cardPrice = $payPrice - 100;
                    $this->setCache('deposit_add_100_' . $userId, 100, 6 * 60);
                } elseif ($payPrice == 99) {
                    $cardPrice = 99;
                } else {
                    $cardPrice = 0;
                }
            }
            $incData['deposit_id'] = $resDeposit['data']['deposit_id'] ?? 0;
            $incData['deposit_price'] = $resDeposit['data']['deposit_price'] ?? 0;
            $incData['deposit_name'] = $resDeposit['data']['deposit_name'] ?? '';
            $incData['grade'] = $resDeposit['data']['grade'] ?? 0;
        }

        $userCardDetailsData['extra_money'] = 0;
        $userCardDetailsData['deposit_money'] = 0;
        $userCardDetailsData['user_id'] = $userId;
        $userCardDetailsData['card_id'] = $cardId;
        $userCardDetailsData['money'] = $shouldPrice;
        $userCardDetailsData['pay_money'] = 0;
        $userCardDetailsData['pay_time'] = 0;// 支付时间
        $userCardDetailsData['refund_status'] = 1;
        $userCardDetailsData['create_time'] = $t;
        $userCardDetailsData['update_time'] = $t;
        $userCardDetailsData['card_type'] = $card['card_type'];
        $userCardDetailsData['coupon_price'] = 0;
        //2021.3.12 验证用户优惠券是否可用
        if ($params['coupon_id'] > 0) {
            $user_coupon = $this->checkUserCoupon($userId, $params['coupon_id'], $payPrice);
            if ($user_coupon['status'] == 0) {
                return $this->error(100, $user_coupon['msg']);
            }
            //支付金额扣除优惠券金额
            $payPrice -= $user_coupon['price'];
            $cardPrice -= $user_coupon['price'];  //实际支付金额
            $userCardDetailsData['coupon_price'] = $user_coupon['price'];  //优惠券金额
        }

        //2021.3.12  验证用户体验卡是否可用
        if ($params['experience_id'] > 0) {
            $user_experience = $this->checkUserExperience($userId, $params['experience_id'], $cardId);
            if ($user_experience['status'] == 0) {
                return $this->error(100, $user_experience['msg']);
            }
            //年卡时长增加体验时长
            $userCardDetailsData['card_open_time'] = $userCardDetailsData['card_open_time'] + ($user_experience['day'] * 24 * 60 * 60);
            $userCardDetailsData['experience_time'] = $user_experience['day'];  //体验时长
        }

        $userCardDetailsData['coupon_id'] = $params['coupon_id'];  //优惠券
        $userCardDetailsData['experience_id'] = $params['experience_id'];  //体验卡
        $userCardDetailsData['user_card_id'] = 0;
        $payData = [];
        switch ($status) {
            case 1://正常办卡
                //体验卡
                list($userCardDetailsData, $payPrice) = $this->ExperienceCard($userCardDetailsData, $userId, $payPrice);
                $userCardDetailsData['describe'] = '用户正常办卡';
                $userCardDetailsId = (new UserCardDetailsModel())->insert($userCardDetailsData, true);
                if (!$userCardDetailsId) {
                    return $this->error(0, '年卡明细添加失败');
                }
                $outTradeNo = 'RD' . $userCardDetailsId . 'CARD' . rand(10000, 99999);
                if ($params['card_group_id'] > 0) {
                    $this->setCache('card_group' . $outTradeNo, (int)$params['card_group_id'], config('self_config.card_group_end_time'));
                }
                if ($params['open_league'] == 1) {
                    $this->setCache('card_open' . $outTradeNo, $cardPrice, config('self_config.card_group_end_time'));
                }
                $payData['body'] = '睿鼎少儿年卡办理(正常办卡-' . $card['name'] . ')';
                $payData['attach'] = $type;
                $payData['openid'] = $openid;
                $payData['trade_type'] = 'JSAPI';
                $payData['out_trade_no'] = $outTradeNo;
                $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $payPrice * 100;
                break;
            case 2://押金转年卡
                $userCardDetailsData['describe'] = '用户押金转年卡';
                $userCardDetailsData['action_type'] = 2;
                $userCardDetailsData['deposit_money'] = $resDeposit['data']['deposit_price'];
                //$userCardDetailsData['card_open_time'] += config('self_config.deposit_transform_card');//增加赠送时间
                if ($resDeposit['data']['deposit_type'] == 1) {
                    $userCardDetailsData['extra_money'] = $resDeposit['data']['price'];
                    $userCardDetailsData['refund_status'] = 2;
                    $userCardDetailsId = (new UserCardDetailsModel())->insert($userCardDetailsData, true);
                    if (!$userCardDetailsId) {
                        return $this->error(0, '年卡明细添加失败');
                    }
                    $payData['out_trade_no'] = 'RD' . $userCardDetailsId . 'CARD' . rand(10000, 99999);
                    goto payLog;//进行跳转
                } else {
                    $userCardDetailsId = (new UserCardDetailsModel())->insert($userCardDetailsData, true);
                    if (!$userCardDetailsId) {
                        return $this->error(0, '年卡明细添加失败');
                    }
                }
                $payData['body'] = '睿鼎少儿年卡办理(押金转年卡-' . $card['name'] . ')';
                $payData['attach'] = $type;
                $payData['openid'] = $openid;
                $payData['trade_type'] = 'JSAPI';
                $payData['out_trade_no'] = 'RD' . $userCardDetailsId . 'CARD' . rand(10000, 99999);
                $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $payPrice * 100;
                break;
            default:
                return $this->error(100, '暂无此模块');
                break;
        }
        $pay = Pay::getInstance();
        $result = $pay->unifiedorder($payData);
        if (!$result) {
            return $this->error(0, $result['message']);
        }
        payLog:
        //2021-4-22 设置掌灯人入口不可以查看
        self::setCache('lock_business_look_'.$userId,$userId,config('self_config.card_group_end_time'));
        // 支付日志
        $payLogData['order_id'] = $userCardDetailsId;
        $payLogData['order_type'] = 5;// 年卡办理
        $payLogData['order_amount'] = $cardPrice;
        $payLogData['out_trade_no'] = $payData['out_trade_no'];
        $payLogData['user_id'] = $userId;
        $payLogData['add_time'] = $t;
        $payLogResult = (new PayLogModel())->insert($payLogData);
        if (!$payLogResult) {
            return $this->error(0, "支付日志记录错误");
        }
        if ($status == 2) {
            //发起支付的时候 写入redis 方便查询
            self::setCache('deposit_upgrade_card_activate_' . $userId, 1, 30 * 60);
            if ($resDeposit['data']['deposit_type'] == 1) {//发起退款
                return $this->endPrice($userId, $resDeposit['data']['price'], $payData['out_trade_no'], $incData, $userCardDetailsId);
            }
            self::setCache('deposit_upgrade_card_' . $userId, 1, 30 * 60);
        }
        return $this->success(200, '操作成功', json_encode($result));
    }

    /**发起企业付款
     * @param $userId
     * @param $price
     * @param $outTradeNo
     * @param $incData
     * @param $userCardDetailsId
     * @return array
     * @author Poison
     * @date 2020/12/22 10:36 上午
     */
    public function endPrice($userId, $price, $outTradeNo, $incData, $userCardDetailsId)
    {
        Db::startTrans();
        $smart_openid = (new UserModel())->getOneUser($userId, 1, 'smart_openid');
        if (!$smart_openid) {
            return $this->error(100, 'smart_openid 不能为空');
        }
        //开通年卡
        $resPay = (new PayService())->userCardHandle($outTradeNo, time());
        if (!$resPay) {
            Db::rollback();
            return $this->error(100, '年卡记录写入失败');
        }
        //修改押金记录
        $resDeposit = (new DepositService())->deleteData((int)$userId);
        if ($resDeposit['code'] != 200) {
            Db::rollback();
            return $resDeposit;
        }
        $status = 1;
        $remark = '退款成功！';
        $data = [
            'partner_trade_no' => $outTradeNo,
            'openid' => $smart_openid,
            'amount' => env('server_env') === 'dev' ? 30 : $price * 100,
            'desc' => '押金升级年卡 退款：' . $price
        ];
        $upgradeData['user_id'] = $userId;
        $upgradeData['out_refund_no'] = $outTradeNo;
        $upgradeData['out_price'] = $price;
        $upgradeData['deposit_price'] = $incData['deposit_price'];
        $upgradeData['card_price'] = $incData['card_price'];
        $upgradeData['low_grade'] = $incData['grade'];
        $upgradeData['type'] = 2;
        $upgradeData['deposit_id'] = $incData['deposit_id'];
        $pay = Pay::getInstance();
        $result = $pay->promotion($data);
        if ($result['result_code'] != 'SUCCESS') {
            $status = 2;
            $remark = $result['err_code_des'];
            self::insertDepositUpgradeCard($upgradeData, $status, $remark);
            (new UserCardDetailsModel())->where('id', $userCardDetailsId)->update(['refund_status' => 4, 'update_time' => time()]);
            Db::commit();
            return $this->error(102, '您是' . $incData['deposit_name'] . '，将要升级的会员卡是' . $incData['card_name'] . '，系统将为您退回' . $price . '元,1-3个工作日到账，请注意查收!', ['deposit_name' => $incData['deposit_name'], 'card_name' => $incData['card_name'], 'price' => $price]);
        }
        $upgradeData['out_time'] = time();
        self::insertDepositUpgradeCard($upgradeData, $status, $remark);
        (new UserCardDetailsModel())->where('id', $userCardDetailsId)->update(['refund_status' => 3, 'update_time' => time()]);
        Db::commit();
        return $this->success(200, '押金升级年卡成功!');
    }

    /**获取分佣指定年卡Id
     * @return mixed
     * @author Poison
     * @date 2020/12/23 1:49 下午
     */
    public static function getBusinessCardId()
    {
        return (new BusinessRuleModel())->getRuleValue(['partner_type' => 1], 'card_id') ?? 2;//查询是否是指定卡
    }

    /**创建押金升级年卡
     * @param $data
     * @param int $status
     * @param string $remark
     * @return int|string
     * @author Poison
     * @date 2021/1/15 11:33 上午
     */
    private function insertDepositUpgradeCard($data, $status = 1, $remark = "")
    {
        $data['create_time'] = time();
        $data['update_time'] = time();
        $data['is_out'] = $status;
        $data['remark'] = $remark;
        return (new DepositUpgradeCardModel())->insert($data, true);
    }

    /**年卡邀请送时长
     * @param $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/12/30 5:03 下午
     */
    public function cardInvite($params)
    {
        return $this->error(100, '活动已结束');
        $userId = $params['user_id'];
        //将上级id解密
        $parentId = (int)self::getDecode($params['parent_id']);
        //判断上级是否购卡
        $resUserCard = UserCardsModel::getOneCards($parentId);
        if (!$resUserCard) {
            return $this->error(100, '上级未开通年卡');
        }
        $resUserCard = UserCardsModel::getOneCards($userId);
        if ($resUserCard) {
            return $this->error(100, '您已经开通年卡');
        }
        //查询当前用户是否以被邀请
        $lowCardInviteData = CardInviteModel::getOneData($userId);
        if ($lowCardInviteData) {//没有数据写入
            if ($parentId == $lowCardInviteData['parent_id']) {
                return $this->error(100, '您已经和他绑定了');
            }
            if ($lowCardInviteData['card_id'] != 0 && $lowCardInviteData['end_card_time'] == 0) {
                return $this->error(100, '您已购卡，不能被邀请');
            }
            $resUpdateCardInvite = (new CardInviteModel())->where('id', $lowCardInviteData['id'])->update(['delete_time' => time(), 'update_time' => time()]);
            if (!$resUpdateCardInvite) {
                return $this->error(100, '修改失败');
            }
        }
        $resCardInvite = CardInviteModel::CreateData($userId, $parentId);
        if (!$resCardInvite) {
            return $this->error(100, '添加失败');
        }
        return $this->success(200, '邀请成功', []);
    }

    /**年卡赠送时长
     * @param $userId
     * @param $cardId
     * @param $userCardId
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/12/30 5:42 下午
     */
    public function cardGiveTime($userId, $cardId, $userCardId)
    {
        $cardInviteData = CardInviteModel::getOneData($userId);
        if (!$cardInviteData) {
            return false;
        }
        if ($cardInviteData['card_id'] != 0 && $cardInviteData['end_card_time'] == 0) {
            return false;
        }
        $parentUserCards = UserCardsModel::getOneCards($cardInviteData['parent_id']);
        if (!$parentUserCards) {
            return false;
        }
        Db::startTrans();
        $resCardInvite = (new CardInviteModel())->where('id', $cardInviteData['id'])->update(['card_id' => $cardId, 'user_card_id' => $userCardId, 'update_time' => time(), 'end_card_time' => 0]);
        if (!$resCardInvite) {
            Db::rollback();
            return false;
        }
        if ($parentUserCards['is_lock'] == 0) {//没有锁定 修改时长
            $resUpUserCard = (new UserCardsModel())->where('id', $parentUserCards['id'])->update(['end_time' => $parentUserCards['end_time'] + (86400 * 30)]);
            if (!$resUpUserCard) {
                Db::rollback();
                return false;
            }
        }
        //修改open_time
        $resUserCardDetails = (new UserCardDetailsModel())->where('user_card_id', $parentUserCards['id'])->inc('card_open_time', 86400 * 30)->update();
        if (!$resUserCardDetails) {
            Db::rollback();
            return false;
        }
        //增加赠送记录
        $topUser = (new UserModel())->getOneUser($parentUserCards['user_id']);
        $mobiles = $topUser['mobile'];
        $topUserName = $topUser['user_name'];
        $name = (new UserModel())->getOneUser($userId, 1, 'nickname');
        LogService::userLog($topUserName, $parentUserCards['user_id'], '您的下级用户id：' . $userId . '购买年卡，赠送时长 1个月。');
        Db::commit();
        //发送短信模版

        $resSms = (new ALiYunSendSms())->sendSms(
            $mobiles,
            config('ali_config.send_msg.card_pay_give_1'),
            ['name' => $name]
        );
        return $this->success(200, 'sss');
    }

    /**获取用户押金转年卡列表
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/18 2:27 下午
     */
    public function upCard(int $userId)
    {
        //先获取年卡列表
        $cardData = (new CardsModel())->where('is_used', 2)->select();
        //查询用户当前押金
        $userData = (new UserModel())->getOneUser($userId);
        $deposit = $userData['deposit'];
        $grade = $userData['grade'];
        $name = (new UserLevelModel())->where('grade', $grade)->value('level_name');
        foreach ($cardData as $k => $v) {
            //判断用户押金金额是否大于年卡+押金金额
            $price = $v['price'];
            if (config('self_config.deposit_up_card_is_give') == 1) {
                $price = $v['price'] + $v['card_deposit'];
            }
            $cardData[$k]['is_up'] = 2;
            $cardData[$k]['is_deposit'] = config('self_config.deposit_up_card_is_give') ?? 0;
            $cardData[$k]['grade_name'] = $name;
            $cardData[$k]['up_price'] = $price - $deposit;
            if ($deposit > $price) {
                $cardData[$k]['is_up'] = 1;
                $cardData[$k]['up_price'] = $deposit - $price;
            }
            $cardData[$k]['img'] = self::getGoodsImg($v['img']);
        }
        return $this->success(200, '请求成功', $cardData);
    }

    /**年卡激活
     * @param $params
     * @param $userId
     * @return array
     * @author Poison
     * @date 2021/2/23 4:46 下午
     */
    public function doUnlock($params, $userId)
    {
        $userCardId = (int)$params['user_card_id'];
        $userCard = (new UserCardsModel())->getCard(['user_id' => $userId, 'id' => $userCardId]);
        if (!$userCard) {
            return self::error(0, '用户对应年卡信息不存在');
        }
        if ($userCard['is_expire'] == 2) {
            return self::error(0, '年卡已过期');
        }
        if ($userCard['is_lock'] == 0) {
            return self::error(0, '该年卡已激活');
        }
        $cardOpenTime = (new UserCardDetailsModel())->where('user_card_id', $userCardId)->value('card_open_time');
        $t = time();
        $endTime = $t + $cardOpenTime;
        $userCardData = [
            'start_time' => $t,
            'end_time' => $endTime,
            'is_lock' => 0,
            'unlock_time' => $t,
            'update_time' => $t,
        ];
        $userCardsUpdate = (new UserCardsModel())->update($userCardData, ['id' => $userCardId]);
        if (!$userCardsUpdate) {
            return self::error(0, '用户年卡激活失败');
        }
        LogService::addUserCardChangeLog($userId, $userCardId, $userCard['card_id'], 12, '+'.($cardOpenTime/86400).'天', '用户激活年卡', '', '', '', $t, $endTime);
        return self::success(200, '用户年卡激活成功', []);
    }

    /** 获取当前用户是否可以继续拼团
     * @param $userId
     * @return array
     * @author Poison
     * @date 2021/2/24 2:03 下午
     */
    protected function getCountGroup($userId)
    {
        $count = (new CardGroupInfoModel())->where(['user_id' => $userId, 'type' => 1])->count();
        if ($count >= 20) {
            return self::error(100, '非常抱歉！您已经没有拼团资格了');
        }
        return self::success();
    }

    /**获取拼团支付价格
     * @param int $userId
     * @param int $groupId
     * @return array
     * @author Poison
     * @date 2021/2/24 2:29 下午
     */
    protected function getPrice(int $userId, int $groupId)
    {
        //判断是否以满
        $thisRedis = self::getCache('card_group_pay_' . $groupId);
        if ($thisRedis) {
            if ($thisRedis != $userId) {
                return self::error(100, '非常抱歉！该拼团有人正在支付中');
            }
        }
        $t = time() - config('self_config.card_group_out_time');
        $cardGroupData = (new CardGroupModel())->field('num,is_finish')->where('id', $groupId)->where('create_time', '>', $t)->findOrEmpty()->toArray();
        if (!$cardGroupData) {//判断是否过期
            return self::error(100, '拼团已过期');
        }
        if ($cardGroupData['is_finish'] == 1) {
            return self::error(100, '非常抱歉，该拼团已完成，请参与其他拼团');
        }
        $price = (new CardsModel())->where('id', 2)->value('price') ?? 599;
        //判断该次拼完后 团满
        if ($cardGroupData['num'] >= 2) {
            //设置redis锁定
            $price = config('self_config.card_price_399');
            self::setCache('card_group_pay_' . $groupId, $userId, config('self_config.card_group_end_time'));
        } elseif ($cardGroupData['num'] == 1) {
            $price = config('self_config.card_price_499');
        }
        //查询用户是否为该团成员 如果不是 加入
        $resCardGroupInfoData = (new CardGroupInfoModel())->where(['card_group_id' => $groupId, 'user_id' => $userId])->findOrEmpty()->toArray();
        if (!$resCardGroupInfoData) {
            (new CardGroupInfoModel())->insert([
                'user_id' => $userId,
                'card_group_id' => $groupId,
                'user_card_id' => 0,
                'type' => 0,
                'price' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ], true);
        } elseif ($resCardGroupInfoData['is_out'] == 1 || $resCardGroupInfoData['type'] == 1) {
            return self::error(100, '非常抱歉！您已经参加过该团了');
        }

        return ['price' => $price, 'status' => 1];
    }

    public function add($userId, $cardId, $price, $isRobot = 0)
    {
        Db::startTrans();
        $t = time();
        if ($isRobot == 1) {
            $t = $t - rand(15, 1800);
        }
        $cardGroupId = (new CardGroupModel())->insert([
            'user_id' => $userId,
            'num' => 1,
            'create_time' => $t,
            'update_time' => $t,
        ], true);
        if (!$cardGroupId) {
            Db::rollback();
            return $this->error(100, '创建拼团失败 用户id:' . $userId);
        }
        $cardGroupInfo = (new CardGroupInfoModel())->insert([
            'user_id' => $userId,
            'card_group_id' => $cardGroupId,
            'user_card_id' => $cardId,
            'type' => 1,
            'price' => $price,
            'create_time' => $t,
            'update_time' => $t,
            'is_robot' => $isRobot,
        ], true);
        if (!$cardGroupInfo) {
            Db::rollback();
            return $this->error(100, '加入拼团列表失败 用户id:' . $userId);
        }
        //写入日志
        (new CardGroupLogModel())->insert([
            'user_id' => $userId,
            'card_group_id' => $cardGroupId,
            'info' => '用户id:' . $userId . ' 开团成功',
            'create_time' => time()
        ]);
        Db::commit();
        return self::success();
    }

    /**获取支付价格
     * @param $userId
     * @return int|mixed
     * @author Poison
     * @date 2021/2/24 2:50 下午
     */
    public static function getPriceInt($userId)
    {
        $price = (new CardsModel())->where('id', 2)->value('price') ?? 599;
        $t = time() - config('self_config.card_group_end_time');
        $where = [
            ['rd_card_group_info.user_id', $userId],
            ['rd_card_group_info.is_out', 0],
            ['rd_card_group_info.type', 1],
            ['cg.is_finish', 0],
        ];
        $fields = "rd_card_group_info.user_id,rd_card_group_info.card_group_id,rd_card_group_info.price,cg.num,cg.is_finish,cg.create_time";
        $resCardGroupInfoData = (new CardGroupInfoModel())->field($fields)
            ->join('rd_card_group cg', 'rd_card_group_info.card_group_id = cg.id')
            ->where($where)->order('rd_card_group_info.create_time', 'DESC')->findOrEmpty()->toArray();
        //判断是否过期
        if ($resCardGroupInfoData['create_time'] > $t) {
            //判断已支付
            if ($resCardGroupInfoData['num'] == 1) {
                $price = config('self_config.card_price_499');
            } elseif ($resCardGroupInfoData['num'] >= 2) {
                $price = config('self_config.card_price_599');
            }
        }
        return $price;
    }

    /**退款申请
     * @param $params
     * @param $ding
     * @param $type
     * @param $userId
     * @param $cardGroupId
     * @param $resUserCardDetailsData
     * @return bool
     * @author Poison
     * @date 2021/2/24 3:26 下午
     */
    public function sendOutPrice($params, $userId, $cardGroupId, $resUserCardDetailsData, $type = 1)
    {

        try {
            $t = time();
            $resData = (new CardGroupService())->refund(['str' => self::getEncode(json_encode(['user_id' => $params['user_id'], 'group_info_id' => $params['id']]), 'rd029')]);
            if (!$resData || !isset($resData['code']) || $resData['code'] != 200) {
                self::sendDingError('拼团退款失败 用户id: ' . $params['user_id'] . '拼团id: ' . $cardGroupId . ' 失败原因------>', json_encode($resData['message'], JSON_UNESCAPED_UNICODE));
            } else {
                if ($type == 1) {
                    $userDataList = (new UserModel())->getOneUser($userId);
                    $mobile = (new UserModel())->where('user_id', $params['user_id'])->value('mobile');
                    $userName = $userDataList['nickname'] ?? '新会员';
                    $userName = '( ' . $userName . ') ';
                    (new ALiYunSendSms())->sendSms($mobile, 'SMS_206535230', ['password' => $userName, 'name' => $userName]);
                }
            }
            $resCardGroupRefund = (new CardGroupRefundModel())->insert([
                'user_id' => $params['user_id'],
                'card_group_id' => $cardGroupId,
                'user_card_id' => $params['user_card_id'],
                'card_id' => 2,
                'refund_money' => isset($resData['data']['refund_fee']) ? $resData['data']['refund_fee'] / 100 : 0,
                'refund_number' => isset($resData['data']['out_refund_no']) ? $resData['data']['out_refund_no'] : '退款失败',
                'create_time' => $t,
            ]);
            if (!$resCardGroupRefund) {
                self::sendDingError('写入退款记录失败 ', '用户id:' . $params['user_id']);
                return false;
            }
            if ($type == 1) {
                (new CardGroupLogModel())->insert([//写入日志
                    'user_id' => $params['user_id'],
                    'card_group_id' => $cardGroupId,
                    'info' => '用户ID：' . $userId . ' 拼团支付成功，团内成员:' . $params['user_id'] . ' 退款:' . (isset($resData['data']['refund_fee']) ? $resData['data']['refund_fee'] / 100 : 0) . (isset($resData['data']['refund_fee']) ? ' 退款成功！' : ' 退款失败！'),
                    'create_time' => $t,
                ]);
            }
            if (isset($resData['data']['refund_fee']) && $resData['data']['refund_fee']) {
                $resUserCardDetails = (new UserCardDetailsModel())->update([//修改支付价格
                    'pay_money' => $resUserCardDetailsData['pay_money'] - config('self_config.card_group_out_price'),
                    'update_time' => $t
                ], ['id' => $resUserCardDetailsData['id']]);
                if (!$resUserCardDetails) {
                    self::sendDingError('修改支付价格失败 ', '用户id:' . $params['user_id']);
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            self::sendDingError('拼团退款失败 ', $e);
        }
    }

    /**拼团回调处理
     * @param $outTradeNo
     * @param $userId
     * @param $cardId
     * @param $price
     * @param $userCardDetailsId
     * @return array
     * @author Poison
     * @date 2021/2/24 3:48 下午
     */
    public function setPayActivity($outTradeNo, $userId, $cardId, $price, $userCardDetailsId)
    {
        $outTradeNoArray = self::getCache('card_open' . $outTradeNo);
        if ($outTradeNoArray) {
            return self::add($userId, $cardId, $price);
        }
        $cardGroupId = (int)self::getCache('card_group' . $outTradeNo);
        if (!$cardGroupId) {
            return self::error(10001, '没有参加团活动');
        }
        $t = time();//获取当前时间
        try {
            //将团加1
            $cardGroupNum = (new CardGroupModel())->where('id', $cardGroupId)->value('num');
            if ($cardGroupNum >= 2) {
                $cardGroupData['is_finish'] = 1;
                $cardGroupData['finish_time'] = $t;
            }
            $cardGroupData['num'] = $cardGroupNum + 1;
            $cardGroupData['update_time'] = $t;
            $resCardGroup = (new CardGroupModel())->update($cardGroupData, ['id' => $cardGroupId]);
            if (!$resCardGroup) {
                return self::error(100, '修改团状态失败');
            }
            //修改用户的支付状态
            $resCardGroupInfo = (new CardGroupInfoModel())->update([
                'user_card_id' => $cardId,
                'type' => 1,
                'price' => $price,
                'update_time' => $t,
            ], ['user_id' => $userId, 'card_group_id' => $cardGroupId]);
            if (!$resCardGroupInfo) {
                return self::error(100, '修改用户状态失败');
            }
//写入日志
            (new CardGroupLogModel())->insert([
                'user_id' => $userId,
                'card_group_id' => $cardGroupId,
                'info' => '用户Id:' . $userId . ' 拼团成功！',
                'create_time' => $t
            ]);
            $resCardGroupInfoData = (new CardGroupInfoModel())->field('user_card_id,user_id,id')->where(['card_group_id' => $cardGroupId, 'type' => 1, 'is_robot' => 0, 'is_out' => 0])->select()->toArray();
            if ($price == config('self_config.card_price_499')) {//如果用户支付价格等于499 且支付人数为3 或大于是3的时候 给自己退款
                $resCardGroupInfoCount = (new CardGroupInfoModel())->field('user_card_id,user_id,id')->where(['card_group_id' => $cardGroupId, 'type' => 1, 'is_out' => 0])->count();
                if ($resCardGroupInfoCount >= 3) {
                    $cardGroupInfoId = (new CardGroupInfoModel())->where(['user_id' => $userId, 'card_group_id' => $cardGroupId])->value('id');
                    $params['user_card_id'] = config('self_config.open_group_card_id');
                    $params['user_id'] = $userId;
                    $params['id'] = $cardGroupInfoId;
                    $resUserCardDetailsData['pay_money'] = $price;
                    $resUserCardDetailsData['id'] = $userCardDetailsId;
                    self::sendOutPrice($params, $userId, $cardGroupId, $resUserCardDetailsData);
                }
            }
            //遍历数据
            if (!$resCardGroupInfoData) {
                return self::success();
            }
            $userCardDetailsModel = new UserCardDetailsModel();
            foreach ($resCardGroupInfoData as $k => $v) {
                $resUserCardDetailsData = $userCardDetailsModel->field('pay_money,out_trade_no,refund_card_status,id')->where(['user_card_id' => $v['user_card_id'], 'user_id' => $v['user_id']])->findOrEmpty()->toArray();
                if (!$resUserCardDetailsData) {//没有数据 跳过
                    continue;
                }
                if ($resUserCardDetailsData['refund_card_status'] == 1 || $resUserCardDetailsData['refund_card_status'] == 2 || $resUserCardDetailsData['refund_card_status'] == 3) {//判断用户是否退款z
                    continue;
                }
                //判断支付价格 是否大于399
                if ($resUserCardDetailsData['pay_money'] >= config('self_config.card_group_low_price')) {
                    if ($v['user_id'] == $userId) {
                        continue;
                    }
                    $params['id'] = $v['id'];
                    $params['user_id'] = $v['user_id'];
                    $params['user_card_id'] = $v['user_card_id'];
                    //发起退款申请
                    $this->sendOutPrice($params, $userId, $cardGroupId, $resUserCardDetailsData);
                }
            }
            self::setCache('card_group_pay_' . $cardGroupId, $userId, 1);
            if ($cardGroupNum >= 2) {//如果团完成了 机器人开一个新团
                self::addAndOut(['id' => $cardGroupId]);
            }
            return self::success();
        } catch (\Exception $ex) {
            self::sendDingError('拼团退款失败 方法：setPayActivity ', $ex);
        }
    }

    /**添加新团或回收机器人
     * @param $data
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/26 2:15 下午
     */
    public function addAndOut($data)
    {
        //判断团中数量为2 撤出机器人
        $cardGroupCount = (new CardGroupModel())->where('is_finish', 0)->count();
        if ($cardGroupCount < 10) {
            (new UserCardService())->add((new CardGroupOut())->userId(), 0, 0, 1);
        }
        $cardGroupInfo = (new CardGroupInfoModel())->field('user_id')->where(['card_group_id' => $data['id'], 'is_robot' => 1])->select()->toArray();
        if ($cardGroupInfo) {
            $noRobot = self::getCache('card_group_not_used') ?? [];
            if ($noRobot) {
                $noRobot = json_decode($noRobot, true);
            } else {
                $robotArray = (new CardGroupRobotModel())->field('user_id')->where('type', 0)->select()->toArray();
                $noRobot = array_column($robotArray, 'user_id');
            }
            foreach ($cardGroupInfo as $k => $v) {
                (new CardGroupRobotModel())->where('user_id', $v['user_id'])->update(['type' => 0, 'update_time' => time()]);
                $noRobot[] = $v['user_id'];
            }
            $noRobot = array_values(array_unique($noRobot));
            self::setCache('card_group_not_used', json_encode($noRobot));
        }
    }


    /**
     * 验证用户优惠券是否可用
     * @param int $user_id 用户ID
     * @param int $coupon_id 优惠券ID
     * @param float $card_price 年卡金额
     * @return array
     * @author yangliang
     * @date 2021/3/12 15:51
     */
    public function checkUserCoupon(int $user_id, int $coupon_id, float $card_price)
    {
        try {
            if (!empty($coupon_id)) {
                $coupon = UserCouponModel::getCouponByUserIdAndCouponId($user_id, $coupon_id, 1);
                if (empty($coupon) || $coupon['is_del'] == 1) {
                    throw new \Exception('优惠券信息不存在');
                }

                if ($coupon['expire_time'] < time()) {
                    //更新优惠券过期
                    UserCouponModel::where('id', $coupon_id)->update(['is_expire' => 1, 'update_time' => time()]);
                    throw new \Exception('优惠券已过期');
                }

                if ($coupon['is_used'] == 1) {
                    throw new \Exception('优惠券已使用');
                }

                if ($coupon['is_give_coupon'] == 1) {
                    throw new \Exception('优惠券已转赠');
                }

                if (!in_array($coupon['scenarios'], [0, 1])) {
                    throw new \Exception('优惠券不可使用，请查看优惠券使用场景');
                }

                if ($card_price < $coupon['scenarios_price']) {
                    throw new \Exception('优惠券不可使用，请查看优惠券使用条件');
                }
            }
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
        }

        return ['status' => 1, 'price' => $coupon['price']];
    }


    /**
     * 验证体验卡是否可用
     * @param int $user_id 用户ID
     * @param int $experience_id 用户体验卡ID
     * @param int $card_id 年卡ID
     * @return array
     * @author yangliang
     * @date 2021/3/12 15:57
     */
    public function checkUserExperience(int $user_id, int $experience_id, int $card_id)
    {
        try {
            if (!empty($experience_id)) {
                $experience = UserCouponModel::getCouponByUserIdAndCouponId($user_id, $experience_id, 2);
                if (empty($experience) || $experience['is_del'] == 1) {
                    throw new \Exception('体验卡信息不存在');
                }

                if ($experience['card_id'] != $card_id) {
                    throw new \Exception('体验卡不可使用，体验卡与年卡不匹配');
                }

                if ($experience['expire_time'] < time()) {
                    //更新优惠券过期
                    UserCouponModel::where('id', $experience_id)->update(['is_expire' => 1, 'update_time' => time()]);
                    throw new \Exception('体验卡已过期');
                }

                if ($experience['is_used'] == 1) {
                    throw new \Exception('体验卡已使用');
                }

                if ($experience['is_give_coupon'] == 1) {
                    throw new \Exception('体验卡已转赠');
                }

                if (!in_array($experience['scenarios'], [0, 1])) {
                    throw new \Exception('体验卡不可使用，请查看体验卡使用场景');
                }

            }
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
        }

        return ['status' => 1, 'day' => $experience['experience_time']];
    }



    /**
     * 获取用户年卡信息
     * @param int $user_id 用户ID
     * @param int $card_type 年卡类型  1-年卡  2-体验卡
     * @param int $isRenew 是否可以续费 默认为0
     * @param string $user_name 用户名称
     * @return array
     * @author yangliang
     * @date 2021/3/30 15:07
     */
    public function getUserCardInfo(int $user_id, int $card_type, int $isRenew = 0, string $user_name = '')
    {
        $user_card = UserCardsModel::getCardByUserIdAndExpireAndCardType($user_id, 0, $card_type);
        if (!empty($user_card) && isset($user_card['id'])) {
            //if ($user_card['refund_card_status'] == 0 || $user_card['refund_card_status'] == 4) {
                // 年卡信息
                $user_card['card_info'] = CardsModel::getById($user_card['card_id']);
                // 判断是否过期
                if ($user_card['is_lock'] != 1 && ($user_card['end_time'] < time()) && $user_card['is_expire'] == 1) {  //2020.12.21  by yangliang  修改年卡次数用尽不过期年卡
                    $user_card_update = UserCardsModel::where('id', $user_card['id'])->update(['is_expire' => 2, 'update_time' => time()]);
                    if ($user_card_update) {
                        LogService::userLog($user_name, $user_id, '更新用户年卡过期,ID:' . $user_card['id'], 4);
                    }

                    $user_card = UserCardsModel::getCardByUserIdAndExpireAndCardType($user_id, 1, $card_type);
                    if (!empty($user_card)) {
                        $user_card['card_info'] = CardsModel::getById($user_card['card_id']);
                    }
                }elseif ($user_card['is_expire'] == 2){//判断是否押金已退
                    //判断是否有申请退押金 并且成功
                    $res = NewCardRefundDepositModel::getOneDataByUserId($user_id,$user_card['id']);
                    if($res){
                        if($res['node'] == 3){
                            return [[], 0];
                        }
                    }
                    $resUserCardDetailsData = UserCardDetailsModel::getCardDetailsData(['user_card_id'=>$user_card['id']]);
                    if($resUserCardDetailsData['card_deposit'] == 0){
                        return [[], 0];
                    }
                    if($resUserCardDetailsData['transfer_deposit_id']){
                        return [[], 0];
                    }
                }

                if ($user_card['is_activity'] != 1 && $user_card['card_id'] == 2) {
                    $isRenew = 1;
                }

                //获取押金
                $user_card_detail_data = UserCardDetailsModel::getByUserCardId((int)$user_card['id']);
                $user_card['deposit'] = $user_card_detail_data['card_deposit'];
                $user_card['open_time'] = $user_card_detail_data['card_open_time'];

                $user_card['use_experience'] = 0;  //是否使用体验卡   0-未使用    1-已使用
                $user_card['experience_days'] = 0;  //体验时长（天）
                $user_card['experience_start'] = 0;  //体验开始时间
                $user_card['experience_end'] = 0;  //体验结束时间

                //获取年卡是否使用了体验卡
                if ($user_card_detail_data['experience_id'] > 0 && $user_card_detail_data['experience_status'] == 0) {
                    //年卡未激活
                    if ($user_card['start_time'] == 0) {
                        $user_card['use_experience'] = 1;
                        $user_card['experience_days'] = $user_card_detail_data['experience_time'];
                        $user_card['experience_start'] = '';
                        $user_card['experience_end'] = '';
                        $user_card['open_time'] = $user_card['open_time'] - $user_card_detail_data['experience_time'] * 86400;  //年卡时长扣除体验时长
                    } else {  //年卡已激活
                        //体验卡结束时间
                        $experience_end = $user_card['start_time'] + $user_card_detail_data['experience_time'] * 86400;
                        //验证体验卡是否过期（体验卡过期不显示）
                        if ($experience_end > time()) {
                            $user_card['use_experience'] = 1;
                            $user_card['experience_days'] = $user_card_detail_data['experience_time'];
                            $user_card['experience_start'] = date('Y-m-d', $user_card['start_time']);
                            $user_card['experience_end'] = date('Y-m-d', $experience_end);
                            $user_card['start_time'] = $experience_end;  //年卡开始时间扣除体验时长
                        }
                    }
                }
//            } else {
//                $user_card = [];
//            }
        }
        return [$user_card, $isRenew];
    }


    /**
     * 体验卡转年卡
     * @param array $user_card_detail 购买年卡信息
     * @param int $user_id 用户ID
     * @param float $pay_price 支付金额
     * @return array
     * @author yangliang
     * @date 2021/3/30 15:17
     */
    public function ExperienceCard(array $user_card_detail, int $user_id, float $pay_price)
    {
        //获取用户体验卡
        $user_card = UserCardsModel::getByUserIdAndCardType($user_id, 2);
        if (empty($user_card) || $user_card['is_refund'] == 1) {
            return [$user_card_detail, $pay_price];
        }
        $resNew = (new NewCardRefundDepositModel())->getDataByUserId($user_id,$user_card['id']);
        if($resNew){
            return [$user_card_detail, $pay_price];
        }

        $detail = UserCardDetailsModel::getByUserCardId($user_card['id']);
        if($detail['transfer_deposit_id'] > 0){
            return [$user_card_detail, $pay_price];
        }
        //体验卡剩余天数
        $days_time = ($user_card['end_time'] > time()) ? $user_card['end_time'] - time() : 0;
        $user_card_detail['experience_card_id'] = $user_card['id'];
        $user_card_detail['experience_add_card_days'] = $days_time / 86400;
        //增加时间 在回调整体处理了
        //$user_card_detail['card_open_time'] = $user_card_detail['card_open_time'] + $days_time;
        if ($user_card['is_refund'] == 2 && in_array($user_card['refund_card_status'], [0, 4])) {  //未退年卡押金
            //$pay_price = $pay_price - $detail['card_deposit'];  //扣除押金
        }

        return [$user_card_detail, $pay_price];
    }

    /**验证是否有购买季卡资格
     * @param int $userId
     * @param int $cardId
     * @return array
     * @author Poison
     * @date 2021/5/6 3:42 下午
     */
    protected function checkChannel($userId,$cardId){
        if ($cardId == 5) {//判断用户是否可以购买季卡 规则 特殊渠道的白板用户
            $channelId = (new BusinessOrganUserModel())->where(['user_id' => $userId, 'status' => 1])->order('id', 'DESC')->value('channel_id');
            //判断是否存在与活动渠道中
            if (in_array($channelId, config('self_config.season_card_channel'))) {
                //判断用户是否购买过卡 是否购买过 活着曾经购买过 都进行移除
                $isUpgrade = (new UserUpgradeModel())->where(['user_id' => $userId, 'is_paid' => 1])->findOrEmpty()->toArray();
                if ($isUpgrade) {//如果交过押金
                    return $this->error(100, '您不具有购买季卡的资格');
                }
                $resUserCards = (new UserCardsModel())->field('id')->where('user_id', $userId)->findOrEmpty()->toArray();
                if ($resUserCards) {
                    return $this->error(100, '您不具有购买季卡的资格');
                }
                return successArray();
            } else {
                return $this->error(100, '您不具有购买季卡的资格');
            }
        }
        return successArray();
    }

    /**判断年卡是否失效
     * @param $userId
     * @param $t
     * @param $usersData
     * @param $depositExpireDateTimeStamp
     * @param $card
     * @return array
     * @author Poison
     * @date 2021/5/6 3:58 下午
     */
    protected function checkCard($userId,$t,$usersData,$depositExpireDateTimeStamp,$card){
        $useUserCard = (new UserCardsModel())->where('user_id', $userId)->where('is_expire', 1)->whereIn('refund_card_status', '0,1,2,4')->order('id', 'DESC')->findOrEmpty()->toArray();
        if ($useUserCard) {
            if ($useUserCard['refund_card_status'] == 1 || $useUserCard['refund_card_status'] == 2) {
                return $this->error(100, '您目前有1张年卡正在退卡中，如有疑问，请联系客服。');
            }
            if ($usersData['grade'] > 0) {
                if ($t >= $depositExpireDateTimeStamp) {
                    if ($useUserCard['is_lock'] == 1) {
                        return $this->error(0, '您目前已有1张暂未激活的年卡，无需办理新卡');
                    } else {
                        // 如果已交押金用户已过押金过期时间,年卡已过期或者配送次数已用尽则更新年卡信息
                        if ($t >= $useUserCard['end_time']) {
                            $useUserCardUpdate = (new UserCardsModel())->updateUserCards(['is_expire' => 2, 'update_time' => $t], ['id' => $useUserCard['id']]);
                            if (!$useUserCardUpdate) {
                                return $this->error(0, '年卡信息更新失败');
                            }
                            return successArray();
                        } else {
                            return $this->error(0, '您目前还有1张正在使用中的年卡，无需办理新卡');
                        }
                    }
                } else {
                    return $this->error(0, '您目前已有1张暂未激活的年卡，无需办理新卡');
                }
            } else {
                // 如果未交押金用户,年卡已过期或者配送次数已用尽则更新年卡信息
                if ($t >= $useUserCard['end_time']) {
                    $useUserCardUpdate = (new UserCardsModel())->updateUserCards(['is_expire' => 2, 'update_time' => $t], ['id' => $useUserCard['id']]);
                    if (!$useUserCardUpdate) {
                        return $this->error(0, '年卡信息更新失败');
                    }
                    return successArray();
                } elseif ($useUserCard['card_type'] == 2) {  //当前年卡是体验卡
                    if ($card['card_type'] == 2) {  //体验卡只能购买一次
                        return $this->error(0, '您目前还有1张正在使用中的体验卡，无需办理新卡');
                    }
                    return successArray();
                } else {
                    return $this->error(0, '您目前还有1张正在使用中的年卡，无需办理新卡');
                }
            }
        }else{
            //验证体验卡是否已退押金
            $experience_user_card = UserCardsModel::getByUserIdAndCardType($userId, 2);
            if (!empty($experience_user_card) && ($experience_user_card['refund_card_status'] == 1 || $experience_user_card['refund_card_status'] == 2)) {
                return $this->error(100, '您目前有1张年卡正在退卡中，如有疑问，请联系客服。');
            }
            return successArray();
        }
        return successArray();
    }

    /**修改年卡为过期
     * @param int $userId 用户id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/4/23 9:57 上午
     */
    public function delUserCard(int $userId): array
    {
        //获取最后一条
        $userCard = UserCardsModel::getOneCards($userId);
        if (!$userCard) {
            return errorArray('未找到有效的年卡');
        }
        //判断次数是否用完
        if ($userCard['surplus_ship_num'] > 0) {
            return errorArray('您的年卡还有次数～');
        }
        //没有问题 修改年卡
        $res = (new UserCardsModel())->updateUserCards(['is_expire' => 2, 'end_time' => time(), 'update_time' => time()], ['id' => $userCard['id']]);
        if (!$res) {
            return errorArray('年卡失效失败');
        }
        $userName = (new UserModel())->getOneUser($userId, 1, 'user_name');
        //写入修改日志
        LogService::userLog($userName, $userId, '用户自主关闭年卡 年卡ID：' . $userCard['id'], 0, 5);
        return successArray([], '年卡失效成功');
    }

    /**删除缓存
     * @param $userId
     * @author Poison
     * @date 2021/4/23 11:37 上午
     */
    protected function delUserRedis($userId){
        $this->getRedis()->del('lock_business_look_' . $userId);
        $this->getRedis()->del('out_card_deposit_message' . $userId);
        $this->getRedis()->del('deposit_upgrade_card_' . $userId);
        $this->getRedis()->del('deposit_upgrade_card_activate_' . $userId);
        $this->getRedis()->del('deposit_add_100_' . $userId);
        $this->getRedis()->del('renew_card_pay_' . $userId);
    }

    /**用户退年卡押金接口
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/5/25 2:46 下午
     */
    public function outUserCardDeposit(int $userId)
    {
        $userCardData = UserCardsModel::getByUserId($userId);
        if($userCardData && in_array($userCardData['refund_card_status'],[1,2,3])){
            return errorArray('您目前有1张年卡正在退卡中，如有疑问，请联系客服。');
        }
        if (!$userCardData || $userCardData['is_expire'] != 2) {
            return errorArray('您的年卡正在有效期内，押金在年卡失效后才能申请退还，如有疑问请咨询客服。');
        }
        //判断年卡是否有支付押金
        $userCardDetails = UserCardDetailsModel::getByUserCardId($userCardData['id']);
        if($userCardDetails['card_deposit'] == 0){
            return errorArray('您的年卡未支付押金。');
        }
        if($userCardDetails['transfer_deposit_id']){
            return errorArray('您的年卡押金已转移');
        }
        //判断是否有未归还
        $resOrderGoods = OrderGoodsModel::getReturnGoodsByUserId($userId, 1);
        if ($resOrderGoods) {
            return errorArray('您有未归还的书籍，请将所有书籍归还完成后申请退押金服务。');
        }
        $resNew = NewCardRefundDepositModel::getDataByUserId($userId, $userCardData['id']);
        if ($resNew) {
            return errorArray('您的退押金申请正在处理中，请勿重复申请！');
        }
        //写入信息
        Db::startTrans();
        try {
            $res = NewCardRefundDepositModel::createNewCardRefundDeposit($userId, (int)$userCardData['id']);
            if (empty($res)) {
                Db::rollback();
                return errorArray('申请失败，请联系客服处理');
            }
            //写入日志
            LogService::newCardRefundDepositLog($res, $userId);
            Db::commit();
            $this->sendDing('有一条年卡退押金审批待处理，请尽快处理',['15289379542'],config('ding_config.send_out_deposit'));
            return successArray(['id'=>$res],'申请成功，系统将在3-5个工作日将押金退还至您的微信零钱内，请注意查收！');
        } catch (\Exception $e) {
            Db::rollback();
            $this->sendDingError('退年卡押金异常', $e);
            return errorArray('系统异常，请稍后重试');
        }
    }

    /**取消
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/5/25 4:24 下午
     */
    public function cancelOutUserCardDeposit(int $userId){
        $userCardData = UserCardsModel::getNotCard(['user_id' => $userId]);
//        if (!$userCardData || $userCardData['is_expire'] != 2) {
//            return errorArray('您的年卡正在有效期内，押金在年卡失效后才能申请退还，如有疑问请咨询客服。');
//        }
        $resNew = NewCardRefundDepositModel::getDataByUserId($userId, $userCardData['id']);
        if(!$resNew){
            return errorArray('暂未找到申请记录,请联系客服处理');
        }
        if($resNew['refund_type'] == 2){
            return errorArray('请联系客服取消人工退押金申请');
        }
        if ($resNew['card_status'] == 3) {//已完成
            return errorArray('取消失败，您的退押金申请已处理完成');
        }
        //写入信息
        Db::startTrans();
        try {
            $res = NewCardRefundDepositModel::updateNewCardRefundDeposit($userId, (int)$resNew['id']);
            if (empty($res)) {
                Db::rollback();
                return errorArray('申请失败，请联系客服处理');
            }
            //写入日志
            LogService::newCardRefundDepositLog($resNew['id'], $userId,2);
            Db::commit();
            return successArray(['id'=>$res],'取消成功');
        } catch (\Exception $e) {
            Db::rollback();
            $this->sendDingError('退年卡押金取消异常', $e);
            return errorArray('系统异常，请稍后重试');
        }
    }

    /**获取列表
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/5/26 4:03 下午
     */
    public function outList(int $userId)
    {
        //查询年卡信息
        $userCardData = UserCardsModel::getUserCardInfoByWhere(['user_id'=>$userId]);
        if(!$userCardData){
            return errorArray('您暂未购卡');
        }
        $userCardDetails = UserCardDetailsModel::getCardDetailsData(['user_card_id'=>$userCardData['id']]);
        $res = NewCardRefundDepositModel::getOneDataByUserId($userId,$userCardData['id']);
        $card = CardsModel::getById($userCardData['card_id']);
        $data['status'] = 0;
        $data['list'] = [];
        //查询年卡信息
        $cardName = $card['name'];
        self::getCardName($card,$userCardDetails['money'],$cardName);
        self::getUserCardMessage($userCardData['id'],$data['list']);
        if($res){
            $data['status'] = $res['status'];
            if($res['node'] == 3){
                $data['status'] = 3;
            }
            //写入记录
            $list['message'] = sprintf('退%s年卡押金',$card['name']);
            $list['status'] = $data['status'];
            $list['deposit'] = $card['card_deposit'];
            $list['is_out'] = -1;
            $list['create_time'] = date('Y-m-d H:i:s',$res['update_time']);
            array_unshift($data['list'],$list);
        }
        return successArray($data,'获取成功');
    }

    /**递归处理数据
     * @param int $userCardId
     * @param $list
     * @param int $type
     * @return array
     * @author Poison
     * @date 2021/5/26 4:02 下午
     */
    protected function getUserCardMessage(int $userCardId,&$list,$type = 1){
        $userCardDetails = UserCardDetailsModel::getCardDetailsData(['user_card_id'=>$userCardId]);
        if(!$userCardDetails){
            return errorArray('未查询到数据');
        }
        //查询出来当前年卡的规则
        $card = CardsModel::getById($userCardDetails['card_id']);
        $cardName = $card['name'];
        self::getCardName($card,$userCardDetails['money'],$cardName);
        if ($userCardDetails['action_type'] != 6) {//如果不是续费 组装数据 返回信息
            $res = UserCardsOperationLogsModel::getById($userCardDetails['user_id'],$userCardId);
            if(!$res){
                return  errorArray();
            }
            //截取数据

            if ($type == 2) {
                $data['message'] = sprintf('%s押金转出', $cardName);
                $data['status'] = 0;
                $data['is_out'] = -1;
                $data['deposit'] = $card['card_deposit'];
                $data['create_time'] = date('Y-m-d H:i:s', $userCardDetails['update_time']);
                $list[] = $data;
            }
            $data['message'] = sprintf('购买%s支付押金', $cardName);
            $data['status'] = 0;
            $data['is_out'] = 1;
            $data['deposit'] = $card['card_deposit'];
            $data['create_time'] = date('Y-m-d H:i:s', $userCardDetails['pay_time']);
            $list[] = $data;
            return successArray();
        }
        $transferId = (new UserCardDetailsModel())->where([
            'transfer_deposit_id'=> $userCardId,
        ])->value('user_card_id');
        if($transferId){
            $data['message'] = sprintf('%s押金转出',$cardName);
            $data['status'] = 0;
            $data['is_out'] = -1;
            $data['deposit'] = $card['card_deposit'];
            $data['create_time'] = date('Y-m-d H:i:s',$userCardDetails['update_time']);
            $list[] = $data;
        }
        //如果不是这张年卡的 开始递归查询并组装数据
        $data['message'] = sprintf('%s押金转入',$cardName);
        $data['status'] = 0;
        $data['is_out'] = 1;
        $data['deposit'] = $card['card_deposit'];
        $data['create_time'] = date('Y-m-d H:i:s',$userCardDetails['pay_time']);
        $list[] = $data;
        $res = self::getUserCardMessage($transferId ?? 0, $list, 2);
        if (isset($res['code'])) {
            if(count($list) == 1){
                $data['message'] = sprintf('购买%s支付押金', $cardName);
                $data['status'] = 0;
                $data['is_out'] = 1;
                $data['deposit'] = $card['card_deposit'];
                $data['create_time'] = date('Y-m-d H:i:s', $userCardDetails['pay_time']);
                $list[] = $data;
                //return successArray();
            }
            unset($list[0]);
            $list = array_merge($list);
        }
    }

    /**获取年卡名称
     * @param $card
     * @param float $price 支付价格
     * @param string $cardName 年卡名称
     * @author Poison
     * @date 2021/5/26 3:03 下午
     */
    public function getCardName($card, float $price, string &$cardName)
    {
        if ($card['price'] < $price) {
            $cardName = '超级年卡';
            //根据预计支付价格 计算*几
            $res = CardPriceSetModel::getPriceById($card['id'], $price);
            if (!$res) {
                $cardName = $card['name'];
            }
            $cardName .= sprintf('(%s x %s年)', $card['name'], $res['year'] / (86400 * 30 * 12));
        }
    }

    public function vipLog(int $userid): array
    {

    }
}