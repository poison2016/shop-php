<?php


namespace app\common\service;


use app\common\model\CatModel;
use app\common\model\GoodsModel;
use app\common\model\GoodsSearchRecordModel;
use app\common\model\OrderGoodsModel;

class SearchService extends ComService
{
    /**搜索内容
     * @param array $data
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/28 2:39 下午
     */
    public function getSearch(array $data)
    {
        $searchName = (string)$data['search_name'];//搜索的内容
        $page = ($data['page'] - 1) * config('self_config.goods_limit');
        $isShow = (int)$data['is_show'];//是否有货
        $userId = (int)$data['user_id'];
        //查询商品数据
        $where[] = ['goods_name', 'like', '%' . $searchName . '%'];
        if ($isShow) {
            $where[] = ['now_store', '>', 0];
        }
        $goodsData = (new GoodsModel())->getGoodsData($where, $page, config('self_config.goods_limit'));
        $goodsService = new GoodsService();
        foreach ($goodsData as $k => $v) {
            $goodsData[$k] = $goodsService->getGoodsAttrs($v, $userId);
        }
        return self::success(200, '获取成功', $goodsData);
    }

    /**写入搜索值
     * @param array $params
     * @return array
     * @author Poison
     * @date 2021/1/29 2:13 下午
     */
    public function searchName(array $params){
        //查询搜索值
        $searchCount = (new GoodsSearchRecordModel())->where('search_name',$params['search_name'])->where('user_id',$params['user_id'])->count();
         if($searchCount <=0){
             $recordData['goods_id'] = $params['goods_id'] ?? 0;
             $recordData['user_id'] = $params['user_id'];
             $recordData['search_name'] = $params['search_name'];
             (new GoodsSearchRecordModel())->addData($recordData);
         }
        return self::success(200,'提交成功');
    }

    /**清除搜索内容
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/1/28 2:42 下午
     */
    public function clearSearch(int $userId){
       $res =  (new GoodsSearchRecordModel())->where('user_id',$userId)->update(['status'=>99,'update_time'=>time()]);
       if(!$res){
         return self::error(100,'清除失败');
       }
       return self::success(200,'清除成功');
    }

    /**获取热门数据
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/29 9:55 上午
     */
    public function hot(){
        $res = [];
        self::getHot($res,'content');
        return self::success(200,'获取成功',$res);
    }

    /**对外接口 搜索前页面数据
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/28 9:22 上午
     */
    public function record(int $userId)
    {
        //获取查询词
        $recordData['record'] = (new GoodsSearchRecordModel())->getSearch($userId);//获取搜索内容
        //self::getHot($recordData);//获取热门书籍
        //获取相关推荐
        self::recommend($userId, $recordData);
        return self::success(200, '成功', $recordData);
    }

    /**获取相关书籍
     * @param int $userId
     * @param $recordData
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/28 2:15 下午
     */
    public function recommend(int $userId, &$recordData)
    {
        $resSearchRecord = (new GoodsSearchRecordModel())->field('goods_id')->where('user_id', $userId)->limit(2)->order('id', 'DESC')->select()->toArray();
        $searchArray = [];
        if ($resSearchRecord) {//如果存在
            self::forData($resSearchRecord, $searchArray);
        }
        //判断是否满足10条 如果不满足 进行订单取
        $recordData['recommend'] = $searchArray;
        $searchArray = [];//初始化
        $lowCount = 10 - count($recordData['recommend']);
        if ($lowCount > 0) {//如果不够十本 继续加入
            //查询用户订单
            $orderGoodsData = (new OrderGoodsModel())->field('goods_id')->where('user_id', $userId)->order('rec_id', 'DESC')->limit(2)->select()->toArray();
            self::forData($orderGoodsData, $searchArray);
            if ($searchArray) {
                $searchArray = array_slice($searchArray, 0, $lowCount);
                $recordData['recommend'] = array_merge($recordData['recommend'], $searchArray);
            }
        }
        //如果还是不够 那么去热搜接口补充进来
        $lowCount = 10 - count($recordData['recommend']);
        if ($lowCount > 0) {
            self::getHot($hotData, 'recommend');//获取热门书籍
            if($hotData){
                $hotLowData = array_slice($hotData, 0, 5);
                $recordData['recommend'] = array_merge($recordData['recommend'], $hotLowData);
            }
        }
    }

