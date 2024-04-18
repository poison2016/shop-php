<?php

/**
 * 拼团相关
 */

namespace app\common\service;


use app\common\ConstLib;
use app\common\exception\ApiException;
use app\common\model\CardGroupInfoModel;
use app\common\model\CardGroupLogModel;
use app\common\model\CardGroupModel;
use app\common\model\CardGroupRefundLogModel;
use app\common\model\CardGroupRefundModel;
use app\common\model\UserCardDetailsModel;
use app\controller\CardGroup;
use think\facade\Db;
use think\facade\Log;
use wechat\Pay;

class CardGroupService extends ComService
{

    /**
     * 拼团退款
     * @param $data
     * @return array
     * @author yangliang
     * @date 2020/11/26 11:29
     */
    public function refund($data)
    {
        //解密数据
        $arr = self::getDecode($data['str'], 'rd029');
        $arr = json_decode($arr, true);
        if (empty($arr['user_id']) || empty($arr['group_info_id'])) {
            return $this->error(100, '参数错误');
        }
        $group_info = CardGroupInfoModel::getByUserIdAndId($arr['user_id'], $arr['group_info_id']);
        if (empty($group_info)) {
            return $this->error(100, '拼团信息不存在');
        }

        $user_card = UserCardDetailsModel::getByUserCardId($group_info['user_card_id']);
        if (empty($user_card) || $user_card['refund_card_status'] == 3) {
            return $this->error(100, '年卡信息不存在');
        }

        if ($user_card['pay_money'] <= ConstLib::GROUP_REFUND_WARNING_PRICE) {
            return $this->error(100, '操作异常');
        }

        try {
            $money = ConstLib::GROUP_REFUND_PRICE;
            $total_money = $group_info['price']  + $user_card['card_deposit'];
            if (env('server_env') === 'dev') {
                $money = 0.01;
                if ($group_info['price'] == 599) {
                    $total_money = 0.03;
                } else if ($group_info['price'] == 499) {
                    $total_money = 0.02;
                } else {
                    $total_money = 0.01;
                }
            }

            $out_refund_no = sprintf('RD%dGROUP%d', $group_info['id'], rand(10000, 99999));
            $data = [
                'out_trade_no' => $user_card['out_trade_no'],
                'out_refund_no' => $out_refund_no,  //退款单号
                'refund_fee' => $money * 100,
                'total_fee' => (int)($total_money * 100),
                'refund_desc' => sprintf('家庭阅读年卡，拼团成功，退还%.2f元', $money)  //退款原因
            ];
            //退款前记录请求信息
            CardGroupRefundLogModel::create([
                'out_refund_no' => $out_refund_no,
                'group_info_id' => $arr['group_info_id'],
                'req_data' => json_encode($data),
                'status' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'type' => 1,
            ]);
            $pay = Pay::getInstance();
            $res = $pay->refund($data);
            //测试使用下面
//            $res['result_code'] = 'SUCCESS';
//            $res['refund_fee'] = $money * 100;
//            $res['out_refund_no'] = $out_refund_no;
            if ($res['result_code'] != 'SUCCESS') {
                //记录日志
                self::updateRefundLog($res, $out_refund_no, 2);
                return $this->error(100, $res['err_code_des']);
            }
        } catch (ApiException $ae) {
            //记录日志
            self::updateRefundLog($ae->getMessage(), $out_refund_no, 2);
            return $this->error(100, $ae->getMessage());
        } catch (\Exception $e) {
            //记录日志
            self::updateRefundLog($e->getMessage(), $out_refund_no, 2);
            return $this->error(100, $e->getMessage());
        }

        //记录日志
        self::updateRefundLog($res, $out_refund_no, 1);
        return $this->success(200, '退款成功', $res);
    }


    /**
     * 记录退款日志
     * @param $data  退款返回信息
     * @param $out_refund_no  退款单号
     * @param $status  状态  1-成功    2-失败
     * @author yangliang
     * @date 2020/12/7 9:41
     */
    public function updateRefundLog($data, $out_refund_no, $status)
    {
        try {
            CardGroupRefundLogModel::where('out_refund_no', $out_refund_no)
                ->update([
                    'res_data' => json_encode($data),
                    'status' => $status,
                    'update_time' => time()
                ]);
        } catch (\Exception $e) {
            Log::error('------e======' . json_encode($e));
        }
    }

