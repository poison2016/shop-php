<?php

/**
 * 商品业务相关
 */


namespace app\common\service;


use app\common\ConstLib;
use app\common\model\CartModel;
use app\common\model\CatModel;
use app\common\model\GoodsAttentionModel;
use app\common\model\GoodsAttributeModel;
use app\common\model\GoodsAttrModel;
use app\common\model\GoodsCatModel;
use app\common\model\GoodsCollectModel;
use app\common\model\GoodsCommentByModel;
use app\common\model\GoodsCommentModel;
use app\common\model\GoodsCommentPointModel;
use app\common\model\GoodsImagesModel;
use app\common\model\GoodsModel;
use app\common\model\GoodsReadModel;
use app\common\model\GoodsSearchModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\SearchMatchingGoodsModel;
use app\common\model\SelfCheckLogModel;
use app\common\model\UserModel;
use app\common\traits\CacheTrait;
use think\facade\Log;
use think\Model;

class GoodsService extends ComService
{
    use CacheTrait;

    /**借阅排行榜
     * @param array $params
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2020/11/9 2:41 下午
     */
    public function readRanking(array $params, int $userId)
    {

        //获取redis是否存放数据
        $resGoodsData = json_decode($this->getCache('read_top_100'), true);
        if (!$resGoodsData) {
            //连表查询借阅Top100
            $resGoodsData = (new GoodsCatModel())->selectGoodsLimitData(944);
            $resGoodsData = $GoodsData = self::bubble_sort($resGoodsData, 'goods_read');
            $this->setCache('read_top_100', json_encode($GoodsData), 60 * 60 * 3);
        }
        $page = ($params['page'] - 1) * config('self_config.goods_limit');
        $length = config('self_config.goods_limit');
        $GoodsDataLimit = array_slice($resGoodsData, $page, $length);
        //开始准备下面的数据
        foreach ($GoodsDataLimit as $k => $v) {
            unset($GoodsDataLimit[$k]['goods_read']);
            $GoodsDataLimit[$k] = $this->getGoodsAttrs($v, $userId);
        }

        return $this->success(200, '获取成功', $GoodsDataLimit);
    }

    /**
     * 根据商品分类ID获取商品列表
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2020/10/29 11:09
     */
    public function getGoodsListByCatId(array $data): array
    {
        $list = GoodsCatModel::getGoodsListByCatId($data['cat_id'], $data['page'], $data['rn']);
        if (!empty($list)) {
            foreach ($list as &$v) {
                $v = self::getGoodsAttrs($v, $data['userId']);
            }
        }
        return $this->success(200, '请求成功', $list);
    }


    /**智能选书---》获取分类
     * @param array $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/11/9 2:51 下午
     */
    public function getAiAge(array $params)
    {
        $catData = (new CatModel())->field('id AS parent_id,name')->where(['parent_id' => $params['parent_id'], 'cat_type' => 0, 'is_show' => 1])->select()->toArray();
        return $this->success(200, '获取成功', $catData);
    }

