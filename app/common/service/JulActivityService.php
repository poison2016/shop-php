<?php
/**
 * JulActivityService.php
 * 七月邀请买赠活动相关
 * author yangliang
 * date 2021/7/14 17:17
 */

namespace app\common\service;


use app\common\exception\ApiException;
use app\common\model\CardsModel;
use app\common\model\InviteRecordModel;
use app\common\model\JulActivityChannelUserModel;
use app\common\model\JulActivityChannelUserSettleModel;
use app\common\model\NewCardRefundDepositModel;
use app\common\model\PayLogModel;
use app\common\model\UserCardChangeLogModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserCardsOperationLogs;
use app\common\model\UserCardsOperationLogsModel;
use app\common\model\UserLevelModel;
use app\common\model\UserModel;
use Exception;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;
use think\facade\Log;
use think\facade\Log as LogSave;
use wechat\Pay;

class JulActivityService extends ComService
{

    /**
     * 验证用户是否有邀请权限
     * 年卡会员、押金会员
     * 需求：
     *     渠道一级用户不受年卡和押金限制，可直接参与活动
     *     只要用户存在参与活动记录，无论用户年卡过期或退卡，仍然可以继续参与活动
     *     年卡或押金用户首次自主进入活动（非邀请），创建一条活动参与记录
     * @param int $user_id  用户ID
     * @return array
     * @throws Exception
     * @author: yangliang
     * @date: 2021/7/15 10:45
     */
    public function checkUserInvite(int $user_id): array
    {
        $user = (new UserModel())->getOneUser($user_id);
        if(empty($user)){
            return $this->error(100, '用户信息异常');
        }

        //渠道一级用户不受限制，可以直接参与活动
        $top_user = JulActivityChannelUserModel::getByUserIdAndIsTop($user_id, 1);
        if(!empty($top_user)){
            return $this->success(200, 'success');
        }

        //未参与记录（此参与记录指没有邀请权限，没有自主进入过记录）
        $not_involved = JulActivityChannelUserModel::getNoFatherByUserId($user_id);
        //非押金用户，验证年卡
        if($user['grade'] < 1 && time() > $user['user_deposit_expire_time']){
            //年卡用户
            $user_card = UserCardsModel::getByUserId($user_id);
            //不存在年卡或者年卡过期或已退卡，且没有参与活动记录，需要办理年卡（需求：只要之前参与过，即使年卡过期也能继续参与活动）
            if ((empty($user_card) || $user_card['is_expire'] == 2 || $user_card['is_refund'] == 1) && empty($not_involved)) {
                return $this->error(-1, '您还不是年卡用户，请购买年卡后参与活动');
            }

            //退卡中不支持参与活动，（需求：只要之前参与过，即使已退卡也能参与活动）
            if(!empty($user_card)) {
                if (in_array($user_card['refund_card_status'], [1, 2]) && empty($not_involved)) {
                    return $this->error(100, '您有正在处理的退卡申请，您需处理完成后才可参加此活动。');
                }
            }
        }

        //用户参与活动记录
        $jul_user = JulActivityChannelUserModel::getByUserId($user_id);

        //无参与记录，进行初始化
        if(empty($jul_user)){
            $res = JulActivityChannelUserModel::create([
                'user_id' => $user_id,
                'channel_id' => 1,
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ]);
            if(!$res){
                return $this->error(100, '系统异常');
            }
        }

        return $this->success(200, 'success');
    }


    /**
     * 邀请
     * 需求：
     *     自己不能邀请自己
     *     渠道一级用户不支持被邀请
     *     存在死绑关系，不支持转绑：
     *         活动时间内上级邀请下级，下级在72小时内购买任意年卡后两者关系即死绑
     *     邀请成功后绑定关系72小时内不支持转绑：
     *         活动时间内，上级邀请下级后产生绑定关系，下级72小时内未购卡，则绑定关系失效，72小时后下级可被任意用户邀请
     *     同一用户被相同上级多次邀请，不创建新的邀请记录，只更新原邀请记录的邀请时间
     * @param array $params
     * @return array
     * @author: yangliang
     * @date: 2021/7/15 14:37
     */
    public function invite(array $params): array
    {
        Db::startTrans();
        try {
            $jul_activity_conf = config('self_config.jul_activity');
            if(time() < strtotime($jul_activity_conf['activity_start_time']) || time() > strtotime($jul_activity_conf['activity_end_time'])){
                throw new Exception('活动暂未开始或已结束');
            }

            if($params['user_id'] == $params['parent_id']){
                throw new Exception('自己不能邀请自己');
            }

            //获取上级用户渠道信息
            $parent_user = JulActivityChannelUserModel::getByUserId($params['parent_id']);
            $channel_id = !empty($parent_user) ? $parent_user['channel_id'] : 1;  //渠道默认自然渠道

            //验证被邀请用户是否锁定
            $relation = JulActivityChannelUserModel::getEffectiveByUserId($params['user_id']);
            if(!empty($relation)){
                if($relation['is_top'] == '1'){
                    throw new Exception('渠道一级不可转绑');
                }

                if(intval($relation['status']) > 1){
                    throw new Exception('用户存在死绑关系');
                }

                if(($relation['create_time'] + intval($jul_activity_conf['keep_time'] * 60 * 60)) > time() && $relation['father_id'] > 0){
                    throw new Exception('关系锁定期内，关系绑定未超过'.$jul_activity_conf['keep_time'].'小时');
                }
            }

            //同一用户多次邀请，只更新渠道和邀请时间，不创建新邀请记录
            if(!empty($relation) && $relation['father_id'] == $params['parent_id']){
                $res = JulActivityChannelUserModel::where('id', $relation['id'])
                    ->update(['channel_id' => $channel_id, 'create_time' => time(), 'update_time' => time()]);
            }else {
                //更新用户之前关系为作废
                JulActivityChannelUserModel::where('user_id', $params['user_id'])
                    ->where('status', '<', '2')->update(['status' => 0, 'update_time' => time()]);
                //建立新关系
                $res = JulActivityChannelUserModel::create([
                    'user_id' => $params['user_id'],
                    'father_id' => $params['parent_id'],
                    'channel_id' => $channel_id,
                    'is_top' => 0,
                    'status' => 1,
                    'create_time' => time(),
                    'update_time' => time()
                ]);
            }

            if(!$res){
                throw new Exception('绑定关系建立失败');
            }

            //用户邀请成功记录
            InviteRecordModel::addRecord($params['user_id'], $params['parent_id'], 'jul_activity', 1);
        }catch (Exception $e){
            Db::rollback();
            //用户邀请失败记录
            InviteRecordModel::addRecord($params['user_id'], $params['parent_id'], 'jul_activity', 0, $e->getMessage());
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '邀请成功');
    }

    /**获取用户邀请列表
     * @param array $params
     * @return array
     * @author Poison
     * @date 2021/7/15 3:33 下午
     */

