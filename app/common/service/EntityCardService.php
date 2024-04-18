<?php

/**
 * 实体卡业务相关
 */

namespace app\common\service;


use app\common\model\CardsModel;
use app\common\model\DistributorsLogModel;
use app\common\model\DistributorsModel;

use app\common\model\EntityCardLogModel;
use app\common\model\EntityCardModel;
use app\common\model\PayLogModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use think\facade\Db;
use think\facade\Log as LogSave;
use think\Model;
use wechat\Pay;

class EntityCardService extends ComService
{
    use CacheTrait;

    /**
     * 统计实体卡状态总数
     * @param int $user_id 用户ID
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author yangliang
     * @date 2020/10/29 11:11
     */
    public function getCountCardStatus(int $user_id): array
    {
        $dis = DistributorsModel::getByUserId($user_id);
        if (empty($dis)) {
            return $this->error(100, '实体卡渠道商不存在');
        }

        //总计卡数
        $total_count = EntityCardLogModel::getTotalCount($dis['id']);

        //未开卡
        $unuse_count = EntityCardLogModel::getCountByDis($dis['id'], 2);

        //已开卡
        $open_count = EntityCardLogModel::getCountByDis($dis['id'], 3);

        //已激活
        $active_count = EntityCardLogModel::getCountByDis($dis['id'], 4);

        //已退卡
        $return_count = EntityCardLogModel::getCountByDis($dis['id'], 5);

        //已回收
        $recovery_count = EntityCardLogModel::getCountRecovery($dis['id'], 6);

        //已作废
        $obsolete_count = EntityCardLogModel::getCountByDis($dis['id'], 7);

        $data = [
            'total_count' => $total_count,
            'unuse_count' => $unuse_count,
            'open_count' => $open_count,
            'active_count' => $active_count,
            'return_count' => $return_count,
            'recovery_count' => $recovery_count,
            'obsolete_count' => $obsolete_count,
        ];

        return $this->success(200, '请求成功', $data);
    }


    /**
     * 获取渠道商实体卡列表数据
     * @param int $user_id 用户ID
     * @param int $status 实体卡状态
     * @param int $page 当前页码
     * @param int $limit 每页显示条数
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author yangliang
     * @date 2020/10/29 11:12
     */
    public function getListByCardStatus(int $user_id, int $status): array
    {
        $dis = DistributorsModel::getByUserId($user_id);
        if (empty($dis)) {
            return $this->error(100, '实体卡渠道商不存在');
        }

        $list = EntityCardLogModel::getListByDisIdAndStatus($dis['id'], $status);
        if (!empty($list)) {
            foreach ($list as &$v) {
                $card_log = EntityCardLogModel::getLastCardLog($v['distributors_id'], $v['id']);
                $v['status'] = $card_log['status'];
                $v['status_times'] = is_int($card_log['create_time'])?date('Y-m-d H:i:s', $card_log['create_time']):$card_log['create_time'];
            }
        }

        return $this->success(200, '请求成功', $list);
    }

    /**跳转中间件
     * @param array $params
     * @param int $userId
     * @return array
     * @throws \app\common\exception\ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/7/2 9:23 上午
     */
    public function checkPay(array $params, int $userId){
        $cardCheckCode = (string)$params['card_check_code'];//接收校验码
        $cardKey = (string)$params['card_key'];//接收公钥
        $entityCardModel = new EntityCardModel();
        if (!$cardCheckCode) {
            return $this->error(1002, '请输入正确的校验码');
        }
        //首先判断用户有没有开卡 如果没有开卡进行下一步
        $resUserCardsData = UserCardsModel::getOneCards($userId);//获取用户年卡信息
        if ($resUserCardsData) {
            return $this->error(1001, '目前仅支持每个用户拥有一张年卡，您已经是年卡用户，您可以等年卡过期后再激活，也可将该卡转给他人使用');
        }
        //查询卡号是否被激活
        $resEntityCardData = $entityCardModel->field('cardnumber,status,id,distributors_id')->where(['cdkey' => $cardKey, 'check_code' => $cardCheckCode])->findOrEmpty()->toArray();
        if (!$resEntityCardData) {
            return $this->error(1002, '校验码无效');
        }
        //进行状态过滤
        $resCode = $this->getCode((int)$resEntityCardData['status']);
        if ($resCode['code'] != 200) {
            return $resCode;
        }
        //查询渠道商信息
        $disData = DistributorsModel::getDataById($resEntityCardData['distributors_id']);
        //判断是否有渠道是有需要支付押金
        if($disData['is_deposit'] == 1){//需要加纳押金
            return self::entityPay($resEntityCardData,$params,$userId);
        }
        //直接开通
        return self::openEntityCard($params,$userId);
    }

