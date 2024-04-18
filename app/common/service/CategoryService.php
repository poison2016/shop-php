<?php


namespace app\common\service;

use app\common\model\CatModel;
use app\common\model\RegionModel;
use app\common\traits\CacheTrait;
use think\facade\Db;

/**
 *分类
 * Class CategoryService
 * @package app\common\service
 * @author Poison
 * @date 2021/2/20 11:07 上午
 */
class CategoryService extends ComService
{
    use CacheTrait;

    /**获取年龄分类
     * @param $catType
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/20 11:32 上午
     */
    public function getAgeCatList($catType)
    {
        $ageCats = json_decode($this->getCache('cat_list_age'), TRUE);
        if (!$ageCats) {
            $ageCats = self::getParentSonsCats($catType);
            if (!$ageCats) {
                return self::error(100, '暂无数据');
            }
            self::setCache('cat_list_age', json_encode($ageCats), config('self_config.age_cats_expire'));
        }
        return self::success(200, '获取成功', $ageCats);
    }

    /**获取分类列表
     * @param int $catType
     * @param int $parentId
     * @param int $isIndex
     * @param int $userId
     * @param int $page
     * @param int $isShowContent
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/20 4:03 下午
     */
    public function getCatsList($catType = 0, $parentId = 0, $isIndex = 1, $userId = 0, $page = 1, $isShowContent = 2)
    {
        $redisCatListName = 'cat_list';
        $parentIdArr = explode(',', ''.$parentId);
        if ($isIndex == 1) {
            $redisCatListName .= '_index';
            if (count($parentIdArr) > 1) {
                $redisCatListName .= '_small';
            } else {
                if ($parentId) {
                    $redisCatListName .= '_' . $parentId;
                }
            }
        } else {
            if ($parentId) {
                $redisCatListName .= '_' . $parentId;
            }
        }
        $cats = json_decode(self::getCache($redisCatListName), TRUE);
        if (!$cats) {
            $catModel = new CatModel();
            if ($isIndex == 1) {
                if (count($parentIdArr) > 1) {
                    $cats = [];
                    foreach ($parentIdArr as $p) {
                        //判断是否有符合条件分类
                        $isShowIds = $catModel->where(['cat_type' => $catType, 'parent_id' => $p, 'is_show' => 1])->order('is_sort', 'DESC')->column('id');
                        if (!$isShowIds) {
                            return self::error(100, '暂无数据');
                        }
                        $goodsCats = self::getCats([], $catType, $p, config('self_config.index_cats_small_limit'));
                        array_push($cats, $goodsCats);
                    }
                } else {
                    //判断是否有符合条件分类
                    $isShowIds = $catModel->where(['cat_type' => $catType, 'parent_id' => $parentId, 'is_show' => 1])->order('is_sort', 'DESC')->column('id');
                    if (!$isShowIds) {
                        return self::error(0, '暂无数据');
                    }
                    $cats = self::getCats([], $catType, $parentId, config('self_config.index_cats_limit'));
                }
            } else {
                $cats = $catModel->where(['cat_type' => $catType, 'parent_id' => $parentId, 'is_show' => 1])->order('is_sort', 'DESC')->select()->toArray();
            }
            if (!$cats) {
                return self::error(100, '暂无数据');
            }
            self::setCache($redisCatListName, json_encode($cats), config('self_config.index_cats_list_expire'));
        }
        if ($isShowContent == 1) {
            $cats = array_splice($cats, ($page - 1) * 5, 5);
            foreach ($cats as $k => $v) {
                if (isset($v[0])) {
                    foreach ($v as $key => $vue) {
                        $cats[$k][$key]['goods_info'] = (new GoodsService())->getGoodsList(['status' => 2, 'parent_cat_id' => $vue['parent_id'], 'cat_id' => $vue['id'], 'is_stock' => 0, 'user_id' => $userId]);
                    }
                } else {
                    $cats[$k]['goods_info'] = (new GoodsService())->getGoodsList(['status' => 2, 'parent_cat_id' => $v['parent_id'], 'cat_id' => $v['id'], 'is_stock' => 0, 'user_id' => $userId]);
                }

            }
        }
        return self::success(200, '获取成功', $cats);
    }

