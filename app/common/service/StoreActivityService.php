<?php
/**
 * 门店活动相关
 * @author yangliang
 * @date 2020/11/3 14:02
 */
declare(strict_types=1);

namespace app\common\service;


use app\common\ConstCode;
use app\common\model\PayLogModel;
use app\common\model\PickUpModel;
use app\common\model\StoreActivityInfoAccessListModel;
use app\common\model\StoreActivityInfoModel;
use app\common\model\StoreActivityOrderModel;
use app\common\model\StoreActivitySignUpLogsModel;
use app\common\model\StoreActivitySignUpModel;
use app\common\model\StoresModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use think\facade\Db;
use think\Model;
use wechat\Pay;

class StoreActivityService extends ComService
{
    use CacheTrait;

    /**获取本周门店活动
     * @param int $user_id
     * @param $data
     * @return array
     * @author Poison
     * @date 2021/3/3 10:02 上午
     */

    public function getList(int $userId, $data): array
    {
        $user = (new UserModel())->getOneUser($userId);
        if(!$user){
            return $this->error(100, '未获取到用户信息');
        }
        switch ($data['type']) {
            case 1:
                return $this->store($userId, (int)$data['page'], $user, 'sal.end_time > ' . time(), $data['store_id']);
                break;
            case 2:
                return $this->store($userId, (int)$data['page'], $user, 'sal.end_time < ' . time(), $data['store_id'],1);
                break;
            case 3:
                return $this->thisStoreActivity($userId, (int)$data['page'], $user, $data['store_id']);
                break;
            default:
                return $this->error(100, '参数不正确');
                break;
        }


    }

    /**门店活动首页内容
     * @param int $userId
     * @param int $page
     * @param $user
     * @param $where
     * @param int $storeId
     * @return array
     * @author Poison
     * @date 2021/3/3 10:12 上午
     */
    protected function store(int $userId, int $page, $user, $where,$storeId = 0,$isEnd = 0)
    {
        $startTime = mktime(0, 0, 0, (int)date('m'), (int)(date('d') - date('w') + 1), (int)date('Y'));
        $endTime = mktime(23, 59, 59, (int)date('m'), (int)(date('d') - date('w') + 7), (int)date('Y'));
        $lng = $user['longitude'];
        $lat = $user['latitude'];
        //用户不存在经纬度，取所属门店经纬度
        if (empty($lng) || empty($lat)) {
            if ($user['store_id'] > 0) {
                $store = StoresModel::getById($user['store_id']);
                $lng = $store['longitude'];
                $lat = $store['latitude'];
            } else {    //无门店则获取所有门店
                $lng = '';
                $lat = '';
            }
        }
        $res = StoreActivityInfoModel::getList($startTime, $endTime, $lng, $lat, $where, $page,$storeId,$isEnd);
        if (!empty($res)) {
            $res = self::getContent($res, $userId);
        }

        return $this->success(200, '请求成功', $res);
    }

    /**查询与自己相关的
     * @param int $userId
     * @param int $page
     * @param $user
     * @return array
     * @author Poison
     * @date 2021/3/3 12:00 下午
     */
    private function thisStoreActivity(int $userId, int $page, $user,$storeId)
    {
        $lng = $user['longitude'] ?? '0.0';
        $lat = $user['latitude'] ?? '0.0';
        $res = StoreActivityOrderModel::thisList($page, $userId, $lng, $lat,$storeId);
        if (!empty($res)) {
            $res = self::getContent($res, $userId, 1);
        }
        return $this->success(200, '请求成功', $res);
    }

