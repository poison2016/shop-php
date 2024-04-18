<?php


namespace app\common\service;


use app\common\model\AdModel;
use app\common\model\NavBannerModel;
use app\common\model\PickUpModel;
use app\common\model\StoresModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use app\common\traits\QrCodeTrait;

/**
 *
 * Class IndexService
 * @package app\common\service
 * @author liyongsheng
 * @date 2020/11/5 10:08
 */
class IndexService extends ComService
{
    use CacheTrait;
    use QrCodeTrait;

    /**
     * 获取banner
     * @param $isBuy
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author liyongsheng
     * @date date
     */
    public function banner($isBuy): array
    {
        $list = NavBannerModel::getList($isBuy);
        if (count($list) == 0) {
            return $this->error(0, '暂无数据');
        } else {
            return $this->success(200, '成功', $list);
        }
        
    }

    /** 获取所有门店+配送点的信息
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/11/18 9:38 上午
     */
    public function getPickUpStaticData(): array
    {
        //获取redis是否存放数据
        $resData = json_decode($this->getCache('pick_up_cache'), true);
        if (!$resData) {
            $resPickData = (new PickUpModel())->selectPickData();//查询配送点
            $resStoreData = (new StoresModel())->selectData();//查询门店
            foreach ($resStoreData as $k => $v) {
                $resStoreData[$k]['is_collection'] = 10;
            }
            $resData = array_merge($resPickData, $resStoreData);
            $this->setCache('pick_up_cache', json_encode($resData));
        }
        return $this->success(200, '获取成功', $resData);
    }
    
    /**
     * 二维码地址生成
     * @param string $code
     * @param array $param
     * @param int $userId
     * @return array
     * @author liyongsheng
     * @date 2020/12/16 23:50
     */
    public function createInviteCode(string $code, array $param = [], int $userId = 0): array
    {
        try {
            $str = $this->returnQrcodeImg($code, $param, $userId);
            return $this->success(200, '获取成功', $str);
        } catch (\Exception $e) {
            return $this->error(0, '生成失败');
        }
    }

    /**获取
     * @param $userId
     * @return array
     * @author Poison
     * @date 2020/12/24 3:07 下午
     */
    public function getAds($userId) {
        $ads = (new AdModel())->where('is_show',1)->order('id','DESC')->select()->toArray();
        if (!$ads) {
            return $this->error(0, '暂无数据');
        }
        foreach ($ads as $k=>$v){
            $ads[$k]['ad_img'] = getGoodsImg($v['ad_img']);
            $ads[$k]['is_ad'] = 0;// 是否已看过广告:0否(默认)1是
        }

        // 判断当前用户是否已经看过广告
//        if ($userId) {
//            $user = (new UserModel())->getOneUser($userId);
//            // 已看广告ID
//            if ($user['is_ad']) {
//                // 判断当前广告是否为已看过广告
//                if ($ads['id'] == $user['is_ad']) {
//                    $ads['is_ad'] = 1;
//                    $adHour = $ads['ad_hour'];
//                    if ($adHour > 0) {
//                        $adHourTime = 86400 * $adHour;
//                        // 已超过间隔时间
//                        if (time() + $adHourTime > $user['ad_time']) {
//                            $ads['is_ad'] = 0;
//                        }
//                    }
//                }
//            }
//        }

        return $this->success(200,'获取成功',$ads??[]);
    }


    /**
     * 获取首页快讯公告
     * @return array
     * @author yangliang
     * @date 2021/2/20 10:14
     */
    public function getNews(){
        $newsResult[] = ['title' => '睿鼎少儿新会员系统正式上线', 'url' => 'https://mp.weixin.qq.com/s/SDSk05lnSYsAevD2vZmcXw'];
        return $this->success(200, '获取成功', $newsResult);
    }
}