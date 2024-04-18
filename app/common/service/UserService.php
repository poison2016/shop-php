<?php


namespace app\common\service;


use app\common\ConstLib;
use app\common\model\ActivityUserModel;
use app\common\model\ApplyModel;
use app\common\model\CardsModel;
use app\common\model\ChangeIdentityModel;
use app\common\model\ClassesModel;
use app\common\model\DistributorsModel;
use app\common\model\GoodsCollectModel;
use app\common\model\GoodsModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\PickUpModel;
use app\common\model\PointsModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserCouponModel;
use app\common\model\UserModel;
use app\common\model\UserPartnerModel;
use app\common\model\WxFansInfoModel;
use app\common\traits\CacheTrait;
use app\common\traits\SmartTrait;
use app\Request;
use think\facade\Log;

class UserService extends ComService
{
    use SmartTrait;


    /**
     * 用户关联信息
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/18 17:53
     */
    public function getUserByUserId(int $user_id)
    {
        $user = (new UserModel())->getOneUser($user_id);
        if (empty($user)) {
            return $this->error(100, '该用户不存在');
        }

        $res_distributors = DistributorsModel::getByUserId($user_id);

        $user['signature'] = self::getEncode($user_id);
        $user['is_student'] = 0;
        $user['look_user'] = ConstLib::LOOK_USER;
        $user['look_user_two'] = ConstLib::LOOK_USER_TWO;
        $user['is_distributors'] = !empty($res_distributors) ? 1 : 0;  //2020-10-27 判断用户是否有实体卡商身份

        $isRenew = 0;  //是否可以续费 默认为0

        // 查询是否学生账户:身份=3,等级>0,押金>0
        if (!empty($user['mobile'])) {
            $student = UserModel::getByMobileAndIdentity($user['mobile'], 3);
            if (!empty($student && !empty($student['pickup_id']))) {
                $user['is_student'] = 1;
                $student['class_name'] = ClassesModel::getByClassId($student['pickup_id'])['class_name'];
            }
        }

        // 获取用户可用阅币
        $points = PointsModel::getByUserId($user_id);
        if (!empty($points)) {
            $points['points'] = intval($points['points']);
        }

        //获取用户已看书籍
        $user_read_book_count = OrderGoodsModel::getSumGoodsNumByUserId($user_id) ?? 0;
        // 获取用户节省金额
        $user_save_money = OrderGoodsModel::getSaveMoneyByUserId($user_id) ?? 0;
        // 获取用户借阅中书籍
        $borrow_goods = (new OrderGoodsService())->getBorrowGoods($user_id);
        $user_borrow_goods = $borrow_goods['data'];
        // 获取用户收藏
        $user_goods_collect_count = GoodsCollectModel::getCountByUserId($user_id);

        if ($user['pickup_id'] > 0) {
            $pick_up = (new PickUpModel())->getPickUpByPickUpId($user['pickup_id']);
        }

        $user['wx_qrcode'] = $this->checkWxQrcode($user);
        // Partner info 现在储值卡 人人都有 不是馆长特权 2019年12月5日 11:20:02
        $partner = (new UserPartnerModel())->getOneData($user_id);
        // 年卡
//        $user_card = UserCardsModel::getCardByUserIdAndExpire($user_id, 1);
//        if (!empty($user_card) && isset($user_card['id'])) {
//            if ($user_card['refund_card_status'] == 0 || $user_card['refund_card_status'] == 4) {
//                // 年卡信息
//                $user_card['card_info'] = CardsModel::getById($user_card['card_id']);
//                // 判断是否过期
//                if ($user_card['is_lock'] != 1 && ($user_card['end_time'] < time())) {  //2020.12.21  by yangliang  修改年卡次数用尽不过期年卡
//                    $user_card_update = UserCardsModel::where('id', $user_card['id'])->update(['is_expire' => 2, 'update_time' => time()]);
//                    if ($user_card_update) {
//                        LogService::userLog($user['user_name'], $user_id, '更新用户年卡过期,ID:' . $user_card['id'], 4);
//                    }
//
//                    $user_card = UserCardsModel::getCardByUserIdAndExpire($user_id, 1);
//                    if (!empty($user_card)) {
//                        $user_card['card_info'] = CardsModel::getById($user_card['card_id']);
//                    }
//                }
//
//                if ($user_card['is_activity'] != 1 && $user_card['card_id'] == 2) {
//                    $isRenew = 1;
//                }
//
//                //获取押金
//                $user_card_detail_data = UserCardDetailsModel::getByUserCardId((int)$user_card['id']);
//                $user_card['deposit'] = $user_card_detail_data['card_deposit'];
//                $user_card['open_time'] = $user_card_detail_data['card_open_time'];
//
//                $user_card['use_experience'] = 0;  //是否使用体验卡   0-未使用    1-已使用
//                $user_card['experience_days'] = 0;  //体验时长（天）
//                $user_card['experience_start'] = 0;  //体验开始时间
//                $user_card['experience_end'] = 0;  //体验结束时间
//
//                //获取年卡是否使用了体验卡
//                if ($user_card_detail_data['experience_id'] > 0 && $user_card_detail_data['experience_status'] == 0) {
//                    //年卡未激活
//                    if ($user_card['start_time'] == 0) {
//                        $user_card['use_experience'] = 1;
//                        $user_card['experience_days'] = $user_card_detail_data['experience_time'];
//                        $user_card['experience_start'] = '';
//                        $user_card['experience_end'] = '';
//                        $user_card['open_time'] = $user_card['open_time'] - $user_card_detail_data['experience_time'] * 86400;  //年卡时长扣除体验时长
//                    } else {  //年卡已激活
//                        //体验卡结束时间
//                        $experience_end = $user_card['start_time'] + $user_card_detail_data['experience_time'] * 86400;
//                        //验证体验卡是否过期（体验卡过期不显示）
//                        if ($experience_end > time()) {
//                            $user_card['use_experience'] = 1;
//                            $user_card['experience_days'] = $user_card_detail_data['experience_time'];
//                            $user_card['experience_start'] = date('Y-m-d', $user_card['start_time']);
//                            $user_card['experience_end'] = date('Y-m-d', $experience_end);
//                            $user_card['start_time'] = $experience_end;  //年卡开始时间扣除体验时长
//                        }
//                    }
//                }
//            } else {
//                $user_card = [];
//            }
//        }

        list($user_card, $isRenew) = (new UserCardService())->getUserCardInfo($user_id, 1, $isRenew, $user['user_name']);

        //体验卡
        list($experience_card, $experience_isRenew) = (new UserCardService())->getUserCardInfo($user_id, 2, $isRenew, $user['user_name']);

        //2021.7.17 新增体验卡过期后不返回
        if (!empty($user_card) && !empty($experience_card)) {
            $experience_card = ($experience_card['is_expire'] == 2) ? [] : $experience_card;
        }

        unset($user['unionid'], $user['openid']);
        $firstOrder = (new OrderModel())->where('user_id', $user_id)->count();

        //全局设置
        if ($this->existsCache('sysSet')) {
            $sysSet = json_decode($this->getCache('sysSet'), true);
        } else {
            $sysSet = [];
        }

        // 判断是否购买过年卡
        $isVip = UserCardsModel::where('user_id', $user_id)->count();

        $result = [
            'info' => $user ?? [],
            'student_info' => $student ?? [],
            'points' => $points ?? [],
            'readBook' => $user_read_book_count,
            'saveMoney' => $user_save_money,
            'borrowGoods' => count($user_borrow_goods),
            'goodsCollect' => $user_goods_collect_count,
            'pickup' => $pick_up ?? [],
            'partner' => $partner ?? [],
            'card' => $user_card ?? [],
            'isRenew' => $isRenew ?? 0,
            'deposit' => $user_card['is_double'] ?? 0,
            'experience_card' => $experience_card ?? [],
            'is_first_order' => $firstOrder > 0 ? 0 : 1,
            'sysSet' => $sysSet,
            'is_vip' => $isVip
        ];

        return $this->success(200, '获取成功', $result);
    }