    /** 获取商品详情 ---》通用方法
     * @param array $goods
     * @param int $user_id
     * @param int $status
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/11/10 10:23 上午
     */
    public function getGoodsAttrs(array $goods, int $user_id = 0, int $status = 0)
    {
        //判断参数是否有效
        if (!$goods || !$goods['goods_id']) {
            return $this->error(100, '参数错误');
        }
        $goodsId = (int)$goods['goods_id'];
        $goods['cats'] = [];// 分类
        $goods['tags_id'] = [];// 分类id
        $goods['tags'] = [];// 标签
        // 商品主图片
        $goods['original_img'] = $this->getGoodsImg($goods['original_img'], 400, 400);
        // 阅读量
        $goods['read'] = $read = (new GoodsReadModel())->where('goods_id', $goodsId)->value('goods_read', 0);
        $rand = mt_rand(1, 9);
        $rand_ = mt_rand(50, 80);
        switch ($read) {
            case $read >= 0 && $read <= 30:
                $goods['read'] = ($read + $rand_) * 10 + $rand;
                break;
            case $read > 30 && $read <= 50:
                $goods['read'] = ($read + $rand_) * 30 + $rand;
                break;
            case $read > 50 && $read <= 80:
                $goods['read'] = ($read + $rand_) * 50 + $rand;
                break;
            case $read > 80 && $read <= 100:
                $goods['read'] = ($read + $rand_) * 100 + $rand;
                break;
            case $read > 100 && $read <= 500:
                $goods['read'] = ($read + $rand_) * 50 + $rand;
                break;
            case $read > 500 && $read <= 1000:
                $goods['read'] = ($read + $rand_) * 30 + $rand;
                break;
            default:
                $goods['read'] = $read * 30 + $rand;
                break;
        }
        $goods['is_cart'] = (new CartModel())->where(['user_id' => $user_id, 'goods_id' => $goodsId])->value('id') ?? 0;
        $goods['is_collect'] = (new GoodsCollectModel())->where(['user_id' => $user_id, 'goods_id' => $goodsId])->value('collect_id') ?? 0;
        // 是否馆藏书籍
        $gcCount = (new GoodsCatModel())->where(['goods_id' => $goodsId, 'cat_id' => 422])->count();
        $goods['is_borrowed'] = $gcCount > 0 ? 0 : 1;
        if ($status == 1) {
            // 商品图册
            $goodsImgData = [];
            $goodsImg = (new GoodsImagesModel())->getGoodsImg($goodsId);
            foreach ($goodsImg as $key => $v) {
                $goodsImgData[$key] = $this->getGoodsImg($v['image_url']);
            }
            $goods['goods_imgs'] = $goodsImgData;

            $tags = CatModel::getCatTag($goodsId);
            if ($tags) {
                foreach ($tags as $k => $tag) {
                    $goods['tags'][] = $tag['name'];
                    $goods['tags_id'][] = $tag['id'];
                    if ($tag['cat_type'] == 3) {// 标签

                    } else {// 分类
                        $goods['cats'][] = $tag['name'];
                    }
                }
            }
        }
        if(isset($goods['tags']) && $goods['tags']){
            //找出指定范围内的信息
            $goods['tags'] = array_slice(self::getTags($goods['tags'],$goods['tags_id']),0,4);
        }
        $goods['real_stock'] = (new GoodsModel())->where('goods_id', $goodsId)->value('now_store');
        $goods['is_read'] = (new OrderModel())->getOrderRead($goodsId, $user_id) ?? 0;
        // 商品属性
        $goodsType = isset($goods['goods_type']) ? $goods['goods_type'] : 1;
        $attrs = GoodsAttributeModel::getAttributeData($goodsType);
        $goods['series'] = '睿鼎少儿精选图书';
        $goods['reason'] = '睿鼎少儿推荐';
        if ($status == 1) {
            $goods['author'] = '-';
            $goods['publish'] = '-';
            $goods['isbn'] = '-';
            $goods['booksize'] = '-';
            $goods['page'] = '-';
        }
        //商品分类
        $searchArray = [];
        $catName = (new CatModel())->getOneGoodsCat(['rd_cat.cat_type' => 0, 'gc.goods_id' => $goods['goods_id']]);
        if ($catName) {//如果有第一分类 进行处理 没有 不处理
            $parentCatName = (new CatModel())->field('name,id')->where('id', $catName['parent_id'])->findOrEmpty()->toArray();
            $searchArray[] = $parentCatName['name'] ?? '';
        }
        $catName = (new CatModel())->getGoodsCat(['rd_cat.cat_type' => 3, 'gc.goods_id' => $goods['goods_id']],3);
        foreach ($catName as $v){
            $searchArray[] = $v['name'];
        }
        $goods['classify'] = $searchArray;
        if ($attrs) {

            $ids = array_column($attrs, 'attr_id');
            $attrValue = GoodsAttrModel::getAttrValueByAttrIds($goodsId, $ids);
            $attrValue = self::changeIndex($attrValue, 'attr_id');

            foreach ($attrs as $attr) {
                switch ($attr['attr_name']) {
                    case '系列':
                        $goods['series'] = $attrValue[$attr['attr_id']]['attr_value'] ?? '睿鼎少儿精选图书';
                        break;
                    case '上架理由':
                        $goods['reason'] = $attrValue[$attr['attr_id']]['attr_value'] ?? '睿鼎少儿推荐';
                        break;
                }

                if ($status == 1) {
                    // 属性
                    switch ($attr['attr_name']) {
                        case '作者':
                            $goods['author'] = $attrValue[$attr['attr_id']]['attr_value'] ?? '-';
                            break;
                        case '出版社':
                            $goods['publish'] = $attrValue[$attr['attr_id']]['attr_value'] ?? '-';
                            break;
                        case 'ISBN':
                            $goods['isbn'] = $attrValue[$attr['attr_id']]['attr_value'] ?? '-';
                            break;
                        case '开本':
                            $goods['booksize'] = $attrValue[$attr['attr_id']]['attr_value'] ?? '-';
                            break;
                        case '页码':
                            $goods['page'] = $attrValue[$attr['attr_id']]['attr_value'] ?? '-';
                            break;
                    }
                }
            }
        }

        return $goods;
    }
    protected function getTags($data,$tagId){
        foreach ($data as $k=> $v){
           $res =  self::selectTags($tagId[$k]);
           if(!$res){
              unset($data[$k]);
           }
        }
        return array_merge($data);
    }
    protected function selectTags($id){
        $res = (new CatModel())->where('id',$id)->findOrEmpty()->toArray();
        if($res && $res['parent_id']){
            return self::selectTags($res['parent_id']);
        }else{
            if(!in_array($id,config('self_config.tags_id_array'))){
                return false;
            }
        }
        return true;
    }

