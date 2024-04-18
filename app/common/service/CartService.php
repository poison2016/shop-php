<?php


namespace app\common\service;


use app\command\NewBuyCardUsersInfo;
use app\common\ConstCode;
use app\common\ConstLib;
use app\common\exception\ApiException;
use app\common\model\CartModel;
use app\common\model\GoodsModel;
use app\common\model\GoodsCatModel;
use app\common\model\GoodsStockModel;
use app\common\model\JdOrderGoodsModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\PickUpModel;
use app\common\model\ReturnGoodsModel;
use app\common\model\StockLackModel;
use app\common\model\StoresModel;
use app\common\model\UserCardsModel;
use app\common\model\UserLevelModel;
use app\common\model\UserModel;
use App\Repository\CartRepository;
use think\Exception;
use think\facade\Db;
use think\facade\Env;
use think\facade\Log;
use think\Model;

class CartService extends ComService
{
    public function isBorrowed($goodsId, $userId = 0,$isCheck = true, $referer_source = 3)
    {
        $goodsInfo = (new GoodsModel())->field('goods_name,is_delete,is_on_sale,jd_sku')->where('goods_id', $goodsId)->find();
        if ($goodsInfo['is_delete'] == 1 || $goodsInfo['is_on_sale'] == 0) {
            return $this->error(10150, '书籍<<' . $goodsInfo['goods_name'] . '>>已下架，请选择其他书籍借阅');
        }
        // 2.判断是否馆藏书籍,如果是不能外借
        //$goodsCatCount = (new GoodsCatModel())->getCount([['goods_id', $goodsId], ['cat_id', 422]]);
        $goodsCatCount = (new GoodsCatModel())->where(['goods_id' => $goodsId, 'cat_id' => 422])->count();
        if ($goodsCatCount > 0) {
            return $this->error(10150, '书籍<<' . $goodsInfo['goods_name'] . '>>是馆藏书籍，只能到馆阅读');
        }
        $isOrder = ToolsService::searchOrder($userId);
        if($isCheck){
            $userCardsData = (new UserCardsModel())->getOneCards($userId);
            $userData = (new UserModel())->getOneUser($userId);
            $totalStock = $this->getTotalStock($goodsId);
            if ($userCardsData) {
                if ($totalStock <= config('self_config.safe_stock')) {
                    if (!$isOrder) {
                        if ($goodsInfo['jd_sku'] > 0) {
                            $resPostJdCount = $this->postJDCount($goodsInfo['jd_sku'], $goodsInfo['goods_name'], $userId);
                            if ($resPostJdCount['code'] != 200) {
                                return $this->error(20003, $resPostJdCount['message']);
                            }
                        } else {
                            (new UserLackService())->beforeAdd($userId, $goodsId, $referer_source);
                            return $this->error(10161, '书籍<<' . $goodsInfo['goods_name'] . '>>已被借完');
                        }
                    } else {
                        //记录无货流水
                        (new UserLackService())->beforeAdd($userId, $goodsId, $referer_source);
                        StockLackModel::create(['user_id' => $userId, 'goods_id' => $goodsId, 'create_time' => time()]);
                        return $this->error(10161, '书籍<<' . $goodsInfo['goods_name'] . '>>已被借完');
                    }
                }
            } elseif (isset($userData['grade']) && $userData['grade'] > 0) {
                if ($totalStock <= config('self_config.safe_stock')) {
                    //记录无货流水
                    (new UserLackService())->beforeAdd($userId, $goodsId, $referer_source);
                    StockLackModel::create(['user_id' => $userId, 'goods_id' => $goodsId, 'create_time' => time()]);
                    return $this->error(10161, '书籍<<' . $goodsInfo['goods_name'] . '>>已被借完');
                }
            }
        }
        return $this->success();
    }

