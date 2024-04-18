<?php


namespace app\common\service;


use app\common\model\GoodsLackModel;
use app\common\model\UserModel;
use think\facade\Log;

class GoodsLackService extends ComService
{


    /**
     * 缺货登记
     * @param int $user_id
     * @param object $remark
     * @param string $goods_img
     * @return array
     * @author yangliang
     * @date 2021/2/22 11:29
     */
    public function addGoodsLack(int $user_id, $remark, string $goods_img = ''){
        $start_time = strtotime(date('Y-m-d 0:0:0'));
        $end_time = strtotime(date('Y-m-d 23:59:59'));

        $count = GoodsLackModel::getCountByUserId($user_id, $start_time, $end_time);
        if($count >= 20){
            return $this->error(100, '提交过于频繁,请稍后再试');
        }

        if(!empty($goods_img)){
            $parts = parse_url($goods_img);
            if(!empty($parts['path'])){
                $goods_img =$parts['path'];
            }
        }

        $remark['name'] = str_replace(',', '，', $remark['name']);
        $remark['author'] = str_replace(',', '，', $remark['author']);
        $remark['publish'] = str_replace(',', '，', $remark['publish']);
        $remark_str = '';
        $remark_str.=!empty($remark['name']) ? '书名:'.$remark['name'].',' : '';
        $remark_str.=!empty($remark['author']) ? '作者:'.$remark['author'].',' : '';
        $remark_str.=!empty($remark['publish']) ? '版本:'.$remark['publish'] : '';

        $data = [
            'user_id' => $user_id,
            'goods_img' => $goods_img,
            'remark' => $remark_str,
            'add_time' => time()
        ];

        $res = GoodsLackModel::create($data);
        if(!$res){
            return $this->error(100, '提交失败');
        }

        $user = (new UserModel())->getOneUser($user_id);

        // 9.下单成功发送小程序订阅消息
        if(!empty($remark)){

            if($user['smart_openid']){
                $send_data['name'] = $remark['name'] ?? '无';
                $send_data['author'] = $remark['author'] ?? '无';
                $send_data['publish'] = $remark['publish'] ?? '无';
                $send_data['remark'] = '如有疑问请咨询客服 85799903';
                $res = (new SmartSendService())->lackBookMessage($user['smart_openid'], $send_data, 'pages/packageOne/index/index');
                if(!$res){
                    Log::info('心愿书单-》发送小程序提醒失败 返回数据:'.json_encode($res,JSON_UNESCAPED_UNICODE));
                }
            }
        }

        return $this->success(200, '提交成功', $res);
    }
}