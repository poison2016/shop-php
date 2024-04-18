<?php
declare(strict_types=1);

namespace app\common\service;


use app\common\model\GoodsCommentActivityModel;
use app\common\model\GoodsCommentActivityRefundLogsModel;
use app\common\model\GoodsCommentActivityRefundModel;
use app\common\model\GoodsCommentActivityUsersModel;
use app\common\model\GoodsCommentModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardsModel;
use think\facade\Db;

class CommentActivityService extends ComService
{


    /**
     * 获取用户任务状态
     * @param int $user_id  用户ID
     * @return array
     * @author yangliang
     * @date 2021/4/19 11:22
     */
    public function getInfo(int $user_id):array
    {
        try {
            $status = -1;  //任务状态  -1：无可报名任务  0：未报名  1：进行中  2：任务完成  3：任务未完成    4：兑换阅读基金审核中    5：兑换阅读基金审核被拒    6：兑换阅读基金审核成功
            //获取用户任务
            $record = GoodsCommentActivityUsersModel::getRecordByUserId($user_id);
            if (empty($record)) {
                //获取可报名任务
                $activity = GoodsCommentActivityModel::getActivity();
                $status = empty($activity) ? $status : 0;
                return $this->success(200, '获取成功', ['status' => $status, 'activity' => $activity]);
            }

            $activity = GoodsCommentActivityModel::getById($record['activity_id']);
            //验证任务是否已过期
            $is_expire = self::checkActivityExpire($record['id'], $activity['id']);
            if ($is_expire) {  //任务过期，未完成
                $record['is_complete'] = 0;
            }

            //存在已报名任务
            switch ($record['is_complete']) {
                case 0:  //任务未完成（已关闭）
                    $data['status'] = 3;
                    break;
                case 1:  //进行中任务
                    $data = [
                        'status' => 1,
                        'complete_num' => $record['complete_num'],
                        'num' => $activity['rule_comment_num'] - $record['complete_num'],  //剩余篇数
                        'days' => intval(abs(($record['create_time'] + $activity['days'] * 86400) - time()) / 86400),  //剩余天数
                        'complete_day' => intval(abs(time() - $record['create_time']) / 86400),
                        'today_comment' => GoodsCommentModel::getTodayActivityComment($user_id, $activity['id']),
                        'rule_comment_num' => $activity['rule_comment_num'],
                    ];
                    break;
                case 2:  //任务已完成
                    $data = [
                        'complete_num' => $record['complete_num'],
                        'num' => $activity['rule_comment_num'] - $record['complete_num'],  //剩余篇数
                        'days' => intval(abs(($record['create_time'] + $activity['days'] * 86400) - time()) / 86400),  //剩余天数
                        'complete_day' => intval(abs(time() - $record['create_time']) / 86400),
                        'today_comment' => GoodsCommentModel::getTodayActivityComment($user_id, $activity['id']),
                        'rule_comment_num' => $activity['rule_comment_num'],
                    ];
                    //获取任务兑换记录
                    $refund = GoodsCommentActivityRefundModel::getByActivityIdAndUserId($activity['id'], $user_id);
                    $data['status'] = self::refundStatusToActivityStatus(isset($refund['status']) ? $refund['status'] : '');
                    break;
            }
            $data['user_activity_id'] = $record['id'];
            $data['is_close'] = $record['is_close'];
            return $this->success(200, '获取成功', $data);
        }catch (\Exception $e){
            return $this->error(100, $e->getMessage());
        }
    }


    /**
     * 任务报名
     * @param array $params
     * @return array
     * @author yangliang
     * @date 2021/4/19 13:47
     */
    public function apply(array $params):array
    {
        $activity = GoodsCommentActivityModel::getById($params['activity_id']);
        if(empty($activity)){
            return $this->error(100, '任务不存在');
        }

        if($activity['start_time'] > time()){
            return $this->error(100, '任务暂未开始');
        }

        if($activity['is_end'] == 1){
            return $this->error(100, '任务已关闭');
        }

        if(self::checkUserCard($params['user_id']) == 0 && $activity['is_card'] == 1){
            return $this->error(100, '该任务仅限年卡用户参与');
        }

        if(!empty(GoodsCommentActivityUsersModel::getByActivityIdAndUserId($params['activity_id'], $params['user_id']))){
            return $this->error(100, '您已报名该活动，请勿重复报名');
        }

        $res = GoodsCommentActivityUsersModel::create([
            'user_id' => $params['user_id'],
            'activity_id' => $params['activity_id'],
            'complete_num' => 0,
            'is_complete' => 1,
            'complete_time' => 0,
            'create_time' => time(),
            'update_time' => time()
        ]);

        if(!$res){
            return $this->error(100, '报名失败');
        }

        return $this->success(200, '报名成功', ['user_activity_id' => $res->id]);
    }


