<?php


namespace app\common\service;


use app\common\model\ApplyLogModel;
use app\common\model\BusinessUserLogModel;
use app\common\model\BuyOrderLogModel;
use app\common\model\CardsModel;
use app\common\model\DepositLogModel;
use app\common\model\DepositModel;
use app\common\model\NewCardRefundDepositLogModel;
use app\common\model\OrderLogModel;
use app\common\model\OrderStockLogModel;
use app\common\model\PartnerLogModel;
use app\common\model\PayLogModel;
use app\common\model\PointsLogModel;
use app\common\model\UserCardChangeLogModel;
use app\common\model\UserCardsOperationLogsModel;
use app\common\model\UserModel;
use app\common\model\UsersLogModel;
use think\Model;

class LogService extends ComService
{
    /**订单日志创建数据
     * @param $orderInfo
     * @param $userInfo
     * @param $actionNote
     * @param $statusDesc
     * @param $actionType
     * @param int $isBuy
     * @return bool
     */
    public function orderLog($orderInfo, $userInfo, $actionNote, $statusDesc, $actionType, $isBuy = 0)
    {
        if (!$orderInfo['order_id']) {
            return false;
        }
        $orderLog['order_id'] = $orderInfo['order_id'];
        $orderLog['order_status'] = $orderInfo['order_status'];
        $orderLog['action_user'] = $userInfo['user_id'];
        $orderLog['user_name'] = $userInfo['user_name'];
        $orderLog['action_note'] = $actionNote;
        $orderLog['status_desc'] = $statusDesc;
        $orderLog['action_type'] = $actionType;
        $orderLog['log_time'] = time();
        if ($isBuy == 1) {
            $orderLog['is_admin'] = $userInfo['is_admin'] ?? 0;
            $buyOrderLog = new BuyOrderLogModel();
            return $buyOrderLog->insert($orderLog, true);
        }
        $order = new OrderLogModel();
        return $order->insert($orderLog, true);
    }

    public function stockLog($goodsInfo, $userInfo, $stockInfo, $storeId = 0, $orderSn = '')
    {
        if (!$goodsInfo['goods_id'] || !$userInfo['user_id']) {
            return false;
        }

        $stockLog['order_sn'] = $orderSn;
        $stockLog['goods_id'] = $goodsInfo['goods_id'];
        $stockLog['goods_sn'] = $goodsInfo['goods_sn'];
        $stockLog['goods_name'] = $goodsInfo['goods_name'];
        $stockLog['goods_num'] = $goodsInfo['goods_num'];
        $stockLog['user_id'] = $userInfo['user_id'];
        $stockLog['update_stock'] = $stockInfo['update_stock'];
        $stockLog['old_stock'] = $stockInfo['old_stock'];
        $stockLog['new_stock'] = $stockInfo['new_stock'];
        $stockLog['beizhu'] = $stockInfo['beizhu'];
        $stockLog['is_admin'] = isset($userInfo['is_admin']) ? $userInfo['is_admin'] : 0;
        $stockLog['store_id'] = $storeId;
        $stockLog['log_type'] = isset($stockInfo['log_type']) ? $stockInfo['log_type'] : 0;
        $stockLog['online'] = 1;// 小程序
        $stockLog['ctime'] = time();
        $result = (new OrderStockLogModel())->insert($stockLog, true);
        return $result;
    }

