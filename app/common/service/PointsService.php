<?php


namespace app\common\service;

use app\common\model\OrderModel;
use app\common\model\PointsInviteModel;
use app\common\model\PointsLogModel;
use app\common\model\PointsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use think\facade\Db;
use think\facade\Log as LogSave;

class PointsService extends ComService
{
    public function handlePoints(array $userInfo, array $points_info, int $type = 0)
    {
        $pointsModel = (new PointsModel());
        $user_id = $userInfo['user_id'];
        $points = (int)$points_info['points'];
        $userPoints = $pointsModel->where('user_id', $user_id)->find();
        if (!$userPoints) {
            // 添加
            $pointsResult = $pointsModel->insert(['user_id' => $user_id, 'create_time' => time()]);
            if (!$pointsResult) {
                return $this->error(0, "积分记录添加失败");
            }
        }
        // 判断积分是否小于1大于0
        if ($points > 0 && $points < 1) {
            $points = 1;
        }
        // 积分变动:type=1增加,type=0减少
        if ($type == 1) {
            $points_update = $pointsModel->where('user_id', $user_id)->inc('points', $points)->update(['update_time' => time()]);
        } else {
            // 判断是否有增加过积分
            $pointsLogResult = $pointsModel->where('user_id', $user_id)->select();
            if ($userPoints['points'] < $points && count($pointsLogResult) <= 0) {
                return $this->error(0, "无需扣减积分");
            }
            $points_update = $pointsModel->where('user_id', $user_id)->dec('points', $points)->update(['update_time' => time()]);
        }
        if (!$points_update) {
            return $this->error(0, "积分变动更新失败");
        }

        // 添加积分变动日志
        $pl_res = (new PointsLogModel())->addPointsLog($user_id, $points, (int)$points_info['ptype'], $points_info['pnote'], $type == 1 ? 1 : 0);
        if (!$pl_res) {
            return $this->error(null, "积分变动日志添加失败");
        }
        // 积分变动发送模板消息
        if ($type == 1) {
            $lastPoints = '+' . intval($points);
        } else {
            $lastPoints = '-' . intval($points);
        }
        //发送模版消息
        (new SendMessageService())->sendPointsMessage($user_id, ['content' => $points_info['wechat_message'], 'points' => intval($lastPoints) . '阅币']);
        return $this->success(200, '', $points);
    }


    /**
     * 用户积分明细
     * @param int $user_id 用户ID
     * @param int $page 分页页码
     * @return array
     * @author yangliang
     * @date 2021/2/22 9:30
     */
    public function getUserPointsInfo(int $user_id, int $page)
    {
        $list = PointsLogModel::getPointsLogByUserId($user_id, $page);
        $count = PointsLogModel::getCountPointsLogByUserId($user_id);
        if (!$count) {
            return $this->error(100, '数据为空');
        }
        foreach ($list as &$v) {
            self::getMessage($v);
        }

        return $this->success(200, '获取成功', $list);
    }

    /**判断
     * @param $data
     * @author Poison
     * @date 2021/6/29 6:19 下午
     */
    private function getMessage(&$data){
        switch ($data['ptype']){
            case 14:
            case 15:
            case 16:
            $data['is_activity'] = 1;
            $data['activity_name'] = '邀请好友得阅币';
            break;
            case 17:
                $str = str_replace('后台增加积分,活动名称：','',$data['pnote']);
                $data['is_activity'] = 1;
                $data['pnote'] = $data['activity_name'] = $str;
                break;
            case 18:
                $data['is_activity'] = 1;
                $data['activity_name'] = '阅读新视界';
                break;
            default:
                break;
        }
    }


    /**
     * 排行榜
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/22 10:02
     */
    public function getRankingList(int $user_id)
    {
        $user = (new UserModel())->getOneUser($user_id);
        if (empty($user_id)) {
            return $this->error(100, '用户不存在');
        }

        $user_points = PointsModel::getByUserId($user_id);
        $user_points['user_name'] = $user['user_name'];
        $user_points['points'] = !empty($user_points['points']) ? $user_points['points'] : 0;
        $user_points['rank'] = PointsModel::getRankByUserId($user_id, $user_points['points']);

        list($res, $count) = PointsModel::getPointsListTop();
        foreach ($res as &$v) {
            $name_len = mb_strlen($v['user_name']);
            if ($name_len > 6) {
                $v['user_name'] = mb_substr($v['user_name'], 0, 3) . '***' . mb_substr($v['user_name'], -3);
            } else if ($name_len > 3) {
                $v['user_name'] = mb_substr($v['user_name'], 0, 3) . '***';
            } else {
                $v['user_name'] = mb_substr($v['user_name'], 0, -1) . '***';
            }
        }

        $res[$count] = $user_points;

        return $this->success(200, '获取成功', $res);
    }

