<?php
declare(strict_types=1);

namespace app\common\service;


use app\common\model\FamilyActivityBrowseModel;
use app\common\model\FamilyActivityBusinessModel;
use app\common\model\FamilyActivityCampPeriodModel;
use app\common\model\FamilyActivityCarouselModel;
use app\common\model\FamilyActivityCatModel;
use app\common\model\FamilyActivityContractModel;
use app\common\model\FamilyActivityInviteModel;
use app\common\model\FamilyActivityModel;
use app\common\model\FamilyActivityOrderModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use Exception;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

class FamilyActivityService extends ComService
{
    use CacheTrait;

    /**获取亲子活动分类
     * @return array
     * @author Poison
     * @date 2020/11/17 11:22 上午
     */
    public function getCat(): array
    {
        $familyActivityCatData = (new FamilyActivityCatModel())->selectCatData();
        foreach ($familyActivityCatData as $k =>$v){
            $familyActivityCatData[$k]['img'] = config('self_config.cdn_address') .$v['img'];
        }
        return $this->success(200, '获取成功', $familyActivityCatData);
    }


    /**
     * 活动列表
     * @param array $params
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/13 15:29
     */
    public function getActivityList(array $params): array
    {
        return $this->success(200, '获取成功', self::buildActivityListData(FamilyActivityModel::getList($params)));
    }


    /**
     * 获取首页推荐活动
     * @param array $params 请求参数（分页）
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/12 17:31
     */
    public function getRecommend(array $params): array
    {
        return $this->success(200, '获取成功', self::buildActivityListData(FamilyActivityModel::getRecommand((int)$params['page'])));
    }


    /**
     * 获取活动轮播图
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/13 9:49
     */
    public function getBanner(): array
    {
        //获取活动轮播图
        $res = FamilyActivityCarouselModel::getBanner();
        if(!empty($res)){
            foreach ($res as &$v){
                $v['img'] = !empty($v['img']) ? $this->getGoodsImg($v['img']) : '';
                $v['note'] = !empty($v['note']) ? explode(',', $v['note']) : [];
            }
        }

        return $this->success(200, '获取成功', $res);
    }


    /**
     * 活动搜索
     * @param $params
     * @return array
     * @author yangliang
     * @date 2021/4/13 13:40
     */
    public function search($params): array
    {
        $list = !empty($params['keywords']) ? FamilyActivityModel::search($params) : [];
        if(!empty($list)){
            foreach ($list as &$v){
                $v['tags'] = !empty($v['tags']) ? explode(',', $v['tags']) : '';
                $v['cover_img'] = !empty($v['cover_img']) ? getGoodsImg($v['cover_img']) : '';
                $v['checkIn'] = ($v['checkIn'] > 9999) ? '9999+' : (int)$v['checkIn'];
            }
        }

        return $this->success(200, '获取成功', $list);
    }


    /**
     * 处理活动列表数据
     * @param array $data
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/13 15:34
     */
    private function buildActivityListData(array $data): array
    {
        if(empty($data)){
            return [];
        }
        foreach ($data as &$v){
            $v['tags'] = !empty($v['tags']) ? explode(',', $v['tags']) : '';
            $v['price'] = FamilyActivityCampPeriodModel::getMinPriceByActivityId($v['id'])['price'] ?? 0;  //活动最低价格
            $checkIn = FamilyActivityOrderModel::getCountUserByActivityId($v['id']) ?? 0;
            $v['cover_img'] = !empty($v['cover_img']) ? getGoodsImg($v['cover_img']) : '';
            $v['checkIn'] = ($checkIn > 9999) ? '9999+' : $checkIn;
        }

        return $data;
    }


