<?php


namespace app\common\service;


use app\common\model\CardsModel;
use app\common\model\CouponModel;
use app\common\model\CouponTurnIncreaseModel;
use app\common\model\PhoneBillCouponModel;
use app\common\model\PhoneUserCouponModel;
use app\common\model\UserCouponModel;
use app\common\model\UserModel;
use think\facade\Db;
use think\Model;

class CouponService extends ComService
{

    /**
     * 获取优惠券
     * @param int $userId
     * @param int $status
     * @return array
     * @author yangliang
     * @date 2021/2/23 18:01
     */
    public function getList(int $userId, int $status)
    {
        // 更新未使用,未过期状态,不是永久有效,已过期优惠券
        $userCoupons = UserCouponModel::getByUserIdAndIsUsed($userId, 0);
        $usedUserCouponId = [];
        foreach ($userCoupons as $v) {
            if ($v['is_expire'] == 0 && $v['expire_time'] > 0 && time() >= $v['expire_time'] && $v['is_give_coupon'] == 0) {
                $usedUserCouponId[] = $v['id'];
            }
        }

        if (!empty($usedUserCouponId)) {
            UserCouponModel::whereIn('id', $usedUserCouponId)->update(['is_expire' => 1, 'update_time' => time()]);
        }

        $res = (new UserCouponModel())->getByUserIdAndStatus($userId, $status);
        $data = [];
        foreach ($res as $k => $v) {
            if ($v['type'] == 2 && $v['card_id']) {//如果等于1 查询年卡价格 和年卡名称
                $cardData = (new CardsModel())->field('name,price')->where('id', $v['card_id'])->findOrEmpty()->toArray();
                if ($cardData) {
                    $res[$k]['card_name'] = $cardData['name'] ?? [];
                    $res[$k]['card_price'] = $cardData['price'] ?? 0;
                }

            }
            $res[$k]['img'] = !empty($v['img']) ? $this->getGoodsImg($v['img']) : $v['img'];
            switch ($v['type']) {
                case 1:
                    $data['coupon'][] = $res[$k];
                    break;
                case 2:
                    $data['experience'][] = $res[$k];
                    break;
                case 3:
                    $data['entity'][] = $res[$k];
                    break;
                case 4:
                    $data['point'][] = $res[$k];
                    break;
            }
        }
        if (!$data) {
            return $this->error(100, '暂无数据');
        }

        return $this->success(200, '获取成功', $data);
    }

    /**给用户赠送优惠券
     * @param int $userId
     * @param int $resPhonePrepaidId
     * @param int $resPhonePrepaidId
     * @return array
     * @author Poison
     * @date 2021/6/23 2021/6/23
     */
    public function addCoupon(int $userId, int $resPhoneBillId, int $resPhonePrepaidId): array
    {
        $billCoupon = PhoneBillCouponModel::getSelectDataById($resPhoneBillId);
        if (!$billCoupon) {
            return $this->error(1001);
        }
        foreach ($billCoupon as $v) {
            $couponData = CouponModel::getDataById($v['coupon_id']);
            if (!$couponData) {
                continue;
            }
            for ($i = 0; $i < $v['num']; $i++) {
                //开始写入到用户记录中
                $res = UserCouponModel::addCoupon($userId, $couponData, $v['coupon_validity']);
                if (!$res) {
                    return errorArray('添加失败');
                }
                (new PhoneUserCouponModel())->insert([
                    'user_id' => $userId,
                    'prepaid_refill_id' => $resPhonePrepaidId,
                    'user_coupon_id' => $res,
                    'create_time' => time(),
                    'update_time' => time()
                ]);
            }
        }
        return successArray();
    }

    /**赠送用户优惠券
     * @param $params
     * @return array
     * @author Poison
     * @date 2021/7/7 1:48 下午
     */
    public function give($params)
    {
        $userId = (int)$params['user_id'];
        $userCouponId = (int)$params['user_coupon_id'];
        //查询优惠券是否可以使用
        $userCouponData = UserCouponModel::getCouponByCouponId($userCouponId);
        $userData = (new UserModel())->getOneUser($userId);
        $resCheck = self::invitedCheck($userCouponData, $userData);
        if ($resCheck['code'] != 200) {
            return $resCheck;
        }
        //开始写入数据
        Db::startTrans();//启动事务
        //过期当前优惠券
        $resUserCoupon = UserCouponModel::updateCoupon($userCouponId);
        if (!$resUserCoupon) {
            Db::rooback();
            return errorArray('数据异常 错误码 Coupon-1001');
        }
        //创建新的优惠券
        $resCreate = UserCouponModel::addUserCoupon($userId, $userCouponData);
        if (!$resCreate) {
            Db::rooback();
            return errorArray('数据异常 错误码 Coupon-1002');
        }
        //写入邀请记录
        $resTurn = CouponTurnIncreaseModel::createData(['give_user_id' => $userCouponData['user_id'], 'receive_user_id' => $userId, 'give_user_coupon_id' => $userCouponData['id'], 'receive_user_coupon_id' => $resCreate]);
        if (!$resTurn) {
            Db::rooback();
            return errorArray('数据异常 错误码 Coupon-1003');
        }
        Db::commit();
        return successArray([], '领取成功');
    }

    /**验证数据
     * @param array $userCouponData 用户优惠券数据
     * @param array $userData 用户信息
     * @return array
     * @author Poison
     * @date 2021/7/7 11:18 上午
     */
    protected function invitedCheck($userCouponData, $userData): array
    {
        if (!$userCouponData) {
            return errorArray('优惠券不存在');
        }
        if ($userCouponData['is_used'] == 1) {
            return errorArray('卡券已被使用！');
        }
        if ($userCouponData['is_del'] == 1) {
            return errorArray('优惠券已删除');
        }
        if ($userCouponData['is_give_coupon'] == 1) {
            return errorArray('卡券已被领取！');
        }
        if ($userCouponData['is_expire'] == 1) {
            return errorArray('优惠券已过期');
        }
        if ($userCouponData['is_can_give'] != 1) {
            return errorArray('该优惠券设置为不可赠送');
        }
        if (!$userData) {
            return errorArray('用户信息不存在');
        }
        if($userData['user_id'] == $userCouponData['user_id']){
            return errorArray('自己不能领取自己赠送的优惠券');
        }
        if (!$userData['mobile']) {
            return errorArray('请先注册后，领取优惠券');
        }
        return $this->success();
    }


//    /**
//     * 优惠券详情
//     * @param int $user_coupon_id  用户优惠券ID
//     * @return array
//     * @author yangliang
//     * @date 2021/2/24 9:18
//     */
//    public function getInfo(int $user_coupon_id){
//        $user_coupon = UserCouponModel::getByUserCouponId($user_coupon_id);
//        if(!empty($user_coupon)){
//            return $this->error(100, '优惠券信息不存在');
//        }
//
//        return $this->success(200, '获取成功', $user_coupon);
//    }
}