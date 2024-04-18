<?php


namespace app\common\service;


use app\common\model\BusinessOrganUserModel;
use app\common\model\BusinessRelationModel;
use app\common\model\CardGroupInfoModel;
use app\common\model\CardGroupInviteModel;
use app\common\model\CardGroupLogModel;
use app\common\model\CardGroupModel;
use app\common\model\CardInviteModel;
use app\common\model\CardPriceSetModel;
use app\common\model\CardsModel;
use app\common\model\CouponModel;
use app\common\model\EntityCardLogModel;
use app\common\model\EntityCardModel;
use app\common\model\JulActivityChannelUserModel;
use app\common\model\NewCardRefundDepositModel;
use app\common\model\PayLogModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserCouponModel;
use app\common\model\UserModel;
use app\common\model\UserUpgradeModel;
use app\common\traits\CacheTrait;
use think\facade\Db;

class CardService extends ComService
{
    use CacheTrait;

    /**2020-10-26  用户通过校验码开通实体卡
     * @param array $params
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function OpenEntityCard(array $params, int $userId)
    {
        $cardCheckCode = (string)$params['card_check_code'];//接收校验码
        $cardKey = (string)$params['card_key'];//接收公钥
        $entityCardModel = new EntityCardModel();
        $isRead = $params['is_read'];//接收是否已阅读
        if ($isRead != 1) {
            return $this->error(0, '请先阅读《绘阅年卡用户服务协议》');
        }
        if (!$cardCheckCode) {
            return $this->error(0, '请输入正确的校验码');
        }
        //首先判断用户有没有开卡 如果没有开卡进行下一步
        $where['is_expire'] = 1;
        $where['is_refund'] = 2;
        $resUserCardsData = UserCardsModel::getOneCards($userId);//获取用户年卡信息
        if ($resUserCardsData) {
            return $this->error(101, '目前仅支持每个用户拥有一张年卡，您已经是年卡用户，您可以等年卡过期后再激活，也可将该卡转给他人使用');
        }
        //查询卡号是否被激活
        $resEntityCardData = $entityCardModel->field('cardnumber,status,id,distributors_id')->where(['cdkey' => $cardKey, 'check_code' => $cardCheckCode])->findOrEmpty()->toArray();
        if (!$resEntityCardData) {
            return $this->error(0, '校验码无效');
        }
        //进行状态过滤
        switch ($resEntityCardData['status']) {
            case $resEntityCardData['status'] == 1 || $resEntityCardData['status'] == 2:
                return $this->error(0, '请联系售卡商开卡');
                break;
            case 4:
                return $this->error(0, '您输入的校验码已失效');
                break;
            case 5:
                return $this->error(0, '该卡已退卡');
                break;
            case 6:
                return $this->error(0, '该卡已回收');
                break;
            case 7:
                return $this->error(0, '该卡已作废');
                break;
        }
        //状态没有问题 开始准备开卡
        //启动事务 启动报错机制
        Db::startTrans();
        try {
            //修改卡的状态为激活状态
            $resEntityCardUpData = $entityCardModel->where('id', $resEntityCardData['id'])->update([
                'status' => 4,
                'activation_time' => time(),
                'activation_id' => $userId,
                'update_time' => time()]);
            if (!$resEntityCardUpData) {
                Db::rollback();
                return $this->error(0, '修改状态失败');
            }
            //写入激活日志
            $resEntityCardLog = (new EntityCardLogModel())->insert([
                'admin_id' => $userId,
                'create_time' => time(),
                'log_info' => '用户激活实体卡',
                'status' => 4,
                'distributors_id' => $resEntityCardData['distributors_id'],
                'entity_card_id' => $resEntityCardData['id']]);
            if (!$resEntityCardLog) {
                Db::rollback();
                return $this->error(0, '添加日志失败');
            }
            //写入年卡开通信息
            $resOpenCards = $this->openCards($userId);
            if ($resOpenCards['code'] != 200) {
                Db::rollback();
                return $resOpenCards;
            }
            //开通共享合伙人
            $resBusiness = (new BusinessService())->thisOpenBusiness($userId);
            if ($resBusiness['code'] != 200) {//写入失败 返回
                Db::rollback();
                return $resBusiness;
            }
            //提交事务
            Db::commit();
            return $this->success(200, '开通成功');
        } catch (\Throwable $e) {
            Db::rollback();
            trace('错误信息' . $e, 'error');
            $this->sendDingError('创建订单异常 用户ID:' . $userId, $e);
            return $this->error(500, '服务器异常，请稍后再试');
        }
    }


    /** 写入年卡信息
     * @param $userId
     * @return array
     */
    public function openCards($userId)
    {
        $cardDuration = (new CardsModel())->where('id',2)->value('card_duration');
        $resUserCardId = (new UserCardsModel())->UserCardsInsert($userId, 2,$cardDuration);//写入userCard表
        if (!$resUserCardId) {
            return $this->error(0, '创建会员失败');
        }
        $resUserCardDetails = UserCardDetailsModel::UserCardsDetailsInsert($userId, $resUserCardId,'',$cardDuration);//写入UserCardsDetails表
        if (!$resUserCardDetails) {
            return $this->error(0, '创建会员明细失败');
        }
        return $this->success();
    }

