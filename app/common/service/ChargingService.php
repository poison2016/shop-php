<?php


namespace app\common\service;


use app\common\model\CouponModel;
use app\common\model\PhoneBillCouponModel;
use app\common\model\PhoneBillModel;
use app\common\model\PhoneBusinessModel;
use app\common\model\PhoneBusinessUserModel;
use app\common\model\PhonePrepaidRefillLogModel;
use app\common\model\PhonePrepaidRefillModel;
use app\common\traits\CurlTrait;
use app\common\traits\SmartTrait;
use think\facade\Db;
use think\Model;
use wechat\Pay;

class ChargingService extends ComService
{
    use CurlTrait;

    public function pay(array $params): array
    {
        $resCheckCode = self::checkCode($params['mobile'], self::returnCode($params['operators']));
        if ($resCheckCode['code'] != 200) {
            return $resCheckCode;
        }
        $userId = (int)$params['user_id'];
        $orderNo = ToolsService::createChargingPay();//生成订单号
        $resPhoneBill = (new PhoneBillModel())->getPhoneById((int)$params['bill_id']);
        if (!$resPhoneBill) {
            return errorArray('参数设定异常');
        }
        $payMoney = sprintf("%.2f", $resPhoneBill['money'] * $resPhoneBill['discount']);
        $flowPackageCode = self::getCode($params['operators'], $resPhoneBill);
        //查询用户当前渠道
        $busUserData = PhoneBusinessUserModel::getBusUser($userId);
        $busId = 1;
        if ($busUserData) {
            $busId = $busUserData['phone_business_id'];
        }
        $prepaidRefillData = [
            'user_id' => $userId,
            'order_no' => $orderNo,
            'money' => $payMoney,
            'bill_id' => $params['bill_id'],
            'phone' => $params['mobile'],
            'flow_package_code' => $flowPackageCode,
            'operators' => $params['operators'],
            'phone_business_id' => $busId,
        ];
        $res = (new PhonePrepaidRefillModel())->createPhoneData($prepaidRefillData);
        if (!$res) {
            return errorArray('网络异常,发起支付失败');
        }
        //写入日志
        PhonePrepaidRefillLogModel::setLog($userId, $res, sprintf('用户ID:%s 发起话费充值，充值金额 %s,支付金额 %s', $userId, $resPhoneBill['money'], $payMoney));
        $payData['body'] = '睿鼎少儿话费慢充(' . $resPhoneBill['money'] . '元)';
        $payData['attach'] = $params['type'];
        $payData['openid'] = $params['openid'];
        $payData['trade_type'] = 'JSAPI';
        $payData['out_trade_no'] = $orderNo;
        $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $payMoney * 100;
        $pay = Pay::getInstance();
        $result = $pay->unifiedorder($payData);
        if (!$result) {
            return $this->error(0, $result['message']);
        }
        return $this->success(200, '操作成功', json_encode($result));
    }

    /**验证手机号
     * @param $phone
     * @param $operators
     * @return array
     * @author Poison
     * @date 2021/6/23 2021/6/23
     */
    private function checkCode($phone, $operators)
    {
        return errorArray('充值活动已结束。更多活动信息，请关注睿鼎少儿公众号。');

        // try {
        //     if (!self::show360($phone, $operators)) {
        //         if (!self::iteblog($phone, $operators)) {
        //             return errorArray('手机号所属运营商检测错误，请重新选择运营商');
        //         }
        //     }
        // } catch (\Exception $ex) {
        //     return $this->success();
        // }
        // return $this->success();
    }

    /**360接口
     * @param $phone
     * @param $operators
     * @return bool
     * @author Poison
     * @date 2021/6/23 2021/6/23
     */
    private function show360($phone, $operators)
    {
        $url = 'https://cx.shouji.360.cn/phonearea.php?number=' . $phone;
        $resData = json_decode(file_get_contents($url), TRUE);
        if (!$resData) {
            return false;
        }
        if ($resData['code'] != 0) {
            return false;
        }
        $operator = $resData['data']['sp'];
        if (!strpos('中国' . $operator, $operators)) {
            return false;
        }
        return true;
    }

    /**ITEBLOG接口
     * @param $phone
     * @param $operators
     * @return bool
     * @author Poison
     * @date 2021/6/23 2021/6/23
     */
    private function iteblog($phone, $operators)
    {
        $url = 'https://www.iteblog.com/api/mobile.php?mobile=' . $phone;
        $resData = json_decode(file_get_contents($url), TRUE);
        if (!$resData) {
            return false;
        }
        if (!strpos('中国' . $resData['operator'], $operators)) {
            return false;
        }
        return true;
    }