    /**获取商品信息
     * @param array $data
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/19 10:54 上午
     */
    public function getGoodsList(array $data): array
    {
        $userId = (int)$data['user_id'] ?? 0;
        $data['page'] = $data['page'] ?? 1;
        $data['is_stock'] = $data['is_stock'] ?? 0;
        $data['three_level_id'] = $data['three_level_id'] ?? 0;
        $data['search'] = $data['search'] ?? 0;
        if ($data['search']) {
            $data['search'] = self::replaceChar($data['search'], 1);
            $data['search'] = self::filterEmoji($data['search']);
        }
        $data['status'] = $data['status'] ?? 1;
        $result = [];
        switch ($data['status']) {
            case 1://首页数据
                $type = $data['type'] ?? 'hot';
                $favGoodsIds = [];//猜你喜欢分类ID
                if ($type == 'fav') {
                    if (!$userId) {
                        return self::success(200, '暂无数据', []);
                    }
                    $favGoodsIdsName = 'fav_goods_ids_' . $userId;
                    $favGoodsIds = self::getCache($favGoodsIdsName);
                    if (!$favGoodsIds) {
                        $favGoodsIds = self::getUserFavGoodsIds($userId);
                        if ($favGoodsIds) {
                            self::setCache($favGoodsIdsName, json_encode($favGoodsIds), config('self_config.goods_list_fav_expire'));
                        }
                    }
                }
                $result = self::getTypeGoodsList($type, $data['page'], $data['search'], $favGoodsIds, $userId);
                if ($result && $data['page'] == 1) {
                    $goodsCount = count($result);
                    // 保持偶数个商品
                    if ($goodsCount % 2 != 0) {
                        array_pop($result);
                    }
                }
                break;
            case 2://分类
                $parentCatId = $data['parent_cat_id'] ?? 0;
                $catId = $data['cat_id'] ?? 0;
                $result = self::getCatGoodsList($parentCatId, $catId, $data['page'], $data['search'], $data['is_stock'], $userId, $data['three_level_id']);
                break;
            case 3://学校
                $schoolId = $data['school_id'] ?? 0;
                $gradeId = $data['grade_id'] ?? 0;
                if ($schoolId > 0 && $gradeId > 0) {
                    $result = self::getSchoolGoodsList($schoolId, $gradeId, $data['page'], $data['search'], $userId);
                }
                break;
            case 4://搜索
                $result = self::getSearchGoodsList($data['page'], $data['search'], $userId);
                break;
            case  5://同类推荐
                $goodsId = $data['goods_id'];
                if ($goodsId) {
                    $result = self::getCache('series_goods_list_' . $goodsId);
                    if ($result) {
                        $result = json_decode($result, true);
                    } else {
                        //所属系列名称
                        $series = (new GoodsAttrModel())->where(['goods_id' => $goodsId, 'attr_id' => 214])->value('attr_value');
                        if ($series) {
                            $seriesGoodsList = [];// 同系列商品
                            $seriesGoodsIds = (new GoodsAttrModel())->where(['attr_id' => 214, 'attr_value' => $series])
                                ->where('goods_id', '<>', $goodsId)->column('goods_id');
                            if ($seriesGoodsIds) {
                                $seriesGoodsList = (new GoodsModel())->getGoodsByGoodsIds($seriesGoodsIds);//同类商品
                            }
                            $catGoodsList = [];
                            $left = config('self_config.index_limit') - count($seriesGoodsIds);
                            if ($left > 0) {
                                //获取用户已借阅过的书籍分类（最后一本书的对应分类）
                                $catId = self::getUserFavCatId((int)$userId);
                                if (!$catId) {
                                    $catIds = (new GoodsCatModel())->where('goods_id', $goodsId)->column('cat_id');
                                    if ($catIds) {
                                        $idx = random_int(0, count($catIds) - 1);
                                        $catId = $catIds[$idx];
                                    } else {
                                        return self::error(100, '暂无数据');
                                    }
                                }
                                $catGoodsIds = (new GoodsCatModel())->where('cat_id', $catId)
                                    ->group('goods_id')->limit(config('self_config.index_limit'))->column('goods_id');
                                if ($catGoodsIds) {
                                    //删除当前商品
                                    $key = array_search($goodsId, $catGoodsIds);
                                    if ($key !== false) {
                                        array_splice($catGoodsIds, $key, 1);
                                    }
                                    if ($catGoodsIds) {
                                        $catGoodsList = (new GoodsModel())->getGoodsByGoodsIds($catGoodsIds);//同分类商品
                                    }
                                }
                            }
                            $result = array_merge($seriesGoodsList, $catGoodsList);
                        } else {
                            $catId = 0;
                            $catIds = (new GoodsModel())->where('goods_id', $goodsId)->column('cat_id');
                            if ($catIds) {
                                $idx = random_int(0, count($catIds) - 1);
                                $catId = $catIds[$idx];
                            }
                            if (!$catId) {
                                $catId = self::getUserFavCatId((int)$userId);
                            }
                            if (!$catId) {
                                return self::error(100, '暂无数据');
                            }
                            $catGoodsIds = (new GoodsCatModel())->where('cat_id', $catId)->group('goods_id')
                                ->limit(config('self_config.index_limit'))->column('goods_id');
                            if (!$catGoodsIds) {
                                return self::error(100, '暂无数据');
                            }
                            //删除当前商品
                            $key = array_search($goodsId, $catGoodsIds);
                            if ($key !== false) {
                                array_splice($catGoodsIds, $key, 1);
                            }
                            if ($catGoodsIds) {
                                $result = (new GoodsModel())->getGoodsByGoodsIds($catGoodsIds);//同分类商品
                            }
                        }
                        if ($result) {
                            foreach ($result as $key => $goods) {
                                $result[$key] = self::getGoodsAttrs($goods, $userId);
                            }
                            self::setCache('series_goods_list_' . $goodsId, json_encode($result), config('self_config.goods_list_expire'));//缓存
                        }
                    }
                }
                break;
            default:
                break;
        }
        if (!$result) {
            return self::error(100, '暂无数据');
        }
        //获取书籍用户标识
        $result = self::getGoodsMark($result, $userId);
        return self::success(200, '获取成功', $result);
    }

