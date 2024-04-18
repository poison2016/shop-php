<?php


namespace app\common\service;


use app\common\ConstLib;
use app\common\model\BusinessUserModel;
use app\common\model\LotteryInvitationModel;
use app\common\model\LotteryModel;
use app\common\model\OrderModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\model\UserPartnerModel;
use app\common\Tools\ALiYunSendSms;
use app\common\traits\SmartTrait;
use think\facade\Db;
use think\facade\Env;
use think\Model;
use wechat\DataCrypt;

class LoginService extends ComService
{
    use SmartTrait;

    /**
     * 发送手机验证码
     * @param int $user_id 用户ID
     * @param string $mobile 手机号码
     * @return array
     * @author yangliang
     * @date 2021/2/19 9:59
     */
    public function sendMobileMessage(int $user_id, string $mobile)
    {
        if (Env::get('server_env') == 'dev') {
            return $this->success(200, '不用验证', ['status' => 0, 'verify' => '不用验证']);
        }

        if (intval($user_id) < 1) {
            return $this->error(100, '用户不存在');
        }

        if (empty($mobile)) {
            return $this->error(100, 'mobile不能为空');
        }

        if (strlen($mobile) != 11) {
            return $this->error(100, '请输入正确手机号码');
        }

        $code = $this->getCache($mobile);

        if (!empty($code)) {
            return $this->success(200, '验证码有效期为5分钟', ['status' => 0, 'verify' => $code]);
        } else {
            $code = rand(1000, 9999);
//            $content = sprintf('温馨提醒：您的验证码是%s，请勿泄露，全国唯一咨询电话：%s，爱阅读，爱睿鼎！', $code, ConstLib::SERVICE_PHONE);
            $res = (new ALiYunSendSms())->sendSms($mobile, config('ali_config.send_msg.template_code'), ['code' => $code]);
            if ($res['code'] == 200) {
                $this->setCache($mobile, $code, 300);
                return $this->success(200, '验证码已发送', ['status' => 1]);
            } else {
                return $this->error(100, $res['msg']);
            }
        }
    }


    /**
     * 验证手机验证码
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/19 10:43
     */
    public function verifyMobile(array $data)
    {
        if (Env::get('server_env') == 'dev') {
            return $this->success(200, '不用验证', ['status' => 0, 'verify' => '不用验证']);
        }

        if (empty($data['mobile'])) {
            return $this->error(100, 'mobile不能为空');
        }

        if (empty($data['code'])) {
            return $this->error(100, 'code不能为空');
        }

        $verify_code = $this->getCache($data['mobile']);
        if (empty($verify_code)) {
            return $this->error(100, '验证码已过期');
        }

        if ($verify_code != $data['code']) {
            return $this->error(100, '验证码填写错误');
        }

        return $this->success(200, '验证成功');
    }


