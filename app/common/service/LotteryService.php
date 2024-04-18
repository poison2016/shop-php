<?php

/**
 * 抽奖活动相关
 */

namespace app\common\service;


use app\common\model\CouponModel;
use app\common\model\GoodsCommentModel;
use app\common\model\LotteryInvitationModel;
use app\common\model\LotteryModel;
use app\common\model\LotteryPrizeChangeModel;
use app\common\model\LotteryPrizeListModel;
use app\common\model\LotteryPrizeModel;
use app\common\model\PointsLogModel;
use app\common\model\PointsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserCouponModel;
use app\common\model\UserModel;
use Exception;
use think\facade\Db;
use think\Model;

class LotteryService extends ComService
{

    /**
     * 获取活动抽奖信息
     * @param int $user_id 用户ID
     * @param int $lottery_id 活动ID
     * @param float $version  版本号，兼容之前版本
     * @return array
     * @author yangliang
     * @date 2021/3/10 17:32
     */
    public function getInfo(int $user_id, int $lottery_id, float $version): array
    {
        //抽奖信息
        $lottery = LotteryModel::getById($lottery_id);
        if (empty($lottery)) {
            return $this->error(100, '暂无活动信息');
        }

        //奖项信息
        $prize = LotteryPrizeModel::getByLotteryId($lottery['id'], 'id, name, prize_img, thumbnail, remark');
        if (empty($prize)) {
            return $this->error(100, '活动信息异常，请联系客服');
        }

        //兼容之前版本
        if($version < 1){
            foreach ($prize as &$v){
                $v['remark'] = $v['name'];
            }
        }

        $lottery['start_time'] = date('Y-m-d H:i:s', $lottery['start_time']);
        $lottery['end_time'] = date('Y-m-d H:i:s', $lottery['end_time']);

        //获取用户身份
        //$role = $this->getUserRole($user_id);

        //赠送抽奖次数
        $change_res = $this->addLotteryChange($user_id, $lottery['id']);

        //可抽奖次数
        $lottery_num = LotteryPrizeChangeModel::getSumByUserId($user_id, $lottery['id']);

        return $this->success(200, '获取成功', ['lottery' => $lottery, 'prize' => $prize, 'lottery_num' => ($lottery_num > 0) ? $lottery_num : 0,'code'=>$change_res['code'],'message'=>$change_res['message']]);
    }


    /**
     * 获取用户身份 （1、非年卡用户  2、年卡用户）
     * @param int $user_id 用户ID
     * @return int
     * @author yangliang
     * @date 2021/3/11 9:19
     */
    public function getUserRole(int $user_id): int
    {
        $user_card = UserCardsModel::getUnRefundByUserId($user_id);
        if (!empty($user_card) && $user_card['is_expire'] == 1) {
            return 2;  //年卡用户
        }

        return 1;  //非年卡用户
    }

    /**第一次进去用户领取次数
     * @param int $userId
     * @param int $lotteryId
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/6/29 5:43 下午
     */
    public function addLotteryChange(int $userId, int $lotteryId)
    {
        //判断用户是否注册
        $mobile = (new UserModel())->getOneUser($userId,1,'mobile');
        if(!$mobile){
            return errorArray('您还没有注册');
        }
        //判断用户年卡信息
        $userData = UserCardsModel::getOneCards($userId);
        if (!$userData) {
            return errorArray('您还没有购卡',102);
        }
        //判断是否已经领取
        $lotteryData = (new LotteryPrizeChangeModel())->where(['user_id' => $userId, 'type' => 1, 'lottery_id' => $lotteryId])->findOrEmpty()->toArray();
        if ($lotteryData) {
            return errorArray('你已经完成任务了',104);
        }
        if ($userData['create_time'] < strtotime('2021-07-01 0:0:0') || $userData['create_time'] > strtotime('2021-07-18 23:59:59')) {
            return errorArray('您还未完成任务，邀请朋友注册获得抽奖机会。',102);
        }
        //判断有效书评是否大于10
        $commentCount = (new GoodsCommentModel())->where(['is_type' => 3, 'is_effective' => 1,'user_id'=>$userId])->count();
        if ($commentCount < 10) {
            return errorArray('您还未完成任务，邀请朋友注册获得抽奖机会。',102);
        }
        //写入数据
        $res = (new LotteryPrizeChangeModel())->insert([
            'user_id' => $userId,
            'lottery_id' => $lotteryId,
            'type' => 1,
            'change_num' => 1,
            'remark' => '完成任务用户赠送1次抽奖',
            'create_time' => time()
        ], true);
        if (!$res) {
            return errorArray('写入失败',103);
        }
        return successArray();
    }