    /**获取成团数量
     * @return array
     * @author Poison
     * @date 2020/11/25 4:28 下午
     */
    public function getTeamList(): array
    {
        //获取当前拼图的团数量
        $resCardGroupInfoData = (new CardGroupInfoModel())->getTeamList();
        $cardGroupThreeNumber = $this->getCache('card_group_three_number') ?? 0;
        $cardGroupTwoNumber = $this->getCache('card_group_two_number') ?? 0;
        if (!$cardGroupTwoNumber) {
            $this->setCache('card_group_two_number', 1);
        }
        if (!$cardGroupThreeNumber) {
            $this->setCache('card_group_three_number', 1);
        }
        //初始化数据
        $two = (int)$cardGroupTwoNumber;
        $three = (int)$cardGroupThreeNumber;
//        if ($resCardGroupInfoData) {
//            foreach ($resCardGroupInfoData as $k => $v) {
//                if ($v['count'] == 2) {
//                    $two++;
//                } elseif ($v['count'] >= 3) {
//                    $three++;
//                }
//            }
//        }
        return $this->success(200, '获取成功', ['two' => $two, 'three' => $three]);
    }

    /** 获取可以参加的团
     * @param $userId
     * @param $param
     * @return array
     * @author Poison
     * @date 2020/11/25 5:08 下午
     */
    public function getAccessPayList(array $param, int $userId)
    {
        $CardGroupData = CardGroupModel::getCardGroupContent($param['page']);//获取当前尚未结束活动
        //初始化数据
        $resCardGroupInfoData = [];
        $i = 0;
        //开始读取数据
        foreach ($CardGroupData as $k => $v) {
            //判断活动时间是否超过设定
            if (($v['create_time'] + config('self_config.card_group_out_time')) < time()) {
                self::setDataModel($v['id'], $v['create_time'], $v['user_id']);
                unset($CardGroupData[$k]);
                continue;
            }
            //查询下面的人 进行数据组装
            $resCardGroupInfoData[$i]['list'] = CardGroupInfoModel::getPayList($v['id']) ?? [];
            $resCardGroupInfoData[$i]['out_time'] = $v['create_time'] + config('self_config.card_group_out_time') - time();
            $resCardGroupInfoData[$i]['is_finish'] = $v['is_finish'];
            $i++;
        }
        return $this->success(200, '获取成功', $resCardGroupInfoData);
    }

    /** 邀请用户进入团购
     * @param array $param
     * @param int $userId
     * @author Poison
     * @date 2020/11/25 5:59 下午
     */
    public function invite(array $param, int $userId): array
    {
        //查询是否是已加入该团
        $resCardGroupInfo = (new CardGroupInfoModel())
            ->where(['user_id' => $userId, 'card_group_id' => $param['card_group_id']])
            ->findOrEmpty()->toArray();
        if ($resCardGroupInfo) {
            return $this->error(100, '您已经参加了该拼团了，快去邀请吧');
        }
        //判断邀请人是否是再该团内
        $resCardGroupInfoSelect = (new CardGroupInfoModel())
            ->where(['user_id' => $param['top_id'], 'card_group_id' => $param['card_group_id']])
            ->findOrEmpty()->toArray();
        if (!$resCardGroupInfoSelect) {
            return $this->error(100, '邀请人没有参加拼图');
        }
        //判断是否成团+ 或者到时间
        $resCardGroup = (new CardGroupModel())
            ->where('id', $resCardGroupInfoSelect['card_group_id'])
            ->findOrEmpty()->toArray();
        if ($resCardGroup) {
            //判断用户是否成团
            $outTime = time() - config('self_config.card_group_out_time');
            if ($resCardGroup['is_finish'] == 1) {
                return $this->error(3001, '非常抱歉！该团已拼团成功，请选择参与其他拼团');
            }
            if ($resCardGroup['create_time'] < $outTime) {//超过时间了 修改为成团
                self::setDataModel($param['card_group_id'], $resCardGroup['create_time'], $resCardGroup['user_id']);
                return $this->error(3001, '非常抱歉！该团已拼团成功，请选择参与其他拼团');
            }

        }
        //开启事务
        Db::startTrans();
        $data['user_id'] = $userId;
        $data['card_group_id'] = $param['card_group_id'];
        $resAddCardGroupInfo = CardGroupInfoModel::addCardGroupInfo($data);//写入记录表
        if (!$resAddCardGroupInfo) {
            Db::rollback();
            return $this->error(100, '添加失败');
        }

        (new CardGroupLogModel())->insert(array(//写入日志
            'user_id' => $userId,
            'card_group_id' => $param['card_group_id'],
            'info' => '用户ID：' . $param['top_id'] . " 邀请 用户ID：" . $userId . '入团成功',
            'create_time' => time()
        ));
        (new CardGroupInviteModel())->insert([
            'user_id' => $userId,
            'top_id' => $param['top_id'],
            'card_group_id' => $param['card_group_id'],
            'create_time' => time()
        ]);
        Db::commit();
        return $this->success(200, '入团成功', self::getOneData($userId, 1));
    }