    /**
     * Check wx qrcode exists
     * @param $user
     * @return string
     * @author yangliang
     * @date 2021/2/18 17:08
     */
    public function checkWxQrcode(array $user)
    {
        if (empty($user['wx_qrcode'])) {
            if ($user['is_rdbaby'] == 1) {
                $rdbaby_user_id = $user['user_id'];
            } else {
                $rdbaby_user_id = $user['rdbaby_user_id'] > 0 ? $user['rdbaby_user_id'] : 0;
            }

            $scene_str = ($rdbaby_user_id > 0) ? sprintf('%d-rdbaby-%d', $user['user_id'], $rdbaby_user_id) : $user['user_id'];
            $qrInfo = $this->createLimitQrcode($scene_str);
            if (!empty($qrInfo['ticket'])) {
                $qrcode_url = sprintf(config('wx_config.api.qrcode_show_url'), $qrInfo['ticket']);
                $oss_path = sprintf('public/upload/user/qrcode/%d/user_%d_qrcode.png', $user['user_id'], $user['user_id']);
                $oss_res = \OssUpload::getInstance()->upload($oss_path, file_get_contents($qrcode_url));
                if ($oss_res['status']) {
                    UserModel::where('user_id', $user['user_id'])->update(['wx_qrcode' => $oss_path]);
                    $user['wx_qrcode'] = sprintf('/%s', $oss_path);
                }
            }
        }

        return $user['wx_qrcode'];
    }


