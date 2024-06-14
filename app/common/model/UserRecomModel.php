<?php

namespace app\common\model;

use think\facade\Db;
use think\Model;

class UserRecomModel extends Model
{
    // 定义表名
    protected $table = 'tz_user_recom';

    /**
     * 获取直接下级用户
     * @param string $userId
     * @return array
     */
    public function getDirectSubordinates($userId)
    {
        return Db::table($this->table)->where('user_id', $userId)->select()->toArray();
    }

    public function getLowUserId($userIds = [],$level = 0,&$ret = []){
         $userIdss = $this->whereIn('user_id',$userIds)->column('recom_user_id');
        $ret[$level]['number'] = count($userIdss);
        $level++;
        if($level <= 2){
            $this->getLowUserId($userIdss,$level,$ret);
        }
        return $ret;
    }

    /**
     * 递归查询三级下级用户信息
     * @param string $userId 当前用户ID
     * @param int $level 当前递归层级
     * @param array $result 存储每一级的下级用户信息
     * @return array
     */
    public function getSubordinatesRecursive($userId, $level = 0, &$result = [], &$processed = [])
    {
        // 避免重复处理
        if (in_array($userId, $processed)) {
            return $result;
        }

        $processed[] = $userId;

        if ($level < 3) {
            $directSubordinates = $this->getDirectSubordinates($userId);
            foreach ($directSubordinates as $subordinate) {
                // 检查是否已经处理
                if (in_array($subordinate['user_id'], $processed)) {
                    continue;
                }

                $subordinate['level'] = $level;

                // 存储在对应的层级数组中
                if (!isset($result[$level])) {
                    $result[$level] = [];
                }
                $result[$level][] = $subordinate;

                // 递归查询下级用户
                $this->getSubordinatesRecursive($subordinate['user_id'], $level + 1, $result, $processed);
            }
        }

        return $result;
    }

}