    /**获取最后一个 邀请团
     * @param int $userId
     * @param int $type
     * @return array
     * @author Poison
     * @date 2020/11/26 4:07 下午
     */
    public function getOneData(int $userId, int $type = 0): array
    {
        //判断自己是否已经支付且没有退团
        $cardGroupId = (new CardGroupInfoModel())->where('user_id', $userId)->where('type', 1)->where('is_out', 0)->value('card_group_id');
        if ($cardGroupId) {//如果有阻拦
            return $this->error(100, '您已经成功拼团了，快去借书吧');
        }
        //获取所有邀请
        $t = time() - config('self_config.card_group_out_time');
        $resCardGroupInviteData = CardGroupInviteModel::getList($userId, $t);
        //查询状态
        if (!$resCardGroupInviteData) {
            //如果没有 查询最❤️的一个
            $resCardGroupInviteData = CardGroupInviteModel::getLowList($userId);
            if (!$resCardGroupInviteData) {
                return $this->error(100, '非常抱歉！您还没有参团');
            }
//            CardGroupModel::setOutTime($resCardGroupInviteData['card_group_id'],$resCardGroupInviteData['create_time'] + config('self_config.card_group_out_time'));
            return $this->error(3002, '团满了', $resCardGroupInviteData);
        }
        $resCardGroupInfoData = (new CardGroupInfoModel())->getList($resCardGroupInviteData['card_group_id']);
        $resData = [];
        $i = 0;
        foreach ($resCardGroupInfoData as $k => $v) {
            $resCardGroupInfoData[$k]['is_invite'] = 0;
            if ($v['user_id'] == $resCardGroupInviteData['top_id']) {
                $resCardGroupInfoData[$k]['is_invite'] = 1;
            }
            if ($v['type'] == 0) {
                unset($resCardGroupInfoData[$k]);
                continue;
            }
            if ($userId == $v['user_id'] && $v['type'] == 1 && $v['is_out'] == 1) {
                return $this->success();
            }
            $resData[$i] = $resCardGroupInfoData[$k];
            $i++;
        }
        if ($type == 1) {
            return $resData;
        }

        return $this->success(200, '获取成功', $resData);

    }

    /**获取前五条数据
     * @return array
     * @author Poison
     * @date 2020/11/27 10:30 上午
     */
    public function getFiveList()
    {
        return $this->success(200, '获取成功', CardGroupModel::getFive());
    }

    /**获取当前用户支付且没有退款的团
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2020/12/2 10:56 上午
     */
    public function thisCardGroup(int $userId)
    {
        //获取当前用户支付且没有退款的团
        $cardGroupId = (new CardGroupInfoModel())->where('user_id', $userId)->where('type', 1)->where('is_out', 0)->value('card_group_id');
        $cardGroupInfoData = (new CardGroupInfoModel())->alias('cgi')
            ->field('cgi.card_group_id,cgi.create_time,cgi.price,cgi.type,cgi.is_out,cgi.user_id,cg.is_finish,cg.create_time as open_time,u.user_name,u.nickname,u.head_pic')
            ->join('rd_card_group cg', 'cgi.card_group_id = cg.id')
            ->join('rd_users u', 'cgi.user_id = u.user_id')
            ->where('cgi.type', 1)
            ->where('cgi.card_group_id', $cardGroupId)
            ->order('cgi.id','ASC')
            ->select()->toArray();
        $isUp = 0;
        foreach ($cardGroupInfoData as $k => $v) {
            $outTimes = config('self_config.card_group_out_time') + $v['open_time'];
            if ($v['is_finish'] == 0 && $outTimes <= time()) {
                if ($isUp == 0) {
                    self::setDataModel($cardGroupId, $v['open_time'], $v['user_id']);
                    $isUp++;
                }
                $cardGroupInfoData[$k]['is_finish'] = 1;
            }
        }
        return $this->success(200, '获取成功', $cardGroupInfoData);
    }