    /**获取书籍用户标识
     * @param $goods
     * @param int $userId
     * @return mixed
     * @author Poison
     * @date 2021/2/19 10:52 上午
     */
    public function getGoodsMark($goods, $userId = 0)
    {
        foreach ($goods as $k => $v) {
            $goodsId = $v['goods_id'];
            //用户是否阅读过标识
            $goods[$k]['is_read'] = 0;
            //用户是否收藏
            $goods[$k]['is_collect'] = 0;
            //用户是否已添加到购物车
            $goods[$k]['is_cart'] = 0;
            if ($userId > 0) {
                $orderGoodsCount = (new OrderGoodsModel())->where(['user_id' => $userId, 'goods_id' => $goodsId])->count();
                if ($orderGoodsCount > 0) {
                    $goods[$k]['is_read'] = 1;
                }
                $goodsCollectId = (new GoodsCollectModel())->where(['user_id' => $userId, 'goods_id' => $goodsId])->value('collect_id');
                if ($goodsCollectId) {
                    $goods[$k]['is_collect'] = $goodsCollectId;
                }
                $cartId = (new CartModel())->where(['user_id' => $userId, 'goods_id' => $goodsId])->value('id');
                if ($cartId) {
                    $goods[$k]['is_cart'] = $cartId;
                }
            }
        }
        return $goods;
    }