    /**
     * 赠送抽奖次数
     * @param int $user_id 用户ID
     * @param int $lottery_id 抽奖活动ID
     * @param int $role 用户身份  1、非年卡用户  2、年卡用户
     * @return bool
     * @author yangliang
     * @date 2021/3/11 9:34
     */
//    public function addLotteryPrizeChange(int $user_id, int $lottery_id, int $role): bool
//    {
//        $data = [];
//        //注册用户首次赠送1次抽奖记录
//        $user_lottery = LotteryPrizeChangeModel::findByUserIdAndLotteryId($user_id, $lottery_id, 1);
//        if(empty($user_lottery)) {
//            $data[] = [
//                'user_id' => $user_id,
//                'lottery_id' => $lottery_id,
//                'type' => 1,
//                'change_num' => 1,
//                'remark' => '注册用户赠送1次抽奖',
//                'create_time' => time()
//            ];
//        }
//
//        $mLotteryPrizeChange = new LotteryPrizeChangeModel();
//        //年卡用户首次赠送1次抽奖记录
//        $card_lottery = LotteryPrizeChangeModel::findByUserIdAndLotteryId($user_id, $lottery_id, 4);
//        if (empty($card_lottery) && $role == 2) {
//            $data[] = [
//                'user_id' => $user_id,
//                'lottery_id' => $lottery_id,
//                'type' => 4,
//                'change_num' => 1,
//                'remark' => '购卡用户赠送1次抽奖',
//                'create_time' => time()
//            ];
//
//        }
//
//        if (!empty($data) && !$mLotteryPrizeChange->insertAll($data)) {
//            return false;
//        }
//
//        return true;
//    }