    /**查询数据
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function info()
    {
        $data = (new PhoneBillModel())->getSelectData();
        foreach ($data as &$v) {
            $v['pay_money'] = sprintf("%.2f", $v['money'] * $v['discount']);
            $v['coupon_list'] = PhoneBillCouponModel::getSelectDataById($v['id']);
            foreach ($v['coupon_list'] as $key => $vue) {
                $v['coupon_list'][$key]['coupon_info'] = (new CouponModel())->getDataById($vue['coupon_id']);
            }
        }
        return successArray($data);
    }

    /**获取用户充值列表
     * @param array $prams
     * @return array
     * @author Poison
     * @date 2021/6/23 2021/6/23
     */
    public function getList(array $prams): array
    {
        $userId = (int)$prams['user_id'];
        return successArray((new PhonePrepaidRefillModel())->getSelectData($userId));
    }

    /**支付回调
     * @param string $orderNo
     * @return bool
     */
    public function callBack(string $orderNo)
    {
        $resPhonePrepaid = (new PhonePrepaidRefillModel())->where('order_no', $orderNo)->findOrEmpty()->toArray();
        if (!$resPhonePrepaid) {
            return false;
        }
        if ($resPhonePrepaid['status'] != 0) {
            return true;
        }
        //$billData = (new PhoneBillModel())->where('id',$resPhonePrepaid['bill_id'])->findOrEmpty()->toArray();
        Db::startTrans();
        try {
            $userId = (int)$resPhonePrepaid['user_id'];
            $resCoupon = (new CouponService())->addCoupon($userId, $resPhonePrepaid['bill_id'], $resPhonePrepaid['id']);
            if ($resCoupon['code'] == 100) {
                self::sendDingError(sprintf('用户ID：%s 充值话费 赠送优惠券失败', $userId), '');
                Db::rollback();
            }
            $auth = self::auth();//签名
            $res = $this->curl_request(config('self_config.charging_host') . config('self_config.charging_pay_address') .
                '?auth=' . $auth . '&orderNo=' . $orderNo . '&tel=' . $resPhonePrepaid['phone'] . '&flowPackageCode=' . $resPhonePrepaid['flow_package_code'] .
                '&callbackUrl=' . env('app.host', 'https://api6.rd029.com') . '/charging/callbackMessage');
            $resData = json_decode($res, TRUE);
            $isAbnormal = false;
            $upPhonePrepaid['status'] = 1;
            if (!$resData || !$resData['header']['success']) {
                PhonePrepaidRefillLogModel::setLog($userId, $resPhonePrepaid['id'], sprintf('用户ID:%s 充值话费失败，失败原因:%s', $userId, $resData['header']['errorInfo']));
                $this->sendDingError(sprintf('用户ID:%s 订单号:%s 充值话费失败，失败原因:', $userId, $orderNo), $resData['header']['errorInfo']);
                $upPhonePrepaid['is_abnormal'] = 1;
                $isAbnormal = true;
                goto a;//充值失败跳转
            }
            //充值成功 判断是否延时
            $content = sprintf('用户ID:%s 订单号:%s 话费充值支付成功，话费充值中。。。', $userId, $orderNo);
            if (!$resData['body']['isDelayed']) {
                $upPhonePrepaid['status'] = 2;
                $upPhonePrepaid['arrival_time'] = time();
                $content = sprintf('用户ID:%s 订单号:%s 话费充值支付成功，话费充值成功。', $userId, $orderNo);
            }
            a://跳转到这里
            $upPhonePrepaid['accept_seq'] = $resData['body']['acceptSeq'] ?? '';
            $upPhonePrepaid['pay_time'] = time();
            $upPhonePrepaid['update_time'] = time();
            $resPhonePrepaidCount = (new PhonePrepaidRefillModel())->where('order_no', $orderNo)->update($upPhonePrepaid);
            if (!$resPhonePrepaidCount) {
                return false;
            }
            if (!$isAbnormal) {//没有异常 写入正常日志
                //写入日志
                PhonePrepaidRefillLogModel::setLog($userId, $resPhonePrepaid['id'], $content ?? '');
            }
            Db::commit();
            return true;
        } catch (\Exception $ex) {
            self::sendDingError(sprintf('用户ID：%s 充值话费 异常', $userId), $ex);
            Db::rollback();
            return false;
        }
    }