    /**获取用户借阅过的书籍同类书籍
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/18 10:30 上午
     */
    public function getUserFavGoodsIds(int $userId)
    {
        $goodsId = (new OrderGoodsModel())->where('user_id', $userId)->order('rec_id', 'DESC')->value('goods_id');
        if (!$goodsId) {
            return [];
        }
        $seriesName = (new GoodsAttrModel())->where(['goods_id' => $goodsId, 'attr_id' => 214])->value('attr_value');
        if (!$seriesName) {
            return [];
        }
        return (new GoodsAttrModel())->where(['attr_id' => 214, 'attr_value' => $seriesName])->where('goods_id', '<>', $goodsId)->column('goods_id');
    }

    /**首页商品列表
     * @param $type
     * @param int $page
     * @param string $search
     * @param array $favGoodsIds
     * @param int $userId
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/18 12:01 下午
     */
    public function getTypeGoodsList($type, $page = 0, $search = '', $favGoodsIds = [], $userId = 0)
    {
        $goodsList = [];
        if ($page <= 1 && !$search) {
            $goodsListName = $type . '_goods_list';
            if ($type == 'fav' && $userId > 0) {
                $goodsListName .= '_user_' . $userId;
            }
            if ($page == 1) {
                $goodsListName .= '_more';
            }
            $goodsList = self::getCache($goodsListName);
        }
        if ($goodsList) {
            $goodsList = json_decode($goodsList, TRUE);
        } else {
            $goodsList = (new GoodsModel())->getGoodsByType($type, $page, $search, $favGoodsIds);
            if ($goodsList) {
                foreach ($goodsList as $k => $v) {
                    $goodsList[$k] = self::getGoodsAttrs($v, $userId);
                }
                if ($page <= 1 && !$search) {
                    self::setCache($goodsListName, json_encode($goodsList), config('self_config.goods_list_expire'));
                }
            }
        }
        return $goodsList;
    }

    public function getCatGoodsList($parentCatId = 0, $catId = 0, $page = 1, $search = '', $isStock = 0, $userId = 0, $threeLevelId = 0)
    {
        $catModel = new CatModel();
        $catList = [];
        if ($isStock == 0) {
            if (!$threeLevelId) {
                if ($page == 1 && !$search) {
                    $catListName = 'cat_goods_list_' . $parentCatId . '_' . $catId;
                    $catList = self::getCache($catListName);
                }
            } else {
                if ($page == 1 && !$search) {
                    $catListName = 'cat_goods_list_' . $parentCatId . '_' . $catId . '_' . $threeLevelId;
                    $catList = self::getCache($catListName);
                }
            }
        }
        if ($catList) {
            $catList = json_decode($catList, TRUE);
        } else {
            $catIds = [];//初始化变量
            if ($threeLevelId > 0) {
                $catIdsArr = $catModel->where('parent_id', $catId)->column('id');
                $catIds = implode(',', $catIdsArr);
                if ($catId > 0) {
                    $catIds = $threeLevelId;
                }
            } else {
                if ($parentCatId > 0) {
                    $catIdsArr = $catModel->where('parent_id', $parentCatId)->column('id');
                    $catIds = implode(',', $catIdsArr);
                    if ($catId > 0) {
                        $catIds = $catId;
                    }
                }
            }
            $catList = (new GoodsModel())->getGoodsByCatId($catIds, $page, $search, $isStock);
            if ($catList) {
                foreach ($catList as $key => $goods) {
                    $catList[$key] = self::getGoodsAttrs($goods, $userId);
                    /* //查询标签信息
                     $searchArray = [];
                     if($parentCatId){
                         $searchArray[] = $catModel->where('id',$parentCatId)->value('name');
                     }else{
                         $catData = $catModel->field(['rd_cat.cat_type', 'rd_cat.name', 'rd_cat.parent_id'])
                             ->join('rd_goods_cat gc','rd_cat.id = gc.cat_id')
                             ->where(['rd_cat.cat_type'=>0,'gc.goods_id'=>$goods['goods_id']])
                             ->group('gc.cat_id')->findOrEmpty()->toArray();
                         $searchArray[] = $catModel->where('id',$catData['parent_id'])->value('name');
                     }*/
                }
                if ($page <= 1 && !$search && $isStock == 0) {
                    if (isset($catListName)) {
                        self::setCache($catListName, json_encode($catList), config('self_config.goods_list_expire'));
                    }
                }
            }
        }
        return $catList;
    }

