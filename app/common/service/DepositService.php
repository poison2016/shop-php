<?php

/**
 * 押金相关
 */

namespace app\common\service;


use app\common\model\ApplyLogModel;
use app\common\model\ApplyModel;
use app\common\model\DepositModel;
use app\common\model\DepositUpgradeCardModel;
use app\common\model\GoodsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserDepositExpireTimeLogModel;
use app\common\model\UserLevelModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use think\facade\Db;
use think\facade\Log;
use think\Model;


class DepositService extends ComService
{
    use CacheTrait;

    /**
     * 押金延期
     * @param $data
     * @return array
     * @author yangliang
     * @date 2020/12/18 14:32
     */
    public function extension(array $data): array
    {
        $user = (new UserModel())->getOneUser($data['userId']);
        if (empty($user)) {
            return $this->error(100, '用户不存在');
        }

        if ($user['deposit'] <= 0) {
            return $this->error(100, '非押金用户，不能领取');
        }

        $userCard = UserCardsModel::getByUserId($data['userId']);
        if($userCard){
            return $this->error(100, '年卡用户，不能领取');
        }


        Db::startTrans();
        try {
            $apply = ApplyModel::where('user_id', $data['userId'])
                ->where('type', 1)
                ->where('status', '<', 6)
                ->where('status', '<>', -1)
                ->order('apply_id', 'DESC')
                ->find();

            if (!empty($apply)) {
                //取消用户退押金申请
                $apply_res = ApplyModel::where('apply_id', $apply['apply_id'])
                    ->update(['status' => -1]);

                $apply_log = ApplyLogModel::create([
                    'apply_id' => $apply['apply_id'],
                    'user_id' => $data['userId'],
                    'admin_id' => 0,
                    'admin_name' => '系统',
                    'loginfo' => '用户选择领取6个月时长'
                ]);
                if (!$apply_res || !$apply_log) {
                    throw new \Exception('取消退押金申请失败');
                }
            }

            //一个用户只能延期一次
            $check = self::checkExtension($data);
            if ($check['code'] == 200 && $check['data']['is_received'] == 1) {
                Db::commit();
                return $this->error(100, '您的押金到期时间为' . $check['data']['expire_time']);
            }


            $expire_time = 0;
            $log_time = 0;
            //退卡延续6个月

            if ($data['type'] == 1) {
                $expire_time = 6 * 30 * 24 * 60 * 60;
                $log_time = 6 * 30 * 24 * 60 * 60;

            }

            $user = (new UserModel())->getOneUser($data['userId']);
            //是押金用户，没有押金到期时间则初始化并在加6个月
            if (empty($user['user_deposit_expire_time'])) {
                $expire_time = $expire_time + 1611158399;    //2021-01-20 23:59:59
            }

            //更新用户押金到期时间
            $user_res = UserModel::where('user_id', $data['userId'])->inc('user_deposit_expire_time', $expire_time)->update();

            //记录用户押金延期日志
            $log = UserDepositExpireTimeLogModel::create([
                'user_id' => $data['userId'],
                'type' => $data['type'],
                'create_time' => time(),
                'update_time' => time(),
                'status' => 1,
                'open_time' => $log_time
            ]);

            if (!$user_res || !$log) {
                throw new \Exception('押金延期失败');
            }
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '押金延期成功', ['expire_time' => date('Y-m-d', $expire_time)]);
    }


    /**
     * 验证是否已领取6个月押金时长
     * @param $data
     * @return array
     * @author yangliang
     * @date 2020/12/18 16:20
     */
    public function checkExtension($data)
    {
        $record = UserDepositExpireTimeLogModel::getByUserIdAndType($data['userId'], $data['type']);
        $user = (new UserModel())->getOneUser($data['userId']);
        $userCard = UserCardsModel::getByUserId($data['userId']);
        $dataTime = '无';
        if($user['user_deposit_expire_time']){
            $dataTime = date('Y-m-d', $user['user_deposit_expire_time']);
        }
        if ($userCard) {
            return $this->success(200, '获取成功', ['is_received' => 0, 'is_card' => 1, 'expire_time' =>$dataTime]);
        }
        if (!empty($record)) {
            return $this->success(200, '获取成功', ['is_received' => 1, 'is_card' => 0, 'expire_time' =>$dataTime]);
        }

        return $this->success(200, '获取成功', ['is_received' => 0, 'is_card' => 0,'expire_time' => $dataTime]);
    }