    /**
     * 绑定手机号码
     * @param string $mobile
     * @param int $user_id
     * @param int $status
     * @param string $session_key
     * @return array
     * @author yangliang
     * @date 2021/2/19 14:16
     */
    public function bindMobile(string $mobile, int $user_id, int $status = 0, string $session_key = '',$isRegister = 0)
    {
        if (empty($mobile)) {
            return $this->error(100, 'mobile不能为空');
        }

        if (strlen($mobile) != 11) {
            return $this->error(100, '请输入正确手机号码');
        }

        Db::startTrans();

        try {
            $user_info = UserModel::getByMobileAndIdentity($mobile, 1);
            $current_user = (new UserModel())->getOneUser($user_id);
            // 判断手机号对应用户账户是否存在
            if (!empty($user_info)) {
                // 如果openid存在,则判断用户openid是否重复
                if (!empty($user_info['openid'])) {
                    $other_user_ids = UserModel::getUserIdByOpenIdAndNotUserId($user_info['openid'], $user_info['user_id']);
                    // 如果openid重复,则重置除了手机号对应用户账户以外所有账号openid，unionid，smart_openid为空
                    if (count($other_user_ids) > 0) {
                        foreach ($other_user_ids as $v) {
                            $other_user_res = UserModel::where('user_id', $v['user_id'])->update(['openid' => '', 'unionid' => '', 'smart_openid' => '']);
                            if (!$other_user_res) {
                                throw new \Exception('用户信息重置失败');
                            }

                            // 记录用户信息更新日志
                            $actNote = '绑定手机重置openid,unionid,smart_openid为空';
                            if ($status == 1) {
                                $actNote = '绑定手机(微信绑定手机号码)重置openid,unionid,smart_openid为空';
                            }

                            LogService::userLog($v['user_name'], $v['user_id'], $actNote);
                        }
                    }
                }

                // 1.绑定手机账号对应unionid存在并且和当前登录账户unionid不同,清空openid(公众号)
                // 2.绑定手机账号对应unionid不存在,清空openid(公众号)
                if ((isset($user_info['unionid']) && $user_info['unionid'] != $current_user['unionid']) || !$user_info['unionid']) {
                    $user_data['openid'] = '';
                }

                $user_data['smart_openid'] = $current_user['smart_openid'];
                $user_data['unionid'] = $current_user['unionid'];
                $user_data['update_time'] = time();

                $user_update_res = UserModel::where('user_id', $user_info['user_id'])->update($user_data);
                if (!$user_update_res) {
                    throw new \Exception('用户信息更新失败');
                }

                // 记录用户信息更新日志
                $actNote = '绑定手机更新unionid,smart_openid';
                if ($status == 1) {
                    $actNote = '绑定手机(微信绑定手机号码)更新unionid,smart_openid';
                }

                LogService::userLog($user_info['user_name'], $user_info['user_id'], $actNote);

                // 删除当前登录用户信息
                if ($user_info['user_id'] != $user_id) {
                    if ($current_user['deposit'] > 0) {
                        // 更新当前用户信息
                        $current_user_update_res = UserModel::where('user_id', $user_id)->update(['openid' => '', 'unionid' => '', 'smart_openid' => '', 'update_time' => time()]);
                        if (!$current_user_update_res) {
                            throw new \Exception('用户信息更新失败');
                        }

                        // 记录用户信息更新日志
                        $actNote = '绑定手机重置openid,unionid,smart_openid为空';
                        if ($status == 1) {
                            $actNote = '绑定手机(微信绑定手机号码)重置openid,unionid,smart_openid为空';
                        }

                        LogService::userLog($current_user['user_name'], $user_id, $actNote);
                    } else {
                        // 判断是否有订单信息,如果没有订单信息则删除用户信息
                        $order_count = (new OrderModel())->getCount($user_id);
                        // 2020年7月27日 15:45:36 判断有没有年卡，有年卡也允许删除
                        $user_card_info = UserCardsModel::getByUserId($user_id);
                        $business_info = BusinessUserModel::getOneBusinessUserData(['user_id'=>$user_id]);
                        if ($order_count == 0 && !$user_card_info && !$business_info) {
                            // 删除当前用户信息
                            $user_del = UserModel::where('user_id', $user_id)->delete();
                            if (!$user_del) {
                                throw new \Exception('用户信息删除失败');
                            }

                            // 记录用户信息删除日志
                            $actNote = '绑定手机删除当前用户信息,current_user_id:' . $user_id . ',user_id:' . $user_info['user_id'];
                            if ($status == 1) {
                                $actNote = '绑定手机(微信绑定手机号码)删除当前用户信息,current_user_id:' . $user_id . ',user_id:' . $user_info['user_id'];
                            }

                            LogService::userLog($current_user['user_name'], $user_id, $actNote);

                            // 删除储值卡信息
                            $user_partner = (new UserPartnerModel())->getOneData($user_id);
                            if(!empty($user_partner)) {
                                $user_partner_del = UserPartnerModel::where('user_id', $user_id)->delete();
                                if (!$user_partner_del) {
                                    throw new \Exception('用户储值卡信息删除失败');
                                }
                            }
                        }
                    }
                }


                // 如果当前unionid存在并且新创建账户未删除成功,则判断unionid重复或者smart_openid重复
                // 则重置除了手机号对应用户账户以外所有账号openid，unionid，smart_openid为空
                if ($current_user['unionid'] || $current_user['smart_openid']) {
                    // unionid重复或者smart_openid重复
                    $other_unionid_user_ids = UserModel::getUserIdByUnionidOrSmartOpenidAndNotUserId($user_info['user_id'], $current_user['smart_openid'], $current_user['unionid']);
                    if (count($other_unionid_user_ids) > 0) {
                        foreach ($other_unionid_user_ids as $uv) {
                            $other_unionid_user_res = UserModel::where('user_id', $uv['user_id'])->update(['openid' => '', 'unionid' => '', 'smart_openid' => '', 'update_time' => time()]);
                            if (!$other_unionid_user_res) {
                                throw new \Exception('用户信息重置失败---');
                            }

                            // 记录用户信息更新日志
                            $actNote = '绑定手机unionid重复重置openid,unionid,smart_openid为空';
                            if ($status == 1) {
                                $actNote = '绑定手机(微信绑定手机号码)unionid重复重置openid,unionid,smart_openid为空';
                            }

                            LogService::userLog($uv['user_name'], $uv['user_id'], $actNote);
                        }
                    }
                }

                $response = $this->getToken($user_info['user_id'], $session_key);
            } else {
                $user_update_res = UserModel::where('user_id', $user_id)->update(['mobile' => $mobile, 'update_time' => time()]);
                if (!$user_update_res) {
                    throw new \Exception('用户信息更新失败');
                }

                // 记录用户信息更新日志
                $actNote = '绑定手机更新mobile';
                if ($status == 1) {
                    $actNote = '绑定手机(微信绑定手机号码)更新mobile';
                }

                LogService::userLog($current_user['user_name'], $user_id, $actNote);

                $response = $this->getToken($user_id, $session_key);
            }
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }
        if($isRegister){
           (new UserModel())->updateUserInfo($user_id, ['is_give_coupon_register' => 1, 'update_time' => time()]);
        }
        //查询活动id
        $lotteryId = (new LotteryInvitationModel())->where('Invited_user_id',$user_id)->order('create_time','DESC')->value('lottery_id');
        if($lotteryId){
            (new LotteryService())->inviteOk(['lottery_id'=>$lotteryId],$user_id);
        }
        //阅币积分赠送
        (new PointsService())->addBindPoints($user_id);
        Db::commit();
        return $this->success(200, '绑定成功', $response);
    }