    /**判断用户是否支付2次或超过2次
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2020/12/3 4:20 下午
     */
    public function getIsPay(int $userId)
    {
        //查询用户的拼团支付过的次数
        $count = (new CardGroupInfoModel())->where(['user_id' => $userId, 'type' => 1])->count();
        if ($count >= 20) {
            return self::error(100, '非常抱歉！您没有拼团资格');
        }
        return self::success();
    }

    /**设置拼团完成
     * @param $cardGroupId
     * @param $openTime
     * @param $userId
     * @author Poison
     * @date 2020/12/7 11:22 上午
     */
    private function setDataModel($cardGroupId, $openTime, $userId)
    {
        CardGroupModel::setOutTime($cardGroupId, $openTime + config('self_config.card_group_out_time'));
        (new CardGroupLogModel())->insert([
            'user_id' => $userId,
            'card_group_id' => $cardGroupId,
            'info' => '拼团超时，自动成团',
            'create_time' => time()
        ]);
    }

    public static function getPrice($userId, $card, $isType = 0)
    {
        $data['now_price'] = $cardPrice = $card['price'];
        $cardId = $card['id'];
        $isHold = 0;
        $cardPrice399 = config('self_config.card_price_399');
        //查询上级
        $topId = (new BusinessRelationModel())->getOneData($userId);
        if ($isType != 4) {//2020-11-7 版本控制
                $bindType = (new BusinessRelationModel())->getOneData($userId);
                if ($bindType) {
                    $cardPrice = $cardPrice399;
                }
            //TODO 2020-11-20 新增掌灯人规则 判断用户的上级是否是自主支付
            if ($isType == 5) {
                if ($topId) {//如果有上级进入判断
                    $userCardDetails = UserCardDetailsModel::getCardDetailsData(['user_id' => $topId, 'pay_money' => ['>', 0], 'pay_time' => ['>', 0], 'card_id' => $cardId], 'pay_money', 'id DESC');
                    if (!$userCardDetails) {
                        //判断是不是股东
                        $isPartner = (new UserModel())->getOneUser($userId, 1, 'is_partner');
                        $isHold = 0;
                        $cardPrice = $card['price'];
                        if ($isPartner == 1) {
                            $isHold = 1;
                            $cardPrice = $cardPrice399;
                        }
                    }
                }
            }
        }
//        if (in_array($topId, config('self_config.special_user_pay_card'))) {
//            $cardPrice = $cardPrice399;
//            $isHold = 1;
//        }
        $data['price'] = $cardPrice;
        $data['is_hold'] = $isHold;
        return $data;

    }