    /**积分邀请用户
     * @param $param
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/4/27 4:17 下午
     */
    public function invite($param)
    {
        if (!config('self_config.is_open_points')) {
            return errorArray('功能已下架');
        }
        $userId = (int)$param['user_id'];
        $topId = getTopId($param['top_id']);//获取上级id
        //验证上级是否存在
        if ($topId == $userId) {
            return errorArray('自己不能邀请自己');
        }
        $topUser = (new UserModel())->getOneUser($topId);
        if (!$topUser) {
            return errorArray('上级不存在');
        }
        $inviteData = PointsInviteModel::getInvite($userId, [0, 1, 2]);
        if ($inviteData) {
            if ($inviteData['fid'] = $topId) {
                return errorArray('您已经邀请过他了');
            }
        }
        //读取用户数据
        $isType = 0;//用户当前的状态
        $isGive = 2;//是否已注册
        $userMobile = (new UserModel())->getOneUser($userId, 1, 'mobile');
        if ($userMobile) {
            $isType = 1;
            //判断是否有年卡
            $userCard = UserCardsModel::getOneCards($userId);
            if ($userCard) {
                $isType = 2;
            }
        } else {
            $isGive = 1;
        }
        $res = (new PointsInviteModel())->addInvite($userId, $topId, $isType, $isGive);
        if (!$res) {
            return errorArray('邀请失败');
        }
        //LogService::pointsLog($userId, $topId, 0, '邀请成功');
        return successArray([], '邀请成功');
    }