    /**
     * 获取token值
     * @param int $user_id
     * @param string $sessionKey
     * @return array
     * @author yangliang
     * @date 2021/2/19 14:11
     */
    public function getToken(int $user_id, string $sessionKey = '')
    {
        $smart_openid = (new UserModel())->getOneUser($user_id, 1, 'smart_openid');
        $token = (new \Jwt())->jwtEncode(['user_id' => $user_id, 'smart_openid' => $smart_openid, 'session_key' => $sessionKey]);

        return ['token' => $token];
    }


    /**
     * 切换手机号
     * @param int $user_id
     * @param string $mobile
     * @param string $sessionKey
     * @return array
     * @author yangliang
     * @date 2021/2/19 15:22
     */
    public function switchMobile(int $user_id, string $mobile, string $sessionKey = '')
    {
        $current_user = (new UserModel())->getOneUser($user_id);
        if (empty($current_user)) {
            return $this->error(100, '切换用户不存在');
        }

        if (strlen($mobile) != 11) {
            return $this->error(100, '请输入正确手机号码');
        }

        if ($mobile == $current_user['mobile']) {
            return $this->success(200, '账号切换成功');
        }

        $new_user = UserModel::getByMobileAndIdentity($mobile, 1);
        if (empty($new_user)) {
            return $this->error(100, '请输入正确手机号码');
        }

        if ($new_user['identity'] != 1 || $new_user['is_lock'] == 1) {
            return $this->error(100, '请输入正确手机号码');
        }

        Db::startTrans();
        try {
            $new_user_data['openid'] = $current_user['openid'];
            // 1.切换手机账号对应unionid存在并且和当前登录账户unionid不同,清空openid(公众号)
            // 2.切换手机账号对应unionid不存在,清空openid(公众号)
            if (($new_user['unionid'] && $new_user['unionid'] != $current_user['unionid']) || !$new_user['unionid']) {
                $new_user_data['openid'] = '';
            }

            // 更新切换用户信息
            $new_user_data['unionid'] = $current_user['unionid'];
            $new_user_data['smart_openid'] = $current_user['smart_openid'];
            $new_user_res = UserModel::where('user_id', $new_user['user_id'])->update($new_user_data);
            if (!$new_user_res) {
                throw new \Exception('新用户信息更新失败,切换失败');
            }

            // 记录用户信息更新日志
            LogService::userLog($new_user['user_name'], $new_user['user_id'], '切换手机更新用户信息openid,unionid,smart_id');

            // 更新当前用户信息
            $user_res = UserModel::where('user_id', $user_id)->update(['openid' => '', 'unionid' => '', 'smart_openid' => '']);
            if (!$user_res) {
                throw new \Exception('用户信息更新失败,切换失败');
            }

            // 记录用户信息更新日志
            LogService::userLog($current_user['user_name'], $user_id, '切换手机重置用户信息openid,unionid,smart_id为空');

            $response = $this->getToken($user_id, $sessionKey);
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '账号切换成功', $response);
    }