    /**通用方法 数据渲染
     * @param $data
     * @param int $userId
     * @param int $type
     * @author Poison
     * @date 2021/3/3 10:12 上午
     */
    private function getContent($data, int $userId, $type = 0)
    {
        $signOrderModel = new StoreActivityOrderModel();
        foreach ($data as &$v) {
            if ($type == 0) {
                $oneData = StoreActivityOrderModel::getOrderByActivityId($userId,$v['id']);
                $v['out_trade_no'] = isset($oneData['out_trade_no']) ? $oneData['out_trade_no'] : '';
                $signUpData = $signOrderModel->where(['activity_id' => $v['id'], 'user_id' => $userId, 'out_trade_no' => isset($oneData['out_trade_no']) ? $oneData['out_trade_no'] : '', 'is_delete' => 0])->order('id', 'DESC')->findOrEmpty()->toArray();
            } else {
                $signUpData =  $signOrderModel->where(['activity_id' => $v['id'], 'user_id' => $userId, 'out_trade_no' => isset($v['out_trade_no']) ? $v['out_trade_no'] : '', 'is_delete' => 0])->order('id', 'DESC')->findOrEmpty()->toArray();
            }
            $v['status'] = 4;//没有信息
            if($signUpData){
                $v['status'] = $signUpData['order_status'];
                if ($signUpData['order_status'] == 1 && $signUpData['pay_money'] > 0) {
                    $v['status'] = 3;
                }
            }

            $v['start_time'] = date('m月d日 H:i:s', $v['start_time']);
            $v['end_time'] = date('m月d日 H:i:s', $v['end_time']);
            $v['cover'] = config('ali_config.domain') . $v['cover'];
            $v['info'] = $v['info'] ? json_decode($v['info'], true) : '';
            $v['activity_img'] = config('ali_config.domain') . $v['activity_img'];

        }
        return $data;

    }

    /**获取门店信息
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/11/20 3:56 下午
     */
    public function getStoreList(): array
    {
        $resStoresData = (new StoresModel())->field('id,name')->where(['store_type' => 1, 'is_show' => 1])->select()->toArray();
        $resPickUpData = (new PickUpModel())->field('pickup_id as id,pickup_name as name')->where(['is_build' => 1, 'is_lock' => 0])->whereIn('is_collection', [5, 6])->select()->toArray();
        $data = array_merge($resStoresData, $resPickUpData);
        return $this->success(200, '获取成功', $data);
    }

