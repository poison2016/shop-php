<?php


namespace app\common\service;


use app\common\model\CatModel;

class CatService extends ComService
{


    /**
     * 根据上级ID获取分类标签
     * @param int $parent_id 上级ID
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author yangliang
     * @date 2020/10/29 11:08
     */
    public function getListByParentId(int $parent_id): array
    {
        $res = CatModel::getListByParentId($parent_id);
        return $this->success(200, '请求成功', $res);
    }
}