    /**储值卡变动
     * @param $userInfo
     * @param $partnerInfo
     * @param int $types
     * @param $note
     * @param int $status
     * @param int $storeId
     * @param int $canUse
     * @param null $pId
     * @return bool
     * @author Poison
     * @date 2020/12/19 11:58 上午
     */
    public function partnerLog($userInfo, $partnerInfo, $types = 0, $note, $status = 0, $storeId = 0, $canUse = 1, $pId = null)
    {
        if (!$userInfo['user_id']) {
            return false;
        }
        $partnerLog['user_id'] = $userInfo['user_id'];
        $partnerLog['partner_sn'] = $partnerInfo['partner_sn'];
        $partnerLog['amount'] = $partnerInfo['amount'];
        $partnerLog['types'] = $types;
        $partnerLog['note'] = $note;
        $partnerLog['status'] = $status;
        $partnerLog['store_id'] = $storeId;
        $partnerLog['admin_id'] = isset($userInfo['admin_id']) ? $userInfo['admin_id'] : 0;
        $partnerLog['can_use'] = $canUse;
        $partnerLog['create_time'] = time();
        $partnerLog['update_time'] = time();
        // 用户自己进行，储值卡充值时，赠送金额与购买金额的联系字段
        $partnerLog['p_id'] = $pId ?? 0;
        return (new PartnerLogModel())->insert($partnerLog, true);
    }

    /**支付日志
     * @param $orderInfo
     * @param int $isBuy
     * @param int $userId
     * @return bool|int|string
     * @author Poison
     * @date 2020/12/19 12:02 下午
     */
    public function payLog($orderInfo, $isBuy = 0, $userId = 0) {
        if (!$orderInfo['order_id']) {
            return false;
        }
        $payLog['order_id'] = $orderInfo['order_id'];
        $payLog['order_type'] = $orderInfo['order_type'];
        $payLog['order_amount'] = $orderInfo['order_amount'];
        $payLog['out_trade_no'] = $orderInfo['out_trade_no'];
        $payLog['is_buy'] = $isBuy;
        $payLog['add_time'] = time();
        $payLog['user_id'] = $userId ?? 0;
        return (new PayLogModel())->insert($payLog,true);
    }

    /**押金条日志
     * @param $depositId
     * @param $log
     * @param $userId
     * @return int|string
     * @author Poison
     * @date 2020/12/22 10:20 上午
     */
    public static function depositLog($depositId,$log,$userId){
        return (new DepositLogModel())->insert([
            'admin_id'=>1,
            'user_name'=>'admin',
            'deposit_id'=>$depositId,
            'info'=>$log,
            'add_time'=>time()
        ],true);
    }

    /**写入退/升/降押金记录
     * @param $userId
     * @param $applyId
     * @param $logInfo
     * @param int $status
     * @param int $isShip
     * @param int $isDeposit
     * @param int $isBook
     * @return int|string
     * @author Poison
     * @date 2020/12/22 10:52 上午
     */
    public static function applyLogAdd($userId,$applyId,$logInfo,$status = 6,$isShip = 3,$isDeposit = 1,$isBook = 0){
        return (new ApplyLogModel())->insert([
            'apply_id'=>$applyId,
            'user_id'=>$userId,
            'admin_id'=>0,
            'admin_name'=>'系统',
            'content'=>'操作',
            'add_time'=>time(),
            'loginfo'=>$logInfo,
            'status'=>$status,
            'is_book'=>$isBook,
            'is_deposit'=>$isDeposit,
            'is_ship'=>$isShip,
        ],true);
    }

    /**写入用户日志数据
     * @param $userName
     * @param $userId
     * @param $content
     * @param int $actUserType
     * @param int $actType
     * @param int $admin
     * @return int|string
     * @author Poison
     * @date 2020/12/31 2:08 下午
     */
    public static function userLog($userName,$userId,$content,$actUserType = 0,$actType = 0,$admin = 0){
        return (new UsersLogModel())->insert([
            'user_name'=>$userName,
            'user_id'=>$userId,
            'act_note'=>$content,
            'act_user_type'=>$actUserType,
            'act_type'=>$actType,
            'admin_id'=>$admin,
            'add_time'=>time(),
        ],true);
    }

    /**写入掌灯人日志
     * @param $userId
     * @param $balance
     * @return int|string
     * @author Poison
     * @date 2021/2/23 11:45 上午
     */
    public static function BusinessUserLog($userId,$balance){
        //写入日志
        return (new BusinessUserLogModel())->insert([
            'admin_id' => 0,
            'user_id' => $userId,
            'type_name' => '用户提现-余额减少 当前余额为:' . $balance,
            'create_time' => time()
        ],true);

    }