    /**更加商品id循环查询
     * @param $data
     * @param $searchArray
     * @author Poison
     * @date 2021/1/28 11:59 上午
     */
    protected function forData($data, &$searchArray)
    {
        $catModel = new CatModel();
        foreach ($data as $k => $v) {
            if ($v['goods_id'] > 0) {
                //二次分类
                $catData = $catModel->getOneGoodsCat(['rd_cat.cat_type' => 0, 'gc.goods_id' => $v['goods_id']]);
                if ($catData && isset($catData['cat_id'])) {
                    self::getCatGoods(2, $catData['cat_id'], $searchArray);
                }
                //一级分类
                $parentCatName = isset($catData['parent_id']) ? $catModel->field('name,id')->where('id', $catData['parent_id'])->findOrEmpty()->toArray() : '';
                if ($parentCatName) {
                    self::getCatGoods(1, $catData['parent_id'], $searchArray);
                }
            }
        }
    }

    /**获取分类数据
     * @param int $status
     * @param int $catId
     * @param $searchArray
     * @author Poison
     * @date 2021/1/28 10:46 上午
     */
    protected function getCatGoods(int $status = 1, int $catId, &$searchArray)
    {
        $d = [];
        $where[] = ['gd.now_store', '>', 0];
        if ($status == 2) {//二级分类
            $where[] = ['gc.cat_id', '=', $catId];
            $data = (new CatModel())->getGoodsCat($where);
            shuffle($data);
            $d = array_slice($data, 0, 3);
        } else {
            $where[] = ['rc.parent_id', '=', $catId];
            $data = (new CatModel())->getGoodsCat($where);
            shuffle($data);
            $d = array_slice($data, 0, 2);
        }
        unset($where);
        foreach ($d as $k => $v) {
            $v['original_img'] = self::getGoodsImg($v['original_img']);
            $searchArray[] = $v;
        }
    }

    /**获取热门书籍
     * @param $recordData
     * @param $type
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/28 9:19 上午
     */
    public function getHot(&$recordData, $type = "hot")
    {
        //获取热门书籍
        $where[] = ['is_hot', '=', 1];
        $where[] = ['now_store', '>', 0];
        $goodsModel = new GoodsModel();
        $hot = $goodsModel->getGoodsData($where, 1, 30);
        unset($where);
        $where[] = ['is_new', '=', 1];
        $where[] = ['is_hot', '=', 0];
        $where[] = ['now_store', '>', 0];
        $new = $goodsModel->getGoodsData($where, 1, 30);
        unset($where);
        $hotList = array_merge($hot, $new);
        if(!$hotList){
            return $recordData;
        }
        shuffle($hotList);
        $len = self::randNumber(count($hotList));
        $i = 0;
        foreach ($hotList as $k => $v) {
            $i++;
            if ($k >= $len && $k < ($len + 8)) {
                $v['original_img'] = self::getGoodsImg($v['original_img']);
                if ($type == 'hot') {
                    $recordData[$type][] = $v;
                } else {
                    $recordData[] = $v;
                }

            }
        }
    }

    /**随机截取数据
     * @param int $count
     * @param int $length
     * @return int
     * @author Poison
     * @date 2021/1/28 2:16 下午
     */
    private function randNumber(int $count, $length = 8)
    {
        $res = rand(0, $count);
        if (($count - $length) < $res) {
            return self::randNumber($count, $length);
        }
        return $res;
    }

}