    /**
     * 抽奖
     * @param int $user_id 用户ID
     * @param int $lottery_id 抽奖活动ID
     * @return array
     * @author yangliang
     * @date 2021/3/11 15:11
     */
    public function lottery(int $user_id, int $lottery_id): array
    {
        //抽奖信息
        $lottery = LotteryModel::getById($lottery_id);
        if (empty($lottery)) {
            return $this->error(100, '抽奖活动不存在');
        }

        if (time() < $lottery['start_time']) {
            return $this->error(100, '抽奖活动暂未开始');
        }

        if (time() >= $lottery['end_time']) {
            return $this->error(100, '抽奖活动已结束');
        }

        //奖项信息
        $prize = LotteryPrizeModel::getByLotteryId($lottery_id);
        if (empty($prize)) {
            return $this->error(100, '活动信息异常，请联系客服');
        }

        //可抽奖次数
        $lottery_num = LotteryPrizeChangeModel::getSumByUserId($user_id, $lottery_id);
        if ($lottery_num < 1) {
            return $this->error(100, '抽奖次数不足');
        }

        //获取用户身份
        $role = $this->getUserRole($user_id);

        //判断是否首次抽奖
        $num = LotteryPrizeChangeModel::getLotteryCount($user_id, $lottery_id);

        list($pro_arr, $arr) = $this->buildData($user_id, $lottery_id, $prize, $role, $num);

        $prize_idx = !empty($pro_arr) ? $this->get_rand($pro_arr) : -1;
        //创建抽奖记录
        $change_res = LotteryPrizeChangeModel::create([
            'user_id' => $user_id,
            'lottery_id' => $lottery_id,
            'type' => 3,
            'change_num' => -1,
            'remark' => '抽奖',
            'create_time' => time()
        ]);
        if (!$change_res) {
            return $this->error(100, '系统异常');
        }

        Db::startTrans();
        try {
            $mLotteryPrizeList = new LotteryPrizeListModel();
            $mUserCoupon = new UserCouponModel();
            $prize_bingo = ($prize_idx < 0) ? [] : $arr[$prize_idx];
            $coupon_res = true;
            //中奖记录
            $prize_res = $mLotteryPrizeList->insert([
                'user_id' => $user_id,
                'prize_id' => ($prize_idx >= 0) ? $prize_bingo['id'] : -1,  // -1 未中奖
                'lottery_id' => $lottery_id,
                'create_time' => time()
            ], true);

            //抽中奖品
            $couponData = (new CouponModel())->where('id',$prize_bingo['coupon_id'])->findOrEmpty()->toArray();
            if($prize_idx >= 0) {
                //发放奖品
                $coupon_res = $mUserCoupon->insert([
                    'user_id' => $user_id,
                    'coupon_id' => $prize_bingo['coupon_id'],
                    'is_expire' => 0,
                    'expire_time' => ($prize_bingo['end_type'] == 2) ? $prize_bingo['end_time'] : (time() + $prize_bingo['end_time'] * 60 * 60 * 24),
                    'is_used' => $couponData['jf_type'] ? 1 : 0,
                    'used_time' => $couponData['jf_type'] ? time() : 0,
                    'type' => $prize_bingo['type'],
                    'card_id' => $prize_bingo['card_id'],
                    'experience_time' => $prize_bingo['experience_time'],
                    'coupon_keys' => 'RD' . date('YmdHis') . 'U' . $user_id . 'LOTTERY' . $lottery_id,
                    'scenarios' => $prize_bingo['scenarios'],
                    'scenarios_price' => $prize_bingo['scenarios_price'],
                    'price' => $prize_bingo['price'],
                    'name' => $prize_bingo['name'],
                    'img' => $prize_bingo['prize_img'],
                    'remark' => $couponData['describe'],
                    'jf_type' => $couponData['jf_type'],
                    'jf_num' => $couponData['jf_num'],
                    'is_can_give' => $couponData['is_can_give'],
                    'create_time' => time(),
                    'update_time' => time(),
                ], true);
            }
            if (!$prize_res || !$coupon_res) {
                throw new Exception('系统异常，请重试');
            }
            //赠送阅币
            if( $couponData['jf_type']){
                $pointsModel = (new PointsModel());
                $userPoints = $pointsModel->where('user_id', $user_id)->findOrEmpty()->toArray();
                if (!$userPoints) {
                    // 添加
                    $pointsResult = $pointsModel->insert(['user_id' => $user_id, 'create_time' => time()]);
                    if (!$pointsResult) {
                        return $this->error(0, "积分记录添加失败");
                    }
                }
                $points_update = $pointsModel->where('user_id', $user_id)->inc('points', $couponData['jf_num'])->update(['update_time' => time()]);
                if(!$points_update){
                    throw new Exception('系统异常，请重试');
                }
                //写入日志
                // 添加积分变动日志
                $pl_res = (new PointsLogModel())->addPointsLog($user_id,  $couponData['jf_num'], 18, '大转盘抽奖', 1);
                if (!$pl_res) {
                    return $this->error(null, "积分变动日志添加失败");
                }
            }

        } catch (Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, 'success', ['id' => ($prize_idx >= 0) ? $prize_bingo['id'] : -1, 'lottery_num' => $lottery_num - 1]);
    }