    /**添加用户到活动
     * @param array $params
     * @param int $userId
     * @param int $activityId
     * @param string $channel
     * @return array
     * @throws \app\common\exception\ApiException
     * @author Poison
     * @date 2021/7/13 5:11 下午
     */
    public function addActivity(array $params,int $userId,int $activityId,string $channel = ''): array
    {
        $orderData = StoreActivityOrderModel::getOrderByActivityId($userId,$activityId);
        if(isset($orderData['order_status']) && $orderData['order_status'] == 1){
            return errorArray('您已经支付过该订单了');
        }
        $ActivityData = (new StoreActivityInfoModel())->where('id', $activityId)->findOrEmpty()->toArray();
        if (!$ActivityData) {
            return $this->error(100, '活动不存在');
        }
        if ($ActivityData['is_start'] != 1) {
            return $this->error(100, '活动未发布');
        }
        if ($ActivityData['is_delete'] != 1) {
            return $this->error(100, '活动已被管理员删除');
        }
        if ($ActivityData['end_time'] < time()) {
            return $this->error(100, '活动已结束！');
        }
        if ($ActivityData['sign_up_end_time'] < time()) {
            return $this->error(100, '活动报名时间已结束');
        }
        if($ActivityData['surplus_num'] <= 0){
            return $this->error(ConstCode::ALERT_CODE,'报名失败，该活动已报满，您可参加其他活动或联系客服处理。');
        }
        foreach ($params as $v) {
            if ($ActivityData['age_min'] > $v['child_age'] || $ActivityData['age_max'] < $v['child_age']) {
                return $this->error(100, sprintf('报名失败，该活动年龄限制为 最大年龄 %s 最小年龄 %s',$ActivityData['age_max'],$ActivityData['age_min']));
            }
        }

        //查询门店信息
        $name = (new StoresModel())->where('id', $ActivityData['store_id'])->value('name');
        $outTradeNo = 'RD' . date('YmdHis') . 'STORE' . rand(100, 999);
        Db::startTrans();
        if($orderData){
            StoreActivityOrderModel::updateOrder($userId, $orderData['activity_id']);//修改数据为失效
            (new StoreActivitySignUpModel())->where('order_id',$orderData['id'])->update(['is_delete'=>1,'update_time'=>time()]);
        }
        $userCard =  (new UserCardsModel())->getOneCards($userId);
        //TODO 2021-9-7 判断是否是系列
        $isOrder = false;
        if ($userCard) {
            if ($ActivityData['is_series']) {
                $storeActivityData = StoreActivityOrderModel::where(['user_id' => $userId, 'series_id' => $ActivityData['series_id'], 'order_status' => 1])->select();
                if ($storeActivityData) {
                    foreach ($storeActivityData as $v) {
                        $seriesNum = $ActivityData['series_num'] - $v['series_num'];
                        if (!$isOrder) {
                            if ($seriesNum == -1 || $seriesNum == 1) {
                                $isOrder = true;
                                $ActivityData['registration_fee'] = $ActivityData['series_continuity_fee'];
                                break;
                            }
                        }
                    }
                }
            }
            //TODO 2021-9-7 如果不是系列 进行会员价格判断
            if (!$isOrder) {
                $ActivityData['registration_fee'] = $ActivityData['card_registration_fee'];
            }
        }
        $orderCount = count($params);
        //创建基础订单
        $sumPrice = $orderCount * ($ActivityData['registration_fee'] + $ActivityData['deposit']);
        $orderId = (new StoreActivityOrderModel())->addOrder([
            'user_id' => $userId,
            'activity_id' => $activityId,
            'money' => $sumPrice,
            'pay_money' => $sumPrice,
            'out_trade_no' => $outTradeNo,
            'activity_money' => $orderCount * $ActivityData['registration_fee'],
            'deposit_money' => $orderCount * $ActivityData['deposit'],
            'order_status' => $sumPrice == 0 ? 1 : 0,
            'pay_time' => $sumPrice == 0 ? time() : 0,
            'channel' => $channel,
            'series_id' => $ActivityData['series_id'],
            'series_num' => $ActivityData['series_num'],
            'order_user_num' => $orderCount
        ]);
        if (!$orderId) {
            Db::rollback();
            return errorArray('创建订单失败');
        }

        foreach ($params as $v){
            $insertData = [];
            $logInfo = '孩子：' . $v['child_name'] . '报名成功';
            if ($ActivityData['is_price'] == 1) {
                $logInfo .= ',未支付';
            }
            $insertData['create_time'] = time();
            $insertData['update_time'] = time();
            $insertData['user_id'] = $userId;
            $insertData['activity_id'] = $activityId;
            $insertData['child_name'] = $v['child_name'];
            $insertData['child_sex'] = $v['child_sex'];
            $insertData['child_age'] = $v['child_age'];
            $insertData['order_id'] = $orderId;
            $resStoreActivitySignUpId = (new StoreActivitySignUpModel())->insert($insertData, TRUE);
            if (!$resStoreActivitySignUpId) {
                Db::rollback();
                return $this->error(100, '添加失败');
            }
            StoreActivitySignUpLogsModel::addLog($userId, $logInfo, $resStoreActivitySignUpId);
        }
        Db::commit();
        if ($sumPrice > 0) {//如果需要支付 准备好参数
            $openid = (new UserModel())->where('user_id', $userId)->value('smart_openid');
            if (!$openid) {
                return $this->error(100, '请重新刷新用户信息');
            }
            //准备支付参数
            $payData['body'] = $ActivityData['activity_name'].'_' . mb_substr($name,0,2,'utf-8');
            $payData['attach'] = 'activity';
            $payData['openid'] = $openid;
            $payData['trade_type'] = 'JSAPI';
            $payData['out_trade_no'] = $outTradeNo;
            $payData['total_fee'] = env('server_env') === 'dev' ? 1 : $sumPrice * 100;
            $pay = Pay::getInstance();
            $result = $pay->unifiedorder($payData);
            if (!$result) {
                return $this->error(0, $result['message']);
            }
            // 支付日志
            $payLogData['order_id'] = $activityId;
            $payLogData['order_type'] = 9;// 活动
            $payLogData['order_amount'] = $sumPrice;
            $payLogData['out_trade_no'] = $payData['out_trade_no'];
            $payLogData['user_id'] = $userId;
            $payLogData['add_time'] = time();
            $payLogResult = (new PayLogModel())->insert($payLogData);
            if (!$payLogResult) {
                return $this->error(0, "支付日志记录错误");
            }
            //发起支付
            return $this->success(201, '操作成功', ['pay' => json_encode($result), 'order_id' => $orderId]);
        }
        $params['order_id'] = $orderId;
        //TODO 2021-9-22 降低数量
        (new StoreActivityInfoModel())->where('id',$activityId)->dec('surplus_num',$orderCount)->update();
        return successArray($params);
    }

