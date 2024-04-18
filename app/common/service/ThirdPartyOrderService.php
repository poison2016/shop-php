<?php


namespace app\common\service;


use app\common\model\OrderModel;
use app\common\model\PurchaseSelfGoodsModel;
use app\common\model\UserCardsModel;
use app\common\traits\CacheTrait;
use think\facade\Db;

class ThirdPartyOrderService extends ComService
{
    use CacheTrait;

    /**写入第三方库
     * @param array $orderGoodsData
     * @param $userId
     * @param int $orderId
     * @return array
     * @author Poison
     * @date 2021/1/13 9:27 上午
     */
    public function create($orderGoodsData = [], $userId, int $orderId)
    {
        if (!$orderGoodsData) {//如果数据为空 直接返回
            return $this->success();
        }
        $data = [];
        foreach ($orderGoodsData as $k => $v) {
            $data[$k]['order_id'] = $orderId;
            $data[$k]['user_id'] = $userId;
            $data[$k]['order_sn'] = $v['order_sn'];
            $data[$k]['goods_id'] = $v['goods_id'];
            $data[$k]['store_id'] = 45;
            $data[$k]['create_time'] = time();
            $data[$k]['update_time'] = time();
        }
        $res = PurchaseSelfGoodsModel::createGoods($data);
        if ($res <= 0) {
            trace('第三方库下单异常:写入第三方库失败', 'error');
            return self::error(100, '下单异常，请稍后下单');
        }
        return self::success(200, '写入完成');
    }

    /**判断用户是否可以中转仓下单
     * @param int $userId 用户id
     * @return bool false 不可以 true 可以
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/8 11:26 上午
     */
    public static function checkUserOrder(int $userId)
    {
        $thirdParty = (new ThirdPartyOrderService());
        $isCheck = $thirdParty->getCacheThird();
        if (!isset($isCheck)) {
            $isCheck = config('self_config.third_party_order');
            $thirdParty->getCacheThird(2);
        }
        if ($isCheck != 1) {
            return false;
        }
//        //先查询用户是否是年卡会员
//        $userCard = UserCardsModel::getOneCards($userId);
//        if(!$userCard){
//            return false;
//        }
        //判断用户是否是首单 如果不减 有可能会出现查到到事务中的数据 做过滤
        $thisOrder = (new OrderModel())->getCount($userId, time() - 3);
        if ($thisOrder) {
            return false;
        }
        return true;
    }

    /**获取redis信息
     * @param int $status 1 查询 2 写入
     * @return mixed
     * @author Poison
     * @date 2021/1/13 9:28 上午
     */
    public function getCacheThird($status = 1)
    {
        if ($status == 1) {
            return $this->getCache('third_party_order');
        }
        $this->setCache('third_party_order', 1);
    }

    /**获取参数
     * @param int $userId
     * @param int $orderId
     * @return bool
     * @author Poison
     * @date 2021/1/9 2:58 下午
     */
    public static function checkIsOrder(int $userId, int $orderId)
    {
        $count = (new PurchaseSelfGoodsModel())->where('user_id', $userId)->where('order_id', $orderId)->count();
        if ($count > 0) {
            return true;
        }
        return false;
    }

}