    public function getTotalStock($goodsId)
    {
        $goodsStock = new GoodsStockModel();
        $totalStock = $goodsStock->getStockByGoodsId($goodsId);
        // 所有已开馆门店现有库存
        $stores = (new StoresModel())->getStoresByStoreId();
        foreach ($stores as $store) {
            $nowStore = $goodsStock->getStockByGoodsId($goodsId, $store['id']);
            $totalStock += $nowStore <= 0 ? 0 : $nowStore;
        }
        return $totalStock;
    }

    /**新需求 到馆自取 时 获取门店
     * @param $goodsId
     * @param int $storeId
     * @return int|mixed
     * @author Poison
     * @date  2021-6-18 09:09
     */
    public function getOneTotalStock($goodsId,$storeId = 0)
    {
        $goodsStock = new GoodsStockModel();
        $totalStock = $goodsStock->getStockByGoodsId($goodsId);
        // 所有已开馆门店现有库存
        if($storeId){
            $nowStore = $goodsStock->getStockByGoodsId($goodsId, $storeId);
            $totalStock += $nowStore <= 0 ? 0 : $nowStore;
        }
        return $totalStock;
    }

    /** 请求京东库存接口
     * @param $sku
     * @param $goods_name
     * @param $userId
     * @return array
     */
    public function postJDCount($sku, $goods_name, $userId = 0)
    {
        try {
            $isOrder = ToolsService::searchOrder($userId);
            if ($isOrder) {
                return $this->error(0, '书籍<<' . $goods_name . '>> 库存不足');
            }
            if(!config('self_config.is_end_jd')){
                return $this->error(0, '书籍<<' . $goods_name . '>> 库存不足');
            }
            $skuCount = (new JdOrderGoodsModel())->where(['sku' => $sku, 'status' => 1])->count() ?? 0;
            $skuCount++;
            //2020-9-27 增加虚拟库存 读redis 没有取配置 redis设置为永不过期 这样就可以从redis 直接更新 不需要重启服务
            $Jd_stock_number = config('self_config.jd_stock');
            //参数准备完毕 发起请求
            $data['sku'] = [[
                'skuId' => $sku,
                'num' => $skuCount + $Jd_stock_number,
            ]];
            $url = config('self_config.host');
            $env = Env::get('APP_DEBUG', true);

            if (!$env) {
                $url = config('self_config.host_api');
            }
            $url .= config('self_config.jd_select');
            $resJdData = self::postMam($url, $data);
            if ($resJdData['code'] != 200) {
                return $this->error(0, '请求失败');
            }
            if ($resJdData['data'][0]['stockStateId'] != 33) {
                return $this->error(0, '书籍<<' . $goods_name . '>> 库存不足');
            }
            return $this->success();
        } catch (Exception $ex) {
            return $this->error(0, $ex->getMessage());
        }

    }

    /**获取购物车中书数量
     * @param $userId
     */
    public function getCount($userId)
    {
        $count = (new CartModel())->where('user_id', $userId)->count();
        return $this->success(200, '获取成功', ['count' => $count]);
    }