    /**
     * 保存用户的经纬度信息
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/19 9:19
     */
    public function saveUserLocation(array $data)
    {
        $res = UserModel::where('user_id', $data['user_id'])->update(['longitude' => $data['longitude'], 'latitude' => $data['latitude']]);
        if (!$res) {
            return $this->error(100, '更新失败');
        } else {
            return $this->success(200, '更新成功');
        }
    }


    /**
     * 更新个人信息
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/19 15:54
     */
    public function updateUser(int $user_id, array $data)
    {
        // 获取用户个人信息
        $user_info = (new UserModel())->getOneUser($user_id);
        if (empty($user_info)) {
            return $this->error(100, '该用户不存在');
        }

        $data['user_name'] = replaceChar($data['user_name'], 1); // 过滤空格回车特殊字符
        $data['update_time'] = time();
        $res = UserModel::where('user_id', $user_id)->update($data);
        if (!$res) {
            return $this->error(100, '修改失败');
        }

        return $this->success(200, '更新成功', $res);
    }


    /**
     * 根据用户经纬度，获取最近的配送点、代收点信息
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/23 14:01
     */
    public function getNearPickUp(int $user_id, array $data)
    {
        $user_info = (new UserModel())->getOneUser($user_id);
        if (empty($user_info)) {
            return $this->error(100, '用户未找到');
        }

        // 用户的经纬度信息，不全
        if (!$user_info['longitude'] || !$user_info['latitude']) {
            return $this->error(100, '用户位置信息不全');
        }

        // 开始查找，附近配送点
        $res = PickUpModel::getNearPickUp($user_info['longitude'], $user_info['latitude'], $data);
        return $this->success(200, '获取成功', $res);
    }


    /**
     * 根据传过来的经纬度，获取最近的配送点、代收点信息
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/23 14:09
     */
    public function getNearPickUpByOption(array $data)
    {
        $res = PickUpModel::getNearPickUp($data['longitude'], $data['latitude']);
        return $this->success(200, '获取成功', $res);
    }


    /**
     * 获取对应学生账户信息
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/23 14:51
     */
    public function getStudentInfo(int $user_id)
    {
        $user_info = (new UserModel())->getOneUser($user_id);
        if (empty($user_id)) {
            return $this->error(100, '用户登录信息已过期');
        }

        // 查询是否学生账户:身份=3,等级>0,押金>0
        $student_info = UserModel::getByMobileAndIdentity($user_info['mobile'], 3);
        if ($student_info && $student_info['pickup_id']) {
            $student_info['class_name'] = ClassesModel::getByClassId($student_info['pickup_id'])['class_name'];
        } else {
            return $this->error(100, '此账户对应学生账户不存在');
        }

        // 申请转个人信息
        $change_identity = ChangeIdentityModel::getByUserIdAndStudentId($user_id, $student_info['user_id']);
        $student_info['change_info'] = [];
        if (!empty($change_identity)) {
            $change_identity['status_text'] = ChangeIdentityModel::$statusMap[$change_identity['status']];
            $student_info['change_info'] = $change_identity;
        }

        // 已借阅书籍(对应班级账号订单书籍)
        $student_info['order_goods'] = [];
        $class_user_id = UserModel::getByPickupIdAndIdentity($student_info['pickup_id'], 2);
        if (!empty($class_user_id['user_id'])) {
            $order_goods = OrderGoodsModel::getGroupGoodsIdByUserId($user_id);
            foreach ($order_goods as $k => $og) {
                $goods_info = GoodsModel::getByGoodsId($og['goods_id']);
                $og['original_img'] = $goods_info['original_img'];
                $order_goods[$k] = (new GoodsService())->getGoodsAttrs($og, $user_id);
                $order_goods[$k]['price'] = $goods_info['price'];
            }
            $student_info['order_goods'] = $order_goods;
        }

        return $this->success(200, 'success', $student_info);
    }