    /**发起支付
     * @param array $resEntityCardData
     * @param array $params
     * @param int $userId
     * @return array
     * @throws \app\common\exception\ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/6/30 4:46 下午
     */
    public function entityPay(array $resEntityCardData, array $params, int $userId): array
    {
        self::delCache('entity_pay_'.$userId);//清理redis指定值
        $distributorsData = DistributorsModel::getDataById($resEntityCardData['distributors_id']);
        if(empty($distributorsData)){
            return errorArray('获取渠道商信息失败');
        }
        $cardData = CardsModel::getById($distributorsData['card_id']);
        if(empty($cardData)){
            return errorArray('获取指定年卡信息失败');
        }
        $t = time();
        self::setCache('entity_pay_'.$userId,json_encode($params),30 * 60);
        $userData = (new UserModel())->getOneUser($userId);
        $cardDeposit = $cardData['card_deposit'];
        $userCardDetailsData['extra_money'] = 0;
        $userCardDetailsData['deposit_money'] = 0;
        $userCardDetailsData['card_deposit'] = $cardDeposit;
        $userCardDetailsData['user_id'] = $userId;
        $userCardDetailsData['card_id'] = $cardData['id'];
        $userCardDetailsData['money'] = 0;
        $userCardDetailsData['pay_money'] = 0;
        $userCardDetailsData['pay_time'] = 0;// 支付时间
        $userCardDetailsData['refund_status'] = 1;
        $userCardDetailsData['describe'] = '用户实体卡支付押金';
        $userCardDetailsData['create_time'] = $t;
        $userCardDetailsData['update_time'] = $t;
        $userCardDetailsData['card_type'] = $cardData['card_type'];
        $userCardDetailsData['coupon_price'] = 0;
        $userCardDetailsId = (new UserCardDetailsModel())->insert($userCardDetailsData,true);
        //准备支付参数
        $outTradeNo = 'RD-'.$userCardDetailsId.'-DEPOSIT-'.rand(10000,99999);
        $payData['body'] = '睿鼎少儿实体卡办理(支付押金)';
        $payData['attach'] = 'deposit';
        $payData['openid'] = $userData['smart_openid'];
        $payData['trade_type'] = 'JSAPI';
        $payData['out_trade_no'] = $outTradeNo;
        $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $cardDeposit * 100;
        $pay = Pay::getInstance();
        $result = $pay->unifiedorder($payData);
        if (!$result) {
            return $this->error(0, $result['message']);
        }
        $payLogData['order_id'] = $userCardDetailsId;
        $payLogData['order_type'] = 5;// 年卡办理
        $payLogData['order_amount'] = $cardDeposit;
        $payLogData['out_trade_no'] = $payData['out_trade_no'];
        $payLogData['user_id'] = $userId;
        $payLogData['add_time'] = $t;
        $payLogResult = (new PayLogModel())->insert($payLogData);
        if (!$payLogResult) {
            return $this->error(0, "支付日志记录错误");
        }
        $result['deposit'] = $cardDeposit;
        return successArray($result, '操作成功', 201);

    }

    /**支付回调
     * @param string $outTradeNo
     * @param int $t
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/7/1 11:47 上午
     */
    public function entityNotify(string $outTradeNo, int $t): bool
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

        $resUserCardDetails = (new UserCardDetailsModel())->where('id', $payLog['order_id'])->update(['update_time' => time(), 'card_deposit_time' => time(), 'pay_time' => time(), 'card_deposit_trade_no' => $outTradeNo]);
        if(!$resUserCardDetails){
            LogSave::channel('pay')->info('用户年卡记录状态更新失败,out_trade_no:' . $outTradeNo);
            self::sendDingError('out_trade_no:' . $outTradeNo, '用户年卡记录状态更新失败');
            return false;
        }

