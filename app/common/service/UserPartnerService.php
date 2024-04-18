<?php


namespace app\common\service;


use app\common\model\PartnerLogModel;
use app\common\model\UserChargeModel;
use app\common\model\UserModel;
use app\common\model\UserPartnerModel;
use OSS\OssClient;
use think\facade\Db;
use wechat\Pay;

class UserPartnerService extends ComService
{
    public function getUserPartnerPayment($request, $userId){
        $chargeId = $request['chargeId'];
        if (isset($chargeId)) {
            // 获取充值信息
            $chargeInfo = (new UserChargeModel())->getOneData(['id'=>$chargeId,'is_show'=>1]);
            if ($chargeInfo) {
                $charge_amount = $chargeInfo['amount'];
                $extra_amount = $chargeInfo['extra'];
                $body = '用户自己进行储值卡充值';
                $user_info['user_id'] = $userId;
                // 充值金额变动记录
                $userPartnerInfo = (new UserPartnerModel())->getOneData($userId);
                Db::startTrans();
                try {
                    $partner_log_id = (new LogService())->partnerLog($user_info, ['partner_sn' => $userPartnerInfo['partner_sn'], 'amount' => $charge_amount], 1, $body . ',充值金额' . $charge_amount, 1, 0, 0);
                    if ($partner_log_id === false) {
                        Db::rollback();
                        return $this->error(0, '储值卡变动记录添加失败');
                    }
                    // 赠送金额变动记录
                    if ($extra_amount > 0) {
                        $partner_log_id_extra = (new LogService())->partnerLog($user_info, ['partner_sn' => $userPartnerInfo['partner_sn'], 'amount' => $extra_amount], 1, $body . ',充值' . $charge_amount . '赠送金额' . $extra_amount, 2, 0, 0, $partner_log_id);
                        if ($partner_log_id_extra === false) {
                            Db::rollback();
                            return $this->error(0, '储值卡赠送金额变动记录添加失败');
                        }
                    }
                    $out_trade_no = 'RD'. $partner_log_id .'CHARGE'. rand(10000, 99999);
                    // 添加支付记录
                    $payLogResult = (new LogService())->payLog(['order_id' => $partner_log_id, 'order_type' => 3, 'order_amount' => $charge_amount, 'out_trade_no' => $out_trade_no], 0, $userId);
                    if ($payLogResult === false) {
                        Db::rollback();
                        return $this->error(0, '支付记录添加失败');
                    }
                    $payData['body'] = '睿鼎少儿储值卡充值';
                    $payData['attach'] = $request['type'];
                    $payData['openid'] = $request['openid'];
                    $payData['trade_type'] = 'JSAPI';
                    $payData['out_trade_no'] =  $out_trade_no;
                    $payData['total_fee'] =  env('server_env') === 'dev' ? 1 : $charge_amount * 100;
                    $pay = Pay::getInstance();
                    $result = $pay->unifiedorder($payData);
                    if (!$result) {
                        return $this->error(0, $result['message']);
                    }
                    Db::commit();
                    return $this->success( 200, "操作成功",json_encode($result));
                } catch (\Exception $ex) {
                    Db::rollback();
                    return $this->error(0, $ex->getMessage());
                }

            } else {
                return $this->error(0, "充值设定不存在");
            }
        } else {
            return $this->error(0, "请求参数不全");
        }
    }


    /**
     * 支付码
     * @param int $user_id  用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/24 9:47
     */
    public function payQrCode(int $user_id){
        $user_partner = (new UserPartnerModel())->getOneData($user_id);
        if(empty($user_partner)){
            return $this->error(100, '储值卡不存在');
        }

        $user_pwd = (new UserModel())->getOneUser($user_id, 1, 'password');
        $tmp_str_md5 =substr(md5($user_id.$user_pwd), 8, 16);

        $redis_tag = $this->getCache($tmp_str_md5);
        if(!$redis_tag){
            $redis_tag = time();
            // 储值卡二维码过期时间60秒
            $this->setCache($tmp_str_md5, $redis_tag, 60);
        }

        $partner_qrcode = createQrCode($user_id . $redis_tag, null, 800);
        if(!$partner_qrcode){
            return $this->error(100, '二维码未生成');
        }

        // 存储路径
        $savePath = 'userPayQrCode/' . date('Y') . '/' . date('md') . '/';
        // 文件名称
        $fileName = "RD_" . date('YmdHis') . "." . "jpeg";
        // 将图片保存储存路径
        $object = $savePath . $fileName;
        $result = \OssUpload::getInstance()->upload($object, $partner_qrcode);

        return $this->success(200, 'success', $result['data']['url']);
    }


    /**
     * 获取用户储值卡消费明细
     * @param int $user_id
     * @param int $page
     * @param int $limit
     * @return array
     * @author yangliang
     * @date 2021/2/24 9:56
     */
    public function getUserPartnerInfo(int $user_id, int $page, int $limit){
        $user_partner = (new UserPartnerModel())->getOneData($user_id);
        if(empty($user_partner)){
            return $this->error(100, '储值卡不存在');
        }

        // 查询消费明细
        $list = PartnerLogModel::getSearchList($user_id, $page, $limit);

        return $this->success(200, '获取成功', $list);
    }
}