    /**
     * 活动详情
     * @param int $user_id  用户ID
     * @param array $params
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/15 10:13
     */
    public function getInfo(int $user_id, array $params): array
    {
        $activity = FamilyActivityModel::getActivityInfoById($params['activity_id']);
        if(empty($activity)){
            return $this->success(200, '获取成功');
        }

        //封面图片
        $activity['cover_img'] = !empty($activity['cover_img']) ? $this->getGoodsImg($activity['cover_img']) : '';
        //轮播图
        $carousel_img = explode(',', $activity['carousel_img']);
        if(!empty($carousel_img)){
            foreach ($carousel_img as &$iv){
                $iv = $this->getGoodsImg($iv);
            }
        }
        $activity['carousel_img'] = $carousel_img;

        //亮点标签
        $activity['tags'] = !empty($activity['tags']) ? explode(',', $activity['tags']) : [];
        //活动最低价格
        $activity['price'] = FamilyActivityCampPeriodModel::getMinPriceByActivityId($activity['id'])['price'] ?? 0;
        //报名人数
        $checkIn = FamilyActivityOrderModel::getCountUserByActivityId($activity['id']) ?? 0;
        $activity['checkIn'] = ($checkIn > 9999) ? '9999+' : $checkIn;

        //营种状态  0-正常  1-敬请期待（未开售）  2-报名截止  3-已售罄
        $activity_status = ($activity['is_can_buy'] == 0) ? 1 : 0;

        $is_sold_out = 1;  //营种是否已售罄   0-否   1-是
        $is_deadline = 1;  //营种是否报名截止  0-否  1-是

        //营期
        $period = FamilyActivityCampPeriodModel::getByActivityId($activity['id']);
        if(!empty($period)){
            foreach ($period as &$v){
                $v['month'] = (int)date('m', $v['start_time']);
                $v['day'] = date('d', $v['start_time']);
                $v['week'] = $this->getWeek($v['start_time']);

                //营期状态  0-可报名 1-未开售  2-报名截止 3-已满员（已售罄）
                $period_status = 0;
                //营种状态正常则查询营期状态，否则所有营期默认未开售
                if($activity_status != 1) {
                    //报名未截止不更新营种报名截止状态
                    if ($v['deadline'] > time()) {
                        $is_deadline = 0;
                    } else {
                        $period_status = 2;
                    }

                    //成人儿童报名人数未满,不更新营种售罄状态
                    if ($v['adult_stock'] > 0 && $v['child_stock'] > 0) {
                        $is_sold_out = 0;
                    } else {
                        $period_status = 3;
                    }
                }else{
                    $period_status = 1;  //营期状态未开售
                    $is_sold_out = 0;  //营种状态-未售罄
                    $is_deadline = 0;  //营种状态-报名未截止
                }
                $v['period_status'] = $period_status;
            }
        }

        //营期全部报名截止，同步营种状态为报名截止
        if($is_deadline == 1){
            $activity_status = 2;
        }

        //营期全部售罄，同步营种状态为已售罄
        if($is_sold_out == 1){
            $activity_status = 3;
        }
        $activity['activity_status'] = $activity_status;
        $activity['period'] = $period;

        //浏览记录
        FamilyActivityBrowseModel::create([
            'user_id' => $user_id,
            'family_activity_id' => $params['activity_id'],
            'create_time' => time()
        ]);

        return $this->success(200, '获取成功', $activity);
    }


    /**
     * 获取时间戳是周几
     * @param int $timestamp  时间戳
     * @return string
     * @author yangliang
     * @date 2021/4/15 10:32
     */
    private function getWeek(int $timestamp): string
    {
        $week = date('w', $timestamp);
        $arr = [
            '0' => '周日',
            '1' => '周一',
            '2' => '周二',
            '3' => '周三',
            '4' => '周四',
            '5' => '周五',
            '6' => '周六',
        ];
        return $arr[$week];
    }


    /**
     * 精彩活动
     * @param array $params
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/15 11:26
     */
    public function getWonderful(array $params): array
    {
        return $this->success(200, '获取成功', self::buildActivityListData(FamilyActivityModel::getWonderful($params['page'])));
    }


    /**
     * 获取合同
     * @param int $contract_id  合同ID
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/15 14:26
     */
    public function getContract(int $contract_id): array
    {
        $imgs = [];
        $res = FamilyActivityContractModel::getById($contract_id);
        if(!empty($res)){
            $imgs = explode(',', $res['imgs']);
            foreach ($imgs as &$v){
                $v = $this->getGoodsImg($v);
            }
        }
        $res['imgs'] = $imgs;
        return $this->success(200, '获取成功', $res);
    }


    /**
     * 邀请绑定关系
     * @param int $user_id  用户ID
     * @param int $business_user_id  被邀请人ID
     * @return array
     * @author: yangliang
     * @date: 2021/5/6 16:43
     */
    public function invite(int $user_id, int $business_user_id): array
    {
        try {
            if (empty((new UserModel())->getOneUser((int)$business_user_id))) {
                return $this->error(100, '邀请人信息不存在');
            }

            //验证邀请人信息
            $business_user = FamilyActivityBusinessModel::getByUserId((int)$business_user_id);
            if (empty($business_user) || $business_user['is_can_use'] == 0) {
                return $this->error(100, '邀请信息已失效');
            }
            //绑定关系
            self::bindRelation($business_user_id, $user_id);
        }catch (Exception $e){
            return $this->error(100, $e->getMessage());
        }

        return $this->success(200, '绑定成功');
    }


    /**
     * 绑定关系
     * @param int $business_user_id  邀请人ID
     * @param int $user_id  被邀请人ID
     * @throws Exception
     * @author: yangliang
     * @date: 2021/5/7 9:20
     */
    public function bindRelation(int $business_user_id, int $user_id){
        //存在已锁定的关系，不允许绑定新关系
        if(!empty(FamilyActivityInviteModel::getByUserId($user_id))){
            throw new Exception('已存在绑定关系');
        }

        //创建绑定关系
        $res = FamilyActivityInviteModel::create([
            'family_activity_business_user_id' => $business_user_id,
            'user_id' => $user_id,
            'is_lock' => 1,
            'create_time' => time(),
            'update_time' => time()
        ]);

        if(!$res){
            throw new Exception('绑定失败');
        }
    }
}