<?php
/**
 * SmartMsgPushJob.php
 * 小程序消息推送相关
 * author yangliang
 * date 2021/5/20 10:49
 */

namespace app\job;


use app\common\service\ComService;
use app\common\service\LogisticsService;
use think\facade\Log;
use think\queue\Job;

class SmartMsgPushJob
{

    /**
     * @param Job $job
     * @param $data
     * @author: yangliang
     * @date: 2021/5/20 11:36
     */
    public function fire(Job $job, $data){
        //只执行一次
        if ($job->attempts() > 1) {
            $job->delete();
        }

        //无消息主体，不处理，删除任务
        if(empty($data['contents'])){
            $job->delete();
            return ;
        }

        try {
            //解析消息主体
            $contents = xmlToArray($data['contents']);

            switch ($contents['MsgType']) {
                case 'event':
                    //运单轨迹更新事件
                    if($contents['Event'] == 'add_express_path') {
                        self::logisticMsg($contents);
                    }
                    break;
                default:
                    self::v5kf($data);
            }

            $job->delete();
            return ;
        }catch (\Exception $e){
            $job->delete();
            self::failed($data, $e);
            return ;
        }
    }


    /**
     * 任务执行失败回调
     * @param $data
     * @param $e
     * @author: yangliang
     * @date: 2021/5/20 11:28
     */
    public function failed($data, $e){
        (new ComService())->sendDingError('客服消息处理任务失败，data：'.json_encode($data), $e);
    }


    /**
     * v5客服消息转发处理
     * @param $data
     * @author: yangliang
     * @date: 2021/5/20 11:28
     */
    private function v5kf($data){
        $conf = config('v5kf');
        $url = sprintf(' https://wxapp.v5kf.com/public/wxapp?site_id=%s&account=%s&signature=%s&timestamp=%d&nonce=%d&openid=%s',
            $conf['site_id'], $conf['account'], $data['signature'], $data['timestamp'], $data['nonce'], $data['openid']);
        curl_http($url, true, $data['contents'], ['HTTP_USER_AGENT: Mozilla/4.0', 'HTTP_CONTENT_TYPE: text/xml']);
    }



    private function logisticMsg($data){
//        $url = 'https://dev.rd029.com/Logistics/wechatMsgPush';
//        curl_http($url, true, $data);

        $params['delivery_id'] = $data['DeliveryID'];
        $params['waybill_id'] = $data['WayBillId'];
        $params['order_id'] = $data['OrderId'];
        $params['count'] = $data['Count'];
        $params['actions_arr'] = $data['Actions'];
        (new LogisticsService())->wechatMsgPush($params);
    }
}