    /**学校商品列表
     * @param $schoolId
     * @param $gradeId
     * @param int $page
     * @param string $search
     * @param int $userId
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/18 3:37 下午
     */
    public function getSchoolGoodsList($schoolId, $gradeId, $page = 1, $search = '', $userId = 0)
    {
        $goodsList = [];
        if ($page == 1 && !$search) {
            $goodsListName = "sg_goods_list" . $schoolId . '_' . $gradeId;
            $goodsList = self::getCache($goodsListName);
        }
        $goodsList = [];
        if ($goodsList) {
            $goodsList = json_decode($goodsList, TRUE);
        } else {
            $schoolGoodsIds = (new GoodsCatModel())->where('cat_id', $schoolId)->group('goods_id')->column('goods_id');
            $gradeGoodsIds = (new GoodsCatModel())->where('cat_id', $gradeId)->group('goods_id')->column('goods_id');
            foreach ($schoolGoodsIds as $k => $schoolGoodsId) {
                if (!in_array($schoolGoodsId, $gradeGoodsIds)) {
                    unset($schoolGoodsIds[$k]);
                }
            }
            if ($schoolGoodsIds) {
                $goodsList = (new GoodsModel())->getGoodsByGoodsIds($schoolGoodsIds, $page, $search);// 同学校年级分类
                if ($goodsList) {
                    foreach ($goodsList as $key => $goods) {
                        $goodsList[$key] = self::getGoodsAttrs($goods, $userId);
                    }
                    if ($page <= 1 && !$search) {
                        $this->getRedis()->set($goodsListName, json_encode($goodsList), config('self_config.goods_list_expire'));// 缓存8小时
                    }
                }
            }
        }
        return $goodsList;
    }

    /**首页搜索列表
     * @param int $page
     * @param string $search
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/18 3:47 下午
     */
    public function getSearchGoodsList($page = 1, $search = '', $userId = 0)
    {
        $word = self::searchMatching($search);
        $goodsList = (new GoodsModel())->getGoodsByCatId([], $page, $word);
        $status = 0;
        $goodsIdArray = [];
        if ($goodsList) {
            $status = 1;
            foreach ($goodsList as $key => $goods) {
                $goodsIdArray[] = $goods['goods_id'];
                $goodsList[$key] = self::getGoodsAttrs($goods, $userId);
            }
        }
        try {
            if ($search && $userId && $page == 1) {//如果有搜索内容和用户id时写入数据
                GoodsSearchModel::addSearch($userId, $search, $status, $goodsIdArray ? implode(',', $goodsIdArray) : '');
            }
        } catch (\Exception $e) {
        }
        return $goodsList;
    }

    /**模糊匹配
     * @param string $search
     * @return string
     * @author Poison
     * @date 2021/4/6 5:34 下午
     */
    protected function searchMatching($search = '')
    {
        if (!isset($search) || !$search) {
            return '';
        }
        $word = self::keysCache("*~".$search."~*");
        if(!$word){
            return $search;
        }
        $searchKeyName = $word[0];
        $word = self::getCache($searchKeyName);
        return $word;
    }

