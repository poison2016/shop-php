<?php


namespace app\common\service;


use app\common\ConstLib;
use app\common\model\CartModel;
use app\common\model\GoodsAttributeModel;
use app\common\model\GoodsAttrModel;
use app\common\model\GoodsCollectModel;
use app\common\model\GoodsCommentActivityModel;
use app\common\model\GoodsCommentActivityUsersModel;
use app\common\model\GoodsCommentByModel;
use app\common\model\GoodsCommentModel;
use app\common\model\GoodsCommentPointModel;
use app\common\model\GoodsModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use app\Request;
use think\facade\Db;

class CommentService extends ComService
{
    use CacheTrait;


    /**
     * 用户对商品评论
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/24 15:33
     */
    public function setGoodsComment(int $user_id, array $data){
        if(empty(intval($data['goods_id']))){
            return $this->error(100, '商品不存在');
        }

        if(empty(trim($data['content']))){
            return $this->error(100, '书评内容不能为空');
        }

        //2021.9.17 取消书评本书限制
//        $comment_num = GoodsCommentModel::getEffecCountByGoodsIdAndUserId($data['goods_id'], $user_id);
//        if($comment_num > ConstLib::GOODS_COMMENT_MAX){
//            return $this->error(100, sprintf('同一用户对同一本书最多只可发表%d篇书评', ConstLib::GOODS_COMMENT_MAX));
//        }

        Db::startTrans();
        try {
            $is_on_sale = GoodsModel::getByGoodsId($data['goods_id'])['is_on_sale'];
            if($is_on_sale != 1){
                throw new \Exception('商品不存在');
            }

            if(is_array($data['content_img'])){
                $content_img = implode(',', $data['content_img']);
            }else{
                $content_img = $data['content_img'] ?? '';
            }

            $comment_data = [
                'goods_id' => $data['goods_id'],
                'user_id' => $user_id,
                'content' => base64_encode(trim($data['content'])),
                'content_img' => $content_img,
                'is_type' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ];

            //获取用户正在进行中的任务
            $user_activity = GoodsCommentActivityUsersModel::getProcessingByUserId($user_id);
            if(!empty($user_activity)){
                //获取任务规则信息
                $activity = GoodsCommentActivityModel::getById($user_activity['activity_id']);
                //验证有效书评字数
                if(mb_strlen($data['content'], 'utf-8') < $activity['min_font_num']){
                    throw new \Exception(sprintf('有效书评必须大于%d个字', $activity['min_font_num']));
                }
                //验证用户年卡是有有效
                $user_card = (new CommentActivityService())->checkUserCard($user_id);
                //任务只限年卡用户参与，记录任务ID、任务不限年卡用户参与，记录任务ID
                if(($activity['is_card'] == 1 && $user_card > 0) || $activity['is_card'] == 0){
                    $comment_data['activity_id'] = $user_activity['activity_id'] ?? 0;
                }
            }
            $res = GoodsCommentModel::create($comment_data);
            if(!$res){
                throw new \Exception('评论失败');
            }

           // $this->sendDing('请注意，有新的评论审核需要处理~', ConstLib::DING_DING_COMMENT_MOBILE, config('ding_config.comment_ok_ding'));
        }catch (\Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '评论成功', $res);
    }