    public static function postMam($url, $data)
    {
        $data = json_encode($data);
        $headerArray = array("Content-type:application/json;charset='utf-8'", "Accept:application/json");
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headerArray);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = json_decode(curl_exec($curl), TRUE);
        curl_close($curl);
        return $output;
    }


    /**
     * 一键加入购物车
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2020/11/9 14:41
     */
    public function batchAddCart(array $data): array
    {
        $goods_ids = explode(',', $data['goods_ids']);
        $userId = $data['userId'];
        $isCheck = false;
        if (isset($data['is_skip']) && $data['is_skip'] == 1) {
            $isCheck = true;
            //清理选中状态
            //CartModel::upDataCardSelected($userId);
        }

        $bookArray = [];
        $res = [];
        Db::startTrans();
        try {
            $thirdPartyOrder = ThirdPartyOrderService::checkUserOrder($data['userId']);
            foreach ($goods_ids as $goods_id) {
                $status = 0;
                $msg = '';
                $goods_info = GoodsModel::getByGoodsId($goods_id);
                if (!$thirdPartyOrder) {
                    // 判断书籍是否可借
                    $isBorrowedRes = $this->isBorrowed($goods_id, $userId, true, 3);

                    if ($isBorrowedRes['code'] == 10150) {
                        $status = 1;  //下架、馆藏
                        $msg = $isBorrowedRes['message'];
//                    throw new ApiException($isBorrowedRes['message'], ['code' => $isBorrowedRes['code']]);
                    } else if ($isBorrowedRes['code'] == 10161) {
                        $status = 2;  //被借完
                        $msg = $isBorrowedRes['message'];
                    }
                }

                if (!$isCheck) {
                    // 判断购物车是否已满
                    $totalCartGoods = CartModel::getCartListByUserId($userId, -1);
                    if (count($totalCartGoods) >= ConstLib::CART_MAX_COUNT) {
                        $msg = '您的购物车已满';
                        $status = 3;
//                    throw new ApiException('您的购物车已满', ['code' => 0]);
                    }
                }

                // 判断是否已添加过此书籍
                $cartCount = CartModel::getCountByUserIdAndGoodsId($userId, $goods_id);

                if ($cartCount > 0) {
                    $msg = '您已添加过此书籍';
                    $status = 4;
                    $bookArray[] = $goods_id;
//                    throw new ApiException('您已添加过此书籍《'.$goods_info['goods_name'].'》', ['code' => 0]);
                }

                // 判断库存是否足够
                // 总现有库存
                $userCardData = UserCardsModel::getOneCards($userId);

                if (!$userCardData) {
                    $totalStock = $this->getTotalStock($goods_id);
                    if ($totalStock <= ConstLib::SAFE_STOCK) {
                        $msg = '此书籍已被借完';
                        $status = 2;
                        //记录无货流水
                        (new UserLackService())->beforeAdd($userId, $goods_id, 3);
                        StockLackModel::create(['user_id' => $userId, 'goods_id' => $goods_id, 'create_time' => time()]);
//                        throw new ApiException('此书籍已被借完《'.$goods_info['goods_name'].'》', ['code' => 20005]);
                    }
                }

                if (empty($msg) && $status == 0) {
                    if($isCheck){
                        $cartData['selected'] = 0;
                    }
                    $cartData['user_id'] = $userId;
                    $cartData['goods_id'] = $goods_info['goods_id'];
                    $cartData['goods_sn'] = $goods_info['goods_sn'];
                    $cartData['goods_name'] = $goods_info['goods_name'];
                    $cartData['goods_num'] = 1;
                    $cartData['add_time'] = time();
                    $cartId = CartModel::create($cartData);
                    if (!$cartId) {
                        throw new \Exception('购物车添加失败');
                    }
                } else {
                    $cartId = 0;
                }

                $res[] = [
                    'goods_id' => $goods_id,
                    'cart_id' => $cartId ? $cartId->id : 0,
                    'status' => $cartId ? 0 : $status,
                    'msg' => $msg
                ];
            }
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error(100, $e->getMessage());
        }
//        if($bookArray && $isCheck){
//            CartModel::upSelected($userId,$bookArray);
//        }
        Db::commit();
        return $this->success(200, '添加成功', $res);
    }


    /**
     * 购物车列表
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2020/12/18 10:17
     */
    public function getList(array $data): array
    {
        $list = CartModel::getList($data['userId'], $data['page'], 20);

        if (empty($list)) {
            return $this->error(10150, '暂无记录');
        }

        $user_card = UserCardsModel::getOneCards($data['userId']);
        $thirdPartyOrder = ThirdPartyOrderService::checkUserOrder($data['userId']);
        foreach ($list as $k => $v) {
            //验证购物车书籍是否可借，不可借移除此书籍
//            if(!$thirdPartyOrder){//首单不验证
//                $is_borrowed = self::isBorrowed($v['goods_id'],$data['userId']);
//                if($is_borrowed['code'] != 200){
//                    CartModel::where('id', $v['cart_id'])->delete();
//                }
//            }


            $goods = GoodsModel::getByGoodsId($v['goods_id']);
            if(empty($goods)){
                unset($list[$k]);
                continue;
            }
            $list[$k]['original_img'] = $this->getGoodsImg($goods['original_img'] ?? '');
           /* if (!empty($goods['original_img']) && strstr($goods['original_img'], 'http') == false) {
                $v['original_img'] = config('ali_config.domain') . $goods['original_img'];
            } else {
                $v['original_img'] = $goods['original_img'];
            }*/
            $list[$k]['price'] = $goods['price'];
            $list[$k]['jd_sku'] = $goods['jd_sku'];
            $list[$k]['is_jd'] = $goods['is_jd'];
            $list[$k]['good_category'] = $goods['good_category'];

            // 不考虑班级用户和学生用户
            $list[$k]['is_left'] = 0;  //是否显示库存剩余:0否1是
            $total_stock = self::getTotalStock($v['goods_id']);
            if (!$thirdPartyOrder) {
                //总现有库存
                if ($total_stock <= config('self_config.cart_left_limit')) {
                    $list[$k]['is_left'] = 1;
                }
                //库存剩余数量
                $list[$k]['left_count'] = ($total_stock > 0) ? $total_stock : 0;
                if (config('self_config.is_jd') == 1) {
                    if ($v['left_count'] <= 0) {
                        if (!empty($user_card) && $goods['jd_sku'] > 0) {
                            //请求京东库存接口
                            $jd_res = self::postJDCount($goods['jd_sku'], $v['goods_id'], $data['userId']);
                            if ($jd_res['code'] != 200) {
                                $list[$k]['left_count'] = 0;
                            } else {
                                $list[$k]['is_left'] = 0;  //是否显示库存剩余:0否1是
                            }
                        }
                    }
                }

            }
        }
        $list = array_merge($list);
        return $this->success(200, '请求成功', $list);
    }


    /**
     * 添加购物车
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2020/12/18 11:25
     */
    public function addCart(array $data): array
    {
        if (empty(intval($data['goods_id']))) {
            return $this->error(100, '书籍不存在');
        }

        $thirdPartyOrder = ThirdPartyOrderService::checkUserOrder((int)$data['userId']);
        if (!$thirdPartyOrder) {
            $is_borrowed = self::isBorrowed($data['goods_id'], $data['userId'], true, $data['referer_source']);
            if ($is_borrowed['code'] != 200) {
                return $this->error($is_borrowed['code'], $is_borrowed['message']);
            }
        }

        //验证购物车数量超限
        $cart_list = CartModel::getCartListByUserId($data['userId'], -1);
        if (count($cart_list) >= config('self_config.cart_max_count')) {
            return $this->error(100, '您的购物车已满');
        }

        //验证年卡
        $check_card = (new UserCardService())->checkUserCardByUserId($data['userId'], [$data['goods_id']]);
        if ($check_card['code'] != 200) {
            return $this->error(2047, $check_card['message']);
        }

        //验证重复添加
        $goods_count = CartModel::getCountByUserIdAndGoodsId($data['userId'], $data['goods_id']);
        if ($goods_count > 0) {
            return $this->error(100, '您已添加过此书籍');
        }

        //验证库存（总现有库存）
//        $user_card = UserCardsModel::getOneCards($data['userId']);
//        if(empty($user_card)){
//            $total_stock = self::getTotalStock($data['goods_id']);
//            if($total_stock <= config('self_config.safe_stock')){
//                return $this->error(20005, '此书籍已被借完');
//            }
//        }

        //2021.7.5  新增库存无货记录流水
//        $user_card = UserCardsModel::getOneCards($data['userId']);
//        if(empty($user_card)){
//            $total_stock = self::getTotalStock($data['goods_id']);
//            if($total_stock <= config('self_config.safe_stock')){
//                StockLackModel::create(['user_id' => $data['userId'], 'goods_id' => $data['goods_id'], 'create_time' => time()]);
//            }
//        }

        $goods = GoodsModel::getByGoodsId($data['goods_id']);
        if (empty($goods)) {
            return $this->error(100, '书籍不存在');
        }

        //验证商品库存  2021.8.16
        $stock_res = self::checkGoodsStock($data['userId'], $goods['goods_id'], $goods['goods_name'], $data['referer_source'] ?? 3);
        if($stock_res['code'] != 200){
            return $this->error($stock_res['code'], $stock_res['message']);
        }

        $cart = [
            'user_id' => $data['userId'],
            'goods_id' => $goods['goods_id'],
            'goods_sn' => $goods['goods_sn'],
            'goods_name' => $goods['goods_name'],
            'goods_num' => 1,
            'add_time' => time(),
        ];

        $res = CartModel::create($cart);
        if (!$res) {
            return $this->error(100, '购物车添加失败');
        }

        return $this->success(200, '添加成功', $res->id);
    }


    /**
     * 购物车商品选中取消
     * @param $data
     * @return array
     * @author yangliang
     * @date 2020/12/18 11:49
     */
    public function selectCart(array $data): array
    {
        $res = CartModel::whereIn('id', $data['cart_ids'])->update(['selected' => $data['selected']]);
        if (!$res) {
            return $this->error(100, '更新失败');
        }

        return $this->success(200, '更新成功');
    }


    /**
     * 删除购物车商品
     * @param $data
     * @return array
     * @author yangliang
     * @date 2020/12/18 13:38
     */
    public function deleteCart(array $data): array
    {
        $res = CartModel::where('id', $data['cart_id'])->delete();
        if (!$res) {
            return $this->error(10120, '删除失败');
        }

        return $this->success(200, '删除成功');
    }


    /**
     * 获取用户未还书籍
     * @param array $data
     * @return array
     * @author yangliang
     * @date 2020/12/19 10:04
     */
    public function getReturnGoods(array $data): array
    {
        $list = OrderGoodsModel::getReturnGoodsByUserId($data['userId'], 0);
        if (empty($list)) {
            return $this->error(100, '暂无记录');
        }
        foreach ($list as &$v) {
            if (!empty($v['original_img']) && strstr($v['original_img'], 'http') == false) {
                $v['original_img'] = config('ali_config.domain') . $v['original_img'];
            }
        }

        return $this->success(200, '请求成功', $list);
    }


    /**
     * 购物车提交检查是否需要升级
     * @param int $user_id 用户ID
     * @param int $return_count 还书数量
     * @return array
     * @author yangliang
     * @date 2021/2/20 15:53
     */
    public function checkUserGrade(int $user_id, int $return_count = 0)
    {
        $user_info = (new UserModel())->getOneUser($user_id);
        $real_goods = GoodsModel::getReturnGoodsByUserId($user_id, 1);  // 用户手中实际书籍
        $real_count = count($real_goods);

        if($user_info['is_exception_borrow'] == 0) {
            //2021.8.5  产品需求先验证订单
            //判断是否有未归还
            $resOrderGoods = OrderGoodsModel::getReturnGoodsByUserId($user_id, 1);
            if ($resOrderGoods) {
                foreach ($resOrderGoods as $v) {
                    if ($v['order_status'] <= 4 && $v['order_status'] > -1) {//判断是否有未确认收货
                        return $this->error(ConstCode::ORDER_UNRECEIVED, '您有未确认收货的订单，请先确认收货，并归还书籍后再进行新的借阅订单');
                    }
                    if ($v['is_exception'] == 1) {
                        return $this->error(ConstCode::ORDER_UNHANDLED_EXCEPTION, '您有异常订单未处理，请处理异常订单后再进行新的借阅订单');
                    }
                }

                //未申请还书
                $un_repay = OrderGoodsModel::getUnRepayByUserId($user_id);
                if (!empty($un_repay)) {
                    return $this->error(ConstCode::ORDER_UNREPAY, '您有未归还的订单，请归还书籍后再进行新的借阅订单');
                }
            }
            //新增判断
            $exceptionOrder = (new OrderModel())->where(['is_exception' => 1, 'user_id' => $user_id])->select()->toArray();
            if(!empty($exceptionOrder)) {
                return $this->error(ConstCode::ORDER_UNHANDLED_EXCEPTION, '您有异常订单未处理，请处理异常订单后再进行新的借阅订单');
            }
        }else{
            //后台已设置异常订单可借阅
            $real_count = 0;
        }
        

        $cart_count = CartModel::getSelectedCountByUserId($user_id);
        if ($cart_count < 1) {
            return $this->error(100, '请选择购物车商品');
        }

        if ($cart_count > 12) {
            return $this->error(100, '下单书籍不能超过12本');
        }
        // 判断是否选择多次相同的书
        $cart_goods_counts = CartModel::getSelectGoodsNumByUserId($user_id);
        if (!empty($cart_goods_counts)) {
            foreach ($cart_goods_counts as $v) {
                if ($v['c'] > 1) {
                    return $this->error(100, '借书单<<' . $v['goods_name'] . '>>书籍,请勿添加多次');
                }
            }
        }

        $t = time();
        $grade = $user_info['grade'];
        $goods_num = $cart_count + $real_count - $return_count;

        // 押金过期时间
        $depositExpireDateTimeStamp = $user_info['user_deposit_expire_time'];
        $user_card = UserCardsModel::getUnRefundByUserId($user_id);
        // 只要办理过年卡则判断年卡是否可用
        if ($user_card && $user_card['is_expire'] == 1 && $user_card['is_refund'] == 2) {
            if ($user_card['is_lock'] == 1) {
                if ($t > $depositExpireDateTimeStamp) {
                    return $this->error(100, '您的年卡暂未激活');
                }
            } else {
                $is_use_user_card_res = (new UserCardService())->isUseUserCard($user_card, $goods_num, $user_id, $cart_count);
                if ($is_use_user_card_res['code'] != 200) {
                    return $this->error(100, $is_use_user_card_res['message']);
                }

                return $this->success(200, '年卡可用', ['is_upgrade' => 0]);
            }
        } else {
            if ($grade > 0) {
                if ($t > $depositExpireDateTimeStamp) {
                    return $this->error(-1, '您还没有年卡，请先办理年卡。');
                }
            } else {
                return $this->error(-1, '您还没有年卡，请先办理年卡。');
            }
        }

        // 判断借书数量是否超过押金用户最大借书数量
        if ($cart_count > ConstLib::DEPOSIT_MAX_BOOKS) {
            // 还需要加一个判断，是否有待收货的订单
            $order_count = OrderModel::getCountByUserIdAndStatus($user_id, [3, 4]);
            if ($order_count > 0) {
                return $this->error(100, '您的借书数量已超过等级限制，检测到您有未确认收货的订单，请确认收货');
            } else {
                return $this->error(100, '您的借书数量已超过等级限制，您可以减少借阅本数，也可以选择办理年卡');
            }
        }

        //正在归还的书籍
        $return_goods_num = ReturnGoodsModel::getCountByUserId($user_id);

        // 押金等级对应借书数量
        $can_num = UserLevelModel::getByGrade($grade)['books'];
        $goods_num = $cart_count + $real_count - $return_count - $return_goods_num;
        if ($goods_num > ConstLib::DEPOSIT_MAX_BOOKS) {
            $order_count = OrderModel::getCountByUserIdAndStatus($user_id, [3, 4]);
            if ($order_count > 0) {
                return $this->error(100, '您的借书数量已超过等级限制，检测到您有未确认收货的订单，请确认收货');
            } else {
                return $this->error(100, '押金用户最多可借' . ConstLib::DEPOSIT_MAX_BOOKS . '本书，您可以减少借阅本数，也可以选择办理年卡');
            }
        }

        // 在规定时间节点之前判断押金等级
        $result['is_upgrade'] = 0;
        $message = '不用升级';
        if ($goods_num > $can_num) {
            return $this->error(100, '您的借书数量已超过等级限制，您可以减少借阅本数，也可以选择办理年卡');
        }

        return $this->success(200, $message, $result);
    }


    /**
     * 获取配送信息
     * @param int $user_id 用户ID
     * @param string $rec_ids 还书信息
     * @return array
     * @author yangliang
     * @date 2021/2/20 16:37
     */
    public function getShipInfo(int $user_id, string $rec_ids)
    {
        $user = (new UserModel())->getOneUser($user_id);

        // 配送点信息
        $pick_up = [];
        if ($user['pickup_id']) {
            $pick_up = (new PickUpModel())->getPickUpByPickUpId($user['pickup_id']);
        }

        // 专业快递配送费用
        $shippingPrice = 0;
//        $order_count = (new OrderModel())->getCount($user_id);
//        if ($order_count > 0) {
//            $shippingPrice = ConstLib::ORDER_SHIPPING_PRICE;
//        }

        //2020-09-03 如果用户是vip 没有用费
        // 借书信息
        $goods_list = CartModel::getCartListByUserId($user_id);
        if (empty($goods_list)) {
            return $this->error(100, '请选择借阅书籍');
        }

        foreach ($goods_list as &$v) {
            // 判断已添加购物车书籍是否可借
            $isBorrowedRes = $this->isBorrowed($v['goods_id'], $user_id,false, 1);
            if ($isBorrowedRes['code'] != 200) {
                return $this->error(100, $isBorrowedRes['message']);
            }

            $goods_info = GoodsModel::getByGoodsId($v['goods_id']);
            if (!empty($goods_info['original_img'])) {
                $v['original_img'] = $this->getGoodsImg($goods_info['original_img']);
            }
            $v['price'] = $goods_info['price'];
            $v['good_category'] = $goods_info['good_category'];
        }

        // 还书信息
        $returnGoodsList = [];
        if (!empty($rec_ids)) {
            $returnGoodsList = GoodsModel::getOrderGoodsByRecIds($rec_ids);
            if (!empty($returnGoodsList)) {
                foreach ($returnGoodsList as &$rv) {
                    if (!empty($rv['original_img'])) {
                        $rv['original_img'] = $this->getGoodsImg($rv['original_img'], 400, 400);
                    }
                }
            }
        }

        $res = [
            'pickup' => $pick_up,
            'shipping_price' => $shippingPrice,
            'goods_list' => $goods_list,
            'rec_ids' => $rec_ids,
            'return_goods_list' => $returnGoodsList
        ];

        return $this->success(200, 'success', $res);
    }


    /**
     * 验证商品库存
     * 以用户最后一个订单的配送方式为主，进行商品库存核验
     * 集中配书、专业快递：只验证仓储库存
     * 到馆自取：最后一个订单所选门店 + 仓储库存
     * @param int $user_id 用户ID
     * @param int $goods_id 商品ID
     * @param string $goods_name 商品名称
     * @return array
     * @author: yangliang
     * @date: 2021/8/16 17:30
     */
    public function checkGoodsStock(int $user_id, int $goods_id, string $goods_name, int $referer_source): array
    {
        $stores = '';
        //获取用户最后一个订单配送方式
        $last_order = OrderModel::getLastOrderByUserId($user_id);

        //验证所选门店 + 仓储库存
        if(!empty($last_order) && $last_order['shipping_code'] == 1){
            $stores = $last_order['store_id'];
        }

        //默认都会查仓储库存
        $query = GoodsStockModel::alias('gs')->field('gs.stock');

        if(!empty($stores)){
            $sql = sprintf('SELECT stock FROM rd_goods_stock%s WHERE goods_id = %d', $stores, $goods_id);
            $query->unionAll($sql);
        }

        $sub_query = $query->where('gs.goods_id', $goods_id)->buildSql();

        //计算仓储和门店库存书
        $stock = Db::table($sub_query.' as t')->sum('t.stock');
        if(intval($stock) <= config('self_config.safe_stock')){
            //记录无货流水
            (new UserLackService())->beforeAdd($user_id, $goods_id, $referer_source);
            StockLackModel::create(['user_id' => $user_id, 'goods_id' => $goods_id, 'create_time' => time()]);
            return $this->error(100, '书籍<<' . $goods_name . '>>库存已不足,请借阅其他书籍');
        }

        return $this->success(200, 'success');
    }
}