    /**
     * 验证用户年卡是否有效
     * @param int $user_id  用户ID
     * @return int
     * @author yangliang
     * @date 2021/4/19 12:01
     */
    public function checkUserCard(int $user_id): int
    {
        $user_card = UserCardsModel::getByUserId($user_id);
        if(empty($user_card)){
            return  0;
        }

        if($user_card['is_expire'] == 2){
            return 0;
        }

        if($user_card['is_refund'] == 1){
            return 0;
        }

        return $user_card['id'];
    }


    /**
     * 兑换阅读基金
     * @param array $params
     * @return array
     * @author yangliang
     * @date 2021/4/19 17:21
     */
    public function exchange(array $params):array
    {
        $user_activity = GoodsCommentActivityUsersModel::getById($params['user_activity_id']);
        if(empty($user_activity)){
            return $this->error(100, '任务信息异常');
        }

        //验证任务是否完成
        $activity = GoodsCommentActivityModel::getById($user_activity['activity_id']);
        if($user_activity['complete_num'] < $activity['rule_comment_num'] || $user_activity['is_complete'] != 2 || empty($user_activity['complete_time'])){
            return $this->error(100, '任务未完成');
        }

        //验证是否重复提交兑换
        $refund = GoodsCommentActivityRefundModel::getByActivityIdAndUserId($activity['id'], $params['user_id']);
        if(!empty($refund) && $refund['status'] != 2){
            return $this->error(100, '存在兑换阅读基金记录，请勿重复提交');
        }

        //验证任务规则，是否只有年卡用户可完成
        $user_card_id = self::checkUserCard($params['user_id']);
        if($activity['is_card'] == 1 && $user_card_id == 0){
            return $this->error(100, '您还不是年卡用户，无法兑换阅读基金');
        }


        Db::startTrans();
        try {
            $res = GoodsCommentActivityRefundModel::create([
                'activity_id' => $activity['id'],
                'user_id' => $params['user_id'],
                'user_card_id' => $user_card_id,
                'refund_price' => 0,
                'node' => 1,
                'status' => 0,
                'create_time' => time(),
                'update_time' => time()
            ]);

            if(!$res){
                throw new \Exception('申请失败');
            }
            //申请日志
            $log = GoodsCommentActivityRefundLogsModel::create([
                'user_id' => $params['user_id'],
                'user_type' => 2,
                'refund_id' => $res->id,
                'log_info' => '用户申请兑换阅读基金',
                'create_time' => time(),
            ]);

            if(!$log){
                throw new \Exception('申请日志记录失败');
            }
        }catch (\Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '申请成功');
    }


    /**
     * 退款状态映射任务状态
     * @param string $refund_status
     * @return int
     * @author yangliang
     * @date 2021/4/20 9:18
     */
    private function refundStatusToActivityStatus($refund_status = ''){
        $arr = [
            '0' => 4,  //兑换阅读基金审核中
            '1' => 6,  //兑换阅读基金审核成功
            '2' => 5,  //兑换阅读基金审核被拒
        ];

        if($refund_status === ''){
            return 2;  //任务已完成
        }

        return $arr[$refund_status];
    }


    /**
     * 验证用户任务是否过期
     * @param int $user_activity_id  用户任务ID
     * @param int $activity_id  任务ID
     * @return bool
     * @author yangliang
     * @date 2021/4/20 17:45
     */
    private function checkActivityExpire(int $user_activity_id, int $activity_id){
        $user_activity = GoodsCommentActivityUsersModel::getById($user_activity_id);
        $activity = GoodsCommentActivityModel::getById($activity_id);
        $end_time = $user_activity['create_time'] + $activity['days'] * 86400;
        if(time() > $end_time){  //任务已过期
            //验证任务是否完成
            if($user_activity['complete_num'] < $activity['rule_comment_num'] || $user_activity['is_complete'] != 2 || empty($user_activity['complete_time'])){
                //任务未完成，更新用户任务状态
                $res = GoodsCommentActivityUsersModel::where('id', $user_activity_id)->update(['is_complete' => 0, 'update_time' => time()]);
                if(!$res){
                    throw new \Exception('数据操作异常');
                }
                return true;
            }
        }

        return false;
    }


    /**
     * 关闭任务
     * @param array $params
     * @return array
     * @author yangliang
     * @date 2021/4/21 13:56
     */
    public function closeActivity(array $params):array
    {
        $user_activity = GoodsCommentActivityUsersModel::getById($params['user_activity_id']);
        if(empty($user_activity)){
            return $this->error(100, '任务信息异常');
        }

        $refund = GoodsCommentActivityRefundModel::getByActivityIdAndUserId($user_activity['activity_id'], $params['user_id']);
        if(!empty($refund) && !in_array($refund['status'], [1, 2])){
            return $this->error(100, '任务状态异常');
        }

        $res = GoodsCommentActivityUsersModel::where('id', $params['user_activity_id'])->update(['is_close' => 1, 'update_time' => time()]);
        if(!$res){
            return $this->error(100, '关闭失败');
        }

        return $this->success(200, '关闭成功');
    }
}