    /**
     * 写入操作
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/24 15:57
     */
    public function setByContent(int $user_id, array $data){
        if(empty($data['comment_id'])){
            return $this->error(100, '评论ID不能为空');
        }

        if(empty(trim($data['content']))){
            return $this->error(100, '评论内容不能为空');
        }

        $comment_data = GoodsCommentModel::getById($data['comment_id']);
        if(empty($comment_data)){
            return $this->error(100, '您要评论的书评不存在');
        }

        if($comment_data['is_type'] == 4){
            return $this->error(100, '您要评论的书评已被删除');
        }

        if($comment_data['is_type'] == 1){
            return $this->error(100, '您要评论的书评正在审核中');
        }

        if($comment_data['is_type'] == 2){
            return $this->error(100, '您要评论的书评审核失败');
        }

        $by_comment_data = [
            'comment_id' => $data['comment_id'],
            'user_id' => $user_id,
            'content' => base64_encode(trim($data['content'])),
            'is_type' => 1,
            'top_id' => $data['top_id'] ?? 0,
            'superior_id' => $data['by_comment_id'] ?? 0,
        ];

        $is_type_one = 0;
        if(!empty($by_comment_data['superior_id'])){
            $is_type_one = GoodsCommentByModel::getById($by_comment_data['superior_id'])['is_type'];
        }else if(!empty($by_comment_data['top_id'])){
            $is_type_one = GoodsCommentByModel::getById($by_comment_data['top_id'])['is_type'];
        }

        if($is_type_one == 1){
            return $this->error(100, '该评论处于审核状态');
        }

        if($is_type_one == 2){
            return $this->error(100, '该评论处于审核失败状态');
        }

        if($is_type_one == 4){
            return $this->error(100, '该评论处于删除状态');
        }

        if($data['is_by_comment'] == 1){  //没有被回复
            $by_comment_data['is_reply'] = 1;
            $by_comment_data['by_user_id'] = $comment_data['user_id'] ?? 0;
        }else{
            if($data['is_by_status'] == 1){
                $by_comment_data['is_reply'] = 1;
                $by_comment_data['by_user_id'] = $data['by_user_id'] ?? $comment_data['user_id'];
            }else{
                $by_comment_data['is_reply'] = 2;
                $by_comment_data['by_user_id'] = $data['by_user_id'] ?? $comment_data['user_id'];
            }
        }
        $by_comment_data['create_time'] = time();
        $res = GoodsCommentByModel::create($by_comment_data);
        if(!$res){
            return $this->error(100, '评论失败');
        }

        //$this->sendDing('请注意，有新的评论审核需要处理~', ConstLib::DING_DING_COMMENT_MOBILE, config('ding_config.comment_ok_ding'));

        return $this->success(200, '评论成功', $res);
    }


