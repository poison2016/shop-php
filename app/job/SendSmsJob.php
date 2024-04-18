<?php


namespace app\job;


use app\common\model\FamilyActivityOrderModel;
use app\common\service\ComService;
use app\common\Tools\ALiYunSendSms;
use think\queue\Job;

class SendSmsJob
{

    /**
     * 定时任务-亲子活动创建订单20分钟未支付发送短信提醒
     * 注意：业务及入参发生变更后，已经压入队列的数据做兼容处理，否则程序抛出异常
     * @param Job $job
     * @param $data
     * @author: yangliang
     * @date: 2021/4/26 15:45
     */
    public function fire(Job $job, $data){
        try {
            //短信重试1次后删除任务
            if ($job->attempts() > 1) {
                $job->delete();
            }

            if(empty($data['mobile'])){
                throw new \Exception('手机号码为空');
            }

            //各业务验证
            if(!self::checkJob($data)){
                $job->delete();
                return ;
            }

            //测试服不发送短信
            if (env('server_env') === 'dev') {
                $job->delete();
                return ;
            }

            //发送短信
            $res = (new ALiYunSendSms())->sendSms($data['mobile'], $data['template_code'], $data['params'] ?? []);
            if($res['code'] == 0){
                throw new \Exception('短信回执失败，'.json_encode($res));
            }

            $job->delete();
        }catch (\Exception $e){
            $job->delete();
            $this->failed($data, $e);
            return ;
        }
    }


    /**
     * 任务重试次数到达最大，进行钉钉提醒
     * @param $data
     * @param $e
     * @author: yangliang
     * @date: 2021/4/27 17:58
     */
    public function failed($data, $e){
        (new ComService())->sendDingError('亲子活动待支付订单短信提醒任务失败，订单ID：'.$data['order_id'].'，data：'.json_encode($data), $e);
    }


    /**
     * 业务验证
     * @param array data
     * @return bool
     * @author: yangliang
     * @date: 2021/4/27 17:51
     */
    private function checkJob(array $data):bool
    {
        switch ($data['type']){
            case 'family_unpay_order':
                return $this->checkFamilyUnPayOrder((int)$data['order_id']);
                break;
            //TODO  完善其他类型业务验证
            default:
                return true;
        }
    }


    /**
     * 验证亲子活动订单状态
     * @param int $order_id  订单ID
     * @return false
     * @author: yangliang
     * @date: 2021/4/27 15:24
     */
    private function checkFamilyUnPayOrder(int $order_id): bool
    {
        $order = FamilyActivityOrderModel::getById($order_id);
        if ($order['status'] != 0) {
            return false;
        }
        return true;
    }
}