    /**
     * 构建数据
     * @param int $user_id 用户ID
     * @param int $lottery_id 抽奖活动ID
     * @param array $prize 抽奖奖项
     * @param int $role 用户身份  1、非年卡用户  2、年卡用户
     * @param int $type 类型  1、首抽  2、非首抽
     * @return array
     * @author yangliang
     * @date 2021/3/11 11:07
     */
    public function buildData(int $user_id, int $lottery_id, array $prize, int $role = 1, int $type = 2): array
    {
        $rate = '';
        if ($role == 2 && $type < 1) {  //年卡用户首抽
            $rate = 'card_odds';
        } elseif ($role == 2 && $type > 0) {  //年卡用户非首抽
            $rate = 'repeat_card_odds';
        } elseif ($role == 1 && $type < 1) {  //非年卡用户首抽
            $rate = 'user_odds';
        } elseif ($role == 1 && $type > 0) {  //非年卡用户非首抽
            $rate = 'repeat_user_odds';
        }

        //数据重组
        $pro_arr = [];
        $arr = [];
        //获取该用户中奖纪录
       // $record = LotteryPrizeListModel::getByLotteryAndUserId($lottery_id, $user_id);
        //$record_prize_ids = !empty($record) ? array_column($record, 'prize_id') : [];
        foreach ($prize as $v) {
            //剔除已中奖项和0概率奖项
            //if (!in_array($v['id'], $record_prize_ids) && $v[$rate] > 0) {
            if ($v[$rate] > 0) {
                $k = intval($v[$rate] * 100);  //中奖概率扩大取整
                $pro_arr[] = $k;
                $arr[] = $v;  //生成新的奖池
            }
        }

        return [$pro_arr, $arr];
    }


    /**
     * 抽奖算法
     * @param array $pro_arr
     * @return int
     * @author yangliang
     * @date 2021/3/11 10:05
     */
    public function get_rand(array $pro_arr = []): int
    {
        if (empty($pro_arr)) {
            return -1;
        }
        $rid = -1;
        
        // 概率数组的总权重
        $pro_sum = array_sum($pro_arr);
        if ($pro_sum == 0) {
            return $rid;
        }
        // 概率数组循环
        foreach ($pro_arr as $k => $pro_cur) {
            // 从 1 到概率总数中任意取值
            $rand_num = mt_rand(1, $pro_sum);
            // 判断随机数是否在概率权重中
            if ($rand_num <= $pro_cur) {
                // 取出奖品 id
                $rid = $k;
                break;
            } else {
                // 如果随机数不在概率权限中，则不断缩小总权重，直到从奖品数组中取出一个奖品
                $pro_sum -= $pro_cur;
            }
        }
        unset($pro_arr);
        return $rid;
    }