    /**
     * 用户点赞
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/24 16:26
     */
    public function setPoint(int $user_id, array $data){
        if(empty($data['comment_id'])){
            return $this->error(100, '评论ID不能为空');
        }

        Db::startTrans();
        try {
            $point_data = GoodsCommentPointModel::getByUserIdAndCommentId($user_id, $data['comment_id']);
            if(empty($point_data)){
                $point_res = GoodsCommentPointModel::create([
                    'user_id' => $user_id,
                    'user_ip' => getClientIP(),
                    'comment_id' => $data['comment_id'],
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                if(!$point_res){
                    throw new \Exception('点赞失败');
                }

                $is_this_user = GoodsCommentModel::getById($data['comment_id']);
                //如果不是点赞自己 进行消息写入
                if($is_this_user['user_id'] != $user_id){
                    $this->setCache('comment_message_comment_top'.$user_id, 1, ConstLib::COMMENT_LIST_EXPIRE);
                }

                GoodsCommentModel::where('id', $data['comment_id'])->update(['point_num' => $is_this_user['point_num'] + 1]);
                Db::commit();
                return $this->success(200, '点赞成功', ['comment_id' => $data['comment_id']]);
            }else{  //存在删除
                $point_res =GoodsCommentPointModel::where('user_id', $user_id)->where('comment_id', $data['comment_id'])->delete();
                if(!$point_res){
                    return $this->error(100, '取消点赞失败');
                }

                $inc_data = GoodsCommentModel::where('id', $data['comment_id'])->dec('point_num', 1)->update();
                if(!$inc_data){
                    return $this->error(100, '点赞失败');
                }

                $is_this_user = GoodsCommentModel::getById($point_data['comment_id']);
                //如果不是点赞自己 进行消息写入
                if($is_this_user['user_id'] != $user_id){
                    $this->setCache('comment_message_comment_top'.$user_id, 1, ConstLib::COMMENT_LIST_EXPIRE);
                }
                Db::commit();
                return $this->success(200, '取消点赞成功', ['comment_id' => $data['comment_id']]);
            }
        }catch (\Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }
    }


    /**
     * 热门
     * @param int $user_id
     * @param int $page
     * @return array
     * @author yangliang
     * @date 2021/2/25 9:55
     */
    public function getPopular(int $user_id, int $page){
        $list = [];
        if($page == 1){
            $list = self::getCache('comment_hot');
        }
        if($list){
            $list = json_decode($list,true);
        }else{
            $list = GoodsCommentModel::getPopular($user_id, $page);
            $list = $this->ForSquare($user_id, $list, 0);
            if($page == 1){
                self::setCache('comment_hot',json_encode($list),10 * 60);
            }
        }
        return $this->success(200, '获取成功',$list );
    }


    /**
     * 最新
     * @param int $user_id
     * @param int $page
     * @return array
     * @author yangliang
     * @date 2021/2/25 10:49
     */
    public function getNews(int $user_id, int $page){
        $list = [];
        if($page == 1){
            $list = self::getCache('comment_new_'.$user_id);
        }
        if($list){
            $list = json_decode($list,true);
        }else{
            $list = GoodsCommentModel::getNews($user_id, $page);
            $list = $this->ForSquare($user_id, $list, 0);
            if($page == 1){
                self::setCache('comment_new_'.$user_id,json_encode($list),5 * 60);
            }
        }
        return $this->success(200, '获取成功',$list );
    }


    /**
     * 我的
     * @param int $user_id
     * @param int $page
     * @param int $is_type
     * @return array
     * @author yangliang
     * @date 2021/2/25 10:58
     */
    public function getMine(int $user_id, int $page, int $is_type){
        $list = GoodsCommentModel::getMine($user_id, $page, $is_type);
        return $this->success(200, '获取成功', $this->ForSquare($user_id, $list, 1));
    }


    /**
     * 组装数据
     * @param int $user_id
     * @param $goods_comment
     * @param $is_this_content
     * @return mixed
     * @author yangliang
     * @date 2021/2/25 9:53
     */
    public function ForSquare(int $user_id, $goods_comment, $is_this_content){
        foreach ($goods_comment as $k => $v){
            if($v['is_type'] == 2){  //2020-8-18 超过15天 过滤
                $start_time = strtotime(date('Y-m-d',$v['create_time']));
                $end_time = (strtotime(date('Y-m-d',time())) - $start_time) / 86400;
                if($end_time > ConstLib::GOODS_END_BOOK){
                    unset($goods_comment[$k]);
                }
            }

            $user_name = substr($v['user_name'], 0, 3);
            $goods_comment[$k]['user_name'] = $v['user_name'];
            if ($user_name === 'VIP') {
                $goods_comment[$k]['user_name'] = $v['nickname'];
                if (!$v['nickname']) {
                    $goods_comment[$k]['user_name'] = $v['user_name'];
                }
            }

            $goods_comment[$k]['content'] = mb_convert_encoding(base64_decode($v['content']), 'UTF-8', 'UTF-8');
            $attr_zz = GoodsAttributeModel::getByTypeIdAndAttrName($v['goods_type'], '作者');
            $attr_value = GoodsAttrModel::getAttrValueByGoodsIdAndAttrId($v['goods_id'], $attr_zz['attr_id']);
            $goods_comment[$k]['writer'] = empty($attr_value['attr_value']) ? '未知': $attr_value['attr_value'];  //作者
            $attr_xl = GoodsAttributeModel::getByTypeIdAndAttrName($v['goods_type'], '系列');
            $attr_value1 = GoodsAttrModel::getAttrValueByGoodsIdAndAttrId($v['goods_id'], $attr_xl['attr_id']);
            $goods_comment[$k]['series'] = empty($attr_value1['attr_value']) ? '睿鼎少儿推荐系列': $attr_value1['attr_value'];  //系列
            $goods_comment[$k]['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
            $goods_comment[$k]['content_img'] = $v['content_img'] ? explode(',', $v['content_img']) : [];
            $goods_comment[$k]['original_img'] = $this->getGoodsImg($v['original_img']);
            $goods_comment[$k]['userReadBookCount'] = OrderGoodsModel::getSumGoodsNumByUserId($v['user_id']);
            $goods_comment[$k]['is_cart'] = CartModel::getByUserIdAndGoodsId($user_id, $v['goods_id'])['id'] ?? 0;  //是否收藏 是返回收藏id
            $goods_comment[$k]['is_collect'] = GoodsCollectModel::getByUserIdAndGoodsId($user_id, $v['goods_id'])['collect_id'] ?? 0; //是否在借书单 是返回借书单id
            $goods_comment[$k]['goodsBorrow'] = OrderGoodsModel::getSumGoodsNumByGoodsId($v['goods_id']);  //图书借阅数量
            $is_this_point = GoodsCommentPointModel::getByUserIdAndCommentId($user_id, $v['comment_id']);
            $goods_comment[$k]['is_click'] = ($is_this_point)? 1: 0;

            //------- 2020-8-13 添加 用户评论数量 +自己 -------
            $goods_comment[$k]['point_num'] = GoodsCommentByModel::getCountByCommentIdAndType($v['comment_id'], 3) + GoodsCommentByModel::getCountByCommentIdAndTypeAndUserId($v['comment_id'], 1, $user_id);

            $attr = GoodsAttributeModel::getByTypeId($v['goods_type']);
            $attr_value = GoodsAttrModel::getAttrValueByGoodsIdAndAttrId($v['goods_id'], $attr['attr_id']);
            $goods_comment[$k]['reason'] = !$attr_value ? $attr['attr_name'] . ':' . '睿鼎少儿推荐' : $attr['attr_name'] . ':' . $attr_value;  //上榜理由

            if($is_this_content == 0){
                unset($goods_comment[$k]['remark']);
            }
            unset($goods_comment[$k]['goods_type']);
        }
        return $goods_comment;
    }


    /**
     * 获取用户借阅过的书
     * @param int $user_id
     * @param int $page
     * @return array
     * @author yangliang
     * @date 2021/2/25 11:23
     */
    public function borrowBook(int $user_id, int $page){
        $order_goods = OrderGoodsModel::getByUserIdAndIsRepay($user_id, 2, $page);
        if(empty($order_goods)){
            return $this->error(100, '获取成功', []);
        }

        //有值进行值拼接
        foreach ($order_goods as &$v) {
            $v['original_img'] = $this->getGoodsImg($v['original_img']);
            $attr_zz = GoodsAttributeModel::getByTypeIdAndAttrName($v['goods_type'], '作者');
            $attr_value = GoodsAttrModel::getAttrValueByGoodsIdAndAttrId($v['goods_id'], $attr_zz['attr_id'] ?? 212);
            $v['writer'] = !$attr_value['attr_value'] ? '未知' : $attr_value['attr_value'];  //作者

            $attr_xl = GoodsAttributeModel::getByTypeIdAndAttrName($v['goods_type'], '系列');
            $attr_value1 = GoodsAttrModel::getAttrValueByGoodsIdAndAttrId($v['goods_id'], $attr_xl['attr_id'] ?? 214);
            $v['series'] = !$attr_value1['attr_value'] ? '睿鼎少儿推荐' : $attr_value1['attr_value'];  //系列
        }

        return $this->success(200, '获取成功', $order_goods);
    }


    /**
     * 用户收藏的书
     * @param int $user_id
     * @param int $page
     * @return array
     * @author yangliang
     * @date 2021/2/25 11:50
     */
    public function collectBook(int $user_id, int $page){
        $goods_collect = GoodsCollectModel::getGoodsCollectByUserId($user_id,$page);
        if(empty($goods_collect)){
            return $this->success(200, '获取成功', []);
        }

        //有值进行值拼接
        foreach ($goods_collect as &$v){
            $v['original_img'] = $this->getGoodsImg($v['original_img']);
            $attr_zz = GoodsAttributeModel::getByTypeIdAndAttrName($v['goods_type'], '作者');
            $attr_value = GoodsAttrModel::getAttrValueByGoodsIdAndAttrId($v['goods_id'], $attr_zz['attr_id']);
            $v['writer'] = !$attr_value['attr_value'] ? '未知': $attr_value['attr_value'];  //作者

            $attr_xl = GoodsAttributeModel::getByTypeIdAndAttrName($v['goods_type'], '系列');
            $attr_value1 = GoodsAttrModel::getAttrValueByGoodsIdAndAttrId($v['goods_id'], $attr_xl['attr_id']);
            $v['series'] = !$attr_value1['attr_value'] ? '睿鼎少儿推荐': $attr_value1['attr_value'];  //系列
        }

        return $this->success(200, '获取成功', $goods_collect);
    }


    /**
     * 修改
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/25 12:00
     */
    public function update(int $user_id, array $data){
        $comment = GoodsCommentModel::getById($data['comment_id']);
        if($comment['is_type'] > 2){
            return $this->error(100, '已发布书评不能修改');
        }

        if($user_id != $comment['user_id']){
            return $this->error(100, '您没有该评论的修改权限');
        }
        $up_data = [];
        if(!empty($data['content_img'])){
            $up_data['content_img'] = $data['content_img'];
        }

        if(empty($data['content'])){
            return $this->error(100, '书评内容不能为空');
        }

        //获取用户正在进行中的任务
        $user_activity = GoodsCommentActivityUsersModel::getProcessingByUserId($user_id);
        if(!empty($user_activity)){
            //获取任务规则信息
            $activity = GoodsCommentActivityModel::getById($user_activity['activity_id']);
            //验证有效书评字数
            if(mb_strlen($data['content'], 'utf-8') < $activity['min_font_num']){
                throw new \Exception(sprintf('有效书评必须大于%d个字', $activity['min_font_num']));
            }
            //验证用户年卡是有有效
            $user_card = (new CommentActivityService())->checkUserCard($user_id);
            //任务只限年卡用户参与，记录任务ID、任务不限年卡用户参与，记录任务ID
            if(($activity['is_card'] == 1 && $user_card > 0) || $activity['is_card'] == 0){
                $up_data['activity_id'] = $user_activity['activity_id'] ?? 0;
            }
        }

        $up_data['content'] = base64_encode(trim($data['content']));
        $up_data['remark'] = '';
        $up_data['is_type'] = 1;
        $up_data['update_time'] = time();
        $up_data['is_robot'] = 0;
        $res = GoodsCommentModel::where('id', $data['comment_id'])->update($up_data);
        if(!$res){
            return $this->error(100, '修改失败');
        }

        return $this->success(200, '修改成功', $data['comment_id']);
    }


    /**
     * 删除
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/25 13:51
     */
    public function delete(int $user_id, array $data){
        if(empty($data['comment_id'])){
            return $this->error(100, '书评id不能为空');
        }

        Db::startTrans();
        try {
            if ($data['is_type'] == 1) {  //删除书评
                $res = GoodsCommentModel::where('id', $data['comment_id'])->where('user_id', $user_id)->update(['is_type' => 4, 'update_time' => time()]);
                if (!$res) {
                    throw new \Exception('删除失败');
                }
                GoodsCommentByModel::where('comment_id', $data['comment_id'])->update(['is_type' => 4, 'update_time' => time()]);
            } else {
                $top_ids = GoodsCommentByModel::getCountByTopId($data['comment_id']);
                if ($top_ids) {
                    $comment_id = GoodsCommentByModel::getById($data['comment_id']);
                    GoodsCommentModel::where('id', $comment_id['comment_id'])->dec('reply_num', $top_ids)->update();
                }

                $res = GoodsCommentByModel::where('id', $data['comment_id'])->where('user_id', $user_id)->update(['is_type' => 4, 'update_time' => time()]);
                if (!$res) {
                    throw new \Exception('删除失败');
                }
            }
        }catch (\Exception $e){
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }

        Db::commit();
        return $this->success(200, '删除成功');
    }


    /**
     * 获取单个评论下信息
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/25 14:42
     */
    public function getOneMessageData(int $user_id, array $data){
        if(empty($data['comment_id'])){
            return $this->error(100, '非常抱歉 请求异常 请刷新小程序后尝试。');
        }

        try {
            $click_num = GoodsCommentModel::getById($data['comment_id'])['click_num'] + 1;
            GoodsCommentModel::where('id', $data['comment_id'])->update(['click_num' => $click_num]);
            $goods_by_comment = GoodsCommentByModel::getListByTopIdAndCommentId($user_id, $data['comment_id'], $data['page'] ?? 1);
            foreach ($goods_by_comment as &$v){
                if(substr($v['user_name'], 0, 3) === 'VIP'){
                    $v['user_name'] = $v['nickname'];
                }

                $v['content'] = base64_decode($v['content']);
                $v['create_time'] = date('Y-m-d H:i', $v['create_time']);
                $v['by_comment_data'] = GoodsCommentByModel::getAllByTopIdAndUserId($v['by_comment_id'], $user_id);
                foreach ($v['by_comment_data'] as &$bv){
                    if(substr($bv['user_name'], 0, 3) === 'VIP'){
                        $bv['user_name'] = $bv['nickname'];
                    }
                    $bv['content'] = base64_decode($bv['content']);
                    $users = (new UserModel())->getOneUser($bv['by_user_id']);
                    if(substr($users['user_name'], 0, 3) === 'VIP'){
                        $bv['by_user_name'] = $users['nickname'];
                        if (!$users['nickname']) {
                            $bv['by_user_name'] = $users['user_name'];
                        }
                    }else{
                        $bv['by_user_name'] = $users['user_name'];
                    }

                    $bv['by_head_pic'] = $users['head_pic'];
                    $bv['create_time'] = date('Y-m-d H:i', $bv['create_time']);
                }
            }

            $goods_comment = GoodsCommentModel::getOneById($data['comment_id']);
            $dd = $this->ForSquare($user_id, [$goods_comment], 1);
            array_unshift($goods_by_comment, $dd[0]);
        }catch (\Exception $e){
            return $this->error(100, $e->getMessage());
        }

        return $this->success(200, '获取成功', $goods_by_comment);
    }


    /**
     * 获取我的信息
     * @param int $user_id
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2021/2/25 15:46
     */
    public function getCommentUser(int $user_id, array $data){
        if($data['type'] == 1){
            $res_number = GoodsCommentByModel::getCountByUserIdAndIsTypeAndIslook($user_id, 3, 1);
            $res_number += count(GoodsCommentPointModel::getListByUserIdAndIsLook($user_id, 1));
            return $this->success(200, 'success', ['number' => $res_number]);
        }else{
            //2020-8-18 修改为屏蔽后的消息可见
            $list = GoodsCommentByModel::getListByUserIdAndIsType($user_id, 3, $data['page'] ?? 1);
            foreach ($list as &$v){
                GoodsCommentByModel::where('id', $v['by_comment_id'])->update(['is_look' => 2]);
                $v['original_img'] = GoodsModel::getByGoodsId($v['goods_id'])['original_img'];
                $v['is_point'] = 1;  //是否是评论
                $v['create_time'] = date('Y-m-d H:i', $v['create_time']);//时间
                if(substr($v['user_name'], 0, 3) === 'VIP'){
                    if ($v['nickname']) {
                        $v['user_name'] = $v['nickname'];
                    }
                }

                $v['content'] = base64_decode($v['content']);
                if(substr($v['by_user_name'], 0, 3) === 'VIP'){
                    if ($v['by_nickname']) {
                        $v['by_user_name'] = $v['by_nickname'];
                    }
                }

                if ($v['superior_id']) {
                    $v['by_content'] =  GoodsCommentByModel::getById($v['superior_id'])['content'];
                    $v['by_content_id'] = $v['superior_id'];
                } elseif ($v['top_id']) {
                    $v['by_content'] = GoodsCommentByModel::getById($v['top_id'])['content'];
                    $v['by_content_id'] = $v['top_id'];
                } else {
                    $v['by_content'] = GoodsCommentByModel::getById($v['comment_id'])['content'];
                    $v['by_content_id'] = $v['comment_id'];
                }
                $v['by_content'] = base64_decode($v['by_content']);
            }

            $point_list = GoodsCommentPointModel::getByUserIdAndIsType($user_id, 3, $data['page'] ?? 1);
            $list_p = [];
            foreach ($point_list as $k => $pv){
                GoodsCommentPointModel::where('is_look', 1)->update(['is_look' => 2,'update_time'=>time()]);
                $list_p[$k]['user_id'] = $pv['user_id'];
                $users = (new UserModel())->getOneUser($pv['user_id']);
                if(substr($users['user_name'], 0, 3) === 'VIP'){
                    $list_p[$k]['user_name'] = $users['nickname'];
                    if (!$users['nickname']) {
                        $list_p[$k]['user_name'] = $users['user_name'];
                    }
                }

                $list_p[$k]['head_pic'] = $users['head_pic'];
                $list_p[$k]['content'] = base64_decode($pv['content']);
                $list_p[$k]['create_time'] = date('Y-m-d H:i',$pv['create_time']);
                $list_p[$k]['by_user_id'] = $user_id;
                $list_p[$k]['original_img'] = GoodsModel::getByGoodsId($pv['goods_id'])['original_img'];
                $list_p[$k]['is_point'] = 0;  //是否是评论
                $list_p[$k]['is_look'] = $pv['is_look'];//是否是评论
                $list_p[$k]['comment_id'] = $pv['comment_id'];//是否是评论
            }

            return $this->success(200, '获取成功', quickSort(array_merge($list, $list_p), 'create_time'));
        }
    }


    /**
     * 获取书籍下评论
     * @param int $user_id
     * @param int $goods_id
     * @param $page
     * @return array
     * @author yangliang
     * @date 2021/2/25 16:05
     */
    public function getComment(int $user_id, int $goods_id, $page){
        $goods_comment = GoodsCommentModel::getComment($user_id, $goods_id, $page);
        return $this->success(200, '获取成功', $this->ForSquare($user_id, $goods_comment, 0));
    }
}