    public function inviteList(array $params): array
    {
        $data = JulActivityChannelUserModel::getListByUserId($params['page'], [['cu.father_id', '=', $params['user_id']], ['cu.status', '<>', '0']]);
//        $ok = 0;
        foreach ($data as &$v) {
            $v['card_name'] = '';
            $v['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
            if ($v['bind_time'] > 0) {//如果买卡
//                $ok++;
                $v['bind_time'] = date('Y-m-d H:i:s', $v['bind_time']);
                //查询年卡名称
                $v['card_name'] = UserCardsModel::getUserCardListByUserId($v['user_id'])['name'] ?? '';
            }else{
                $v['bind_time'] = '';
            }
        }
        return successArray([
            'list' => $data,
            'sum' => JulActivityChannelUserModel::getListCountByUserId($params['user_id']),
            'limit' => config('self_config.coupon_limit'),
            'page' => $params['page'],
            'ok' => JulActivityChannelUserSettleModel::getCountByUserId($params['user_id'])
        ]);
    }

    /**获取用户完成列表
     * @param int $userId
     * @param int $page
     * @return array
     * @author Poison
     * @date 2021/7/18 10:14 上午
     */
    public function receiveList(int $userId,int $page): array
    {
        $v3_time = strtotime(config('self_config.jul_activity.v3_time'));
        $okCount = 0;
        $name = '';
        $grade = (new UserModel())->getOneUser($userId,1,'grade');
        $userCardData = UserCardsModel::getByUserId($userId);
        if($userCardData){
            $name = CardsModel::getById($userCardData['card_id'])['name'];
            if(config('self_config.jul_activity.secondary_card.card_id') == $userCardData['card_id']){
                $name = CardsModel::getById(config('self_config.jul_activity.secondary_card.gift_card_id'))['name'];
            }

        }
        if($grade > 0 && !$userCardData){
            $name = UserLevelModel::getByGrade((int)$grade)['level_name'];
        }

        $card_name = CardsModel::getById(7)['name'];
        $list = JulActivityChannelUserSettleModel::getByUserId($userId, $page);
        if(!empty($list)){
            foreach ($list as &$v){
                //V3版本兼容V1V2
                if($v['create_time'] > $v3_time){
                    $name = (!empty($userCardData) && in_array($userCardData['card_id'], [2, 7])) ? $name : $card_name;
                }

                $v['this_card_name'] = '';
                if( $v['father_give_time']){
                    $v['this_card_name'] = $name . $v['father_give_time'] / 86400 . '天';
                }
                $v['business_accounting_time'] = !empty($v['business_accounting_time']) ? date('Y-m-d H:i:s', $v['business_accounting_time']) : '';

                $record = JulActivityChannelUserModel::getBySettleAndFatherId($v['id'], $v['user_id']);
                if(!empty($record)){
                    foreach ($record as &$rv){
                        $rv['card_name'] = '';
                        $rv['give_time'] = '';
                        //查询年卡名称
                        $rv['card_name'] = CardsModel::getById((int)$rv['user_give_type'])['name'];
                        if ((int)$rv['status'] == 3) {
                            $rv['give_time'] = date('Y-m-d H:i:s', $rv['update_time']);
                        }
                        $rv['bind_time'] = ($rv['bind_time'] > 0) ? date('Y-m-d H:i:s', $rv['bind_time']) : '';
                    }
                }
                $v['record'] = $record;
            }
        }

        $user = (new UserModel())->getOneUser($userId);
        $msg = '';

        //V3版本更新时间后领取，显示弹框
        if(time() > strtotime(config('self_config.jul_activity.v3_time'))) {
            $card = isset($userCardData['card_id']) ? CardsModel::getById($userCardData['card_id']) : [];
            $grade = UserModel::alias('u')->join('user_level ul', 'u.grade = ul.grade')
                ->where('u.user_id', $userId)->find();
            //获取用户当前身份
            if (!empty($userCardData) && $userCardData['is_refund'] == 2 &&
                $user['grade'] > 0 && time() < $user['user_deposit_expire_time']) {
                if ($userCardData['is_lock'] == 1) {  //年卡未激活
                    $card_detail = UserCardDetailsModel::where('user_card_id', $userCardData['id'])->find();
                    $card_time = ceil($card_detail['card_open_time'] / 60 / 60 / 24);
                } else {
                    $card_time = ceil(($userCardData['end_time'] - $userCardData['start_time']) / 60 / 60 / 24);
                }
                if ($userCardData['card_id'] == 2) {
                    $msg = '您目前是' . $grade['level_name'] . '+' . $card['name'] . '用户，领取成功后将升级为' . $card['name'] . '资格，有效期为360天+剩余年卡时长' . $card_time . '天+剩余押金时长' . ceil(($user['user_deposit_expire_time'] - time()) / 60 / 60 / 24) . '天';
                } else {
                    $msg = '您目前是' . $grade['level_name'] . '+' . $card['name'] . '用户，领取成功后将升级为699年卡资格，有效期为360天+剩余年卡时长' . $card_time . '天+剩余押金时长' . ceil(($user['user_deposit_expire_time'] - time()) / 60 / 60 / 24) . '天';
                }
            } elseif (!empty($userCardData) && $userCardData['is_refund'] == 2) {  //年卡用户
                if ($userCardData['is_lock'] == 1) {  //年卡未激活
                    $card_detail = UserCardDetailsModel::where('user_card_id', $userCardData['id'])->find();
                    $card_time = ceil($card_detail['card_open_time'] / 60 / 60 / 24);
                } else {
                    $card_time = ceil(($userCardData['end_time'] - $userCardData['start_time']) / 60 / 60 / 24);
                }
                if (!in_array($userCardData['card_id'], [2, 7])) {
                    $msg = '您目前是' . $card['name'] . '用户，领取成功后将升级为699年卡资格，有效期为360天+剩余年卡时长' . $card_time . '天';
                }
            } elseif ($user['grade'] > 0 && time() < $user['user_deposit_expire_time']) {  //押金用户
                $msg = '您目前是' . $grade['level_name'] . '押金用户，领取后将升级为699年卡资格，有效期为360天+剩余押金时长' . ceil(($user['user_deposit_expire_time'] - time()) / 60 / 60 / 24) . '天';
            }
        }

        return successArray([
            'list' => $list,
            'sum'=> JulActivityChannelUserModel::getListCountByUserId($userId),
            'limit' => config('self_config.coupon_limit'),
            'page' => $page,
            'ok' => JulActivityChannelUserSettleModel::getCountByUserId($userId),
            'msg' => $msg
        ]);
    }


    /**
     * 领取活动赠送时长
     * @param array $params
     * @return array
     * @throws ApiException
     * @author: yangliang
     * @date: 2021/7/16 16:22
     */
    public function giveMe(array $params): array
    {
        Db::startTrans();
        try {
            $jul_activity_conf = config('self_config.jul_activity');
            if (time() < strtotime($jul_activity_conf['receive_start_time']) || time() > strtotime($jul_activity_conf['receive_end_time'])) {
                throw new Exception('当前时间不在领取时间范围内，无法领取');
            }

            $user = (new UserModel())->getOneUser($params['user_id']);
            if(empty($user)){
                return $this->error(100, '用户信息异常');
            }

            $relation = JulActivityChannelUserSettleModel::getById($params['id']);
            if(empty($relation)){
                throw new Exception('赠送记录不存在');
            }

            if($relation['status'] != 0){
                throw new Exception('领取失败，赠送记录已领取');
            }

            //新规则兼容老规则
//            if($relation['create_time'] < strtotime($jul_activity_conf['v3_time'])){
//                //老规则
//                $res = self::oldGift($params['user_id'], $jul_activity_conf, $user, $params['id']);
//                if($res['code'] != 200){
//                    return $this->error($res['code'], $res['message'], $res['data']);
//                }
//            }else{
                //新规则
                $res = self::newGift($params['user_id'], $jul_activity_conf, $user, $params['id']);
                if($res['code'] != 200){
                    return $this->error($res['code'], $res['message'], $res['data']);
                }
//            }

        }catch (Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '领取成功');
    }


    /**
     * 9.9元次卡领取活动赠送时长
     * 过期9.9元次卡
     * 添加699年卡（年卡时长为活动赠送时长 + 9.9元次卡剩余时长）
     * +
     * V3 过期当前年卡，开通新699年卡
     * @param int $user_id 用户ID
     * @param int $father_give_time 赠送时长：小时
     * @param array $user_card 用户年卡
     * @param int $settle_id 赠送时长记录ID
     * @param int $gift_card_id 赠送年卡ID
     * @param int $deposit_time 押金转移时长
     * @throws Exception
     * @author: yangliang
     * @date: 2021/7/16 16:20
     */
    private function addCard(int $user_id, int $father_give_time, array $user_card, int $settle_id, int $gift_card_id, int $deposit_time = 0): void
    {
        $nowTime = time();
        $grade = UserModel::alias('u')->join('user_level ul', 'u.grade = ul.grade')->where('u.user_id', $user_id)->find();
        //存在押金时长，直接过期，押金转出
        if($deposit_time > 0){
            $deposit_res = UserModel::where('user_id', $user_id)->update(['user_deposit_expire_time' => time()]);
            if(!$deposit_res){
                throw new Exception('押金过期失败');
            }
            UserCardChangeLogModel::create([
                'user_id' => $user_id,
                'user_card_id' => 0,
                'card_id' => 0,
                'card_name' => 0,
                'change_duration' => sprintf('-%d天', ceil($deposit_time / 60 / 60 / 24)),
                'change_name' => '0元升级年卡，699年卡权益免费得；活动'.$grade['level_name'].'剩余时长转出',
                'change_reason' => 6,
                'relation_mobile' => '',
                'change_low_start_time' => 0,
                'change_low_end_time' => 0,
                'change_new_start_time' => 0,
                'change_new_end_time' => time(),
                'create_time' => time()
            ]);
        }

        //赠送年卡
        $card = CardsModel::getById($gift_card_id);
        //当前年卡剩余时长
        $card_time = ($user_card['end_time'] - time());
        $card_time = $card_time < 1 ? 0 : $card_time;
        //赠送时长
        $gift_time = $father_give_time * 60 * 60;

        $user_card_detail = UserCardDetailsModel::getByUserCardId($user_card['id']);
        //计算现年卡剩余赠送时长
        $time = $user_card['end_time'] - $user_card['start_time'];
        $give_card_time = (($time - $user_card['give_card_time']) > 0) ? $user_card['give_card_time'] : $time;
        //过期当前年卡
        if($user_card['is_lock'] == 1){
            $card_res = UserCardsModel::where('id', $user_card['id'])
                ->update(['is_expire' => 2, 'expire_time' => time(), 'update_time' => time()]);
            $card_detail_res = UserCardDetailsModel::where('id', $user_card_detail['id'])
                ->update(['card_deposit_status' => 2, 'transfer_card_deposit' => $user_card_detail['card_deposit'], 'update_time' => time(), 'card_open_time' => 0]);
        }else {
            $card_res = UserCardsModel::where('id', $user_card['id'])
                ->update(['is_expire' => 2, 'expire_time' => time(), 'update_time' => time(), 'end_time' => time()]);
            $card_detail_res = UserCardDetailsModel::where('id', $user_card_detail['id'])
                ->update([
                    'card_deposit_status' => 2,
                    'transfer_card_deposit' => $user_card_detail['card_deposit'],
                    'update_time' => time(),
                    'card_open_time' => ceil(($user_card['end_time'] - $user_card['start_time']) / 60 / 60 / 24)
                ]);
        }
        if(!$card_res || !$card_detail_res){
            throw new Exception('当前年卡过期失败');
        }

        //记录当前年卡变动时长，老年卡转出
        $old_card = CardsModel::getById($user_card['card_id']);
        $change_duration =  ceil(($user_card['end_time'] - time()) / 60 / 60 / 24);
        UserCardChangeLogModel::create([
            'user_id' => $user_id,
            'user_card_id' => $user_card['id'],
            'card_id' => $user_card['card_id'],
            'card_name' => $old_card['name'],
            'change_duration' => sprintf('-%d 天', $change_duration),
            'change_name' => '0元升级年卡，699年卡权益免费得；活动，'.$old_card['name'].'时长转出',
            'change_reason' => 6,
            'relation_mobile' => '',
            'change_low_start_time' => $user_card['start_time'],
            'change_low_end_time' => $user_card['end_time'],
            'change_new_start_time' => $user_card['start_time'],
            'change_new_end_time' => time(),
            'create_time' => time()
        ]);

        //添加新年卡
        $card_data = [
            'user_id' => $user_id,
            'card_id' => $card['id'],
            'start_time' => $nowTime,
            'end_time' => ($nowTime + $gift_time),
            'is_expire' => 1,
            'surplus_ship_num' => $card['ship_num'],
            'before_user_card_id' => 0,
            'create_time' => $nowTime,
            'update_time' => $nowTime,
            'is_lock' => 0,
            'unlock_time' => 0,
            'is_double' => 1,
            'is_activity' => 0,
            'give_card_time' => ($give_card_time ?? 0) + $gift_time
        ];

        $card_res = UserCardsModel::create($card_data);
        if(!$card_res){
            throw new Exception('新年卡创建失败');
        }

        $card_detail = [
            'user_id' => $user_id,
            'card_id' => $card['id'],
            'money' => $card['price'],
            'pay_money' => 0,
            'deposit_money' => 0,
            'extra_money' => 0,
            'describe' => '',
            'action_type' => 5,
            'refund_status' => 1,
            'pay_time' => $nowTime,
            'create_time' => $nowTime,
            'update_time' => $nowTime,
            'user_card_id' => $card_res['id'],
            'out_trade_no' => '',
            'is_double' => 0,
            'card_open_time' => $gift_time,
            'card_deposit' => $card['card_deposit'],
            'experience_card_id' => 0,
            'experience_add_card_days' => 0
        ];

        $card_detail_res = UserCardDetailsModel::create($card_detail);
        if(!$card_detail_res){
            throw new Exception('新年卡明细创建失败');
        }

        //记录当前年卡操作日志
        UserCardsOperationLogsModel::create([
            'user_id' => $user_id,
            'user_type' => 2,
            'user_card_id' => $user_card['id'],
            'create_time' => $nowTime,
            'log_info' => '0元升级年卡，699年卡权益免费得；领取赠送年卡时长，过期当前用户年卡ID：'.$user_card['id'].'，转移年卡押金至新年卡：'.$card_res['id']
        ]);

        //赠送年卡时长
        $relation_arr = JulActivityChannelUserModel::getBySettleId($settle_id);
        if(!empty($relation_arr)){
            foreach ($relation_arr as $av){
                //记录年卡变动时长
                UserCardChangeLogModel::create([
                    'user_id' => $user_id,
                    'user_card_id' => $card_res['id'],
                    'card_id' => $card['id'],
                    'card_name' => $card['name'],
                    'change_duration' => sprintf('+%d 天', ceil(($father_give_time / 24) / count($relation_arr))),
                    'change_name' => '0元升级年卡，699年卡权益免费得；赠送'.$card['name'],
                    'change_reason' => 6,
                    'relation_mobile' => $av['mobile'],
                    'change_low_start_time' => $user_card['start_time'],
                    'change_low_end_time' => $user_card['end_time'],
                    'change_new_start_time' => $card_data['start_time'],
                    'change_new_end_time' => $card_data['end_time'],
                    'create_time' => time()
                ]);
            }
        }

        //老年卡时长转入
        $uc_res = UserCardsModel::where('id', $card_res->id)
            ->inc('give_card_time', $card_time)->inc('end_time', $card_time)->update();
        //更新年卡时长
        $ucd_res = UserCardDetailsModel::where('user_card_id', $card_res->id)->inc('card_open_time', $card_time)->update();
        if(!$uc_res || !$ucd_res){
            throw new Exception('老年卡时长转入失败');
        }
        $user_card = UserCardsModel::getById($user_card['id']);
        UserCardChangeLogModel::create([
            'user_id' => $user_id,
            'user_card_id' => $card_res->id,
            'card_id' => $card['id'],
            'card_name' => $card['name'],
            'change_duration' => sprintf('+%d 天', ceil($card_time / 60 / 60 / 24)),
            'change_name' => '0元升级年卡，699年卡权益免费得；活动，'.$card['name'].'时长转为年卡时长',
            'change_reason' => 6,
            'relation_mobile' => '',
            'change_low_start_time' => $nowTime,
            'change_low_end_time' => ($nowTime + $gift_time),
            'change_new_start_time' => $user_card['start_time'],
            'change_new_end_time' => $user_card['end_time'],
            'create_time' => time()
        ]);

        //老押金时长转入
        if($deposit_time > 0){
            $uc_res = UserCardsModel::where('id', $user_card['id'])
                ->inc('give_card_time', $deposit_time)->inc('end_time', $deposit_time)->update();
            //更新年卡时长
            $ucd_res = UserCardDetailsModel::where('user_card_id', $user_card['id'])->inc('card_open_time', $deposit_time)->update();
            if(!$uc_res || !$ucd_res){
                throw new Exception('老年卡时长转入失败');
            }
            $card_new_data = UserCardsModel::getById($user_card['id']);
            UserCardChangeLogModel::create([
                'user_id' => $user_id,
                'user_card_id' => $user_card['id'],
                'card_id' => $user_card['card_id'],
                'card_name' => $card['name'],
                'change_duration' => sprintf('+%d天', ceil($deposit_time / 60 / 60 / 24)),
                'change_name' => '0元升级年卡，699年卡权益免费得；活动，'.$grade['level_name'].'时长转为年卡时长',
                'change_reason' => 6,
                'relation_mobile' => '',
                'change_low_start_time' => $user_card['start_time'],
                'change_low_end_time' => $user_card['end_time'],
                'change_new_start_time' => $card_new_data['start_time'],
                'change_new_end_time' => $card_new_data['end_time'],
                'create_time' => time()
            ]);
        }


        //记录年卡操作日志
        UserCardsOperationLogsModel::create([
            'user_id' => $user_id,
            'user_type' => 2,
            'user_card_id' => $card_res['id'],
            'create_time' => $nowTime,
            'log_info' => '0元升级年卡，699年卡权益免费得；领取赠送年卡时长，开通699年卡，押金由原年卡：'.$user_card['id'].'转移至此年卡'
        ]);

        //更新活动赠送类型，以及时长
        $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)
            ->update([
                'father_give_type' => $card['id'],
                'father_give_time' => $gift_time,
                'update_time' => time(),
                'card_give_time' => $card_time,
                'deposit_give_time' => $deposit_time,
                'user_card_id' => $card_res['id']
            ]);
        if(!$settle_res){
            throw new Exception('年卡时长领取失败');
        }
    }


