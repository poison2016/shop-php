<?php
declare(strict_types=1);

namespace app\common\service;


use app\common\ConstLib;
use app\common\exception\ApiException;
use app\common\model\FamilyActivityBusinessModel;
use app\common\model\FamilyActivityCampPeriodModel;
use app\common\model\FamilyActivityCashFlowModel;
use app\common\model\FamilyActivityModel;
use app\common\model\FamilyActivityOrderDiscountModel;
use app\common\model\FamilyActivityOrderLogsModel;
use app\common\model\FamilyActivityOrderModel;
use app\common\model\FamilyActivityOrderRefundModel;
use app\common\model\FamilyActivityOrderUsersModel;
use app\common\model\FamilyActivityPartnerDiscountModel;
use app\common\model\PartnerLogModel;
use app\common\model\UserModel;
use app\common\model\UserPartnerModel;
use app\common\model\UsersCredentialsInfoModel;
use app\job\CancelActivityOrderJob;
use app\job\SendSmsJob;
use Exception;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;
use think\facade\Queue;
use wechat\Pay;

class FamilyActivityOrderService extends ComService
{

    /**
     * 提交订单
     * 1、验证营种、营期、报名营员信息以及库存
     * 2、获取支付方式以及支付价格
     * 3、创建订单、订单营员信息
     * 4、扣除营期库存
     * 5、处理股东优惠
     * 6、记录订单日志
     * 7、队列推送延时任务（订单30分钟未支付，系统自动取消订单）
     * @param int $user_id  用户ID
     * @param array $params
     * @return array
     * @author yangliang
     * @date 2021/4/16 10:36
     */
    public function apply(int $user_id, array $params): array
    {
        Db::startTrans();
        try {
            //验证数据
            list($activity, $period, $users_credentials_info, $uci_adult, $uci_child) = $this->checkApply($user_id, $params);
            $time_stamp = time();
            //统计总参营人数
            $users_count = count($users_credentials_info);
            //创建订单
            $order_sn = 'RdFamAc'.date('YmdHi', time()).rand(1000, 9999);
            //获取支付信息
            $pay_data = $this->getPayWay($user_id, (int)$activity['id'], (float)$period['price'], $users_count, (int)$activity['is_partner_discount'], (float)$activity['partner_discount_money']);
            //创建订单
            $order_res = FamilyActivityOrderModel::create([
                'user_id' => $user_id,
                'family_activity_id' => $params['activity_id'],
                'order_sn' => $order_sn,
                'user_num' => $users_count,
                'status' => 0,
                'money' => $pay_data['money'],
                'pay_way' => $pay_data['pay_way'],
                'pay_wx' => $pay_data['pay_wx'],
                'pay_partner' => $pay_data['pay_partner'],
                'contact_name' => $params['contact_name'],
                'contact_phone' => trim($params['contact_phone']),
                'is_partner_discount' => $pay_data['is_partner_discount'],
                'create_time' => $time_stamp,
                'update_time' => $time_stamp
            ]);

            $order_id = (int)$order_res->id;
            if(!$order_id){
                throw new Exception('订单创建失败');
            }

            //创建订单营员信息
            $order_users = [];
            foreach ($users_credentials_info as $uv){
                $order_users[] = [
                    'users_credentials_info_id' => $uv['id'],
                    'family_activity_order_id' => $order_id,
                    'family_activity_id' => $params['activity_id'],
                    'family_activity_camp_period_id' => $params['period_id'],
                    'name' => $uv['name'],
                    'credentials_type' => $uv['type'],
                    'credentials_number' => $uv['number'],
                    'adult_stock' => $uv['adult_stock'],
                    'phone' => $uv['phone'],
                    'gender' => $uv['gender'],
                    'create_time' => $time_stamp,
                    'update_time' => $time_stamp
                ];
            }

            if(!FamilyActivityOrderUsersModel::insertAll($order_users)){
                throw new Exception('订单营员信息创建失败');
            }

            //扣营期库存
            $period_res = FamilyActivityCampPeriodModel::where('id', $period['id'])
                ->dec('adult_stock', $uci_adult)->dec('child_stock', $uci_child)->update(['update_time' => time()]);
            if(!$period_res){
                throw new Exception('库存不足');
            }

            //处理股东业务
            self::partnerProcess($user_id, $pay_data, $time_stamp, (int)$activity['id'], $order_id);

            //记录订单日志
            self::addOrderLog($user_id, $order_id, '创建订单');
        }catch (Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();

        //20分钟后未支付发送短信
        $sms_data = [
            'type' => 'family_unpay_order',
            'order_id' => $order_id,
            'mobile' => $params['contact_phone'],
            'template_code' => config('self_config.activity_order_template_code'),
            'params' => ['name' => $activity['title'], 'orderNo' => $order_sn]
        ];
        Queue::later(ConstLib::FAMILY_ACTIVITY_UNPAY_ORDER_TIMESTAMP, SendSmsJob::class, $sms_data, ConstLib::FAMILY_ACTIVITY_ORDER_QUEUE_NAME);

        //30分钟后未支付取消订单
        Queue::later(ConstLib::FAMILY_ACTIVITY_CANCEL_ORDER_TIMESTAMP, CancelActivityOrderJob::class, $order_id, ConstLib::FAMILY_ACTIVITY_ORDER_QUEUE_NAME);

        return $this->success(200, '订单创建成功', ['order_id' => $order_id]);
    }


    /**
     * 订单列表
     * @param array $params
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/20 14:27
     */
    public function orderList(array $params): array
    {
        $list = FamilyActivityOrderModel::getOrderList($params);
        if(!empty($list)){
            foreach ($list as &$v){
                //营种缩略图
                $v['cover_img'] = !empty($v['cover_img']) ? $this->getGoodsImg($v['cover_img']) : '';
                //实付金额
                $v['pay_money'] = sprintf('%.2f', (float)$v['pay_wx'] + (float)$v['pay_partner']);
                if($v['status'] == 3){  //已核销状态归属于已完成
                    $v['status'] = 2;
                }
                unset($v['pay_wx'], $v['pay_partner']);
                //处理退款订单
                if($params['type'] == 99){
                    list($refund_status, $refund_type) = self::getRefundOrderStatus((int)$v['id'], (int)$v['user_num']);
                    if($refund_status != -1){
                        $v['refund_status'] = $refund_status;
                    }

                    if($refund_type != -1){
                        $v['refund_type'] = $refund_type;
                    }
                }
            }
        }

        return $this->success(200, '获取成功', $list);
    }



    /**
     * 订单详情
     * @param array $params
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/21 10:34
     */
    public function orderInfo(array $params): array
    {
        $order = FamilyActivityOrderModel::getOrderInfo($params['user_id'], $params['order_id']);
        if(empty($order)){
            return $this->error(100, '订单信息不存在');
        }

        if($order['status'] == 3){  //已核销状态归属于已完成
            $order['status'] = 2;
        }

        //营种缩略图
        $order['cover_img'] = !empty($order['cover_img']) ? $this->getGoodsImg($order['cover_img']) : '';
        //支付金额
        $order['pay_money'] = sprintf('%.2f', (float)$order['pay_wx'] + (float)$order['pay_partner']);
        //营员信息
        $order_users = FamilyActivityOrderUsersModel::getByOrderId($order['id']);
        if(!empty($order_users)){
            foreach ($order_users as &$uv){
                $uv['start_time'] = date('Y/m/d', $uv['start_time']);
                $uv['end_time'] = date('Y/m/d', $uv['end_time']);
            }
        }
        $order['users'] = $order_users;
        //成交时间
        if($order['status'] == 0){
            $order['deal_time'] = '';
        }else{
            $order['deal_time'] = date('Y-m-d H:i:s', $order['update_time']);
        }

        $order['wx_discount'] = 0;  //是否使用了微信6折， 0-否   1-是
        $order['wx_discount_money'] = 0;  //微信优惠金额
        $order['partner_discount_money'] = 0;  //股东优惠金额
        //优惠金额
        $order['discount'] = FamilyActivityOrderDiscountModel::getByOrderId($params['order_id']);
        if(!empty($order['discount'])){
            foreach ($order['discount'] as $dv){
                if($dv['type'] == 1){
                    $order['partner_discount_money'] = $dv['money'];
                }elseif ($dv['type'] == 2){
                    $order['wx_discount'] = 1;
                    $order['wx_discount_money'] = $dv['money'];
                }
            }
        }

        //退款订单
        if($order['is_refund'] == 1){
            list($refund_status, $refund_type) = self::getRefundOrderStatus((int)$order['id'], (int)$order['user_num']);
            if($refund_status != -1){
                $order['refund_status'] = $refund_status;
            }

            if($refund_type != -1){
                $order['refund_type'] = $refund_type;
            }

            $refund = FamilyActivityOrderRefundModel::getByOrderId($order['id']);
            if(!empty($refund)){
                foreach ($refund as &$v){
                    $users = UsersCredentialsInfoModel::getByIds($v['family_activity_order_users_ids']);
                    $v['users'] = implode('、', array_column($users, 'name'));
                }
            }
            $order['refund'] = $refund;
        }

        //获取用户是否可以使用股东优惠
        if($order['is_use_partner_discount'] == 1){
            $start_time = strtotime(date('Y-01-01', time()));
            $end_time = strtotime(date('Y-12-31 23:59:59', time()));
            //本营种使用减免情况（需求不考虑跨年，同营种只能使用一次）
            $discount_record = FamilyActivityOrderDiscountModel::getActivityPartnerDiscountByActivityIdAndUserId($order['family_activity_id'], $params['user_id']);
            //自然年内已使用减免次数
            $use_discount = FamilyActivityPartnerDiscountModel::getCurrentYearUseByUserId($params['user_id'], $start_time, $end_time);
            //本营种无已使用记录，且自然年内未达到最大减免使用次数，则进行减免
            if(empty($discount_record) && $use_discount < ConstLib::FAMILY_ACTIVITY_PARTNER_MAX_DISCOUNT){
                $order['is_use_partner_discount'] = 1;
            }else{
                $order['is_use_partner_discount'] = 0;
            }
        }

        return $this->success(200, '获取成功', $order);
    }


    /**
     * 修改订单信息
     * 1、验证订单以及订单状态
     * 2、验证营种、营期、报名营员信息以及库存
     * 3、回滚营期库存、回滚用户储值卡金额、删除订单优惠记录（还原订单信息，重新初始化订单）
     * 4、获取支付方式以及支付价格
     * 5、修改订单信息
     * 6、删除订单营员信息（重新初始化订单营员信息）
     * 7、创建订单营员信息
     * 8、扣除营期库存
     * 9、处理股东优惠
     * 10、记录订单日志
     * @param int $user_id  用户ID
     * @param array $params
     * @return array
     * @author yangliang
     * @date 2021/4/21 15:53
     */
    public function changeApply(int $user_id, array $params): array
    {
        $order = FamilyActivityOrderModel::getOrderInfo($user_id, $params['order_id']);
        if(empty($order)){
            return $this->error(100, '订单信息不存在');
        }

        if($order['status'] != 0){
            return $this->error(100, '订单异常，请联系客服处理');
        }

        Db::startTrans();
        try {
            //验证数据
            list($activity, $period, $users_credentials_info, $uci_adult, $uci_child) = $this->checkApply($user_id, $params);
            $time_stamp = time();

            $log_note = sprintf('修改亲子活动产品《%s》订单，订单ID：%d', $activity['title'], $params['order_id']);
            //回滚库存、回滚储值卡金额、删除订单优惠记录
            $this->backStock($params['order_id'], $user_id, (float)$order['pay_partner'], (int)$order['is_partner_pay'], (int)$order['is_partner_discount'], $log_note);

            $users_count = count($users_credentials_info);
            //修改订单
            $order_sn = 'RdFamAc'.date('YmdHi', time()).rand(1000, 9999);

            //获取支付信息
            $pay_data = $this->getPayWay($user_id, (int)$activity['id'], (float)$period['price'], $users_count, (int)$activity['is_partner_discount'], (float)$activity['partner_discount_money']);
            //更新订单信息
            $order_res = FamilyActivityOrderModel::where('id', $params['order_id'])
                ->update([
                'user_id' => $user_id,
                'family_activity_id' => $params['activity_id'],
                'order_sn' => $order_sn,
                'user_num' => $users_count,
                'status' => 0,
                'money' => $pay_data['money'],
                'pay_way' => $pay_data['pay_way'],
                'pay_wx' => $pay_data['pay_wx'],
                'pay_partner' => $pay_data['pay_partner'],
                'contact_name' => $params['contact_name'],
                'contact_phone' => trim($params['contact_phone']),
                'is_partner_discount' => $pay_data['is_partner_discount'],
                'update_time' => $time_stamp
            ]);

            if(!$order_res){
                throw new Exception('订单修改失败');
            }

            //删除原订单营员信息
            if(!FamilyActivityOrderUsersModel::where('family_activity_order_id', $params['order_id'])->delete()){
                throw new Exception('订单营员更新修改');
            }

            //创建订单营员信息
            $order_users = [];
            foreach ($users_credentials_info as $uv){
                $order_users[] = [
                    'users_credentials_info_id' => $uv['id'],
                    'family_activity_order_id' => $params['order_id'],
                    'family_activity_id' => $params['activity_id'],
                    'family_activity_camp_period_id' => $params['period_id'],
                    'name' => $uv['name'],
                    'credentials_type' => $uv['type'],
                    'credentials_number' => $uv['number'],
                    'adult_stock' => $uv['adult_stock'],
                    'phone' => $uv['phone'],
                    'gender' => $uv['gender'],
                    'create_time' => $time_stamp,
                    'update_time' => $time_stamp
                ];
            }

            if(!FamilyActivityOrderUsersModel::insertAll($order_users)){
                throw new Exception('订单营员信息修改失败');
            }

            //扣营期库存
            $period_res = FamilyActivityCampPeriodModel::where('id', $period['id'])
                ->dec('adult_stock', $uci_adult)
                ->dec('child_stock', $uci_child)
                ->update(['update_time' => time()]);
            if(!$period_res){
                throw new Exception('库存不足');
            }

            //处理股东业务
            self::partnerProcess($user_id, $pay_data, $time_stamp, (int)$activity['id'], $params['order_id']);

            //记录订单日志
            self::addOrderLog($user_id, $params['order_id'], '修改订单');
        }catch (Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        
        return $this->success(200, '订单修改成功', ['order_id' => $params['order_id']]);
    }


    /**
     * 获取订单支付方式
     * 1、下单用户若是股东身份，优先进行股东减免（同营种只减免一次且自然年内所有营种累计减免4次）
     * 2、支付方式：
     *     储值卡余额大于订单金额，支付方式为储值卡支付
     *     储值卡余额大于0，支付方式为混合支付，股东身份使用微信支付享6折
     *     微信支付，股东身份使用微信支付享6折
     * @param int $user_id 用户ID
     * @param int $activity_id 营种ID
     * @param float $period_price  营期价格
     * @param int $user_num  报名人数
     * @param int $discount 股东是否优惠  0-否，1-是
     * @param float $discount_money 股东优惠金额
     * @return array
     * @author yangliang
     * @date 2021/4/21 16:53
     */
    public function getPayWay(int $user_id, int $activity_id, float $period_price, int $user_num, int $discount, float $discount_money): array
    {
        //计算订单总金额
        $money = sprintf('%.2f', $period_price * $user_num);
        $is_partner = 0;  //是否股东标识  0-否  1-是
        $is_partner_discount = 0;  //股东是否可使用减免  0-否  1-是
        $pay_partner = 0;  //储值卡支付金额
        $pay_wx = 0;  //微信支付金额
        $wx_discount = 0;  //微信折扣优惠金额
        //验证用户是否股东
        $partner = UserModel::getShareholderByUserId($user_id);
        if(!empty($partner)){
            $is_partner = 1;
            //股东优惠处理（同营种只能优惠一次，自然年内最大优惠4次）
            if($discount == 1 && $discount_money > 0){
                $start_time = strtotime(date('Y-01-01', time()));
                $end_time = strtotime(date('Y-12-31 23:59:59', time()));
                //本营种使用减免情况（需求不考虑跨年，同营种只能使用一次）
                $discount_record = FamilyActivityOrderDiscountModel::getActivityPartnerDiscountByActivityIdAndUserId($activity_id, $user_id);
                //自然年内已使用减免次数
                $use_discount = FamilyActivityPartnerDiscountModel::getCurrentYearUseByUserId($user_id, $start_time, $end_time);
                //本营种无已使用记录，且自然年内未达到最大减免使用次数，则进行减免
                if(empty($discount_record) && $use_discount < ConstLib::FAMILY_ACTIVITY_PARTNER_MAX_DISCOUNT){
                    $is_partner_discount = 1;
                    $money = $money - $discount_money;
                }else{
                    $discount_money = 0;
                }
            }
        }

        //储值卡余额
        $amount = (new UserPartnerModel())->getOneData($user_id, 1, 'amount');
        if($amount > 0 && $amount >= $money){  //储值卡支付
            $pay_way = 3;
            $pay_partner = $money;
        }else if($amount > 0){  //混合支付
            $pay_way = 1;
            $pay_partner = $amount;
            $pay_wx = $money - $amount;
        }else{
            $pay_way = 2;
            $pay_wx = $money;
        }

        //股东微信支付专享6折优惠
        if($is_partner == 1 && $pay_wx > 0){
            $wx_discount = $pay_wx * 0.6;
            $pay_wx = $pay_wx - $wx_discount;
        }

        return [
            'money' => $money,
            'pay_way' => $pay_way,
            'pay_partner' => $pay_partner,
            'pay_wx' => $pay_wx,
            'is_partner' => $is_partner,
            'is_partner_discount' => $is_partner_discount,
            'discount_money' => $discount_money,
            'wx_discount' => $wx_discount
        ];
    }


    /**
     * 验证数据
     * @param int $user_id 用户ID
     * @param array $params
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws Exception
     * @author yangliang
     * @date 2021/4/23 10:52
     */
    private function checkApply(int $user_id, array $params): array
    {
        $activity = FamilyActivityModel::getActivityInfoById($params['activity_id']);
        if(empty($activity)){
            throw new Exception('营种不存在');
        }

        if($activity['is_can_buy'] == 0){
            throw new Exception('营种暂未开售');
        }

        $period = FamilyActivityCampPeriodModel::getByActivityIdAndPeriodId($params['activity_id'], $params['period_id']);
        if(empty($period)){
            throw new Exception('营期不存在');
        }

        if(time() > $period['deadline']){
            throw new Exception('报名已截止');
        }

        //处理营员信息
        $users_credentials = explode(',', $params['users_credentials_ids']);
        $users_credentials_info = UsersCredentialsInfoModel::getByUserIdAndIds($user_id, $users_credentials);
        if(empty($users_credentials_info)){
            throw new Exception('请选择营员');
        }

        $adult_num = 0;  //订单成人数
        $child_num = 0;  //订单儿童数
        foreach ($users_credentials_info as $uv){
            if(!in_array($uv['id'], $users_credentials)){
                throw new Exception('营员信息不存在，请核对');
            }
            if($uv['adult_stock'] == 1){
                $adult_num++;
            }else if($uv['adult_stock'] == 2){
                $child_num++;
            }
        }


        if($period['adult_stock'] <= 0 && $period['child_stock'] <= 0){
            throw new Exception('该营期库存不足，请选择其他营期');
        }else if($period['adult_stock'] < $adult_num){
            throw new Exception(sprintf('该营期仅剩%d个成人库存，请选择其他营期或联系客服处理', ($adult_num - $period['adult_stock']) ?? 0));
        }else if($period['child_stock'] < $child_num){
            throw new Exception(sprintf('该营期仅剩%d个儿童库存，请选择其他营期或联系客服处理', ($child_num - $period['child_stock']) ?? 0));
        }

        //存在邀请码，绑定邀请关系
        if(!empty($params['invite_code'])){
            $business_user = FamilyActivityBusinessModel::getByCode($params['invite_code']);
            if(empty($business_user) || $business_user['is_can_use'] == 0){
                throw new Exception('邀请码无效');
            }

            //绑定关系
            (new FamilyActivityService())->bindRelation($business_user['user_id'], $user_id);
        }

        return [$activity, $period, $users_credentials_info, $adult_num, $child_num];
    }


    /**
     * 提交订单处理股东业务
     * 1、若订单用户为股东，且使用了满减优惠，创建满减优惠记录
     * 2、若订单用户为股东，且使用微信支付享受6折优惠，创建微信折扣优惠记录
     * @param int $user_id  用户ID
     * @param array $pay_data  支付信息
     * @param int $time_stamp  时间戳
     * @param int $activity_id  营种ID
     * @param int $order_id  订单ID
     * @throws Exception
     * @author yangliang
     * @date 2021/4/23 15:14
     */
    private function partnerProcess(int $user_id, array $pay_data, int $time_stamp, int $activity_id, int $order_id): void
    {
        $discount_record = [];
        //记录股东满减优惠信息
        if($pay_data['is_partner_discount'] == 1){
            $discount_record[] = [
                'family_activity_order_id' => $order_id,
                'family_activity_id' => $activity_id,
                'user_id' => $user_id,
                'type' => 1,
                'money' => $pay_data['discount_money'],
                'create_time' => $time_stamp,
                'update_time' => $time_stamp
            ];
        }

        //记录股东微信折扣
        if($pay_data['wx_discount'] > 0){
            $discount_record[] = [
                'family_activity_order_id' => $order_id,
                'family_activity_id' => $activity_id,
                'user_id' => $user_id,
                'type' => 2,
                'money' => $pay_data['wx_discount'],
                'create_time' => $time_stamp,
                'update_time' => $time_stamp
            ];
        }

        if(!empty($discount_record) && !FamilyActivityOrderDiscountModel::insertAll($discount_record)){
            throw new Exception('股东优惠信息记录失败');
        }
    }


    /**
     * 取消订单
     * 1、验证订单以及状态
     * 2、修改订单状态为已取消
     * 3、回滚营期库存、回滚用户储值卡金额、删除订单优惠记录
     * @param int $user_id  用户ID
     * @param int $order_id  订单ID
     * @return array
     * @author yangliang
     * @date 2021/4/23 17:38
     */
    public function cancelOrder(int $user_id, int $order_id): array
    {
        $order = FamilyActivityOrderModel::getById($order_id);
        if(empty($order) || $order['user_id'] != $user_id){
            return $this->error(100, '订单信息不存在');
        }

        if($order['status'] != 0){
            return $this->error(100, '订单状态异常');
        }

        Db::startTrans();
        try {
            //更新订单状态为已取消
            $order_res = FamilyActivityOrderModel::where('id', $order_id)->where('status', 0)
                ->update(['status' => -1, 'update_time' => time()]);
            if(!$order_res){
                throw new Exception('订单取消失败');
            }

            //回滚库存、回滚储值卡金额、删除订单优惠记录
            $log_note = sprintf('取消亲子活动产品订单，订单ID：%d', $order_id);
            $this->backStock($order_id, $user_id, (float)$order['pay_partner'], (int)$order['is_partner_pay'], (int)$order['is_partner_discount'], $log_note);

            //记录订单日志
            self::addOrderLog($user_id, $order_id, '取消订单');
        }catch (Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '取消成功');
    }


    /**
     * 回滚库存、回滚储值卡金额、删除订单优惠记录
     * 回滚库存：
     *     因订单营员与营期绑定，订单支持多营期，根据营员类型以及营期分类进行库存回滚
     * 回滚储值卡金额：
     *     订单需要使用储值卡支付且已经支付储值卡金额进行回滚
     * 删除订单优惠记录：
     *     下单用户是股东身份，订单创建时使用了优惠（例：股东满减、微信支付折扣）
     * @param int $order_id 订单ID
     * @param int $user_id 用户ID
     * @param float $pay_partner 储值卡支付金额
     * @param int $is_partner_pay 储值卡是否已支付  0-未支付  1-已支付
     * @param int $is_partner_discount 是否有股东优惠  0-否  1-是
     * @param string $log_note 回滚储值卡金额日志说明
     * @throws Exception
     * @author yangliang
     * @date 2021/4/25 11:28
     */
    private function backStock(int $order_id, int $user_id, float $pay_partner, int $is_partner_pay, int $is_partner_discount, string $log_note = ''): void
    {
        //原订单成人营员数
        $order_adult = FamilyActivityOrderUsersModel::getCountUserByOrderIdAndType($order_id, 1);
        if(!empty($order_adult)){
            foreach ($order_adult as $av){
                //释放原营期成人库存
                $period_res = FamilyActivityCampPeriodModel::where('id', $av['family_activity_camp_period_id'])
                    ->inc('adult_stock', $av['num'])
                    ->update(['update_time' => time()]);
                if(!$period_res){
                    throw new Exception('营期成人库存还原失败');
                }
            }
        }
        //原订单儿童营员数
        $order_child = FamilyActivityOrderUsersModel::getCountUserByOrderIdAndType($order_id, 2);
        if(!empty($order_child)){
            foreach ($order_child as $cv){
                //释放原营期成人库存
                $period_res = FamilyActivityCampPeriodModel::where('id', $cv['family_activity_camp_period_id'])
                    ->inc('child_stock', $cv['num'])
                    ->update(['update_time' => time()]);
                if(!$period_res){
                    throw new Exception('营期儿童库存还原失败');
                }
            }
        }

        //还原储值卡金额
        if($pay_partner > 0 && $is_partner_pay == 1){
            $partner = (new UserPartnerModel())->getOneData($user_id);
            $partner_res = UserPartnerModel::where('user_id', $user_id)->inc('amount', $pay_partner)->update(['update_time' => time()]);
            $partner_log = PartnerLogModel::create([
                'user_id' => $user_id,
                'partner_sn' => $partner['partner_sn'],
                'amount' => $pay_partner,
                'types' => 1,
                'note' => $log_note,
                'status' => 0,
                'create_time' => time(),
                'update_time' => time()
            ]);
            if(!$partner_res || !$partner_log){
                throw new Exception('储值卡金额还原失败');
            }
        }

        //处理订单优惠信息
        if($is_partner_discount == 1){
            $discount_res = FamilyActivityOrderDiscountModel::where('family_activity_order_id', $order_id)->update(['is_delete' => 1, 'update_time' => time()]);
            if(!$discount_res){
                throw new Exception('订单优惠信息处理失败');
            }
        }
    }



    /**
     * 获取用户待支付订单数
     * @param int $user_id  用户ID
     * @return array
     * @author yangliang
     * @date 2021/4/15 11:01
     */
    public function getUnPay(int $user_id): array
    {
        return $this->success(200, '获取成功', FamilyActivityOrderModel::getCountByUserIdAndStatus($user_id, ConstLib::FAMILY_ACTIVITY_ORDER_UNPAY));
    }


    /**
     * 发起支付
     * 1、验证订单以及状态
     * 2、支付方式为储值卡，且储值卡支付状态未支付，进行储值卡支付，并返回支付成功（状态码：208，由前端进行判断直接展示支付成功页面）
     * 3、支付方式为混合支付，且储值卡支付状态未支付，进行储值卡支付.
     * 4、进行微信支付下单并返回微信支付所需数据
     * @param array $params
     * @return array
     * @author: yangliang
     * @date: 2021/4/26 17:31
     */
    public function getPayment(array $params): array
    {
        if(empty($params['order_id'])){
            return $this->error(100, '订单ID不能为空');
        }

        $order = FamilyActivityOrderModel::getById((int)$params['order_id']);
        if(empty($order)){
            return $this->error(100, '订单不存在');
        }

        if($order['status'] != 0){
            return $this->error(100, '订单状态异常');
        }

        Db::startTrans();
        try {
            $pay_money = $order['pay_wx'] + $order['pay_partner'];
            $activity = FamilyActivityModel::getActivityInfoById($order['family_activity_id']);
            //储值卡支付
            if($order['pay_way'] == 3 && $order['pay_partner'] > 0 && $order['is_partner_pay'] == 0){
                $this->partnerPay((int)$order['user_id'], (float)$order['pay_partner'], (int)$order['id'], $activity['title']);

                //支付回调业务处理
                self::payCallback($order['order_sn'], (float)$order['pay_partner']);

                Db::commit();
                return $this->success(208, '支付成功');
            }elseif ($order['pay_way'] == 1 && $order['pay_partner'] > 0 && $order['is_partner_pay'] == 0){  //混合支付-储值卡支付
                $this->partnerPay((int)$order['user_id'], (float)$order['pay_partner'], (int)$order['id'], $activity['title']);
            }


            $log['order_id'] = $order['id'];
            $log['order_type'] = 50;  //支付类型，亲子活动
            $log['order_amount'] = $pay_money;
            $log['out_trade_no'] = $order['order_sn'];
            $payLogResult = (new LogService())->payLog($log, 0, $order['user_id']);
            if (!$payLogResult) {
                throw new ApiException('支付日志记录错误');
            }
            $payData['body'] = '睿鼎少儿亲子活动';
            $payData['attach'] = $params['type'];
            $payData['openid'] = $params['openid'];
            $payData['trade_type'] = 'JSAPI';
            $payData['out_trade_no'] = $order['order_sn'];
            $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $pay_money * 100;
            $pay = Pay::getInstance();
            $result = $pay->unifiedorder($payData);
            if (!$result) {
                throw new ApiException($result['message']);
            }
        }catch (ApiException $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '操作成功', json_encode($result));
    }


    /**
     * 储值卡支付
     * 扣除用户储值卡余额、记录储值卡变动日志
     * @param int $user_id  用户ID
     * @param float $pay_partner  储值卡支付金额
     * @param int $order_id  订单ID
     * @param string $activity_title  活动名称
     * @throws ApiException
     * @author: yangliang
     * @date: 2021/4/26 18:01
     */
    private function partnerPay(int $user_id, float $pay_partner, int $order_id, string $activity_title): void
    {
        $user_partner = (new UserPartnerModel())->getOneData($user_id);
        if($pay_partner > $user_partner['amount']){
            throw new ApiException('储值卡余额不足');
        }

        //扣除储值卡金额
        $partner_res = UserPartnerModel::where('user_id', $user_id)->dec('amount', $pay_partner)->update(['update_time' => $update_time ?? time()]);
        //记录储值卡日志
        $partner_log = PartnerLogModel::create([
            'user_id' => $user_id,
            'partner_sn' => $user_partner['partner_sn'],
            'amount' => $pay_partner,
            'types' => 0,
            'note' => sprintf('购买亲子活动产品《%s》，订单ID：%d', $activity_title, $order_id),
            'status' => 0,
            'create_time' => time(),
            'update_time' => time()
        ]);

        //更新订单储值卡已支付状态
        $partner_pay = FamilyActivityOrderModel::where('id', $order_id)->update(['is_partner_pay' => 1, 'update_time' => time()]);

        if (!$partner_res || !$partner_log || !$partner_pay) {
            throw new ApiException('储值卡金额扣除失败');
        }
    }


    /**
     * 支付成功后回调业务
     * 1、验证订单以及状态
     * 2、更新订单状态为已支付，支付时间
     * 3、若股东使用优惠，创建使用优惠记录
     * 4、记录订单日志
     * 5、发送订单支付成功短信
     * @param string $outTradeNo 交易号
     * @param float $total_fee 支付金额
     * @throws ApiException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws Exception
     * @author: yangliang
     * @date: 2021/4/27 14:35
     */
    public function payCallback(string $outTradeNo, float $total_fee): void
    {
        $order = FamilyActivityOrderModel::getByOrderSn($outTradeNo);
        if (empty($order)) {
            throw new ApiException(sprintf('亲子活动订单信息不存在,out_trade_no:%s', $outTradeNo), $outTradeNo);
        }

        if ($order['status'] != 0) {
            throw new ApiException(sprintf('亲子活动订单状态异常,out_trade_no:%s', $outTradeNo), $outTradeNo);
        }

        //更新订单状态为已支付
        $order_res = FamilyActivityOrderModel::where('order_sn', $outTradeNo)->update(['status' => 1, 'pay_time' => time()]);
        if(!$order_res){
            throw new ApiException(sprintf('亲子活动订单状态更新失败，out_trade_no:%s', $outTradeNo), $outTradeNo);
        }

        $activity = FamilyActivityModel::getActivityInfoById($order['family_activity_id']);

        //记录股东使用优惠次数
        if($order['is_partner_discount'] == 1){
            $discount_res = FamilyActivityPartnerDiscountModel::create([
                'user_id' => $order['user_id'],
                'family_activity_order_id' => $order['id'],
                'note' => sprintf('购买产品《%s》，订单ID：%d', $activity['title'], $order['id']),
                'create_time' => time(),
                'update_time' => time()
            ]);

            if(!$discount_res){
                throw new ApiException(sprintf('亲子活动股东优惠次数记录失败，out_trade_no:%s', $outTradeNo), $outTradeNo);
            }
        }

        //记录订单日志
        self::addOrderLog((int)$order['user_id'], (int)$order['id'], sprintf('支付订单，支付方式：%s，支付金额：¥%.2f', FamilyActivityOrderModel::$pay_way[$order['pay_way']], $total_fee));

        $order_users = FamilyActivityOrderUsersModel::getByOrderId((int)$order['id']);
        $users_names = '';
        if(!empty($order_users)){
            $users_names = implode(',', array_column($order_users, 'name'));
        }

        $pay_wx = (float)$order['pay_wx'];
        //手续费
        $service_money = ($pay_wx > 0) ? sprintf('%.2f', $pay_wx * 0.006) : 0;
        //记录资金流水
        $flow_res = FamilyActivityCashFlowModel::create([
            'user_id' => $order['user_id'],
            'family_activity_id' => $order['family_activity_id'],
            'family_activity_order_sn' => $order['order_sn'],
            'sn' => $order['order_sn'],
            'pay_way' => $order['pay_way'],
            'family_activity_order_users_names' => $users_names,
            'pay_type' => 1,
            'money' => $order['money'],
            'wx_money' => $order['pay_wx'],
            'partner_money' => $order['pay_partner'],
            'service_money' => $service_money,
            'settled_money' => ($pay_wx > 0) ? ($pay_wx - $service_money) : 0,
            'create_time' => time(),
            'update_time' => time()
        ]);
        if (!$flow_res) {
            throw new ApiException(sprintf('亲子活动流水记录失败，out_trade_no:%s', $outTradeNo), $outTradeNo);
        }

        //支付成功短信推送
        $sms_data = [
            'type' => 'family_order',
            'order_id' => $order['id'],
            'mobile' => $order['contact_phone'],
            'template_code' => config('self_config.activity_order_pay_template_code'),
            'params' => [
                'name' => $activity['title'],
                'teacher' => config('self_config.activity_order_pay_template_teacher'),
                'number' => config('self_config.activity_order_pay_template_number'),
            ]
        ];
        Queue::push(SendSmsJob::class, $sms_data, ConstLib::FAMILY_ACTIVITY_ORDER_QUEUE_NAME);
    }


    /**
     * 记录订单日志
     * @param int $user_id  用户ID
     * @param int $order_id  订单ID
     * @param string $log_info  操作描述
     * @throws Exception
     * @author: yangliang
     * @date: 2021/4/27 16:35
     */
    private function addOrderLog(int $user_id, int $order_id, string $log_info): void
    {
        //记录订单日志
        $order_log = FamilyActivityOrderLogsModel::create([
            'user_id' => $user_id,
            'type' => 2,
            'family_activity_order_id' => $order_id,
            'log_info' => $log_info,
            'create_time' => time(),
        ]);

        if(!$order_log){
            throw new Exception('订单日志记录失败');
        }
    }


    /**
     * 获取退款订单状态
     * @param int $order_id  订单ID
     * @param int $user_num  订单报名人数
     * @return int[]
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author: yangliang
     * @date: 2021/4/28 11:36
     */
    private function getRefundOrderStatus(int $order_id, int $user_num): array
    {
        $refund_status = -1;  //退款订单状态  -1无退款信息  0退款中   1已退款
        $refund_type = -1;  //退款订单类型  -1退款退信息  0部分退款  1全部退款
        //优先展示退款中状态
        $refunding = FamilyActivityOrderRefundModel::getByOrderIdAndStatus($order_id, 0);
        if(!empty($refunding)){
            $refund_status = 0;  //退款中
        }else{
            //已退款
            $refunded = FamilyActivityOrderRefundModel::getByOrderIdAndStatus($order_id, 2);
            if(!empty($refunded)){
                $refund_status = 2;  //已退款
            }
        }

        //统计订单退营人数（未拒绝退款人数与报名任务不同则代表部分退款，否则全部退款）
        $refund_info = FamilyActivityOrderRefundModel::getNotRejectedByOrderId($order_id);
        if(!empty($refund_info)){
            $refund_users = 0;
            foreach ($refund_info as $rv){
                $refund_users += count(explode(',', $rv['family_activity_order_users_ids']));
            }
            if($user_num != $refund_users){
                $refund_type = 0;  //部分退款
            }else{
                $refund_type = 1;  //全部退款
            }
        }

        return [$refund_status, $refund_type];
    }
}