    public function checkStoreActivityPrice(int $activityId, int $userId)
    {
        $activityData = StoreActivityInfoModel::getDataById($activityId);
        //查询用户该期的报名情况
        //查询当前用户报名的期数 却是是否按期数走

    }

    /**获取是否支付参数
     * @param array $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/7/13 2:58 下午
     */
    public function getActivityList(array $params): array
    {
        $userId = (int)$params['user_id'];
        $orderId = (int)$params['order_id'];
        $infoData = StoreActivityInfoModel::getContentById((int)$params['activity_id']);
        if (!isset($infoData['is_price'])) {
            return $this->error(100, '设置异常');
        }
        $orderData = StoreActivityOrderModel::getOrderDataByUserId($userId, $orderId);
        if (!$orderData) {
            $isOrder = false;
            $userCard =  (new UserCardsModel())->getOneCards($userId);
            if ($userCard) {
                if ($infoData['is_series']) {
                    //2021-9-7 修改判断之前报名的期数
                    $storeActivityData = StoreActivityOrderModel::where(['user_id' => $userId, 'series_id' => $infoData['series_id'], 'order_status' => 1])->select();
                    if ($storeActivityData) {
                        foreach ($storeActivityData as $v) {
                            $seriesNum = $infoData['series_num'] - $v['series_num'];
                            if (!$isOrder) {
                                if ($seriesNum == -1 || $seriesNum == 1) {
                                    $isOrder = true;
                                    $infoData['registration_fee'] = $infoData['series_continuity_fee'];
                                    break;
                                }
                            }
                        }
                    }
                }
                if (!$isOrder) {
                    $infoData['registration_fee'] = $infoData['card_registration_fee'];
                }
            }

            $orderData['order_status'] = 4;
            $orderData['activity_info'] = $infoData;
            return successArray($orderData);
        }
        $infoData['registration_fee'] = $orderData['activity_money'];
        $infoData['deposit'] = $orderData['deposit_money'];
        $orderData['activity_info'] = $infoData;
        //开始准备数据
        $signUp = (new StoreActivitySignUpModel())->where(['order_id' => $orderData['id'], 'is_delete' => 0])->select()->toArray();
        foreach ($signUp as $k => $v) {
            $orderData['list'][$k]['child_name'] = $v['child_name'];
            $orderData['list'][$k]['child_sex'] = $v['child_sex'];
            $orderData['list'][$k]['child_age'] = $v['child_age'];
        }

        if($orderData['order_status'] == 1 && $orderData['pay_money'] > 0){
            $orderData['order_status'] = 3;
        }
        return successArray($orderData);

    }

    /**获取门店信息详情
     * @param $data
     * @return array
     * @author Poison
     * @date 2021/3/2 2:17 下午
     */
    public function activityInfo($data)
    {
        $activityId = $data['activity_id'];
        $activityData = (new StoreActivityInfoModel())->where('id', $activityId)->where(['is_start' => 1])->findOrEmpty()->toArray();
        if (!$activityData) {
            return $this->error(100, '未查询到该活动');
        }
        $isPay = 1;
        $lowStore = StoreActivityOrderModel::getOrderByActivityId($data['user_id'],$activityId);
        $activityData['order'] = $lowStore;
        $activityData['order']['order_status'] = 4;
        if(isset($lowStore) && $lowStore){
            $data['order_id'] = $lowStore['id'];
            $activityData['order']['order_status'] = self::getActivityList($data)['data']['order_status'];
        }
        if($activityData['surplus_num'] <= 0){
            $isPay = 0;
        }
        $activityData['name'] = (new StoresModel())->where('id', $activityData['store_id'])->value('name');
        $activityData['activity_img'] = config('ali_config.domain') . $activityData['activity_img'];
        $activityData['info'] = $activityData['info'] ? json_decode($activityData['info'], true) : '';
        $activityData['is_pay'] = $isPay;
        return $this->success(200, '', $activityData);
    }