    /**获取最后一本借阅书籍的分类
     * @param int $userId
     * @return array|mixed
     * @author Poison
     * @date 2021/2/19 9:19 上午
     */
    public function getUserFavCatId(int $userId)
    {
        $goodsId = (new OrderGoodsModel())->where('user_id', $userId)->order('rec_id', 'DESC')->value('goods_id');
        if (!$goodsId) {
            return [];
        }
        return (new GoodsCatModel())->where('goods_id', $goodsId)->value('cat_id');
    }


    /**
     * 获取商品详情
     * @param int $goods_id
     * @param int $user_id
     * @return array
     * @author yangliang
     * @date 2021/2/19 17:56
     */
    public function getGoodsInfo(int $goods_id, int $user_id = 0)
    {
        if ($goods_id == 0) {
            return $this->error(100, '参数异常，请退出重试');
        }
        $goods_info = $this->getCache('goods_info_' . $goods_id);
        if ($goods_info) {
            $goods_info = json_decode($goods_info, true);
        } else {
            $goods_info = GoodsModel::getByGoodsId($goods_id);
            if(!$goods_info){
                return $this->error(100, '商品信息不存在');
            }
            $goods_info = $this->getGoodsAttrs($goods_info, $user_id, 1);
            if ($goods_info) {
                $this->setCache('goods_info_' . $goods_id, json_encode($goods_info), ConstLib::GOODS_LIST_EXPIRE);  // 缓存8小时
            }
        }

        if (!$goods_info) {
            return $this->error(100, '商品信息不存在');
        }

        // 替换商品内容中图片
        $goods_content = $goods_info['goods_content'];
        preg_match_all("/src=\"(.*)\"/U", $goods_info['goods_content'], $goods_img_arr);
        if ($goods_img_arr[1]) {
            $pregs = $goods_img_arr[1];
            foreach ($pregs as $preg) {
                if (strpos($preg, 'https://') === false && strpos($preg, 'http://') === false) {
                    $str = config('ali_config.domain') . $preg;
                    $goods_content = str_replace($preg, $str, $goods_info['goods_content']);
                }
            }
        }
        $goods_info['goods_content'] = $goods_content;

        // 到货提醒数量
        $goods_info['attention_count'] = 0;
        if ($user_id > 0) {
            $goods_info['attention_count'] = GoodsAttentionModel::getCountByUserIdAndGoodsId($user_id, $goods_id);
        }

        // 获取书籍用户标识
        $goods = $this->getGoodsMark([$goods_info], $user_id);
        $goods[0]['comment'] = $this->getOneComment($goods_id, $user_id);
        return $this->success(200, '获取成功', $goods[0]);
    }


    /**
     * 获取单个书评
     * @param int $goods_id 商品ID
     * @param int $user_id 用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/20 9:35
     */
    public function getOneComment(int $goods_id, int $user_id)
    {
        $comment_num = 0;
        $comment_data = GoodsCommentModel::getByGoodsIdAndType($goods_id, 3);
        if ($comment_data) {
            //获取评论数量
            $comment_data['point_num'] = (GoodsCommentByModel::getCountByCommentIdAndType($comment_data['id'], 3) + GoodsCommentByModel::getCountByCommentIdAndTypeAndUserId($comment_data['id'], 1, $user_id)) ?? 0;
            $comment_data['userReadBookCount'] = OrderGoodsModel::getSumGoodsNumByUserId($user_id) ?? 0;  //用户看书数量
            $is_this_point = GoodsCommentPointModel::getByUserIdAndCommentId($user_id, $comment_data['id']);
            if (!empty($is_this_point)) {
                $comment_data['is_click'] = 1;
            } else {
                $comment_data['is_click'] = 0;
            }
            $user_name = substr($comment_data['user_name'], 0, 3);
            if ($user_name == 'VIP' && $comment_data['nickname']) {
                $comment_data['user_name'] = $comment_data['nickname'];
            }

            $comment_data['create_time'] = date('Y-m-d H:i:s', $comment_data['create_time']);

            $comment_num = GoodsCommentModel::getCountByGoodsIdAndType($goods_id, 3);
        }

        return ['comment_data' => $comment_data, 'comment_num' => $comment_num];
    }