        $params = json_decode(self::getCache('entity_pay_'.$payLog['user_id']),true);
        $res = self::openEntityCard($params,$payLog['user_id'],$payLog['order_id']);
        if($res['code']!=200){
            self::sendDingError('out_trade_no:' . $outTradeNo, '实体卡支付押金开卡失败 原因:'.$res['message']);
            return false;
        }
        return true;
    }


    /**2020-10-26  用户通过校验码开通实体卡
     * @param array $params
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function openEntityCard(array $params, int $userId,$orderId = 0)
    {
        $cardCheckCode = (string)$params['card_check_code'];//接收校验码
        $cardKey = (string)$params['card_key'];//接收公钥
        $entityCardModel = new EntityCardModel();
        //查询实体卡商
        $deposit = 0;
        $resEntityCardData = $entityCardModel->field('cardnumber,status,id,distributors_id')->where(['cdkey' => $cardKey, 'check_code' => $cardCheckCode])->findOrEmpty()->toArray();
        $disData = (new DistributorsModel())->where('id',$resEntityCardData['distributors_id'])->findOrEmpty()->toArray();
        $cardId = $disData['card_id'];
        if($disData['is_deposit']){
            $deposit = (new CardsModel())->where('id',$cardId)->value('card_deposit');
        }
        //状态没有问题 开始准备开卡
        //启动事务 启动报错机制
        Db::startTrans();
        //try {
            //修改卡的状态为激活状态
            $resEntityCardUpData = $entityCardModel->where('id', $resEntityCardData['id'])->update([
                'status' => 4,
                'cards_id' => $cardId,
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
            $resOpenCards = $this->openCards($userId, $resEntityCardData['cardnumber'],(int)$cardId,$deposit,$orderId);
            if ($resOpenCards['code'] != 200) {
                Db::rollback();
                return $resOpenCards;
            }
//            if(!$isSpecial){//特殊卡不处理通掌灯人
                //开通共享合伙人
                $resBusiness = (new BusinessService())->thisOpenBusiness($userId, (int)$resOpenCards['data']['user_card_id']);
                if ($resBusiness['code'] != 200) {//写入失败 返回
                    Db::rollback();
                    return $resBusiness;
                }
//            }

            //提交事务
            Db::commit();
            return $this->success(200, '开通成功');
//        } catch (\Throwable $e) {
//            Db::rollback();
//            trace('错误信息' . $e, 'error');
//            $this->sendDingError('创建订单异常 用户ID:' . $userId, $e);
//            return $this->error(500, '服务器异常，请稍后再试');
//        }
    }

    /**查询卡号
     * @param array $params
     * @param int $userId
     * @return array
     */
    public function selectCard(array $params, int $userId)
    {
        $card_key = $params['card_key'];
        if (!$card_key) {
            return $this->error(100, '卡密不能为空');
        }
        //判断用户是否是实体卡商
        $isDistributors = 1;//默认为实体商
        $resDistributors = (new DistributorsModel())->findData($userId);
        if (!$resDistributors) {
            $isDistributors = 2;
            //return $this->error(0, '您还不是实体售卡商');
        }

        $resEntityCard = (new EntityCardModel())->field('id,cardnumber,status')->where(['cdkey' => $card_key])->findOrEmpty()->toArray();
        if (!$resEntityCard) {
            return $this->error(1010, '未查询到卡片信息1');
        }
        if ($isDistributors == 1) {
            //去日志表中查询是否和他有关系
            $resDistributorsLogData = (new EntityCardLogModel())->alias('el')->field('ec.status,ec.distributors_id')->leftjoin('entity_card ec', 'el.entity_card_id = ec.id')->where(['ec.id' => $resEntityCard['id'], 'el.distributors_id' => $resDistributors['id']])->order('el.id Desc')->findOrEmpty()->toArray();
            if (!$resDistributorsLogData) {
                return $this->error(1010, '未查询到卡片信息');
            }

            if ($resDistributorsLogData['distributors_id'] != $resDistributors['id']) {
                return $this->error(1008, '该卡片已经分配给他人，您没有权限对该卡片进行“开卡”操作');
            }
        }


        $resCode = $this->getCode($resEntityCard['status'], 2, $isDistributors);
        if ($resCode['code'] != 200) {
            return $resCode;
        }
        return $this->success(200, '获取成功', ['card_number' => $resEntityCard['cardnumber']]);
    }

    /** 实体卡商开卡
     * @param array $params
     * @param int $userId
     * @return array
     */
    public function setCard(array $params, int $userId)
    {

        $cardKey = (string)$params['card_key'];//接收公钥
        $cardNumber = (string)$params['card_number'];//接收公钥
        if (!$cardKey) {
            return $this->error(100, '卡密不能为空');
        }
        //判断用户是否是实体卡商
        $resDistributors = (new DistributorsModel())->findData($userId);
        if (!$resDistributors) {
            return $this->error(0, '您还不是实体售卡商');
        }
        $resEntityCardData = (new EntityCardModel())->field('cardnumber,status,id,distributors_id')->where(['cdkey' => $cardKey, 'distributors_id' => $resDistributors['id']])->findOrEmpty()->toArray();
        if (!$resEntityCardData) {
            return $this->error(0, '未查询到卡片信息，请扫描正确的二维码');
        }
        //判断状态是否正常
        $resGetCode = $this->getCode($resEntityCardData['status'], 2);
        if ($resGetCode['code'] != 200) {
            return $resGetCode;
        }
        //开启事务
        Db::startTrans();
        try {
            $resEntityCardUpdate = (new EntityCardModel())->where(['cdkey' => $cardKey, 'cardnumber' => $cardNumber, 'distributors_id' => $resDistributors['id']])->update(['status' => 3, 'open_time' => time(), 'update_time' => time()]);
            if (!$resEntityCardUpdate) {
                Db::rollback();
                return $this->error(100, '修改状态失败');
            }
            //写入开卡日志
            $resEntityCardLog = (new EntityCardLogModel())->insert([
                'admin_id' => $userId,
                'create_time' => time(),
                'log_info' => '实体卡商开卡',
                'status' => 3,
                'distributors_id' => (int)$resDistributors['id'],
                'entity_card_id' => (int)$resEntityCardData['id']]);
            if (!$resEntityCardLog) {
                Db::rollback();
                return $this->error(0, '添加日志失败');
            }
            //写入实体商日志
            $resDistributorsLog = DistributorsLogModel::DistributorsLogCreate((int)$userId, (int)$resDistributors['id']);
            if (!$resDistributorsLog) {
                Db::rollback();
                return $this->error(0, '添加实体商日志失败');
            }
            Db::commit();
            return $this->success(200, '开卡成功');
        } catch (\Throwable $e) {
            Db::rollback();
            trace('错误信息' . $e, 'error');
            return $this->error(500, '服务器异常，请稍后再试');
        }
    }

    /** 写入年卡信息
     * @param $userId
     * @param $cardNumber
     * @param int $deposit
     * @param int $cardId
     * @return array
     */
    public function openCards($userId, $cardNumber,int $cardId = 6,int $deposit,$orderId)
    {
        //查询用户是否是押金会员
        $resUserData = (new UserModel())->getOneUser($userId);
        $cardDuration = (new CardsModel())->where('id',$cardId)->value('card_duration');
        $startTime = time();
        $endTime = $startTime + $cardDuration;
        $is_lock = 0;//是否锁定
        if ($resUserData['grade'] > 0) {
            $is_lock = 1;
            $startTime = 0;
            $endTime = 0;
        }

        $resUserCardId = (new UserCardsModel())->UserCardsInsert($userId, $cardId, $cardDuration, $is_lock);//写入userCard表
        if (!$resUserCardId) {
            return $this->error(0, '创建会员失败');
        }
        if($orderId){
            $resUserCardDetails = (new UserCardDetailsModel())->where('id',$orderId)->update(['update_time'=>time(),'user_card_id'=>$resUserCardId,'out_trade_no'=>$cardNumber,'card_open_time'=>$cardDuration]);
        }else{
            $resUserCardDetails = UserCardDetailsModel::UserCardsDetailsInsert($userId, $resUserCardId, $cardNumber,$cardDuration,0,0, $cardId, $deposit);//写入UserCardsDetails表
        }

        if (!$resUserCardDetails) {
            return $this->error(0, '创建会员明细失败');
        }
        LogService::addUserCardChangeLog($userId, $resUserCardId, $cardId, 1, '', '用户实体卡开卡', '', '', '', $startTime, $endTime);
        return $this->success(200, '写入成功', ['user_card_id' => $resUserCardId]);
    }

    /** 返回不同的值
     * @param int $code
     * @param int $type
     * @param int $isDistributors
     * @return array
     */
    public function getCode(int $code, int $type = 1, int $isDistributors = 1)
    {
        switch ($code) {
            case 1:
                return $this->error(0, '未查询到卡片信息');
                break;
            case 2:
                if ($type == 1 || $isDistributors == 2) {
                    return $this->error(0, '请联系售卡商进行开卡操作');
                    break;
                }
                return $this->success();
            case 3:
                if ($type == 2) {
                    return $this->error(1003, '跳转到激活');
                    break;
                }
                return $this->success();
            case 4:
                return $this->error(1006, '该卡片已被售卖使用。');
                break;
            case 5:
                return $this->error(0, '该卡已退卡');
                break;
            case 6:
                if ($isDistributors == 2) {
                    return $this->error(100, '未查询到卡片信息');
                    break;
                }
                return $this->error(1005, '该卡片已被回收，如有疑问，请联系客服。');
                break;
            case 7:
                if ($isDistributors == 2) {
                    return $this->error(100, '未查询到卡片信息');
                    break;
                }
                return $this->error(1005, '该卡片已被作废，如有疑问，请联系客服。');
                break;
            default:
                return $this->error(0, 'code为空');
                break;
        }
    }


}