    /**获取父级和子级分类
     * @param $catType
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/20 11:32 上午
     */
    public function getParentSonsCats($catType)
    {
        $cats = (new CatModel())->field('id,name')->where(['cat_type' => $catType, 'parent_id' => 0, 'is_show' => 1])->order('is_sort', 'DESC')->select()->toArray();
        if ($cats) {
            foreach ($cats as $key => $cat) {
                $cats[$key]['sub_cats'] = (new CatModel())->field('id,name')->where(['parent_id' => $cat['id'], 'is_show' => 1])->order('is_sort', 'DESC')->select()->toArray();
                foreach ($cats[$key]['sub_cats'] as $k => $v) {
                    $cats[$key]['sub_cats'][$k]['sub_two_cats'] = (new CatModel())->field('id,name')->where(['parent_id' => $v['id'], 'is_show' => 1])->order('is_sort', 'DESC')->select()->toArray();
                }
            }
        }
        return $cats;
    }

    /**获取分类信息
     * @param array $lastCats
     * @param $catType
     * @param $parentId
     * @param $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/20 1:52 下午
     */
    public function getCats($lastCats = [], $catType, $parentId, $limit)
    {
        $totalCatsCount = (new CatModel())->where(['cat_type' => $catType, 'parent_id' => $parentId, 'is_show' => 1])->count();
        if ($totalCatsCount <= $limit) {
            return (new CatModel())->field('id,name,img,note,parent_id')->where(['cat_type' => $catType, 'parent_id' => $parentId, 'is_show' => 1])->order('is_sort', 'DESC')->select()->toArray();
        }
        $where = sprintf(' cat_type = %d AND parent_id = %d AND is_show = %d', $catType, $parentId, 1);
        $whereS = sprintf(' t1.cat_type = %d AND t1.parent_id = %d AND t1.is_show = %d', $catType, $parentId, 1);
        $maxId = 'SELECT MAX( id ) FROM `rd_cat` WHERE' . $where;
        $minId = 'SELECT MIN( id ) FROM `rd_cat` WHERE' . $where;
        if ($lastCats) {
            $catIdExist = array_column($lastCats, 'id');
            $whereS .= sprintf(' AND t1.id NOT IN (%s)', implode(',', $catIdExist));
        }
        $catFields = 't1.id,t1.name,t1.img,t1.note,t1.parent_id';
        // 随机抽取指定数量分类
        $sql = 'SELECT ' . $catFields . ' FROM `rd_cat` AS t1 JOIN (SELECT ROUND(RAND() * ((' . $maxId . ')-(' . $minId . ')) + (' . $minId . ')) AS id) AS t2 WHERE t1.id >= t2.id AND ' . $whereS . ' ORDER BY t1.is_sort DESC , t1.id ASC LIMIT ' . $limit;
        $cats = Db::query($sql);
        $catsCount = count($cats);
        if ($catsCount < $limit) {
            $leftCount = $limit - $catsCount;
            $newCats = self::getCats(array_merge($lastCats, $cats), $catType, $parentId, $leftCount);
            $cats = array_merge($cats, $newCats);
        }
        return $cats;
    }

    /**获取学校分类
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/20 4:14 下午
     */
    public function getSchoolGradeCatList()
    {
        $schoolGradeCats = json_decode(self::getCache('cat_list_sg'), TRUE);
        if (!$schoolGradeCats) {
            $schoolGradeCats = (new RegionModel())->field('id,name')->where('parent_id', 0)->select()->toArray();
            foreach ($schoolGradeCats as $key => $region) {
                $schoolGradeCats[$key]['schools'] = [];
                // 获取学校分类
                $schoolCats = (new CatModel())->field('id,name')->where(['region_id'=>$region['id'],'cat_type'=>2,'parent_id'=>0,'is_show'=>1])->select()->toArray();
                if($schoolCats){
                    foreach ($schoolCats as $k => $sc) {
                        // 获取年级分类
                        $schoolCats[$k]['grades'] = (new CatModel())->field('id,name')->where(['cat_type'=>1,'parent_id'=>0,'is_show'=>1])->select()->toArray();
                    }
                }
                $schoolGradeCats[$key]['schools'] = $schoolCats;
            }
            if (!$schoolGradeCats) {
                return self::error(10150, '暂无数据');
            }
            self::setCache('cat_list_sg',json_encode($schoolGradeCats),config('self_config.school_cats_expire'));
        }
        return self::success(200,'获取成功',$schoolGradeCats);
    }

}