    /**
     * 申请班级转个人
     * @param int $user_id
     * @return array
     * @author yangliang
     * @date 2021/2/23 15:20
     */
    public function changeStudent(int $user_id)
    {
        $user_info = (new UserModel())->getOneUser($user_id);
        if (empty($user_id)) {
            return $this->error(100, '用户登录信息已过期');
        }

        // 查询是否学生账户:身份=3,等级>0,押金>0
        $student = UserModel::getByMobileAndIdentityAndGradeAndDeposit($user_info['mobile'], 3, 0, 0);
        if (empty($student)) {
            return $this->error(100, '此账户对应学生账户不存在');
        }

        if ($student['grade'] == 0 || $student['deposit'] == 0) {
            return $this->error(100, '对应学生账户无押金');
        }

        $change_identit_count = ChangeIdentityModel::getCountByUserIdAndStudentIdAndStatus($user_id, $student['user_id'], 0);
        if ($change_identit_count > 0) {
            return $this->error(100, '您已经申请过，请勿重复申请');
        }

        // 判断升级登记是否大于最高等级(钻卡等级)
        if ($user_info['grade'] == 4 || $student['grade'] + $user_info['grade'] > 4) {
            return $this->error(100, '转化后个人账户等级已超出最高等级,请联系客服进行学生账户退卡');
        }

        // 判断是否有未完成审批退卡降级流程
        $apply_count = ApplyModel::getCountByUserIdAndStatus($user_id, [0, 1, 2, 3, 4, 5]);
        if ($apply_count > 0) {
            return $this->error(100, '您有未完成审批的退卡降级流程,请耐心等待客服人员处理,如有疑问请咨询:' . ConstLib::SERVICE_PHONE);
        }

        $data = [
            'user_id' => $user_id,
            'student_id' => $student['user_id'],
            'current_level' => $user_info['grade'],
            'after_level' => $user_info['grade'] + $student['grade'],
            'create_time' => time(),
            'update_time' => time(),
        ];

        $res = ChangeIdentityModel::create($data);
        if (!$res) {
            return $this->error(100, '申请失败');
        }

        return $this->success(200, '申请成功');
    }


    /**
     * 获取挽留用户信息
     * @param int $user_id
     * @return array
     * @author yangliang
     * @date 2021/2/23 15:31
     */
    public function detain(int $user_id)
    {
        $user_info = (new UserModel())->getOneUser($user_id);
        if (empty($user_id)) {
            return $this->error(100, '用户登录信息已过期');
        }

        $reg_time = (new UserModel())->getOneUser($user_id, 1, 'reg_time');
        $register_date_arr = explode('-', date('Y-m-d', $reg_time));
        $res['register_info'] = [
            'year' => $register_date_arr[0],
            'month' => $register_date_arr[1],
            'day' => $register_date_arr[2],
        ];

        $res['read_book'] = OrderGoodsModel::getSumGoodsNumByUserId($user_id) ?? 0;
        $res['save_money'] = OrderGoodsModel::getSaveMoneyByUserId($user_id) ?? 0;
        $user_info = (new UserModel())->getOneUser($user_id);
        $res['activity_count'] = ActivityUserModel::getCountByUserIdOrOpenId($user_id, $user_info['openid'] ?? '');
        $res['days'] = ceil((strtotime(date('Y-m-d')) - $reg_time) / 86400);

        return $this->success(200, 'success', $res);
    }

    /**
     * 同步公众号与小程序的数据
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author liyongsheng
     * @date 2021/3/12 14:55
     */
    public function getUserUnionidFromWxFans()
    {
        // 先到小程序，这时候，绝对会有unionid
        // 先到公众号，这时候，可能会出现没有小程序账号信息
        Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，用户信息情况__执行开始==>');
        $list = WxFansInfoModel::getListNoUnionid();
        foreach ($list as $item) {
            $userInfo = $this->getUserInfoByopenId($item['openid']);
            Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，用户信息情况==>' . json_encode($userInfo, JSON_UNESCAPED_UNICODE));
            if ($userInfo) {
                $unionid = (isset($userInfo['unionid']) && $userInfo['unionid'] != '') ? $userInfo['unionid'] : '';
                if ($unionid != '') {
                    $info = UserModel::where('unionid', $unionid)->find();
                    Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，用户信息情况==>' . json_encode($info, JSON_UNESCAPED_UNICODE));
                    if ($info) {
                        // 有没有都进行，强制更新
                        $info->openid = $item['openid'];
                        $flg = $info->save();

                        if ($flg) {
                            // 更新fans表的信息
                            $fan = WxFansInfoModel::find($item['id']);
                            $fan->unionid = $unionid;
                            $fan->save();
                        }

                        // 删除其它用户open_id相同的，做到逐步清洗用户数据的目的
                        UserModel::where('openid', $item['openid'])
                            ->where('user_id', '<>', $info['user_id'])
                            ->data(['openid' => ''])
                            ->update();
                    }
                }
            }
        }
        Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，用户信息情况==>执行结束' . PHP_EOL);
    }