    /**
     * 授权
     * @param int $user_id
     * @param string $session_key
     * @param string $encryptedData
     * @param string $iv
     * @return array
     * @author yangliang
     * @date 2021/2/19 15:41
     */
    public function handleAuth(int $user_id, string $session_key, string $encryptedData = '', string $iv = '',$is_register = 0)
    {
        if (!empty($encryptedData)) {
            if (!$iv) {
                return $this->error(100, 'iv不能为空');
            }

            $res = (new DataCrypt($session_key))->decryptData($encryptedData, $iv, $wx_user_info);
            if($res['code'] != 200){
                return $this->error(100, $res['message']);
            }

            $wx_user_info = json_decode($wx_user_info, true);

            if ($wx_user_info['phoneNumber']) {
                $res = $this->bindMobile($wx_user_info['phoneNumber'], $user_id, 1, $session_key,$is_register);
                if ($res['code'] != 200) {
                    return $this->error(100, $res['message']);
                }

                $response = $res['data'];
            } else {
                $response = $this->getToken($user_id, $session_key);
            }
        } else {
            $response = $this->getToken($user_id, $session_key);
        }
        //查询活动id
        $lotteryId = (new LotteryInvitationModel())->where('Invited_user_id',$user_id)->order('create_time','DESC')->value('lottery_id');
        if($lotteryId){
            (new LotteryService())->inviteOk(['lottery_id'=>$lotteryId],$user_id);
        }
        //阅币积分赠送
        (new PointsService())->addBindPoints($user_id);
        return $this->success(200, '绑定成功', $response);
    }