    /**
     * 获取活动中间记录
     * @param int $lottery_id 活动ID
     * @return array
     * @author yangliang
     * @date 2021/3/11 15:35
     */
    public function getRecord(int $lottery_id): array
    {
        $list = LotteryPrizeListModel::getRecordByLotteryId($lottery_id);
        if (!empty($list)) {
            foreach ($list as &$v) {
                $v['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
                $v['mobile'] = !empty($v['mobile']) ? substr_replace($v['mobile'], '****', 3, 4) : $v['user_name'];
                $v['time'] = time_tran($v['create_time']);
            }
        }

        return $this->success(200, '获取成功', $list);
    }

    /**邀请用户
     * @param $params
     * @param $userId
     * @return array
     * @author Poison
     * @date 2021/3/11 3:58 下午
     */
    public function invite($params, $userId)
    {
        $params['top_id'] = self::getDecode($params['top_id']);
        $params['invitation_user_id'] = $topIdArray = json_decode($params['top_id'], TRUE);
        if (is_array($topIdArray)) {
            $params['invitation_user_id'] = $topIdArray['top_id'];
        }
        if (!$params['invitation_user_id']) {
            return self::error(100, '上级ID解析失败');
        }
        if ($params['invitation_user_id'] == $userId) {
            return self::error(100, '不能邀请自己');
        }
        unset($params['top_id']);
        $params['Invited_user_id'] = $userId;
        $lotteryId = (new LotteryModel())->where('id', $params['lottery_id'])->where('is_delete', 1)->where('end_time', '>', time())->order('id', 'DESC')->value('id');
        if (!$lotteryId) {
            return self::error(100, '活动暂未开始或已结束!');
        }
        //判断用户是否已注册
        $params['status'] = 0;
        $mobile = (new UserModel())->getOneUser($userId, 1, 'mobile');
        if ($mobile) {
            $params['status'] = 3;
        }
        $resData = (new LotteryInvitationModel())->getOneData(['invitation_user_id' => $params['invitation_user_id'], 'Invited_user_id' => $userId, 'lottery_id' => $lotteryId]);
        if ($resData) {
            return self::error(100, '您已经邀请过他了');
        }
        $res = (new LotteryInvitationModel())->addLotteryInvitation($params);
        if (!$res) {
            return self::error(100, '邀请失败');
        }
        return self::success(200, '邀请成功');
    }

    /**修改状态为邀请成功
     * @param $userId
     * @param $param
     * @return array
     * @author Poison
     * @date 2021/3/11 4:33 下午
     */
    public function inviteOk($param, $userId)
    {
        //判断活动是否结束
        $lotteryId = (new LotteryModel())->where('id', $param['lottery_id'])->where('is_delete', 1)->where('end_time', '>', time())->order('id', 'DESC')->value('id');
        if (!$lotteryId) {
            return self::error(100, '活动暂未开始或已结束!');
        }

        $resData = (new LotteryInvitationModel())->getOneData(['Invited_user_id' => $userId, 'lottery_id' => $lotteryId]);
        if (!$resData) {
            return self::error(100, '暂无邀请记录');
        }
        if ($resData['status'] != 0) {
            return self::error(100, '已绑定或已注册');
        }

        //$topCard = UserCardsModel::getOneCards((int)$resData['invitation_user_id']);
       // $nickname = (new UserModel())->where('user_id', $userId)->value('nickname');
       // $num = 1;
        //$remark = '邀请( ' . $nickname . ' )成功 次数+1';
//        if ($topCard) {
//            $num = 2;
//            $remark = '邀请( ' . $nickname . ' )成功 次数+2';
//        }
//        $data['user_id'] = $resData['invitation_user_id'];
//        $data['lottery_id'] = $resData['lottery_id'];
//        $data['change_num'] = $num;
//        $data['type'] = 2;
//        $data['remark'] = $remark;
//        $resChange = (new LotteryPrizeChangeModel())->addChange($data);
//        if (!$resChange) {
//            return self::error(100, '添加日志失败');
//        }


        //没有问题 开始修改状态
        $res = (new LotteryInvitationModel())->update(['status' => 1, 'update_time' => time()], ['id' => $resData['id']]);
        if (!$res) {
            return self::error(100, '修改失败');
        }
        //判断用户邀请的总数量
        $invitedCount = LotteryInvitationModel::getInvitedCount($lotteryId,$resData['invitation_user_id']);
        //查询赠送次数
        $changeCount = LotteryPrizeChangeModel::lotteryCount($resData['invitation_user_id'],$lotteryId);
        if (($invitedCount - $changeCount * 5) >= 5) {//赠送次数
            $remark = '邀请够5人 次数+1';
            $data['user_id'] = $resData['invitation_user_id'];
            $data['lottery_id'] = $resData['lottery_id'];
            $data['change_num'] = 1;
            $data['type'] = 2;
            $data['remark'] = $remark;
            $resChange = (new LotteryPrizeChangeModel())->addChange($data);
            if (!$resChange) {
                return self::error(100, '添加日志失败');
            }
        }
        return self::success(200, '修改成功');
    }

    /**获取自己的邀请
     * @param int $userId
     * @param $param
     * @return array
     * @author Poison
     * @date 2021/3/15 10:57 上午
     */
    public function thisInvite($param, int $userId)
    {
        $data = (new LotteryInvitationModel())->selectInvitation($param['lottery_id'], $userId);
        foreach ($data as $k => $v) {
            if ($v['head_pic']) {
                $data[$k]['head_pic'] = $this->getGoodsImg($v['head_pic']);
            }
        }
        return self::success(200, '获取成功', $data);
    }
}