    /**获取用户押金升级年卡支付金额
     * @param int $userId
     * @param float $cardPrice
     * @return array
     * @author Poison
     * @date 2020/12/21 11:36 上午
     */
    public function depositPrice(int $userId, float $cardPrice)
    {
        //判断用户是否有押金
        $depositData = DepositModel::getOneData($userId);//获取用户押金条
        $userLevel = (new UserModel())->getOneUser($userId);//获取用户自身押金
        if (!$depositData) {
            return self::error(100, '押金条暂无记录');
        }
        if (!$userLevel) {
            return self::error(100, '用户押金暂无记录');
        }
        if ($depositData['deposit_money'] != $userLevel['deposit']) {
            return self::error(103, '您的押金金额与实际不符，请联系客服进行处理，客服电话029-85799903');
        }
        if ($depositData['grade_now'] != $userLevel['grade']) {
            return self::error(103, '您的押金等级与实际不符，请联系客服进行处理，客服电话029-85799903');
        }
        $thisPrice = $cardPrice - $depositData['deposit_money'];//需要支付的价格
        $depositType = 0;//0 用户支付 1 企业支付
        if ($thisPrice < 0) {
            $thisPrice = $depositData['deposit_money'] - $cardPrice;
            $depositType = 1;
        }
        //查询押金等级名称
        $depositName = (new UserLevelModel())->where('grade', $userLevel['grade'])->value('level_name');
        return self::success(200, '完成', ['deposit_type' => $depositType, 'price' => $thisPrice, 'deposit_price' => $depositData['deposit_money'], 'deposit_id' => $depositData['deposit_id'], 'deposit_name' => $depositName, 'grade' => $userLevel['grade']]);
    }

    /**写入押金转年卡 押金记录
     * @param int $userId
     * @param int $status 退款1 支付2
     * @param float $price 退款/支付金额
     * @param float $cardPrice 年卡价格
     * @param string $outTradeNo 订单号
     * @return array
     * @author Poison
     * @date 2020/12/22 11:10 上午
     */
    public function deleteData(int $userId, $status = 1, $outTradeNo = "", $price = 0.0, $cardPrice = 0.0)
    {
        Db::startTrans();
        try {
            $userData = (new UserModel())->getOneUser($userId);
            $depositData = DepositModel::getOneData((int)$userId);//获取用户押金条
            if (!$depositData) {
                Db::rollback();
                return $this->error(100, '获取押金条失败');
            }
            if ($status != 1) {
                $upgradeData['user_id'] = $userId;
                $upgradeData['out_refund_no'] = $outTradeNo;
                $upgradeData['out_price'] = $price;
                $upgradeData['deposit_price'] = $depositData['deposit_money'] ?? 0;
                $upgradeData['card_price'] = $cardPrice;
                $upgradeData['low_grade'] = $depositData['grade_now'];
                $upgradeData['deposit_id'] = $depositData['deposit_id'];
                $upgradeData['create_time'] = time();
                $upgradeData['update_time'] = time();
                $upgradeData['is_out'] = 1;
                $upgradeData['type'] = 1;
                $upgradeData['remark'] = '支付成功';
                (new DepositUpgradeCardModel())->insert($upgradeData, true);
            }
            //先将用户表中的等级和押金去除掉
            $resUser = (new UserModel())->updateUserInfo($userId, ['deposit' => 0, 'update_time' => time(), 'grade' => 0, 'user_deposit_expire_time' => time()]);
            if (!$resUser) {
                Db::rollback();
                return $this->error(100, '押金归零失败');
            }

            $resDeposit = (new DepositModel())->where('deposit_id', $depositData['deposit_id'])->update([
                'action_return' => 1,
                'return_time' => time(),
                'return_user_id' => 1,
                'return_user_name' => 'admin',
                'action_confirm' => 1,
                'confirm_time' => time(),
                'confirm_user_id' => 1,
                'confirm_user_name' => 'admin',
            ]);
            if (!$resDeposit) {
                Db::rollback();
                return $this->error(100, '押金条修改失败');
            }
            LogService::depositLog($resDeposit, '押金转年卡 押金归零', $userId);
            $resApply = (new ApplyModel())->insert([
                'user_id' => $userId,
                'user_name' => $userData['user_name'],
                'user_phone' => $userData['mobile'],
                'user_grade' => $userData['grade'],
                'user_to_grade' => 0,
                'identity' => 1,
                'type' => 1,
                'reason' => '用户押金转年卡，系统自动去除押金',
                'add_time' => time(),
                'update_time' => time(),
                'status' => 6,
                'level_status' => 0,
                'is_ship' => 3,
                'real_reason' => '系统自动退押金'
            ], true);
            if (!$resApply) {
                Db::rollback();
                return $this->error(100, '退押金申请失败');
            }
            LogService::applyLogAdd($userId, $resApply, '系统自动完成审批');
            LogService::userLog($userData['user_name'], $userId, '用户使用押金升级 ' . $cardPrice . '年卡');
            Db::commit();
            return $this->success(200, '成功');
        } catch (\Exception $ex) {
            Db::rollback();
            return $this->error(100, '网络异常:' . $ex->getMessage());
        }
    }