    /**充值成功回调
     * @param array $params
     * @return array
     */
    public function callbackMessage(array $params): array
    {
        $data = PhonePrepaidRefillModel::getDataByOrderNo((string)$params['orderNo']);
        if (!$data) {
            return $this->error(404, '未查询到数据');
        }
        if ($data['status'] == 3) {
            return $this->error(201, '已处理过改信息');
        }
        if ($params['returnCode'] != 0) {
            PhonePrepaidRefillLogModel::setLog($data['user_id'], $data['id'], '话费充值回调异常通知：' . $params['returnMsg']);
            return $this->success();
        }
        //没有问题开始修改
        $res = (new PhonePrepaidRefillModel())->update(['status' => 2, 'arrival_time' => time(), 'update_time' => time()], ['id' => $data['id']]);
        if (!$res) {
            return $this->error(999998, '数据异常');
        }
        //写入日志
        PhonePrepaidRefillLogModel::setLog($data['user_id'], $data['id'], sprintf('用户ID:%s 订单号:%s 话费充值支付成功，话费充值成功。', $data['user_id'], $data['order_no']));
        return $this->success();
    }

    /**获取code
     * @param $operators
     * @param $phoneBill
     * @return mixed
     */
    private function getCode($operators, $phoneBill)
    {
        switch ($operators) {
            case 1:
                return $phoneBill['unicom_flow_package_code'];
                break;
            case 2:
                return $phoneBill['move_flow_package_code'];
                break;
            case 3:
                return $phoneBill['telecom_flow_package_code'];
                break;
            default:
                return $phoneBill['unicom_flow_package_code'];
        }
    }

    private function returnCode($code)
    {
        switch ($code) {
            case 1:
                return '联通';
                break;
            case 2:
                return '移动';
                break;
            case 3:
                return '电信';
                break;
            default:
                return '电信';
        }
    }


    /**获取check参数
     * @return string
     */
    public function auth(): string
    {
        $channel = config('self_config.charging_channel');
        $version = config('self_config.charging_version');
        $times = date('YmdHis', time());
        $auth = [
            'channel' => $channel,
            'version' => $version,
            'timeStamp' => $times,
            'check' => self::getSignature($version . $channel . $times . config('self_config.charging_ivs'), '874cAthMh4yJyqNO'),
        ];
        return json_encode($auth);
    }

    /**加密
     * @param $str
     * @param $key
     * @return string
     */
    function getSignature($str, $key)
    {
        $signature = "";
        if (function_exists('hash_hmac')) {
            $signature = bin2hex(hash_hmac("sha1", $str, $key, true));
        } else {
            $blocksize = 64;
            $hashfunc = 'sha1';
            if (strlen($key) > $blocksize) {
                $key = pack('H*', $hashfunc($key));
            }
            $key = str_pad($key, $blocksize, chr(0x00));
            $ipad = str_repeat(chr(0x36), $blocksize);
            $opad = str_repeat(chr(0x5c), $blocksize);
            $hmac = pack(
                'H*', $hashfunc(
                    ($key ^ $opad) . pack(
                        'H*', $hashfunc(
                            ($key ^ $ipad) . $str
                        )
                    )
                )
            );
            $signature = bin2hex($hmac);
        }
        return $signature;
    }

    /**渠道邀请
     * @param array $params
     * @return array
     * @author Poison
     * @date 2021/6/24 2021/6/24
     */

    public function invitation(array $params): array
    {
        $resBus = PhoneBusinessModel::getChannel($params['channel_id']);
        if (!$resBus) {
            return errorArray('获取渠道失败');
        }
        $resUser = PhoneBusinessUserModel::getBusUser($params['user_id']);
        $busUserModel = new PhoneBusinessUserModel();
        if ($resUser) {//写入数据
            //判断是否是当前渠道
            if ($resUser['phone_business_id'] == $params['channel_id']) {
                return successArray([], '邀请成功');
            }
            //修改数据
            $resBusUserUp = $busUserModel->update(['is_delete' => 1, 'update_time' => time()], ['id' => $resUser['id']]);
            if (!$resBusUserUp) {
                return errorArray('修改失败');
            }
        }
        //创建数据
        $resBusUserCreate = $busUserModel->createUser($params['channel_id'], $params['user_id']);
        if (!$resBusUserCreate) {
            return errorArray('邀请失败');
        }
        return successArray([], '邀请成功');
    }


}