    /**积分日志写入
     * @param int $userId 用户id
     * @param int $topId 上级id
     * @param int $points 变动积分
     * @param string $pnote 备注信息
     * @param int $status 0-减 1-增
     * @param int $ptype 13-邀请 14-认证 15-购买年卡
     * @param int $isTop 1-上级 2-不是
     * @param int $userCardId 年卡id
     * @param int $relationId 上级积分记录表ID
     * @return int|string
     * @author Poison
     * @date 2021/4/27 4:13 下午
     */
    public static function pointsLog($userId, $topId = 0, $points = 0, $pnote = '', $ptype = 13,$isTop = 1,$relationId = 0,$userCardId = 0, $status = 1)
    {
        $data = array(
            'user_id' => $userId,
            'new_user_id' => $topId,
            'points' => $points,
            'status' => $status,
            'ptype' => $ptype,
            'pnote' => $pnote,
            'admin_id' => 0,
            'create_time' => time(),
            'is_two_way' => 1,
            'top_user_id' => 0,
            'top_user_points' => 0,
            'user_card_id' => $userCardId,
            'relation_id' => $relationId,
        );
        if($isTop !=1){
            $data['user_id'] = $userId;
            $data['new_user_id'] = 0;
            $data['top_user_id'] = $topId;
            $data['top_user_points'] = $points;
        }
        return (new PointsLogModel())->insert($data,true);
    }

    /**写入年卡购买-续费的一些记录
     * @param int $userId
     * @param int $userCardId
     * @param string $logInfo 日志内容
     * @return int
     * @author Poison
     * @date 2021/5/24 12:00 下午
     */
    public static function userCardOperationLog(int $userId, int $userCardId, string $logInfo): int
    {
        $data = [
            'user_id' => $userId,
            'user_type' => 2,
            'user_card_id' => $userCardId,
            'log_info' => $logInfo,
            'create_time' => time()
        ];
        return (new UserCardsOperationLogsModel())->insert($data, true);
    }

    /**增加退押金记录
     * @param int $refundId 退押金返回的ID
     * @param int $userId 用户ID
     * @param int $type 1-正常 2-取消
     * @return int|string
     * @author Poison
     * @date 2021/5/25 2:31 下午
     */
    public static function newCardRefundDepositLog(int $refundId,int $userId,$type = 1){
        $data = [
            'refund_id'=>$refundId,
            'admin_id'=>$userId,
            'user_type'=>2,
            'type'=>1,
            'remark' => $type == 1 ? '用户申请退押金' : '用户取消退押金',
            'create_time'=>time(),
            'update_time'=>time()
        ];
        return (new NewCardRefundDepositLogModel())->insert($data,true);
    }

    /**新增年卡日志
     * @param $userId
     * @param $userCardId
     * @param $cardId
     * @param int $changeReason
     * @param string $changeDuration
     * @param string $changeName
     * @param string $mobile
     * @param int $lowStartTime
     * @param int $lowEndTime
     * @param int $newStartTime
     * @param int $newEndTime
     * @return int|string
     * @author Poison
     * @date 2021/7/16 5:28 下午
     */
    public static function addUserCardChangeLog($userId,$userCardId,$cardId,$changeReason = 1,$changeDuration = '',$changeName = '',$mobile = '',$lowStartTime = 0,$lowEndTime = 0,$newStartTime = 0,$newEndTime = 0){
       return (new UserCardChangeLogModel())->insert([
            'user_id' => $userId,
            'user_card_id' => $userCardId,
            'card_id' =>$cardId,
            'card_name' => CardsModel::getById($cardId)['name'],
            'change_duration' => $changeDuration,
            'change_name' => $changeName,
            'change_reason' => (string)$changeReason,
            'relation_mobile' => $mobile,
            'change_low_start_time' => $lowStartTime,
            'change_low_end_time' => $lowEndTime,
            'change_new_start_time' =>$newStartTime,
            'change_new_end_time' => $newEndTime,
            'create_time' => $newStartTime,
        ]);
    }

}