    public function endCardGroup(array $params): array
    {
//        try {
        //获取需要退款的userId 进行判断是否有资格退款
        $resUserCardDetailsData = (new UserCardDetailsModel())->getUSerCardDetailOne((int)$params['user_id']);
        if (!$resUserCardDetailsData) {
            return $this->error(100, '未查询到支付数据');
        }
        //判断该用户是否创建团
        $cardGroupData = (new CardGroupModel())
            ->alias('cg')
            ->field('cgi.id,cg.id as card_group_id')
            ->join('rd_card_group_info cgi', 'cg.id = cgi.card_group_id')
            ->where('cg.user_id', $params['user_id'])
            ->where('cgi.is_out', 0)
            ->where('cgi.type', 1)
            ->findOrEmpty()->toArray();
        if (!$cardGroupData) {
            return $this->error(100, '该用户未创建团');
        }
        //准备参数
        $data = [
            'str' => self::getEncode(json_encode(['user_id' => $params['user_id'], 'group_info_id' => $cardGroupData['id']]), 'rd029')
        ];
        $resEndData = self::openRefund($data);
        if ($resEndData['code'] != 200) {//请求失败 记录
            //写入日志中
            (new CardGroupLogModel())->insert([
                'user_id' => $params['user_id'],
                'card_group_id' => $cardGroupData['card_group_id'],
                'info' => '开团退款 (手动退款) 退款用户ID：' . $params['user_id'] . ' 退款失败 失败原因:' . ($resEndData['message'] ?? '暂无返回信息'),
                'create_time' => time()
            ]);
            return $this->error(100, '开团退款失败 原因:' . $resEndData['message']);
        }
        //启动事务
        Db::startTrans();
        //开团退款成功 写入拼团日志表
        //写入日志中
        $resCardGroupLog = (new CardGroupLogModel())->insert([
            'user_id' => $params['user_id'],
            'card_group_id' => $cardGroupData['card_group_id'],
            'info' => '开团退款 (手动退款) 退款用户ID：' . $params['user_id'] . ' 退款成功！ 退款金额:' . ($resEndData['data']['refund_fee'] / 100),
            'create_time' => time()
        ]);
        if (!$resCardGroupLog) {
            Db::rollback();
            return $this->error(100, '写入日志失败');
        }
        //修改支付价格
        $resUserCardDetails = (new UserCardDetailsModel())
            ->where('id', $resUserCardDetailsData['id'])
            ->update([
                'pay_money' => $resUserCardDetailsData['pay_money'] - $resEndData['data']['refund_fee'] / 100,
                'update_time' => time()
            ]);
        if (!$resUserCardDetails) {
            Db::rollback();
            return $this->error(100, '修改支付价格失败');
        }
        //写入退款团购退款流水表
        $resCardGroupRefund = (new CardGroupRefundModel())->insert([
            'user_id' => $params['user_id'],
            'card_group_id' => $cardGroupData['card_group_id'],
            'user_card_id' => $resUserCardDetailsData['user_card_id'],
            'refund_money' => $resEndData['data']['refund_fee'] / 100,
            'refund_number' => $resEndData['data']['out_refund_no'],
            'create_time' => time()
        ]);
        if (!$resCardGroupRefund) {
            Db::rollback();
            return $this->error(100, '写入团购退款流水记录失败');
        }
//        }catch (\Exception $ex){
//            Db::rollback();
//            return $this->error(100,'请求异常 '.$ex->getMessage());
//        }
        Db::commit();
        return $this->success(200, '退款成功');
    }

    /**
     * 开团退款
     * @param $data
     * @return array
     * @author yangliang
     * @date 2020/12/10 9:54
     */
    public function openRefund($data)
    {
        //解密数据
        $arr = self::getDecode($data['str'], 'rd029');
        $arr = json_decode($arr, true);

        if (empty($arr['user_id']) || empty($arr['group_info_id'])) {
            return $this->error(100, '参数错误');
        }

        $group_info = CardGroupInfoModel::getByUserIdAndId($arr['user_id'], $arr['group_info_id']);
        if (empty($group_info)) {
            return $this->error(100, '拼团信息不存在');
        }

        $group = CardGroupModel::getByIdAndUserId($group_info['card_group_id'], $arr['user_id']);
        if (empty($group)) {
            return $this->error(100, '非拼团发起者不能退款');
        }

        $user_card = UserCardDetailsModel::getByUserCardId($group_info['user_card_id']);

        if(empty($user_card) || in_array($user_card['refund_card_status'], [1,2,3]) || $user_card['is_refund'] == 1){
            return $this->error(100, '年卡信息不存在');
        }

        if ($user_card['pay_money'] <= ConstLib::GROUP_OPEN_REFUND_PRICE) {
            return $this->error(100, '操作异常');
        }

        $refund_log = CardGroupRefundLogModel::getByGroupInfoIdAndType($arr['group_info_id'], 2);
        if (!empty($refund_log)) {
            return $this->error(100, '拼团发起者已存在退款记录');
        }

        try {
            $money = ConstLib::GROUP_OPEN_REFUND_PRICE;
            $total_money = $group_info['price'];
            if (env('server_env') === 'dev') {
                $money = 0.01;
                if ($group_info['price'] == 599) {
                    $total_money = 0.04;
                } else if ($group_info['price'] == 499) {
                    $total_money = 0.03;
                } else {
                    $total_money = 0.02;
                }
            }

            $out_refund_no = sprintf('RD%dGROUP%dOPEN', $group_info['id'], rand(10000, 99999));
            $data = [
                'out_trade_no' => $user_card['out_trade_no'],
                'out_refund_no' => $out_refund_no,  //退款单号
                'refund_fee' => $money * 100,
                'total_fee' => (int)($total_money * 100),
                'refund_desc' => sprintf('家庭阅读年卡，开团成功，退还%.2f元', $money)  //退款原因
            ];
            //退款前记录请求信息
            CardGroupRefundLogModel::create([
                'out_refund_no' => $out_refund_no,
                'group_info_id' => $arr['group_info_id'],
                'req_data' => json_encode($data),
                'status' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'type' => 2
            ]);
            $pay = Pay::getInstance();
            $res = $pay->refund($data);

            if ($res['result_code'] != 'SUCCESS') {
                //记录日志
                self::updateRefundLog($res, $out_refund_no, 2);
                return $this->error(100, $res['err_code_des']);
            }
        } catch (ApiException $ae) {
            //记录日志
            self::updateRefundLog($ae->getMessage(), $out_refund_no, 2);
            return $this->error(100, $ae->getMessage());
        } catch (\Exception $e) {
            //记录日志
            self::updateRefundLog($e->getMessage(), $out_refund_no, 2);
            return $this->error(100, $e->getMessage());
        }

        //记录日志
        self::updateRefundLog($res, $out_refund_no, 1);
        return $this->success(200, '退款成功', $res);
    }
}