    /**获取日志
     * @param $type
     * @param $activityId
     * @param $outTradeNo
     * @param $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/3/3 5:11 下午
     */
    public function getLog($type, $activityId, $userId, $outTradeNo = '')
    {
        $where['ao.activity_id'] = $activityId;
        $where['ao.user_id'] = $userId;
        $where['ao.is_delete'] = 0;
        $where['sa.is_delete'] = 0;
        if ($outTradeNo) {
            $where['ao.id'] = $outTradeNo;
        }
        $signUpData = (new StoreActivitySignUpModel())->getDataList($where);
        $name = (new StoreActivityInfoModel())->where('id', $activityId)->value('activity_name');
        $array = [];
        $sum = $signUpData[0]['activity_money'];
        if($type == 2){
            $sum = $signUpData[0]['deposit_money'];
        }
        foreach ($signUpData as $v) {
            if ($type == 1) {
                if ($v['order_status'] > 0) {
                    $content['content'] = '“' . $name . '”支付报名费用';
                    $content['price'] = sprintf('%01.2f',$v['activity_money'] / count($signUpData));
                    $content['time'] = date('Y-m-d H:i:s', $v['create_time']);
                    $content['status'] = 1;
                    $array[] = $content;
                }
                if ($v['is_refund_registration_fee'] == 1) {
                    $sum -= $v['refund_registration_fee_price'];
                    $content['content'] = '“' . $name . '”退回报名费';
                    $content['price'] = $v['refund_registration_fee_price'];
                    $content['time'] = date('Y-m-d H:i:s', $v['refund_registration_fee_time']);
                    $content['status'] = 2;
                    $array[] = $content;
                }
            } else {
                if ($v['order_status'] > 0 && $v['deposit_money'] > 0) {
                    $content['content'] = '“' . $name . '”支付押金';
                    $content['price'] = sprintf('%01.2f',$v['deposit_money'] / count($signUpData));
                    $content['time'] = date('Y-m-d H:i:s', $v['create_time']);
                    $content['status'] = 1;
                    $array[] = $content;
                }
                if ($v['is_refund_deposit'] == 1) {
                    $sum -= $v['refund_deposit_price'];
                    $content['content'] = '“' . $name . '”退回押金';
                    $content['price'] = $v['refund_deposit_price'];
                    $content['time'] = date('Y-m-d H:i:s', $v['refund_deposit_time']);
                    $content['status'] = 2;
                    $array[] = $content;
                }
            }
        }
        $data['list'] = $array;
        $data['sum'] = $sum;
        return self::success(200, '获取成功', $data);
    }
    public function addLog($activityId,$userId){
        $data['activity_id'] = $activityId;
        $data['user_id'] = $userId;
        $data['access_time'] = time();
        $res = (new StoreActivityInfoAccessListModel())->insert($data);
        if(!$res){
            return self::error(200,'写入失败');
        }
        return self::success(200,'写入完成');
    }

    /**获取门店信息
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/9/3 4:23 下午
     */
    public function getStoresList(){
        $endData = [];
        $endData['store_list']['直营店'] =  (new StoresModel())->field('id,name')
            ->where('store_type',1)
            ->where('is_show',1)
            ->select()->toArray();
        $endData['store_list']['社区馆'] = (new StoreActivityInfoModel())->alias('sai')->field('s.id,s.name')
            ->leftjoin('rd_stores s','sai.store_id = s.id')->where('s.store_type',2)->group('store_id')->select()->toArray();
       return successArray($endData);
    }
}