    /**获取年卡列表
     * @param $params
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/23 4:24 下午
     */
    public function getCards($params,int $userId){
        $cardsData = (new CardsModel())->getShowCards('*');
        if(!$cardsData){
            return self::error(100,'暂无数据');
        }

        //验证用户是否参与七月邀请买赠活动(0元升级年卡，699年卡权益免费得)
        $jul_activity_user = JulActivityChannelUserModel::getUserOrFatherByUserId($userId);
        $jul_activity_conf = config('self_config.jul_activity');
        //七月活动开始时间
        $jul_activity_start = strtotime($jul_activity_conf['activity_start_time']);
        //七月活动结束时间
        $jul_activity_end = strtotime($jul_activity_conf['activity_end_time']);

        $user = (new UserModel())->getOneUser($userId);
        $isDeposit = true;
        $outDeposit = 0;
        self::getUserCardDeposit($userId,$isDeposit,$outDeposit);
        foreach ($cardsData as $k=>$v){
            $cardsData[$k]['low_card_deposit'] = $v['card_deposit'];
            if ($v['id'] == 2) {
                $priceData = self::getPrice($userId, $v, $params['is_type']);
                $cardsData[$k]['price'] = $priceData['price'];
                $cardsData[$k]['is_hold'] = $priceData['is_hold'];
                $cardsData[$k]['now_price'] = $priceData['now_price'];
            }
            if(!$isDeposit){
                if(($v['card_deposit'] - $outDeposit) <= 0 ){
                    $cardsData[$k]['card_deposit'] = 0;
                   // $cardsData[$k]['card_deposit'] = $v['card_deposit'] - $outDeposit;
                }

            }
            $cardsData[$k]['info'] = [];
            if(config('self_config.is_card_info')){//增加年限购买
                $cardsData[$k]['info'] = self::getCardYearsPrice($v['id']);
            }

            $channelId = (new BusinessOrganUserModel())->where(['user_id'=>$userId,'status'=>1])->value('channel_id');
            if($v['id'] == 5){
                if(in_array($channelId,config('self_config.season_card_channel'))){
                    $isUpgrade = (new UserUpgradeModel())->where(['user_id'=>$userId,'is_paid'=>1])->findOrEmpty()->toArray();
                    if($isUpgrade){//是否交过押金
                        unset($cardsData[$k]);
                        continue;
                    }
                    //判断用户是否买过卡
                    $userCard = (new UserCardsModel())->where('user_id',$userId)->findOrEmpty()->toArray();
                    if($userCard){
                        unset($cardsData[$k]);
                        continue;
                    }
                }else{
                    unset($cardsData[$k]);
                    continue;
                }
            }

            if($v['card_type'] == 2){  //体验卡
                //押金等级为0可购买
                if($user['grade'] != 0){
                    unset($cardsData[$k]);
                    continue;
                }

                //非指定渠道不可购买
                if($v['buy_channel'] != 0 && !in_array($channelId, explode(',', $v['buy_channel']))){
                    unset($cardsData[$k]);
                    continue;
                }

                //未购卡用户才可购买体验卡(存在记录就算)
                $user_card = UserCardsModel::getByUserId($userId);
                if(!empty($user_card)){
                    unset($cardsData[$k]);
                    continue;
                }

                //存在体验卡购买记录，不可购买
                $experience_card = UserCardsModel::getByUserIdAndCardType($userId, 2);
                if(!empty($experience_card)){
                    unset($cardsData[$k]);
                    continue;
                }
            }

            //活动时间内，七月邀请买赠活动(0元升级年卡，699年卡权益免费得)参与用户不支持使用优惠券
            if(!empty($jul_activity_user) && time() >= $jul_activity_start && time() <= $jul_activity_end){
                $cardsData[$k]['is_use_coupon'] = 0;
                $cardsData[$k]['unuse_coupon_msg'] = '您正在参加“0元升级年卡，699权益免费得”活动，此活动不可使用优惠券';
            }
        }
        return self::success(200,'获取成功',array_merge($cardsData));
    }

    protected function getUserCardDeposit($userId,&$isDeposit,&$outDeposit)
    {
        $res = (new UserCardsModel())->getByUserCardInfo($userId);
        if ($res && isset($res['card_deposit']) && !empty($res['card_deposit'])) {
            if(!empty($res['id'])){
                //如果有押金 判断是否退卡
                $newCardRefund = NewCardRefundDepositModel::getDataByUserId($userId,$res['id']);
                if(empty($newCardRefund)){
                    $isDeposit = false;
                    $outDeposit = $res['card_deposit'];
                }
            }
        }
        return  true;
    }


    /**
     * 获取用户未使用的年卡优惠券
     * @param int $user_id  用户ID
     * @param int $card_id  年卡ID
     * @param int $card_price_id  年卡时长价格ID
     * @return array
     * @author yangliang
     * @date 2021/3/12 10:50
     */
    public function getCardCoupon(int $user_id, int $card_id, int $card_price_id){
        $card = CardsModel::getById($card_id);
        if(empty($card)){
            return $this->error(100, '年卡信息不存在');
        }

        $card_price = CardPriceSetModel::getByIdAndCardId($card_price_id, $card_id);


        //用户优惠券
        $user_coupon = UserCouponModel::getUserUnuseCardCoupon($user_id, !empty($card_price) ? $card_price['price'] : $card['price']);
        if(!empty($user_coupon)){
            foreach ($user_coupon as &$v){
                $v['expire_time'] = date('Y-m-d H:i:s', $v['expire_time']);
                unset($v['coupon_keys']);
            }
        }

        //用户体验券
        $user_experience = UserCouponModel::getUserUnuseExperience($user_id, $card_id);
        if(!empty($user_experience)){
            foreach ($user_experience as &$v){
                $v['expire_time'] = date('Y-m-d H:i:s', $v['expire_time']);
                unset($v['coupon_keys']);
            }
        }
        return $this->success(200, '获取成功', ['user_coupon' => $user_coupon, 'user_experience' => $user_experience]);
    }

    /**修改
     * @param int $cardId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/4/26 10:24 上午
     */
    public static function getCardYearsPrice(int $cardId): array
    {
       return CardPriceSetModel::getInfoByCardId($cardId)??[];

    }
}