    /**写入绑定数据
     * @param $userId
     * @return array
     * @author Poison
     * @date 2021/4/28 11:06 上午
     */
    public function addBindPoints($userId)
    {
        $checkMessage = self::checkMessage($userId);
        if ($checkMessage['code'] != 200) {
            return $checkMessage;
        }
        $topUser = $checkMessage['data']['top_user'];
        $inviteId = $checkMessage['data']['invite_id'];
        //开启事务 准备开始写入数据
        $pointsModel = new PointsModel();
        Db::startTrans();
        //查询自己是否有积分数据
        $check = self::addPointsData($pointsModel, [$userId, $topUser['user_id']]);
        if (!$check) {
            trace('积分邀请-绑定赠送积分初始化失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('初始化数据失败');
        }
        //开始赠送积分
        $upPoint = self::addPointsData($pointsModel, [$userId, $topUser['user_id']], 2, config('self_config.bind_points'));
        if (!$upPoint) {
            trace('积分邀请-绑定赠送积分失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('赠送积分失败');
        }
        //修改邀请状态
        $resPointsInvite = (new PointsInviteModel())->where('id', $inviteId)->inc('type', 1)->update(['update_time' => time()]);
        if (!$resPointsInvite) {
            trace('积分邀请-绑定赠送积分修改状态失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('修改状态失败');
        }
        //写入日志
        $userName = (new UserModel())->getOneUser($userId, 1, 'user_name');
        $logId = LogService::pointsLog($topUser['user_id'], $userId, config('self_config.bind_points'), sprintf('邀请好友%s 注册成功', $userName), 14);
        if (!$logId) {
            trace('积分邀请-绑定赠送积分-写入日志失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('写入日志失败');
        }
        LogService::pointsLog($userId, (int)$topUser['user_id'], config('self_config.bind_points'), sprintf('注册成功（好友信息：%s）', $topUser['user_name']), 14, 2, $logId);
        Db::commit();
        return successArray([], '赠送完成');
    }

    /**验证参数
     * @param $userId
     * @param int $type
     * @return array
     * @author Poison
     * @date 2021/4/28 11:44 上午
     */
    public function checkMessage($userId, $type = 1)
    {
        if (!config('self_config.is_open_points')) {
            return errorArray('功能已下架');
        }
        $whereIn = [0];
        if ($type != 1) {
            $whereIn = [0, 1, 2];
        }
        $inviteData = PointsInviteModel::getInvite($userId, $whereIn);
        if (!$inviteData) {
            return errorArray('没有被邀请或已绑定');
        }
        //判断用户是否已注册
        $topUser = (new UserModel())->getOneUser($inviteData['fid']);
        if (!$topUser) {
            return errorArray('上级不存在');
        }
        return successArray(['top_user' => $topUser, 'invite_id' => $inviteData['id']]);
    }

    /**vip写入数据
     * @param int $userId
     * @param int $cardId
     * @return array
     * @author Poison
     * @date 2021/4/28 11:52 上午
     */
    public function vipGivePoints(int $userId, $cardId)
    {
        $checkMessage = self::checkMessage($userId, 2);
        if ($checkMessage['code'] != 200) {
            return $checkMessage;
        }
        $topUser = $checkMessage['data']['top_user'];
        $inviteId = $checkMessage['data']['invite_id'];
        //开启事务 准备开始写入数据
        $pointsModel = new PointsModel();
        Db::startTrans();
        //查询自己是否有积分数据
        $check = self::addPointsData($pointsModel, [$userId, $topUser['user_id']]);
        if (!$check) {
            trace('积分邀请-年卡赠送积分初始化失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('初始化数据失败');
        }
        //开始赠送积分
        $upVip = self::addPointsData($pointsModel, [$userId, $topUser['user_id']], 2, config('self_config.vip_points'));
        if (!$upVip) {
            trace('积分邀请-购卡赠送积分失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('赠送积分失败');
        }
        //修改邀请状态
        $resPointsInvite = (new PointsInviteModel())->where('id', $inviteId)->update(['update_time' => time(), 'type' => 2]);
        if (!$resPointsInvite) {
            trace('积分邀请-绑定赠送积分修改状态失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('修改状态失败');
        }
        $userName = (new UserModel())->getOneUser($userId, 1, 'user_name');
        //写入日志
        $logId = LogService::pointsLog($topUser['user_id'], $userId, config('self_config.vip_points'), sprintf('邀请好友 %s 购卡成功', $userName), 15);
        if (!$logId) {
            trace('积分邀请-绑定赠送积分-写入日志失败 user_id:' . $userId . ' 上级id:' . $topUser['user_id'], 'error');
            Db::rollback();
            return errorArray('写入日志失败');
        }
        LogService::pointsLog($userId, (int)$topUser['user_id'], config('self_config.vip_points'), sprintf('购卡成功（好友信息：%s）', $topUser['user_name']), 15, 2, $logId, $cardId);
        Db::commit();
        return successArray([], '赠送完成');
    }

    /**处理写入业务
     * @param PointsModel $pointsModel
     * @param $userIdArray
     * @param int $checkType
     * @param float $points
     * @return bool
     * @author Poison
     * @date 2021/4/28 11:06 上午
     */
    public function addPointsData(PointsModel $pointsModel, $userIdArray, $checkType = 1, $points = 0.00)
    {
        foreach ($userIdArray as $v) {
            switch ($checkType) {
                case 1://初始化
                    $check = self::checkPoints($pointsModel, $v);
                    if (!$check) {
                        return false;
                    }
                    break;
                case 2://赠送绑定积分
                    $check = $pointsModel->updatePoints($v, $points);
                    if (!$check) {
                        return false;
                    }
                default:

            }
        }
        return true;
    }

    /**验证是否有数据 没有创建
     * @param PointsModel $pointsModel
     * @param $userId
     * @return bool
     * @author Poison
     * @date 2021/4/28 11:06 上午
     */
    public function checkPoints(PointsModel $pointsModel, $userId)
    {
        $userPointsData = $pointsModel->getOneData($userId);
        if (!$userPointsData) {
            $res = $pointsModel->addPoints($userId);
            if (!$res) {
                return false;
            }
        }
        return true;

    }

    /**获取借书数量
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/4/29 2:40 下午
     */
    public function getOrderCount(int $userId)
    {
        $count = (new OrderModel())->where('user_id', $userId)->count();
        return successArray(['count' => $count]);
    }

}