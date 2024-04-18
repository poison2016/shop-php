<?php


namespace app\common\service;

use app\common\jobs\GetUserInfoFromEventMsg;
use app\common\model\UserModel;
use app\common\model\WxFansInfoLogModel;
use app\common\model\WxFansInfoModel;
use app\common\model\WxMsgModel;
use app\common\traits\CacheTrait;
use think\Exception;
use think\facade\Log;

class WxDealService extends ComService
{
    use CacheTrait;

    /**
     * 关注公众号后的处理
     * @param array $data
     * @return array
     * @author liyongsheng
     * @date 2021/3/23 14:08
     */
    public function subscribe(array $data)
    {
        try {
            $info = WxFansInfoModel::getInfoByOpenId($data['FromUserName']);
            $openId = $data['FromUserName'];
            $time = time();
            if (!$info) {
                // 第一次关注，写入关注信息
                $data = [
                    'openid' => $openId,
                    'status' => 1,
                    'num' => 1,
                    'last_subscribe_time' => $time,
                    'last_unsubscribe_time' => 0,
                    'create_time' => $time,
                    'update_time' => $time
                ];
                WxFansInfoModel::addInfo($data);
            } else {
                // 取消关注了，又关注了一次
                $data = [
                    'status' => 1,
                    'num' => $info['num'] + 1,
                    'last_subscribe_time' => $time,
                    'update_time' => $time
                ];
                WxFansInfoModel::updateInfo($data, $openId);
            }

            $data = [
                'open_id' => $openId,
                'status' => 1,
                'create_time' => $time,
                'update_time' => $time
            ];
            WxFansInfoLogModel::addInfo($data);
            return $this->success(200, '请求成功');
        } catch (Exception $e) {
            Log::channel('wxMsg')->info($e->getMessage());
            return $this->error(0, $e->getMessage());
        }
    }

    /**
     * 取消关注后的处理
     * @param array $data
     * @return array
     * @author liyongsheng
     * @date 2021/3/23 14:08
     */
    public function unsubscribe(array $data)
    {
        try {
            $info = WxFansInfoModel::getInfoByOpenId($data['FromUserName']);
            $openId = $data['FromUserName'];
            $time = time();
            if (!$info) {
                // 没查到数据，说明关注时候的记录异常，也把数据再次写入一下
                $data = [
                    'openid' => $openId,
                    'status' => 0,
                    'num' => 1,
                    'last_subscribe_time' => 0,
                    'last_unsubscribe_time' => $time,
                    'create_time' => $time,
                    'update_time' => $time
                ];
                WxFansInfoModel::addInfo($data);
            } else {
                // 取消关注了
                $data = [
                    'status' => 0,
                    'last_unsubscribe_time' => $time,
                    'update_time' => $time
                ];
                WxFansInfoModel::updateInfo($data, $openId);
            }

            $data = [
                'open_id' => $openId,
                'status' => 0,
                'create_time' => $time,
                'update_time' => $time
            ];
            WxFansInfoLogModel::addInfo($data);
            return $this->success(200, '请求成功');
        } catch (Exception $e) {
            Log::channel('wxMsg')->info($e->getMessage());
            return $this->error(0, $e->getMessage());
        }
    }

    /**
     * 公众号消息记录
     * @author liyongsheng
     * @date 2021/4/26 15:23
     */
    public function dealMsg()
    {
        // 删除已经过时的消息，指的是3天前的数据
        $time = strtotime("-3 day");
        WxMsgModel::where('create_time', '<', $time)->delete();

        $length = $this->lLenCache('wx_msg_temp_save_lys');
        if ($length > 0) {
            while ($length > 0) {
                $item = $this->lPopCache('wx_msg_temp_save_lys');
                if ($item) {
                    $item = json_decode($item, true);
                    $userId = UserModel::where('openid', $item['FromUserName'])->value('user_id');
                    $userId = $userId && $userId != '' ? $userId : 0;
                    WxMsgModel::where('openid', $item['FromUserName'])->delete();
                    WxMsgModel::insert([
                        'user_id' => $userId,
                        'openid' => $item['FromUserName'],
                        'create_time' => $item['CreateTime']
                    ]);
                }
                $length = $length - 1;
            }
        }
    }
}