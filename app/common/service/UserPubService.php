<?php


namespace app\common\service;


use app\common\ConstLib;
use app\common\model\UserPubModel;
use app\common\model\UserPubTypeModel;

class UserPubService extends ComService
{

    /**
     * 赠送活动上传图片
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/22 13:55
     */
    public function createPub(array $data){
        if(empty($data['type_id'])){
            return $this->error(100, '活动已结束！');
        }

//        if(empty($data['pub_img'])){
//            return $this->error(100, '请上传图片');
//        }

        $user_pub_type = UserPubTypeModel::getById($data['type_id']);
        if(!$user_pub_type && $user_pub_type['is_closed'] == 1){
            return $this->error(100, '活动已结束！');
        }

        $user_pub_count = UserPubModel::getCountByUserIdAndTypeId($data['user_id'], $data['type_id']);
        if($user_pub_count > 0){
            return $this->error(100, '您已参加过此活动，请勿重复参加。如有疑问请咨询客服: '.ConstLib::SERVICE_PHONE);
        }

        try {
            $pub_notes = [];
            if(!empty($data['pub_note_age'])){
                $pub_notes[] = $data['pub_note_age'];
            }

            if(!empty($data['pub_note_grade'])){
                $pub_notes[] = $data['pub_note_grade'];
            }

            if(!empty($data['pub_note_subject'])){
                $pub_notes[] = $data['pub_note_subject'];
            }

            $user_pub_data = [
                'user_id' => $data['user_id'],
                'type_id' => $data['type_id'],
                'pubimg' => $data['pub_img'],
                'pubnote' => implode(',', $pub_notes),
                'create_time' => time(),
            ];

            $res =UserPubModel::create($user_pub_data);
            if(!$res){
                return $this->error(100, '图片上传失败');
            }

            $str ='恭喜您上传成功，工作人员将在1个工作日内审核，若审核未通过工作人员将会电话告知提醒您重新上传。赠书将会随您新的订单配送至您的手中';
            if($user_pub_type['is_give'] != 1){
                $str = '恭喜您上传成功！';
            }

            return $this->success(200, $str);
        }catch (\Exception $e){
            return $this->error(100, $e->getMessage());
        }
    }
}