    /**
     * 根据openid，获取用户的unionid
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author liyongsheng
     * @date 2021/3/25 17:09
     */
    public function getUserUnionid()
    {
        Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，用户信息情况__执行开始==>');
        $list = WxFansInfoModel::getListNoUnionid();
        Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，执行数据长度为：' . count($list));
        foreach ($list as $key => $item) {
            $userInfo = $this->getUserInfoByopenId($item['openid']);
            Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，用户信息情况==>' . json_encode($userInfo, JSON_UNESCAPED_UNICODE));
            if (isset($userInfo['subscribe']) && $userInfo['subscribe'] == 1) {
                $unionid = (isset($userInfo['unionid']) && $userInfo['unionid'] != '') ? $userInfo['unionid'] : '';
                if ($unionid != '') {
                    WxFansInfoModel::where('id', $item['id'])->update([
                        'unionid' => $unionid,
                        'nickname' => (isset($userInfo['nickname']) && $userInfo['nickname'] != '') ? $userInfo['nickname'] : '',
                        'sex' => (isset($userInfo['sex']) && $userInfo['sex'] != '') ? $userInfo['sex'] : '',
                        'language' => (isset($userInfo['language']) && $userInfo['language'] != '') ? $userInfo['language'] : '',
                        'country' => (isset($userInfo['country']) && $userInfo['country'] != '') ? $userInfo['country'] : '',
                        'province' => (isset($userInfo['province']) && $userInfo['province'] != '') ? $userInfo['province'] : '',
                        'city' => (isset($userInfo['city']) && $userInfo['city'] != '') ? $userInfo['city'] : '',
                        'headimgurl' => (isset($userInfo['headimgurl']) && $userInfo['headimgurl'] != '') ? $userInfo['headimgurl'] : '',
                    ]);
                }
            } else {
                // 用户已经取消关注了，做取消关注的操作
                (new WxDealService())->unsubscribe([
                    'FromUserName' => $item['openid']
                ]);
            }
            dump($key . '/' . count($list));
        }
        Log::channel('queue')->info(date('Y-m-d H:i:s', time()) . '，用户信息情况==>执行结束' . PHP_EOL);
    }

    /**
     * 更新微信信息
     * @author liyongsheng
     * @date 2021/4/26 14:50
     */
    public function updateWxFansInfo()
    {
        $list = WxFansInfoModel::getListByNickname();
        foreach ($list as $key => $item) {
            $userInfo = $this->getUserInfoByopenId($item['openid']);
            WxFansInfoModel::where('id', $item['id'])->update([
                'unionid' => (isset($userInfo['unionid']) && $userInfo['unionid'] != '') ? $userInfo['unionid'] : '',
                'nickname' => (isset($userInfo['nickname']) && $userInfo['nickname'] != '') ? $userInfo['nickname'] : '',
                'sex' => (isset($userInfo['sex']) && $userInfo['sex'] != '') ? $userInfo['sex'] : '',
                'language' => (isset($userInfo['language']) && $userInfo['language'] != '') ? $userInfo['language'] : '',
                'country' => (isset($userInfo['country']) && $userInfo['country'] != '') ? $userInfo['country'] : '',
                'province' => (isset($userInfo['province']) && $userInfo['province'] != '') ? $userInfo['province'] : '',
                'city' => (isset($userInfo['city']) && $userInfo['city'] != '') ? $userInfo['city'] : '',
                'headimgurl' => (isset($userInfo['headimgurl']) && $userInfo['headimgurl'] != '') ? $userInfo['headimgurl'] : '',
            ]);
            dump($key . '/' . count($list));
        }
    }

    /**修改用户表中 门店信息
     * @param $param
     * @return array
     * @author Poison
     * @date 2021/5/13 2:51 下午
     */
    public function upStoreId($param)
    {
        $storeId = (int)$param['store_id'];
        $res = (new UserModel())->updateUserInfo($param['user_id'], ['store_id' => $storeId, 'update_time' => time()]);
        if (!$res) {
            return $this->error(100, '修改门店信息失败');
        }
        return successArray(['store_id' => $storeId]);
    }
}