    /**
     * 0元升级年卡，699年卡权益免费得活动9.9元次卡领取赠送时长补交699年卡押金支付回调
     * @param string $outTradeNo 支付交易号
     * @return bool
     * @author: yangliang
     * @date: 2021/7/16 19:12
     */
    public function activityDepositNotify(string $outTradeNo): bool
    {
        //获取支付前记录
        $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 5])->findOrEmpty()->toArray();
        if (!$payLog) {
            LogSave::channel('pay')->info('年卡支付记录不存在,out_trade_no:' . $outTradeNo);
            self::sendDingError('年卡支付记录不存在,out_trade_no:', $outTradeNo);
            return false;
        }
        // 判断是否为已支付 0  未支付 1 已支付
        if ($payLog['is_paid'] == 1) {
            LogSave::channel('pay')->info('年卡支付记录已支付,out_trade_no:' . $outTradeNo);
            self::sendDingError('年卡支付记录已支付,out_trade_no:', $outTradeNo);
            return true;
        }

        //修改支付成功
        $payLogUpdate = (new PayLogModel())->where('id', $payLog['id'])->update(['is_paid' => 1, 'paid_time' => time()]);
        if (!$payLogUpdate) {
            LogSave::channel('pay')->info('支付记录状态更新失败,out_trade_no:' . $outTradeNo);
            self::sendDingError('out_trade_no:' . $outTradeNo, '支付记录状态更新失败');
            return false;
        }

        //补交押金后，更新用户年卡押金为699年卡押金
        $card = CardsModel::getById(config('self_config.jul_activity.secondary_card.gift_card_id'));
        $resUserCardDetails = UserCardDetailsModel::where('user_card_id', $payLog['order_id'])
            ->update([
                'card_deposit' => $card['card_deposit'],
                'update_time' => time(),
                'card_deposit_time' => time(),
//                'pay_time' => time(),
                'card_deposit_trade_no' => $outTradeNo
            ]);
        if(!$resUserCardDetails){
            LogSave::channel('pay')->info('用户年卡记录状态更新失败,out_trade_no:' . $outTradeNo);
            self::sendDingError('out_trade_no:' . $outTradeNo, '用户年卡记录状态更新失败');
            return false;
        }

        return true;
    }


    /**
     * 领取押金时长
     * @param int $user_id 用户ID
     * @param int $settle_id  赠送记录ID
     * @param int $deposit_time 押金赠送时长（小时）
     * @throws Exception
     * @author: yangliang
     * @date: 2021/7/18 10:33
     */
    private function receiveDepositTime(int $user_id, int $settle_id, int $deposit_time): void
    {
        //押金赠送时长
        $gift_time = $deposit_time * 60 * 60;

        //更新活动赠送类型，以及时长
        $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)
            ->update([
                'father_give_type' => 0,
                'father_give_time' => $gift_time,
                'update_time' => time(),
                'card_give_time' => 0,
                'deposit_give_time' => 0,
                'user_card_id' => 0
            ]);

        //赠送用户押金时长
        $deposit_res = UserModel::where('user_id', $user_id)
            ->inc('user_deposit_expire_time', $gift_time)->update(['update_time' => time()]);

        $grade = UserModel::alias('u')->join('user_level ul', 'u.grade = ul.grade')
            ->where('u.user_id', $user_id)->find();
        UserCardChangeLogModel::create([
            'user_id' => $user_id,
            'user_card_id' => 0,
            'card_id' => 0,
            'card_name' => 0,
            'change_duration' => sprintf('+%d天', ceil($deposit_time / 60 / 60 / 24)),
            'change_name' => '0元升级年卡，699年卡权益免费得；活动，'.$grade['level_name'].'赠送时长',
            'change_reason' => 6,
            'relation_mobile' => '',
            'change_low_start_time' => 0,
            'change_low_end_time' => 0,
            'change_new_start_time' => 0,
            'change_new_end_time' => time(),
            'create_time' => time()
        ]);
        if(!$settle_res || !$deposit_res){
            throw new Exception('押金时长领取失败');
        }
    }


    /**
     * 9.9元次卡特殊处理
     * 需求：
     *     活动上级用户领取奖励时，如果是9.9元特除次卡，赠送699年卡时长，如果9.9元次卡押金不足699年卡押金，需要补缴押金后领取
     *     领取赠送时长：
     *         过期9.9元次卡
     *         开通699年卡，年卡时长为：赠送年卡时长 + 9.9元次卡剩余时长
     * @param int $user_id 用户ID
     * @param array $user_card 用户年卡（9.9元次卡）
     * @param int $settle_id 赠送时长记录ID
     * @param string $smart_openid 用户小程序唯一凭证
     * @param int $secondary_card_time 赠送年卡时长
     * @param int $gift_card_id 赠送年卡ID
     * @return array
     * @throws ApiException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author: yangliang
     * @date: 2021/7/18 10:52
     */
    private function specialSecondaryCard(int $user_id, array $user_card, int $settle_id, string $smart_openid,
                                          int $secondary_card_time, int $gift_card_id): array
    {
        //699年卡信息
        $buy_card = CardsModel::getById($gift_card_id);
        //9.9元次卡验证是否有100押金，若没有100押金，需要补交押金后领取
        $user_card_detail = UserCardDetailsModel::where('user_card_id', $user_card['id'])->find();
        if($user_card_detail['card_deposit'] < $buy_card['card_deposit']){
            Db::rollback();
            $money = $buy_card['card_deposit'] - $user_card_detail['card_deposit'];
            $pay = Pay::getInstance();
            $out_trade_no = 'JUL_ACTIVITY_DEPOSIT'.$settle_id.'_'.rand(1000, 9999);
            $result = $pay->unifiedorder([
                'body' => '睿鼎少儿年卡办理(支付押金)',
                'attach' => 'jul_activity_deposit',
                'openid' => $smart_openid,
                'trade_type' => 'JSAPI',
                'out_trade_no' => $out_trade_no,
                'total_fee' => env('server_env') === 'dev' ? 1 : $money * 100,
            ]);
            if (!$result) {
                return $this->error(100, $result['message']);
            }

            $pay_log = PayLogModel::create([
                'order_id' => $user_card['id'],
                'order_type' => 5,
                'order_amount' => $money,
                'out_trade_no' => $out_trade_no,
                'user_id' => $user_id,
                'add_time' => time()
            ]);
            if(!$pay_log){
                throw new Exception('系统异常');
            }
            return $this->error(1008, '您需要支付押金'.$money.'，才可领取获赠时长', $result);
        }

        //添加699年卡
        self::addCard($user_id, $secondary_card_time, $user_card, $settle_id, 2);
        return $this->success(200, 'success');
    }


    /**
     * 领取年卡时长
     * 需求：
     *     已过期年卡仍然可以领取赠送时长
     * @param int $user_id 用户ID
     * @param array $user_card 用户年卡
     * @param int $gift_card_time 赠送年卡时长（小时）
     * @param int $settle_id 赠送记录ID
     * @throws Exception
     * @author: yangliang
     * @date: 2021/7/18 11:54
     */
    private function receiveCardTime(int $user_id, array $user_card, int $gift_card_time, int $settle_id): void
    {
        //新年卡开始时间
        $start_time = 0;
        //新年卡结束时间
        $end_time = 0;
        //年卡赠送时长
        $gift_time = $gift_card_time * 60 * 60;
        //年卡未激活
        if ($user_card['is_lock'] == 1) {
            //更新年卡时长
            $ucd_res = UserCardDetailsModel::where('user_card_id', $user_card['id'])->inc('card_open_time', $gift_time)->update();
            //更新年卡赠送时长
            $uc_res = UserCardsModel::where('id', $user_card['id'])->inc('give_card_time', $gift_time)->update();
        } else {  //年卡已激活
            $start_time = $user_card['start_time'];
            $end_time = $user_card['end_time'] + $gift_time;
            //年卡已过期处理（已过期年卡仍然赠送时长）
            if($user_card['is_expire'] == 2){
                //更新年卡为未过期，且增加年卡时长
                $uc_res = UserCardsModel::where('id', $user_card['id'])
                    ->inc('give_card_time', $gift_time)->update(['is_expire' => 1, 'start_time' => $start_time, 'end_time' => $end_time]);
            }else {  //未过期，直接增加年卡时长
                $uc_res = UserCardsModel::where('id', $user_card['id'])
                    ->inc('give_card_time', $gift_time)->inc('end_time', $gift_time)->update();
            }
            //更新年卡时长
            $ucd_res = UserCardDetailsModel::where('user_card_id', $user_card['id'])->inc('card_open_time', $gift_time)->update();
        }

        if(!$ucd_res || !$uc_res){
            throw new \Exception('年卡赠送时长领取失败');
        }

        //更新活动赠送类型，以及时长
        $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)
            ->update([
                'father_give_type' => $user_card['card_id'],
                'father_give_time' => $gift_time,
                'update_time' => time(),
                'card_give_time' => 0,
                'deposit_give_time' => 0,
                'user_card_id' => $user_card['id']
            ]);
        if(!$settle_res){
            throw new Exception('年卡时长领取失败');
        }

        $card = CardsModel::getById($user_card['card_id']);

        //记录年卡变动时长
        $relation_arr = JulActivityChannelUserModel::getBySettleId($settle_id);
        if(!empty($relation_arr)){
            foreach ($relation_arr as $av){
                UserCardChangeLogModel::create([
                    'user_id' => $user_id,
                    'user_card_id' => $user_card['id'],
                    'card_id' => $user_card['card_id'],
                    'card_name' => $card['name'],
                    'change_duration' => sprintf('+%d 天', ceil(($gift_time / 60 / 60 / 24) / count($relation_arr))),
                    'change_name' => '0元升级年卡，699年卡权益免费得；赠送'.$card['name'].'时长',
                    'change_reason' => 6,
                    'relation_mobile' => $av['mobile'],
                    'change_low_start_time' => $user_card['start_time'],
                    'change_low_end_time' => $user_card['end_time'],
                    'change_new_start_time' => $start_time,
                    'change_new_end_time' => $end_time,
                    'create_time' => time()
                ]);
            }
        }
    }


    /**
     * 原活动规则V1,V2版本
     * @param int $user_id
     * @param array $jul_activity_conf
     * @param $user
     * @param $settle_id
     * @return array
     * @throws ApiException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    private function oldGift(int $user_id, array $jul_activity_conf, $user, $settle_id){

        $relation = JulActivityChannelUserModel::getBySettleId($settle_id);
        if(empty($relation)){
            return $this->error(100, '邀请记录不存在');
        }

        foreach ($relation as $v) {
            $child_user_card = UserCardsModel::getById($v['user_card_id']);

            //年卡用户
            $user_card = UserCardsModel::getByUserId($user_id);
            //存在年卡，且未退卡，可以领取赠送时长
            if (!empty($user_card) && $user_card['is_refund'] == 2) {
                //正在退卡中不能领取
                if ($user_card['refund_card_status'] > 0) {
                    return $this->error(1009, '您有正在处理的退卡申请，您需处理完成后才可参加此活动。');
                }

                if ($user_card['is_refund_deposit'] == 1) {
                    return $this->error(100, '您的年卡押金已退，暂时不能领取时长');
                }

                //押金退款申请记录
                $deposit_refund = NewCardRefundDepositModel::getOneDataByUserId($user_id, $user_card['id']);
                if (!empty($deposit_refund) && $deposit_refund['status'] == 1) {
                    return $this->error(100, '您有正在处理的退押金申请，您需处理完成后才可参加此活动。');
                }

                //9.9元次卡特殊处理，直接过期9.9元次卡，生成699年卡（时长为赠送时长 + 9.9元次卡剩余时长）
                if ($user_card['card_id'] == $jul_activity_conf['secondary_card']['card_id']) {  //9.9元次卡用户
                    //9.9元用户，邀请好友办理非9.9次卡，赠送90天
                    if ($child_user_card['card_id'] != $jul_activity_conf['secondary_card']['card_id']) {
                        $open_res = self::specialSecondaryCard($user_id, $user_card, $settle_id, $user['smart_openid'],
                            $jul_activity_conf['inviter_gift']['secondary_card'], $jul_activity_conf['secondary_card']['gift_card_id']);
                    } else {
                        //邀请好友办理9.9元次卡，赠送699年卡，30天
                        $open_res = self::specialSecondaryCard($user_id, $user_card, $settle_id, $user['smart_openid'],
                            $jul_activity_conf['secondary_card']['card'], $jul_activity_conf['secondary_card']['gift_card_id']);
                    }
                    if ($open_res['code'] != 200) {
                        Db::rollback();
                        return $this->error($open_res['code'], $open_res['message'], $open_res['data']);
                    }
                } else {  //年卡用户
                    //年卡用户（非9.9次卡用户）邀请好友办理699年卡，赠送180天时长
                    if ($child_user_card['card_id'] != $jul_activity_conf['secondary_card']['card_id']) {
                        self::receiveCardTime($user_id, $user_card, $jul_activity_conf['inviter_gift']['card'], $settle_id);
                    } else {
                        //年卡用户（非9.9次卡用户）邀请好友办理9.9次卡，赠送30天时长
                        self::receiveCardTime($user_id, $user_card, $jul_activity_conf['secondary_card']['card'], $settle_id);
                    }
                }
            } elseif ($user['grade'] > 0) {  //押金用户
                //押金用户，邀请好友办理699年卡，赠送180天时长
                if ($child_user_card['card_id'] != $jul_activity_conf['secondary_card']['card_id']) {
                    //领取押金时长
                    self::receiveDepositTime($user_id, $settle_id, $jul_activity_conf['inviter_gift']['deposit']);
                } else {   //押金用户，邀请好友办理9.9元次卡，赠送30天时长
                    self::receiveDepositTime($user_id, $settle_id, $jul_activity_conf['secondary_card']['card']);
                }
            } else {
                //验证当前用户是否渠道一级用户
                $top_user = JulActivityChannelUserModel::getByUserIdAndIsTop($user_id, 1);
                //非年卡用户，非押金用户，但是渠道一级用户不支持领取
                if (!empty($top_user)) {
                    return $this->error(100, '你还不是老押金或新年卡会员，暂时不能领取时长');
                } else {
                    //无年卡无押金非渠道一级用户，提示购买年卡
                    return $this->error(-1, '您还不是年卡用户，请购买年卡后参与活动');
                }
            }

            $succ_res = JulActivityChannelUserModel::where('id', $v['id'])
                ->update(['status' => 3, 'business_accounting_time' => time(), 'update_time' => time()]);
            if(!$succ_res){
                throw new Exception('领取失败');
            }
        }

        $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)->update(['business_accounting_time' => time(), 'status' =>1, 'update_time' => time()]);
        if(!$settle_res){
            throw new Exception('领取失败');
        }

        return $this->success(200, 'success');
    }


    /**
     * 新活动规则V3版本
     * @param int $user_id
     * @param array $jul_activity_conf
     * @param $user
     * @param $settle_id
     * @return array
     * @throws ApiException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    private function newGift(int $user_id, array $jul_activity_conf, $user, $settle_id){
        //用户当前年卡
        $user_card = UserCardsModel::getByUserId($user_id);
        //年卡 + 押金用户
        if (!empty($user_card) && $user_card['is_refund'] == 2 && $user['grade'] > 0 && $user['user_deposit_expire_time'] > time()) {

            if($user_card['is_refund'] == 1){
                return $this->error(-1, '您还不是年卡用户，请购买年卡后参与活动');
            }

            //正在退卡中不能领取
            if ($user_card['refund_card_status'] > 0) {
                return $this->error(1009, '您有正在处理的退卡申请，您需处理完成后才可参加此活动。');
            }

            if ($user_card['is_refund_deposit'] == 1) {
                return $this->error(100, '您的年卡押金已退，暂时不能领取时长');
            }

            //押金退款申请记录
            $deposit_refund = NewCardRefundDepositModel::getOneDataByUserId($user_id, $user_card['id']);
            if (!empty($deposit_refund) && $deposit_refund['status'] == 1) {
                return $this->error(100, '您有正在处理的退押金申请，您需处理完成后才可参加此活动。');
            }

            //验证用户是否需要补缴押金
            $card_deposit = self::checkUserCardDeposit($user_id, 7, $user_card['id'], $settle_id, $user['smart_openid']);
            if($card_deposit['code'] != 200){
                return $this->error($card_deposit['code'], $card_deposit['message'], $card_deposit['data']);
            }

            //押金剩余时长
            $deposit_time = ($user['user_deposit_expire_time'] - time());

            //领取年卡时长
            self::cardDepositReceive($user_id, $user_card, $jul_activity_conf['new_gift_time'], $settle_id, $deposit_time, 7);
        }elseif (!empty($user_card) && $user_card['is_refund'] == 2) {  //年卡用户
            if($user_card['is_refund'] == 1){
                return $this->error(-1, '您还不是年卡用户，请购买年卡后参与活动');
            }
            
            //正在退卡中不能领取
            if ($user_card['refund_card_status'] > 0) {
                return $this->error(1009, '您有正在处理的退卡申请，您需处理完成后才可参加此活动。');
            }

            if ($user_card['is_refund_deposit'] == 1) {
                return $this->error(100, '您的年卡押金已退，暂时不能领取时长');
            }

            //押金退款申请记录
            $deposit_refund = NewCardRefundDepositModel::getOneDataByUserId($user_id, $user_card['id']);
            if (!empty($deposit_refund) && $deposit_refund['status'] == 1) {
                return $this->error(100, '您有正在处理的退押金申请，您需处理完成后才可参加此活动。');
            }

            //验证用户是否需要补缴押金
            $card_deposit = self::checkUserCardDeposit($user_id, 7, $user_card['id'], $settle_id, $user['smart_openid']);
            if($card_deposit['code'] != 200){
                return $this->error($card_deposit['code'], $card_deposit['message'], $card_deposit['data']);
            }

            //领取年卡时长
            self::cardDepositReceive($user_id, $user_card, $jul_activity_conf['new_gift_time'], $settle_id, 0,7);
        }elseif($user['grade'] > 0){  //押金用户
            
            //押金剩余时长
            $deposit_time = ($user['user_deposit_expire_time'] - time());
            $deposit_res = self::receiveNewDepositTime($user_id, $jul_activity_conf['new_gift_time'], $settle_id, $deposit_time, $user['smart_openid']);
            if($deposit_res['code'] != 200){
                return $this->error($deposit_res['code'], $deposit_res['message'], $deposit_res['data']);
            }
        }else{
            //验证当前用户是否渠道一级用户
            $top_user = JulActivityChannelUserModel::getByUserIdAndIsTop($user_id, 1);
            //非年卡用户，非押金用户，但是渠道一级用户不支持领取
            if (!empty($top_user)) {
                return $this->error(100, '你还不是老押金或新年卡会员，暂时不能领取时长');
            } else {
                //无年卡无押金非渠道一级用户，提示购买年卡
                return $this->error(-1, '您还不是年卡用户，请购买年卡后参与活动');
            }
        }

        $succ_res = JulActivityChannelUserModel::where('settle_id', $settle_id)
            ->update(['status' => 3, 'business_accounting_time' => time(), 'update_time' => time()]);
        $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)
            ->update(['business_accounting_time' => time(), 'status' => 1, 'update_time' => time()]);
        
        if(!$succ_res || !$settle_res){
            throw new Exception('领取失败');
        }

        return $this->success(200, 'success');
    }


    /**
     * 验证用户是否需要补缴押金
     * @param int $user_id  用户ID
     * @param int $gift_card_id  赠送年卡ID
     * @param int $user_card_id  用户当前年卡ID
     * @param int $settle_id  赠送记录ID
     * @param string $smart_openid
     * @return array
     * @throws ApiException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    private function checkUserCardDeposit(int $user_id, int $gift_card_id, int $user_card_id, int $settle_id, string $smart_openid){
        //年卡信息
        $buy_card = CardsModel::getById($gift_card_id);
        //验证是否有100押金，若没有100押金，需要补交押金后领取
        $user_card_detail = UserCardDetailsModel::where('user_card_id', $user_card_id)->find();
        if($user_card_detail['card_deposit'] < $buy_card['card_deposit']){
            Db::rollback();
            $money = $buy_card['card_deposit'] - $user_card_detail['card_deposit'];
            $pay = Pay::getInstance();
            $out_trade_no = 'JUL_ACTIVITY_DEPOSIT'.$settle_id.'_'.rand(1000, 9999);
            $result = $pay->unifiedorder([
                'body' => '睿鼎少儿年卡办理(支付押金)',
                'attach' => 'jul_activity_deposit',
                'openid' => $smart_openid,
                'trade_type' => 'JSAPI',
                'out_trade_no' => $out_trade_no,
                'total_fee' => env('server_env') === 'dev' ? 1 : $money * 100,
            ]);
            if (!$result) {
                return $this->error(100, $result['message']);
            }

            $pay_log = PayLogModel::create([
                'order_id' => $user_card_id,
                'order_type' => 5,
                'order_amount' => $money,
                'out_trade_no' => $out_trade_no,
                'user_id' => $user_id,
                'add_time' => time()
            ]);
            if(!$pay_log){
                throw new Exception('系统异常');
            }
            return $this->error(1008, '您需要支付押金'.$money.'，才可领取获赠时长', $result);
        }

        return $this->success(200, 'success');
    }


    /** V3 纯押金用户领取赠送时长
     * @param int $user_id
     * @param int $gift_card_time
     * @param int $settle_id
     * @param int $deposit_time
     * @return array
     * @throws Exception
     */
    private function receiveNewDepositTime(int $user_id, int $gift_card_time, int $settle_id, int $deposit_time, string $smart_openid){
        Db::rollback();
        $card = CardsModel::getById(7);
        $nowTime = time();
        $gift_time = $gift_card_time * 60 * 60;
        $card_detail = [
            'user_id' => $user_id,
            'card_id' => $card['id'],
            'money' => $card['price'],
            'pay_money' => 0,
            'deposit_money' => 0,
            'extra_money' => 0,
            'describe' => '',
            'action_type' => 1,
            'refund_status' => 1,
            'pay_time' => $nowTime,
            'create_time' => $nowTime,
            'update_time' => $nowTime,
            'user_card_id' => 0,
            'out_trade_no' => '',
            'is_double' => 0,
            'card_open_time' => $gift_time,
            'card_deposit' => $card['card_deposit'],
            'experience_card_id' => 0,
            'experience_add_card_days' => 0
        ];
        $user_card_detail_res = UserCardDetailsModel::create($card_detail);
        if(!$user_card_detail_res){
            throw new Exception('年卡明细创建失败');
        }

        $pay = Pay::getInstance();
        $out_trade_no = 'JUL_ACTIVITY_DC_'.$settle_id.'_'.rand(1000, 9999);
        $result = $pay->unifiedorder([
            'body' => '睿鼎少儿年卡办理(支付押金)',
            'attach' => 'jul_activity_dc_'.$settle_id,
            'openid' => $smart_openid,
            'trade_type' => 'JSAPI',
            'out_trade_no' => $out_trade_no,
            'total_fee' => env('server_env') === 'dev' ? 1 : $card['card_deposit'] * 100,
        ]);
        if (!$result) {
            return $this->error(100, $result['message']);
        }

        $pay_log = PayLogModel::create([
            'order_id' => $user_card_detail_res->id,
            'order_type' => 5,
            'order_amount' => $card['card_deposit'],
            'out_trade_no' => $out_trade_no,
            'user_id' => $user_id,
            'add_time' => time()
        ]);
        if(!$pay_log){
            throw new Exception('系统异常');
        }
        return $this->error(1008, '您需要支付押金'.$card['card_deposit'].'，才可领取获赠时长', $result);
    }


    /**
     * 七月活动，0元升级年卡，699权益免费得
     * 纯押金用户领取赠送时长，过期押金，赠送699年卡
     * @param string $outTradeNo
     * @param int $settle_id
     * @return bool
     */
    public function activityDepositCardNotify(string $outTradeNo, int $settle_id): bool
    {
        Db::startTrans();
        try {
            //获取支付前记录
            $payLog = (new PayLogModel())->where(['out_trade_no' => $outTradeNo, 'order_type' => 5])->findOrEmpty()->toArray();
            if (!$payLog) {
                throw new Exception('七月活动押金转年卡,年卡支付记录不存在');
            }
            // 判断是否为已支付 0  未支付 1 已支付
            if ($payLog['is_paid'] == 1) {
                LogSave::channel('pay')->info('七月活动押金转年卡,年卡支付记录已支付,out_trade_no:' . $outTradeNo);
                self::sendDingError('七月活动押金转年卡,年卡支付记录已支付,out_trade_no:', $outTradeNo, '');
                return true;
            }

            //修改支付成功
            $payLogUpdate = (new PayLogModel())->where('id', $payLog['id'])->update(['is_paid' => 1, 'paid_time' => time()]);
            if (!$payLogUpdate) {
                throw new Exception('七月活动押金转年卡,支付记录状态更新失败');
            }

            $user_card_detail = UserCardDetailsModel::getById($payLog['order_id']);
            if (!$user_card_detail) {//没有写入
                throw new Exception('七月活动押金转年卡,年卡明细信息不存在');
            }

            $user = (new UserModel())->getOneUser($user_card_detail['user_id']);
            //押金剩余时长
            $deposit_time = $user['user_deposit_expire_time'] - time();
            //更新押金过期
            $deposit_res = UserModel::where('user_id', $user_card_detail['user_id'])->update(['user_deposit_expire_time' => time()]);
            if (!$deposit_res) {
                throw new Exception('七月活动押金转年卡,押金过期失败');
            }

            //押金剩余时长，转出押金
            $grade = UserModel::alias('u')
                ->join('user_level ul', 'u.grade = ul.grade')
                ->where('u.user_id', $user_card_detail['user_id'])
                ->find();
            //押金转出日志
            self::addChangeLog([
                'user_id' => $user_card_detail['user_id'],
                'change_duration' => sprintf('-%d天', ceil($deposit_time / 86400)),
                'change_name' => '0元升级年卡，699年卡权益免费得；活动，'.$grade['level_name'].'剩余时长转出',
                'change_reason' => 6,
                'change_new_end_time' => time(),
            ]);


            $card = CardsModel::getById($user_card_detail['card_id']);
            $nowTime = time();
            //写入年卡信息
            $start_time = $nowTime;
            $end_time = $nowTime + $user_card_detail['card_open_time'];
            $card_res = UserCardsModel::create([
                'user_id' => $user_card_detail['user_id'],
                'card_id' => $user_card_detail['card_id'],
                'start_time' => $start_time,
                'end_time' => $end_time,
                'is_expire' => 1,
                'surplus_ship_num' => $card['ship_num'],
                'before_user_card_id' => 0,
                'create_time' => $nowTime,
                'update_time' => $nowTime,
                'is_lock' => 0,
                'unlock_time' => 0,
                'is_double' => 1,
                'is_activity' => 0,
                'give_card_time' => $user_card_detail['card_open_time']
            ]);

            $detail_res = UserCardDetailsModel::where('id', $user_card_detail['id'])
                ->update([
                    'user_card_id' => $card_res->id,
                    'card_deposit_time' => time(),
                    'pay_time' => time(),
                    'card_deposit_trade_no' => $outTradeNo
                ]);
            if (!$card_res || !$detail_res) {
                throw new Exception('七月活动押金转年卡,年卡创建失败');
            }

            //记录年卡操作日志
            UserCardsOperationLogsModel::create([
                'user_id' => $user_card_detail['user_id'],
                'user_type' => 2,
                'user_card_id' => $card_res->id,
                'log_info' => sprintf('用户【ID：%d】参与0元升级年卡，699权益免费得；活动赠送年卡：%s, 支付单号【%s】', $user_card_detail['user_id'], $card['name'], $outTradeNo),
                'create_time' => $nowTime,
            ]);

            $relation_arr = JulActivityChannelUserModel::getBySettleId($settle_id);
            if (!empty($relation_arr)) {
                $i = 1;
                $change_duration = ceil($user_card_detail['card_open_time'] / 86400 / count($relation_arr));
                $prev_start = 0;
                $prev_end = 0;
                foreach ($relation_arr as $av) {
                    $curr_start = $start_time;
                    $curr_end = $start_time + ($change_duration * 86400 * $i);
                    self::addChangeLog([
                        'user_id' => $user_card_detail['user_id'],
                        'user_card_id' => $card_res->id,
                        'card_id' => $card['id'],
                        'card_name' => $card['name'],
                        'change_duration' => sprintf('+%d天', $change_duration),
                        'change_name' => '0元升级年卡，699权益免费得；活动赠送'.$card['name'],
                        'change_reason' => 6,
                        'relation_mobile' => $av['mobile'],
                        'change_low_start_time' => $prev_start,
                        'change_low_end_time' => $prev_end,
                        'change_new_start_time' => $curr_start,
                        'change_new_end_time' => $curr_end,
                    ]);
                    $prev_start = $curr_start;
                    $prev_end = $curr_end;
                    $i++;
                }
            }


            $old_start_time = $start_time;
            $old_end_time = $end_time;
            $end_time = $end_time + $deposit_time;
            //转入押金时长
            $uc_res = UserCardsModel::where('id', $card_res->id)
                ->inc('give_card_time', $deposit_time)->inc('end_time', $deposit_time)->update();
            //更新年卡时长
            $ucd_res = UserCardDetailsModel::where('user_card_id', $card_res->id)->inc('card_open_time', $deposit_time)->update();
            if(!$uc_res || !$ucd_res){
                throw new Exception('老押金转入失败');
            }
            $user_card = UserCardsModel::getById($card_res->id);
            //押金转入日志
            self::addChangeLog([
                'user_id' => $user_card_detail['user_id'],
                'user_card_id' => $card_res->id,
                'card_id' => $user_card_detail['card_id'],
                'card_name' => $card['name'],
                'change_duration' => sprintf('+%d天', ceil($deposit_time / 86400)),
                'change_name' => '0元升级年卡，699年卡权益免费得；活动'.$grade['level_name'].'时长转为'.$card['name'].'时长',
                'change_reason' => 6,
                'change_low_start_time' => $old_start_time,
                'change_low_end_time' => $old_end_time,
                'change_new_start_time' => $start_time,
                'change_new_end_time' => $end_time,
            ]);

            $settle = JulActivityChannelUserSettleModel::where('id', $settle_id)->find();
            $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)
                ->update([
                    'father_give_type' => $card['id'],
                    'father_give_time' => $settle['father_give_time'],
                    'update_time' => time(),
                    'card_give_time' => 0,
                    'deposit_give_time' => $deposit_time,
                    'status' => 1,
                    'user_card_id' => $card_res->id,
                ]);
            if (!$settle_res) {
                throw new Exception('七月活动押金转年卡,年卡时长领取失败');
            }

            $relation_res = JulActivityChannelUserModel::where('settle_id', $settle_id)
                ->update(['status' => '3', 'update_time' => time()]);
            if (!$relation_res) {
                throw new Exception('七月活动押金转年卡,领取失败');
            }
        }catch (Exception $e){
            Db::rollback();
            $msg = $e->getMessage().',out_trade_no:' . $outTradeNo;
            LogSave::channel('pay')->info($msg);
            self::sendDingError($msg, $e);
            return false;
        }

        Db::commit();
        return true;
    }


    /**
     * 押金 + 年卡用户领取时长
     * @param int $user_id
     * @param array $user_card
     * @param int $gift_card_time
     * @param int $settle_id
     * @param int $deposit_time
     * @param int $gift_card_id
     * @throws Exception
     * @author: yangliang
     * @date: 2021/7/26 14:03
     */
    private function cardDepositReceive(int $user_id, array $user_card, int $gift_card_time, int $settle_id, int $deposit_time, int $gift_card_id){
        //年卡赠送时长（秒）
        $gift_time = $gift_card_time * 60 * 60;
        $card = CardsModel::getById($user_card['card_id']);
        $user_card_detail = UserCardDetailsModel::getByUserCardId($user_card['id']);
        //原年卡开始时间
        $old_start_time = $start_time = $user_card['start_time'];
        //原年卡结束时间
        $old_end_time = $end_time = $user_card['end_time'];
        //原年卡赠送时长
        $old_give_card_time = $give_card_time = $user_card['give_card_time'];
        //原年卡明细时长
        $old_card_open_time = $card_open_time = $user_card_detail['card_open_time'];

        //年卡 + 押金用户领取赠送时长

        if($deposit_time > 0) {
            //过期用户押金，押金转出
            $grade = UserModel::alias('u')->join('user_level ul', 'u.grade = ul.grade')->where('u.user_id', $user_id)->find();
            $deposit_res = UserModel::where('user_id', $user_id)->update(['user_deposit_expire_time' => time()]);
            if (!$deposit_res) {
                throw new Exception('押金过期失败');
            }
            //押金转出日志
            self::addChangeLog([
                'user_id' => $user_id,
                'change_duration' => sprintf('-%d天', ceil($deposit_time / 86400)),
                'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $grade['level_name'] . '剩余时长转出',
                'change_reason' => 6,
                'change_new_end_time' => time()
            ]);
        }



        $relation_arr = JulActivityChannelUserModel::getBySettleId($settle_id);
        //年卡处理，699年卡和899年卡直接在现有年卡上赠送时长，不增加新年卡
        if(in_array($user_card['card_id'], [2, 7])){
            //年卡当前未激活
            if ($user_card['is_lock'] == 1) {
                $start_time = time();
                $end_time = time();
                if($deposit_time > 0) {
                    //押金剩余时长转入当前年卡
                    $card_open_time = $card_open_time + $deposit_time;
                    //TODO  押金转入年卡是否算赠送时长？
                    $give_card_time = $give_card_time + $deposit_time;
                    //原年卡未开卡，直接开通，开始时间为当前
                    $start_time = time();
                    //原年卡未开卡，直接开通，结束时间为当前时间 + 原押金转入
                    $end_time = $end_time + $deposit_time;

                    $deposit_ucd_res = UserCardDetailsModel::where('user_card_id', $user_card['id'])
                        ->update(['card_open_time' => $card_open_time, 'update_time' => time()]);
                    $deposit_uc_res = UserCardsModel::where('id', $user_card['id'])
                        ->update(['is_lock' => 0, 'start_time' => $start_time, 'end_time' => $end_time, 'give_card_time' => $give_card_time, 'update_time' => time()]);

                    if (!$deposit_ucd_res || !$deposit_uc_res) {
                        throw new Exception('押金转入年卡失败');
                    }

                    //押金转入年卡日志
                    self::addChangeLog([
                        'user_id' => $user_id,
                        'user_card_id' => $user_card['id'],
                        'card_id' => $user_card['card_id'],
                        'card_name' => $card['name'],
                        'change_duration' => sprintf('+%d天', ceil($deposit_time / 86400)),
                        'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $grade['level_name'] . '时长转为年卡时长',
                        'change_reason' => 6,
                        'change_low_start_time' => $old_start_time,
                        'change_low_end_time' => $old_end_time,
                        'change_new_start_time' => $start_time,
                        'change_new_end_time' => $end_time,
                    ]);
                }


                //赠送年卡时长
                //原年卡开始时间
                $old_start_time = $start_time;
                //原年卡结束时间
                $old_end_time = $end_time;
                //原年卡时长
                $old_card_open_time = $card_open_time;
                //原年卡赠送时长
                $old_give_card_time = $give_card_time;

                //年卡赠送时长
                $card_open_time = $card_open_time + $gift_time;
                $end_time = $end_time + $card_open_time;
                $give_card_time = $give_card_time + $gift_time;
                $ucd_res = UserCardDetailsModel::where('user_card_id', $user_card['id'])
                    ->update(['card_open_time' => $card_open_time, 'update_time' => time()]);
                //更新年卡赠送时长,激活年卡
                $uc_res = UserCardsModel::where('id', $user_card['id'])
                    ->update(['is_lock' => 0, 'start_time' => $start_time, 'end_time' =>$end_time, 'give_card_time' => $give_card_time, 'update_time' => time()]);

                if(!$ucd_res || !$uc_res){
                    throw new Exception('年卡时长赠送失败');
                }

                //激活年卡日志
                self::addChangeLog([
                    'user_id' => $user_id,
                    'user_card_id' => $user_card['id'],
                    'card_id' => $user_card['card_id'],
                    'card_name' => $card['name'],
                    'change_duration' => sprintf('+%d 天', ceil($card_open_time / 86400)),
                    'change_name' => '系统激活用户年卡',
                    'change_reason' => 12,
                    'relation_mobile' => '',
                    'change_low_start_time' => 0,
                    'change_low_end_time' => 0,
                    'change_new_start_time' => $start_time,
                    'change_new_end_time' => $end_time,
                ]);


                //年卡时长赠送日志
                if(!empty($relation_arr)){
                    $change_duration = ceil($gift_time / 86400 / count($relation_arr));
                    $i = 1;
                    $prev_start = $old_start_time;
                    $prev_end = $old_end_time;
                    foreach ($relation_arr as $av){
                        $curr_start = $start_time;
                        $curr_end = $old_end_time + ($change_duration * 86400 * $i);
                        self::addChangeLog([
                            'user_id' => $user_id,
                            'user_card_id' => $user_card['id'],
                            'card_id' => $user_card['card_id'],
                            'card_name' => $card['name'],
                            'change_duration' => sprintf('+%d 天', $change_duration),
                            'change_name' => '0元升级年卡，699年卡权益免费得；活动赠送'.$card['name'].'时长：+'.$change_duration.'天',
                            'change_reason' => 6,
                            'relation_mobile' => $av['mobile'],
                            'change_low_start_time' => $prev_start,
                            'change_low_end_time' => $prev_end,
                            'change_new_start_time' => $curr_start,
                            'change_new_end_time' => $curr_end,
                        ]);
                        $prev_start = $curr_start;
                        $prev_end = $curr_end;
                        $i++;
                    }
                }
            }else{  //当前年卡已激活
                if($deposit_time > 0) {
                    //押金剩余时长转入当前年卡
                    $card_open_time = $card_open_time + $deposit_time;
                    //TODO  押金转入年卡是否算赠送时长？
                    $give_card_time = $give_card_time + $deposit_time;
                    //原年卡已开卡，追加赠送时长
                    $end_time = $end_time + $deposit_time;

                    $deposit_ucd_res = UserCardDetailsModel::where('user_card_id', $user_card['id'])
                        ->update(['card_open_time' => $card_open_time, 'update_time' => time()]);
                    $deposit_uc_res = UserCardsModel::where('id', $user_card['id'])
                        ->update(['start_time' => $start_time, 'end_time' => $end_time, 'give_card_time' => $give_card_time, 'update_time' => time()]);

                    if (!$deposit_ucd_res || !$deposit_uc_res) {
                        throw new Exception('押金转入年卡失败');
                    }

                    //押金转入年卡日志
                    self::addChangeLog([
                        'user_id' => $user_id,
                        'user_card_id' => $user_card['id'],
                        'card_id' => $user_card['card_id'],
                        'card_name' => $card['name'],
                        'change_duration' => sprintf('+%d天', ceil($deposit_time / 86400)),
                        'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $grade['level_name'] . '时长转为年卡时长',
                        'change_reason' => 6,
                        'change_low_start_time' => $old_start_time,
                        'change_low_end_time' => $old_end_time,
                        'change_new_start_time' => $start_time,
                        'change_new_end_time' => $end_time,
                    ]);
                }


                //赠送年卡时长
                //原年卡开始时间
                $old_start_time = $start_time;
                //原年卡结束时间
                $old_end_time = $end_time;
                //原年卡时长
                $old_card_open_time = $card_open_time;
                //原年卡赠送时长
                $old_give_card_time = $give_card_time;

                //年卡赠送时长
                $end_time = $end_time + $gift_time;
                $card_open_time = $card_open_time + $gift_time;
                $give_card_time = $give_card_time + $gift_time;
                $ucd_res = UserCardDetailsModel::where('user_card_id', $user_card['id'])
                    ->update(['card_open_time' => $card_open_time, 'update_time' => time()]);

                //若赠后年卡到期时间大于当前时间修改年卡未过期，（兼容过期情况）
                if($end_time > time()){
                    //更新年卡赠送时长
                    $uc_res = UserCardsModel::where('id', $user_card['id'])
                        ->update(['is_expire' => 1, 'start_time' => $start_time, 'end_time' => $end_time, 'give_card_time' => $give_card_time, 'update_time' => time()]);
                }else {
                    //更新年卡赠送时长
                    $uc_res = UserCardsModel::where('id', $user_card['id'])
                        ->update(['is_expire' => 1, 'start_time' => $start_time, 'end_time' => $end_time, 'give_card_time' => $give_card_time, 'update_time' => time()]);
                }

                if(!$ucd_res || !$uc_res){
                    throw new Exception('年卡时长赠送失败');
                }

                //年卡时长赠送日志
                if(!empty($relation_arr)){
                    $change_duration = ceil($gift_time / 86400 / count($relation_arr));
                    $i = 1;
                    $prev_start = $old_start_time;
                    $prev_end = $old_end_time;
                    foreach ($relation_arr as $av){
                        $curr_start = $start_time;
                        $curr_end = $old_end_time + ($change_duration * 86400 * $i);
                        self::addChangeLog([
                            'user_id' => $user_id,
                            'user_card_id' => $user_card['id'],
                            'card_id' => $user_card['card_id'],
                            'card_name' => $card['name'],
                            'change_duration' => sprintf('+%d 天', $change_duration),
                            'change_name' => '0元升级年卡，699年卡权益免费得；活动赠送'.$card['name'].'时长：+'.$change_duration.'天',
                            'change_reason' => 6,
                            'relation_mobile' => $av['mobile'],
                            'change_low_start_time' => $prev_start,
                            'change_low_end_time' => $prev_end,
                            'change_new_start_time' => $curr_start,
                            'change_new_end_time' => $curr_end,
                        ]);
                        $prev_start = $curr_start;
                        $prev_end = $curr_end;
                        $i++;
                    }
                }
            }
            //更新活动赠送类型，以及时长
            $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)
                ->update([
                    'father_give_type' => $user_card['card_id'],
                    'father_give_time' => $gift_time,
                    'update_time' => time(),
                    'card_give_time' => 0,
                    'deposit_give_time' => ($deposit_time > 0) ? $deposit_time : 0,
                ]);
            if(!$settle_res){
                throw new Exception('年卡时长领取失败');
            }
        }else{

            //当前年卡非699、899年卡，将现有年卡剩余时长进行转移至新699年卡
            //老年卡结束时间
            $old_end_time = $end_time;
            //老年卡现在结束时间
            $end_time = time();
            //老年卡信息
            $old_card = CardsModel::getById($user_card['card_id']);
            //老年卡剩余天数
            $change_duration =  ceil(($user_card['end_time'] - time()) / 86400);


            //过期当前年卡，转出当前年卡
            if($user_card['is_lock'] == 1){  //未激活
                $card_res = UserCardsModel::where('id', $user_card['id'])
                    ->update(['is_expire' => 2, 'expire_time' => time(), 'update_time' => time()]);

                $card_detail_res = UserCardDetailsModel::where('id', $user_card_detail['id'])
                    ->update(['card_deposit_status' => 2, 'transfer_card_deposit' => $user_card_detail['card_deposit'], 'update_time' => time(), 'card_open_time' => 0]);
                if(!$card_res || !$card_detail_res){
                    throw new Exception('老年卡过期失败');
                }
                //年卡转出日志
                self::addChangeLog([
                    'user_id' => $user_id,
                    'user_card_id' => $user_card['id'],
                    'card_id' => $user_card['card_id'],
                    'card_name' => $old_card['name'],
                    'change_duration' => sprintf('-%d 天', ceil($user_card_detail['card_open_time'] / 86400)),
                    'change_name' => '0元升级年卡，699年卡权益免费得；活动'.$old_card['name'].'时长转出',
                    'change_reason' => 6,
                    'change_low_start_time' => $old_start_time,
                    'change_low_end_time' => $old_end_time,
                    'change_new_start_time' => $start_time,
                    'change_new_end_time' => $end_time,
                ]);
            }else {  //已激活
                if($user_card['is_expire'] == 2){  //已过期
                    $card_detail_res = UserCardDetailsModel::where('id', $user_card_detail['id'])
                        ->update([
                            'card_deposit_status' => 2,
                            'transfer_card_deposit' => $user_card_detail['card_deposit'],
                            'update_time' => time(),
                            'card_open_time' => ($end_time - $start_time)
                        ]);
                    if (!$card_detail_res) {
                        throw new Exception('老年卡过期失败');
                    }

                    //年卡转出日志
                    self::addChangeLog([
                        'user_id' => $user_id,
                        'user_card_id' => $user_card['id'],
                        'card_id' => $user_card['card_id'],
                        'card_name' => $old_card['name'],
                        'change_duration' => sprintf('-%d 天', 0),
                        'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $old_card['name'] . '时长转出',
                        'change_reason' => 6,
                        'change_low_start_time' => $old_start_time,
                        'change_low_end_time' => $old_end_time,
                        'change_new_start_time' => $start_time,
                        'change_new_end_time' => $old_end_time,
                    ]);
                }else {
                    $card_res = UserCardsModel::where('id', $user_card['id'])
                        ->update(['is_expire' => 2, 'expire_time' => time(), 'update_time' => time(), 'end_time' => $end_time]);
                    $card_detail_res = UserCardDetailsModel::where('id', $user_card_detail['id'])
                        ->update([
                            'card_deposit_status' => 2,
                            'transfer_card_deposit' => $user_card_detail['card_deposit'],
                            'update_time' => time(),
                            'card_open_time' => ($end_time - $start_time)
                        ]);
                    if (!$card_res || !$card_detail_res) {
                        throw new Exception('老年卡过期失败');
                    }

                    //年卡转出日志
                    self::addChangeLog([
                        'user_id' => $user_id,
                        'user_card_id' => $user_card['id'],
                        'card_id' => $user_card['card_id'],
                        'card_name' => $old_card['name'],
                        'change_duration' => sprintf('-%d 天', $change_duration),
                        'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $old_card['name'] . '时长转出',
                        'change_reason' => 6,
                        'change_low_start_time' => $old_start_time,
                        'change_low_end_time' => $old_end_time,
                        'change_new_start_time' => $start_time,
                        'change_new_end_time' => $end_time,
                    ]);
                }
            }



            $nowTime = time();
            //新年卡开始结束时间
            $new_card_old_start_time = $new_card_start_time = $nowTime;
            $new_card_old_end_time = $new_card_end_time = $nowTime + $gift_time;
            //新年卡赠送时长
            $new_card_old_give_card_time = $new_card_give_card_time = $gift_time;
            $new_card_old_open_time = $new_card_open_time = $gift_time;
            //赠送年卡
            $card_info = CardsModel::getById($gift_card_id);
            //赠送新年卡时长，开通699新年卡
            $new_card = UserCardsModel::create([
                'user_id' => $user_id,
                'card_id' => $card_info['id'],
                'start_time' => $new_card_start_time,
                'end_time' => $new_card_end_time,
                'is_expire' => 1,
                'surplus_ship_num' => $card_info['ship_num'],
                'before_user_card_id' => 0,
                'create_time' => $nowTime,
                'update_time' => $nowTime,
                'is_lock' => 0,
                'unlock_time' => 0,
                'is_double' => 1,
                'is_activity' => 0,
                'give_card_time' => $new_card_give_card_time
            ]);
            if(!$new_card){
                throw new Exception('新年卡创建失败');
            }

            $new_card_detail = UserCardDetailsModel::create([
                'user_id' => $user_id,
                'card_id' => $card_info['id'],
                'money' => $card_info['price'],
                'pay_money' => 0,
                'deposit_money' => 0,
                'extra_money' => 0,
                'describe' => '',
                'action_type' => 5,
                'refund_status' => 1,
                'pay_time' => $nowTime,
                'create_time' => $nowTime,
                'update_time' => $nowTime,
                'user_card_id' => $new_card['id'],
                'out_trade_no' => '',
                'is_double' => 0,
                'card_open_time' => $new_card_open_time,
                'card_deposit' => $card_info['card_deposit'],
                'experience_card_id' => 0,
                'experience_add_card_days' => 0
            ]);
            if(!$new_card_detail){
                throw new Exception('新年卡明细创建失败');
            }

            //记录老年卡操作日志
            UserCardsOperationLogsModel::create([
                'user_id' => $user_id,
                'user_type' => 2,
                'user_card_id' => $user_card['id'],
                'create_time' => $nowTime,
                'log_info' => '0元升级年卡，699年卡权益免费得；领取赠送年卡时长，过期当前用户年卡ID：'.$user_card['id'].'，转移年卡押金至新年卡：'.$new_card['id']
            ]);

            //记录年卡操作日志
            UserCardsOperationLogsModel::create([
                'user_id' => $user_id,
                'user_type' => 2,
                'user_card_id' => $new_card['id'],
                'create_time' => $nowTime,
                'log_info' => '0元升级年卡，699年卡权益免费得；领取赠送年卡时长，开通699年卡，押金由原年卡：'.$user_card['id'].'转移至此年卡'
            ]);

            
            //赠送年卡时长日志
            if(!empty($relation_arr)){
                $change_duration = ceil($gift_time / 86400 / count($relation_arr));
                $j = 1;
                $prev_start = 0;
                $prev_end = 0;
                foreach ($relation_arr as $av){
                    $curr_start = $new_card_start_time;
                    $curr_end = $new_card_start_time + ($change_duration * 86400 * $j);
                    self::addChangeLog([
                        'user_id' => $user_id,
                        'user_card_id' => $new_card['id'],
                        'card_id' => $card_info['id'],
                        'card_name' => $card_info['name'],
                        'change_duration' => sprintf('+%d 天', $change_duration),
                        'change_name' => '0元升级年卡，699年卡权益免费得；赠送'.$card_info['name'],
                        'change_reason' => 6,
                        'relation_mobile' => $av['mobile'],
                        'change_low_start_time' => $prev_start,
                        'change_low_end_time' => $prev_end,
                        'change_new_start_time' => $curr_start,
                        'change_new_end_time' => $curr_end,
                    ]);
                    $prev_start = $curr_start;
                    $prev_end = $curr_end;
                    $j++;
                }
            }



            if($deposit_time > 0) {
                //老押金转入新年卡
                $new_card_give_card_time = $new_card_give_card_time + $deposit_time;
                $new_card_end_time = $new_card_end_time + $deposit_time;
                $new_card_open_time = $new_card_open_time + $deposit_time;
                $uc_res = UserCardsModel::where('id', $new_card->id)
                    ->update(['give_card_time' => $new_card_give_card_time, 'end_time' => $new_card_end_time, 'update_time' => time()]);
                //更新年卡时长
                $ucd_res = UserCardDetailsModel::where('user_card_id', $new_card->id)
                    ->update(['card_open_time' => $new_card_open_time, 'update_time' => time()]);
                if (!$uc_res || !$ucd_res) {
                    throw new Exception('老年卡时长转入失败');
                }

                //押金转入年卡日志
                self::addChangeLog([
                    'user_id' => $user_id,
                    'user_card_id' => $new_card->id,
                    'card_id' => $card_info['id'],
                    'card_name' => $card_info['name'],
                    'change_duration' => sprintf('+%d天', ceil($deposit_time / 86400)),
                    'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $grade['level_name'] . '时长转为年卡时长',
                    'change_reason' => 6,
                    'relation_mobile' => '',
                    'change_low_start_time' => $new_card_old_start_time,
                    'change_low_end_time' => $new_card_old_end_time,
                    'change_new_start_time' => $new_card_start_time,
                    'change_new_end_time' => $new_card_end_time,
                ]);
            }



            //老年卡转入新年卡
            $new_card_old_give_card_time = $new_card_give_card_time;
            $new_card_old_end_time = $new_card_end_time;
            $new_card_old_open_time = $new_card_open_time;

            //当前年卡剩余时长
            if($user_card['is_expire'] == 2){
                //老年卡转入日志
                self::addChangeLog([
                    'user_id' => $user_id,
                    'user_card_id' => $new_card->id,
                    'card_id' => $card_info['id'],
                    'card_name' => $card_info['name'],
                    'change_duration' => sprintf('+%d 天', 0),
                    'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $card['name'] . '时长转为' . $card_info['name'] . '时长',
                    'change_reason' => 6,
                    'change_low_start_time' => $new_card_old_start_time,
                    'change_low_end_time' => $new_card_old_end_time,
                    'change_new_start_time' => $new_card_start_time,
                    'change_new_end_time' => $new_card_end_time,
                ]);
            }else {
                $card_time = ($user_card['is_lock'] == 1) ? $user_card_detail['card_open_time'] : ($user_card['end_time'] - time());
                $card_time = $card_time < 1 ? 0 : $card_time;
                //计算老年卡剩余赠送时长
                $time = ($user_card['is_lock'] == 1) ? $user_card_detail['card_open_time'] : ($user_card['end_time'] - $user_card['start_time']);
                //老年卡剩余赠送时长
                $old_card_give_card_time = (($time - $user_card['give_card_time']) > 0) ? $user_card['give_card_time'] : $time;
                //年卡赠送时长 = 原赠送时长 + 老年卡剩余赠送时长
                $new_card_give_card_time = $new_card_give_card_time + $old_card_give_card_time;
                //新年卡现在到期时间
                $new_card_end_time = $new_card_end_time + $card_time;
                $new_card_open_time = $new_card_open_time + $card_time;

                $uc_res = UserCardsModel::where('id', $new_card->id)
                    ->inc('end_time', $card_time)->update(['give_card_time' => $new_card_give_card_time, 'end_time' => $new_card_end_time, 'update_time' => time()]);

                //更新年卡时长
                $ucd_res = UserCardDetailsModel::where('user_card_id', $new_card->id)
                    ->update(['card_open_time' => $new_card_open_time, 'update_time' => time()]);

                if (!$uc_res || !$ucd_res) {
                    throw new Exception('老年卡时长转入失败');
                }

                //老年卡转入日志
                self::addChangeLog([
                    'user_id' => $user_id,
                    'user_card_id' => $new_card->id,
                    'card_id' => $card_info['id'],
                    'card_name' => $card_info['name'],
                    'change_duration' => sprintf('+%d 天', ceil($card_time / 86400)),
                    'change_name' => '0元升级年卡，699年卡权益免费得；活动' . $card['name'] . '时长转为' . $card_info['name'] . '时长',
                    'change_reason' => 6,
                    'change_low_start_time' => $new_card_old_start_time,
                    'change_low_end_time' => $new_card_old_end_time,
                    'change_new_start_time' => $new_card_start_time,
                    'change_new_end_time' => $new_card_end_time,
                ]);
            }

            //更新活动赠送类型，以及时长
            $settle_res = JulActivityChannelUserSettleModel::where('id', $settle_id)
                ->update([
                    'father_give_type' => $card_info['id'],
                    'father_give_time' => $gift_time,
                    'update_time' => time(),
                    'card_give_time' => $card_time ?? 0,
                    'deposit_give_time' => ($deposit_time > 0) ? $deposit_time : 0,
                    'user_card_id' => $new_card['id']
                ]);
            if(!$settle_res){
                throw new Exception('年卡时长领取失败');
            }
        }

    }


    /**
     * 添加年卡时长变动日志
     * @param array $log
     * @author: yangliang
     * @date: 2021/7/26 12:54
     */
    private function addChangeLog(array $log){
        UserCardChangeLogModel::create([
            'user_id' => $log['user_id'],
            'user_card_id' => $log['user_card_id'] ?? 0,
            'card_id' => $log['card_id'] ?? 0,
            'card_name' => $log['card_name'] ?? '',
            'change_duration' => $log['change_duration'] ?? '',
            'change_name' => $log['change_name'] ?? '',
            'change_reason' => $log['change_reason'],
            'relation_mobile' => $log['relation_mobile'] ?? '',
            'change_low_start_time' => $log['change_low_start_time'] ?? 0,
            'change_low_end_time' => $log['change_low_end_time'] ?? 0,
            'change_new_start_time' => $log['change_new_start_time'] ?? 0,
            'change_new_end_time' => $log['change_new_end_time'] ?? 0,
            'create_time' => time()
        ]);
    }
}