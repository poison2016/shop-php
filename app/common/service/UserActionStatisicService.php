<?php


namespace app\common\service;


use app\common\traits\CacheTrait;

class UserActionStatisicService extends ComService
{
    use CacheTrait;


    /**
     * 小程序事件上报
     * @param int $user_id  用户ID
     * @param array $params  上报信息
     * @return array
     * @author yangliang
     * @date 2021/4/6 10:47
     */
    public function report(int $user_id, array $params){
        $table = '';
        $table_id = 0;

        //到货提醒同意
        if($params['event_mark'] == 'goods_attention_agree'){
            $table = 'goods_attention';
            $table_id = $params['table_id'];
        }

        $data = [
            'user_id' => $user_id,
            'key' => $params['key'],
            'event_type' => $params['event_type'],
            'event_module' => $params['event_module'],
            'event_mark' => $params['event_mark'],
            'content' => $params['content'],
            'goods_id' => $params['goods_id'],
            'related_table' => $table,
            'related_table_id' => $table_id,
            'ip' => getClientIP(),
            'event_time' => $params['event_time'],
            'create_time' => time(),
        ];
        $this->rPushCache('user_action_report', json_encode($data));
        return $this->success(200, '请求成功', $params['key']);
    }
}