    /**
     * 获取用户收藏
     * @param int $user_id 用户ID
     * @param int $page 分页页码
     * @return array
     * @author yangliang
     * @date 2021/2/20 13:49
     */
    public function getGoodsCollectByUserId(int $user_id, int $page)
    {
        $list = GoodsCollectModel::getGoodsCollectByUserId($user_id, $page);
        if (!empty($list)) {
            foreach ($list as $k => $v) {
                $list[$k] = $this->getGoodsAttrs($v, $user_id);
            }

            $list = $this->getGoodsMark($list, $user_id);
        }

        return $this->success(200, 'success', $list);
    }


    /**
     * 商品收藏
     * @param int $user_id 用户ID
     * @param int $goods_id 商品ID
     * @param int $is_collect 收藏ID
     * @return array
     * @author yangliang
     * @date 2021/2/22 9:12
     */
    public function goodsCollect(int $user_id, int $goods_id, int $is_collect)
    {
        $collect_id = 0;
        if ($is_collect > 0) {
            $goods_collect = GoodsCollectModel::getByCollectId($is_collect);
            if (empty($goods_collect)) {
                return $this->error(100, '此书籍已取消收藏,请勿重复操作');
            }

            $res = GoodsCollectModel::where('collect_id', $is_collect)->delete();
            if (!$res) {
                return $this->error(100, '操作失败');
            }
        } else {
            $goods_collect = GoodsCollectModel::getByUserIdAndGoodsId($user_id, $goods_id);
            if (!empty($goods_collect)) {
                return $this->error(100, '此书籍已收藏,请勿重复操作');
            }

            $data = [
                'goods_id' => $goods_id,
                'user_id' => $user_id,
                'add_time' => time(),
            ];

            $res = GoodsCollectModel::create($data);
            if (!$res) {
                return $this->error(100, '操作失败');
            }
            $collect_id = $res->id;
        }

        return $this->success(200, '操作成功', ['is_collect' => $collect_id]);
    }


    /**
     * Get books which user read
     * @param int $user_id
     * @param int $page
     * @param int $limit
     * @return array
     * @author yangliang
     * @date 2021/2/22 17:46
     */
    public function getReadBooksByUserId(int $user_id, int $page, int $limit)
    {
        $list = GoodsModel::getReadBookByUserId($user_id, $page, $limit);
//        $count = GoodsModel::getCountReadBookByUserId($user_id);
        if (!empty($limit)) {
            foreach ($list as $k => $v) {
                $list[$k] = $this->getGoodsAttrs($v, $user_id);
            }
        }

        return $this->success(200, 'success', $list);
    }


    /**
     * 创建提醒
     * @param int $user_id 用户ID
     * @param int $goods_id 商品ID
     * @return array
     * @author yangliang
     * @date 2021/2/23 9:19
     */
    public function createAttention(int $user_id, int $goods_id)
    {
        $user = (new UserModel())->getOneUser($user_id);
        if (empty($user)) {
            return $this->error(100, '用户不存在');
        }

        $goods = GoodsModel::getByGoodsId($goods_id);
        if (empty($goods)) {
            return $this->error(100, '无效的商品');
        }

        $count = GoodsAttentionModel::getCountByUserIdAndGoodsId($user_id, $goods_id);
        if ($count > 0) {
            return $this->error(100, '此商品已添加到货提醒');
        }

        try {

            GoodsAttentionModel::create([
                'user_id' => $user_id,
                'goods_id' => $goods_id,
                'add_time' => time()
            ]);

            if ($user['smart_openid']) {
                $goods_attrs = $this->getGoodsAttrs($goods, $user_id, 1);
                $goods_data = [
                    'name' => $goods['goods_name'],
                    'author' => $goods_attrs['author'] ?? '无',
                    'publish' => $goods_attrs['publish'] ?? '无',
                    'remark' => '如有疑问请咨询客服 85799903'
                ];

                $smart_res = (new SmartSendService())->lackBookMessage($user['smart_openid'], $goods_data, 'pages/packageOne/bookDetail/bookDetail?gId=' . $goods['goods_id']);
                if (!$smart_res) {
                    Log::info('心愿书单-》发送小程序提醒失败 返回数据:' . json_encode($smart_res, JSON_UNESCAPED_UNICODE));
                }
            }
        } catch (\Exception $e) {
            return $this->error(100, $e->getMessage());
        }

        return $this->success(200, '操作成功');
    }
}