<?php


namespace app\common\service;


use app\common\ConstLib;
use app\common\model\ApplyModel;
use app\common\model\OrderModel;
use app\common\model\UserAddressModel;
use app\common\model\UserModel;
use app\common\model\UserUpgradeModel;

class ApplyService extends ComService
{


    /**
     * 用户退卡或降级
     * @param int $user_id
     * @param int $type
     * @param int $grade
     * @param string $user_name
     * @param string $merits
     * @param string $reason
     * @return array
     * @author yangliang
     * @date 2021/2/22 13:38
     */
    public function userDowngrade(int $user_id, int $type = 0, int $grade = 0, string $user_name = '', string $merits = '', string $reason = ''){
        $user_info = (new UserModel())->getOneUser($user_id);
        if(empty($user_info)){
            return $this->error(100, '用户未找到');
        }

        // 查询最后一次退款
        $last_apply = ApplyModel::getLast($user_id);
        if($last_apply && $last_apply['status'] != 6 && $last_apply['status'] != -1){
            return $this->error(100, '您有未完成的审批流程,请耐心等待客服人员处理,如有疑问请咨询:'.ConstLib::SERVICE_PHONE);
        }

        // 查询订单信息
        $order_count = OrderModel::getCountByUserIdAndNotInStatus($user_id, [-1, 9]);
        if($order_count > 0){
            return $this->error(100, '您有未完成的订单,请处理完成后再次申请,如有疑问请咨询:'.ConstLib::SERVICE_PHONE);
        }

        // 等级判断
        //type 0降级,1退卡
        if($type == 0 && !$grade){
            return $this->error(100, '请选择要降的等级');
        }

        // 如果是 降级，降级等级与当前等级一致，提示用户请选择要降的等级
        $grade_before = $user_info['grade']; // 3
        $grade_after = $grade; // 4
        if ($type == 0 && $grade_before <= $grade_after) {
            return $this->error(100, '您选择的降级等级有误');
        }

        // 用户最近一次升级时间
        $upgrade_last_paid_time = UserUpgradeModel::getByUserIdAndPaid($user_id, 1);
        if($type == 0 && $upgrade_last_paid_time['paid_time'] > 0){
            $timestamp_106 = strtotime('2020-01-06 09:30:00');
            $timestamp_122 = strtotime('2020-01-22 00:00:00');
            $timestamp_226 = strtotime('2020-02-26 00:00:00');
            $now = time();
            // 1月6号以后,1月22号以前升级,锁定90天
            if($upgrade_last_paid_time['paid_time'] > $timestamp_106 && $upgrade_last_paid_time['paid_time'] < $timestamp_122){
                // 90天时间
                $timestamp_90 = 90 * 24 * 3600;
                if($upgrade_last_paid_time['paid_time'] + $timestamp_90 > $now){
                    $left_days = ceil(($upgrade_last_paid_time['paid_time'] + $timestamp_90 - $now) / 3600 / 24);
                    $result = '由于寒假大量家长集中升级给孩子多借书，开学集中降级，工作人员无法及时处理，造成大量重复性工作出现。寒假升级用户从升级日起锁定3个月，之后您随时可申请降级。请在'. $left_days .'天后重新提交退卡或降级申请，如有疑问请咨询:'. ConstLib::SERVICE_PHONE;
                    return $this->error(100, $result);
                }
            }else if($upgrade_last_paid_time['paid_time'] > $timestamp_226){
//                $result = '为避免疫情期间临时升级账户后续集中降级引发平台物流超负荷运转，疫情期间升级账号从升级日起锁定3个月，之后可正常申请降级，如有疑问请咨询:'. ConstLib::SERVICE_PHONE;
//                return $this->error(100, $result);
            }
        }

        $data = [
            'user_id' => $user_id,
            'user_name' => $user_name ?? $user_info['user_name'],
            'user_phone' => $user_info['mobile'],
            'user_address' => UserAddressModel::getAddress($user_id)['address'],
            'user_grade' => $grade_before,
            'user_to_grade' => $grade_after,
            'identity' => $user_info['identity'],
            'type' => $type,
            'add_time' => time(),
            'update_time' => time()
        ];

        // 退卡申请:解决问题,退卡原因
        if($type != 0){
            $data['merits'] = $merits;
            $data['reason'] = $reason;
        }

        // 添加退款记录
        $res = ApplyModel::create($data);
        if(!$res){
            return $this->error(100, '申请失败');
        }

        return $this->success(200, '您的申请已提交,请耐心等待客服人员处理,如有疑问请咨询:'.ConstLib::SERVICE_PHONE);
    }


    /**
     * 用户取消，降级或退卡申请
     * @param int $user_id
     * @param int $apply_id
     * @return array
     * @author yangliang
     * @date 2021/2/23 14:16
     */
    public function cancleApply(int $user_id, int $apply_id){
        $apply_info = ApplyModel::getByApplyIdAndUserId($apply_id, $user_id);
        if(!empty($apply_info)){
            $update_flg = ApplyModel::where('apply_id', $apply_id)->update(['status' => -1, 'last_status' => $apply_info['status']]);
            if($update_flg){
                return $this->success(200, '取消成功');
            }else{
                return $this->error(100, '取消失败');
            }
        }else{
            return $this->error(100, '该申请不存在');
        }
    }
}