    /**登录
     * @param $data
     * @return array
     * @author Poison
     * @date 2021/2/20 10:29 上午
     */
    public function handleLogin($data)
    {
        $wxCode = $data['code'];
        try {
            //通过code获取unionid
            $authResponse = $this->handleAuthWx($wxCode);
            $smartUserInfo = json_decode($authResponse, true);
            if (isset($smartUserInfo['errcode'])) {
                return self::error(10160, $smartUserInfo['errmsg']);
            }
            $isNew = 0;// 是否新用户:0否1是
            $smartUnionId = isset($smartUserInfo['unionid']) ? $smartUserInfo['unionid'] : '';
            $smartOpenId = $smartUserInfo['openid'];
            // 判断unionid是否存在:如果存在说明已关注公众号,如果不存在说明未关注公众号
            if ($smartUnionId) {
                //判断通过unionid是否存在：如果存在说明已关注公众号，如果不存在说明未关注公众号
                $userInfo = (new UserModel())->where(['unionid' => $smartUnionId, 'identity' => 1])->findOrEmpty()->toArray();
                if ($userInfo) {
                    //老用户（已关注公众号已注册用户）
                    if ($userInfo['is_lock'] == 1) {
                        return self::error(100, '此用户账号已被锁定,如有疑问请咨询:' . config('self_config.service_phone'));
                    }
                    $userId = (int)$userInfo['user_id'];
                    //更新smart_openid
                    if (empty($userInfo['smart_openid']) && !empty($smartOpenId)) {
                        $resUserUpdate = (new UserModel())->where('user_id', $userId)->update(['smart_openid' => $smartOpenId, 'update_time' => time()]);
                        if (!$resUserUpdate) {
                            return self::error(100, '用户账号smart_openid更新失败');
                        }
                        LogService::userLog($userInfo['user_name'], $userId, '登录更新用户账号smart_openid');
                    }
                } else {
                    // 判断通过smart_openid是否可以查询到此用户信息
                    $userInfo = (new UserModel())->where(['smart_openid' => $smartOpenId, 'identity' => 1])->findOrEmpty()->toArray();
                    if($userInfo){
                        // 老用户(已关注公众号已注册用户)
                        $userId = (int)$userInfo['user_id'];
                        //更新unionid
                       // if (empty($userInfo['unionid'])) {
                            $resUserUpdate = (new UserModel())->where('user_id', $userId)->update(['unionid' => $smartUnionId, 'update_time' => time()]);
                            if (!$resUserUpdate) {
                                return self::error(100, '用户账户账号unionid更新失败');
                            }
                            LogService::userLog($userInfo['user_name'], $userId, '登录更新用户账号unionid');
                       // }
                    }else{
                        // 新用户(已关注公众号未注册用户)
                        $userData['unionid'] = $smartUnionId;
                        $userData['smart_openid'] = $smartOpenId;
                        $userId = (new UserModel())->createUserData($userData);
                        $userName = (new UserModel())->getOneUser($userId,1,'user_name');
                        LogService::userLog($userName, $userId, '登录创建用户账号B');
                        $isNew = 1;
                    }
                }
            }else{
                // 判断通过smart_openid是否可以查询到此用户信息
                $userInfo = (new UserModel())->where(['smart_openid' => $smartOpenId, 'identity' => 1])->findOrEmpty()->toArray();
                if ($userInfo) {
                    // 老用户(未关注公众号已在小程序注册用户)
                    $userId = $userInfo['user_id'];
                } else {
                    $userId = (new UserModel())->createUserData(['smart_openid' => $smartOpenId]);
                    $userName = (new UserModel())->getOneUser($userId,1,'user_name');
                    LogService::userLog($userName, $userId, '登录创建用户账号A');
                    $isNew = 1;
                }
            }
            if (!$userId) {
                return $this->error(0, '账号创建失败');
            }
            // 新注册用户创建储值卡
            if ($isNew == 1) {
                (new UserPartnerModel())->createPartnerData(['user_id'=>$userId]);
            }
            $response = self::getToken($userId,$smartUserInfo['session_key']);
            return self::success(200,'获取成功',$response);
        } catch (\Exception $e) {
            $this->sendDingError('用户登陆异常',$e);
            return self::error(100,$e->getMessage());
        }
    }
}