    /**写入用户购买年卡后押金时长
     * @param array $user
     * @param float $cardPrice
     * @return bool
     * @author Poison
     * @date 2020/12/23 4:04 下午
     */
    public function UserDeposit(array $user, float $cardPrice)
    {
        //获取用户是否是押金用户
        $thisDeposit = (new UserModel())->getOneUser($user['user_id'], 1, 'deposit');
        if ($thisDeposit == 0) {
            return false;
        }
        $resLogThree = UserDepositExpireTimeLogModel::getOneData(['user_id' => $user['user_id'], 'type' => 4, 'status' => 1]);
        if ($resLogThree) {
            return false;
        }
        //判断用户是否已经赠与了6个月的
        $resLog = UserDepositExpireTimeLogModel::getOneData(['user_id' => $user['user_id'], 'type' => 1, 'status' => 1]);
        $resTwoLog = UserDepositExpireTimeLogModel::getOneData(['user_id' => $user['user_id'], 'type' => 2, 'status' => 1]);
        $type = 1;
        if (!$resLog) {//没有 旧的
            $depositTime = config('self_config.pay_card_give_deposit.low.' . $cardPrice);
            $openTime = $depositTime - config('self_config.deposit_transform_card');
        } elseif (!$resTwoLog) {
            $type = 2;
            $openTime = $depositTime = config('self_config.pay_card_give_deposit.new.' . $cardPrice);
        } else {
            return false;
        }
        if ($user['user_deposit_expire_time'] == 0) {
            $user['user_deposit_expire_time'] = 1611158399;
        }
        $upTime = $depositTime + $user['user_deposit_expire_time'];
        if ($type == 1) {//如果状态为1的话 那么进行补写
            $resInsertLog = (new UserDepositExpireTimeLogModel())->insertAll([
                [
                    'user_id' => $user['user_id'],
                    'type' => 1,
                    'create_time' => time(),
                    'update_time' => time(),
                    'open_time' => config('self_config.deposit_transform_card'),
                ], [
                    'user_id' => $user['user_id'],
                    'type' => 2,
                    'create_time' => time(),
                    'update_time' => time(),
                    'open_time' => $openTime,
                ]
            ]);
        } else {
            $resInsertLog = (new UserDepositExpireTimeLogModel())->insert([
                'user_id' => $user['user_id'],
                'type' => $type,
                'create_time' => time(),
                'update_time' => time(),
                'open_time' => $openTime
            ]);
        }
        if (!$resInsertLog) {
            Log::channel('pay')->info('年卡赠送押金时长失败 原因：写入日志失败 userId:' . $user['user_id']);
            self::sendDingError('年卡赠送押金时长失败 原因：写入日志失败 userId:', $user['user_id']);
            return false;
        }
        $resUser = (new UserModel())->updateUserInfo($user['user_id'], ['user_deposit_expire_time' => $upTime]);
        if (!$resUser) {
            Log::channel('pay')->info('年卡赠送押金时长失败 原因：修改用户表押金时长失败 userId：' . $user['user_id']);
            self::sendDingError('年卡赠送押金时长失败 原因：修改用户表押金时长失败 userId:', $user['user_id']);
            return false;
        }
        return true;
    }


    /**
     * 验证押金用户借阅图书
     * @param int $user_id 用户ID
     * @param int|array $goods_ids 商品ID（可接受数组）
     * @param int $is_exist_card 是否存在年卡  0-不存在    1-存在
     * @return array
     * @author yangliang
     * @date 2020/12/24 20:17
     */
    public function checkDepositUser(int $user_id, $goods_ids, int $is_exist_card = 0)
    {
        //验证用户押金是否有效，若有效后面业务继续校验
        $user = (new UserModel())->where('user_id', $user_id)->find();
        //存在押金，并且押金未过期
        if ($user['deposit'] > 0 && $user['user_deposit_expire_time'] > time()) {
            $goods_ids = is_array($goods_ids) ? $goods_ids : [$goods_ids];
            $goods = GoodsModel::getByGoodsIds($goods_ids);
            if (empty($goods)) {
                return $this->error(100, '商品不存在');
            }
            foreach ($goods as $gv) {
                //押金用户不存在年卡，不能借阅京东书籍
                if ($gv['is_jd'] == 1 && $is_exist_card == 0) {
                    return $this->error(100, '押金会员不能借阅京东书籍');
                }
            }
        }

        return $this->success(200, '验证成功');
    }

    /**获取用户领取押金记录
     * @param int $userId
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/12/25 3:36 下午
     */
    public function getDepositMessage(int $userId)
    {
        $depositOutTime = (new UserModel())->getOneUser($userId, 1, 'user_deposit_expire_time');
        $depositData = (new UserDepositExpireTimeLogModel())->where('user_id', $userId)->order('id', 'DESC')->select()->toArray();
        foreach ($depositData as $k => $v) {
            $depositData[$k]['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
        }
        return $this->success(200, '获取成功', ['list' => $depositData ?? [], 'deposit_out_time' => $depositOutTime ? date('Y-m-d H:i:s', $depositOutTime) : '无']);
    }


    /**
     * 获取用户押金
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/20 12:00
     */
    public function getUserDeposit(int $user_id)
    {
        $deposit_res = DepositModel::getByUserId($user_id);
        if (empty($deposit_res)) {
            return $this->error(100, '当前用户押金不存在');
        }

        $apply_res = ApplyModel::getByUserId($user_id);

        $res = [
            'upgrade' => $deposit_res,
            'downgrade' => $apply_res,
        ];

        return $this->success(200, 'success', $res);
    }
}