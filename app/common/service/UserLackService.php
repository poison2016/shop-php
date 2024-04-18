<?php
/**
 * UserLackService.php
 * 文件描述
 * author yangliang
 * date 2021/9/23 11:31
 */

namespace app\common\service;


use app\common\traits\CacheTrait;

class UserLackService extends ComService
{
    use CacheTrait;


    /**
     * 入库前置操作
     * @param int $user_id  用户ID
     * @param int $goods_id  书籍ID
     * @param int $type  数据来源  1-下单，2-搜索，3-加入购物车
     * @author: yangliang
     * @date: 2021/9/23 11:42
     */
    public function beforeAdd($user_id, $goods_id, $type){
        $this->lPushCache('user_lack', json_encode(['user_id' => $user_id, 'goods_id' => $goods_id, 'type' => $type, 'create_time' => time()]));
    }
}