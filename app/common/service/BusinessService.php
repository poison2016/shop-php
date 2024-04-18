<?php


namespace app\common\service;
/**
 *
 *                   __..-----')
 *         ,.--._ .-'_..--...-'
 *        '-"'. _/_ /  ..--''""'-.
 *        _.--""...:._:(_ ..:"::. \
 *     .-' ..::--""_(##)#)"':. \ \)    \ _|_ /
 *    /_:-:'/  :__(##)##)    ): )   '-./'   '\.-'
 *    "  / |  :' :/""\///)  /:.'    --(       )--
 *      / :( :( :(   (#//)  "       .-'\.___./'-.
 *     / :/|\ :\_:\   \#//\            /  |  \
 *     |:/ | ""--':\   (#//)              '
 *     \/  \ :|  \ :\  (#//)
 *          \:\   '.':. \#//\
 *           ':|    "--'(#///)
 *                      (#///)
 *                      (#///)         ___/""\
 *                       \#///\           oo##
 *                       (##///)         `-6 #
 *                       (##///)          ,'`.
 *                       (##///)         // `.\
 *                       (##///)        ||o   \\
 *                        \##///\        \-+--//
 *                        (###///)       :_|_(/
 *                        (sjw////)__...--:: :...__
 *                        (#/::'''        :: :     ""--.._
 *                   __..-'''           __;: :            "-._
 *           __..--""                  `---/ ;                '._
 *  ___..--""                             `-'                    "-..___
 *    (_ ""---....___                                     __...--"" _)
 *      """--...  ___"""""-----......._______......----"""     --"""
 *                    """"       ---.....   ___....----
 */

use app\common\model\BusinessCommissionModel;
use app\common\model\BusinessCommissionSetModel;
use app\common\model\BusinessOrganUserModel;
use app\common\model\BusinessRelationModel;
use app\common\model\BusinessRuleModel;
use app\common\model\BusinessUserBankModel;
use app\common\model\BusinessUserLogModel;
use app\common\model\BusinessUserModel;
use app\common\model\BusinessWithdrawRecordModel;
use app\common\model\BusinessWithdrawSetModel;
use app\common\model\CardsModel;
use app\common\model\DistributionRolePicturesModel;
use app\common\model\UserCardDetailsModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\traits\CurlTrait;
use think\facade\Db;
use think\facade\Log;
use think\Model;
use wechat\DataCrypt;

class BusinessService extends ComService
{
    use CurlTrait;

    /**实体卡 开通合伙人
     * @param int $userId
     * @param int $user_card_id
     * @return array
     */
    public function thisOpenBusiness(int $userId, int $user_card_id = 0)
    {
        //首先判断用户是否有软绑
        $resBusinessRelation = (new BusinessRelationModel())->getOneData($userId);
        if (!$resBusinessRelation) {
            //如果没有绑定与自然人渠道绑定
            $resBusinessRelationOne = (new BusinessRelationModel())->businessRelationInsert($userId, config('self_config.nature_person'), 1, 2);
            $resBusinessRelationTwo = (new BusinessRelationModel())->businessRelationInsert($userId, config('self_config.company'), 2, 2);
            if (!$resBusinessRelationOne || !$resBusinessRelationTwo) {
                return $this->error(0, '绑定关系失败');
            }
            //写入共享合伙人信息
            $resBusinessUser = (new BusinessUserModel())->businessUserInsert($userId, (int)$user_card_id, 0);
            $resBusinessUserLog = (new BusinessUserLogModel())->businessUserLogInsert($userId, '用户用户开通实体卡 注：实体卡开卡不分佣');
            if (!$resBusinessUser) {
                return $this->error(0, '写入掌灯人信息失败');
            }
            //写入渠道信息
            $resBusinessOrganUser = (new BusinessOrganUserModel())->BusinessOrganUserInsert($userId);
            if (!$resBusinessOrganUser) {
                return $this->error(0, '写入掌灯人渠道信息失败');
            }
        } else {//没有进行修改
            //修改软绑为硬绑
            $resBusinessRelationUpdate = (new BusinessRelationModel())->businessRelationUpdate($userId, 2);
            if (!$resBusinessRelationUpdate) {
                return $this->error(0, '更改绑定关系失败');
            }
            //修改用户信息为成功掌灯人
            $resBusinessUserUpdate = (new BusinessUserModel())->businessUserUpdate($userId, 0, (int)$user_card_id);
            //写入日志
            $resBusinessUserLog = (new BusinessUserLogModel())->businessUserLogInsert($userId, '用户开通实体卡 上级id：' . $resBusinessRelation['user_id'] . ' 注：实体卡开卡不分佣');
            if (!$resBusinessUserUpdate || !$resBusinessUserLog) {
                return $this->error(0, '更改用户掌灯人信息失败');
            }
        }
        return $this->success();
    }

    public function errLog($params, $userId)
    {
        //解密
        $topId = self::getDecode($params['top_id']);
        $arrTopId = json_decode($topId, true);
        if (is_array($arrTopId)) {
            $topId = $arrTopId['top_id'];
        }
        //写入日志
        $message = '   被邀请人ID：' . $userId . '  返回消息--->' . $params['message']['message'];
        $resBusinessUserLog = (new BusinessUserLogModel())->insert(array(
            'admin_id' => 0,
            'user_id' => $topId,
            'type_name' => $message,
            'create_time' => time()
        ));
        if (!$resBusinessUserLog) {
            return $this->error(100, '写入失败');
        }
        return $this->success(200, '写入成功');
    }

    /**掌灯人回调处理
     * @param int $userId 用户id
     * @return array
     * @author Poison
     * @date 2020/12/16 4:21 下午
     */
    public function InsertUser(int $userId)
    {
        $params = UserCardDetailsModel::getCardDetailsData(['user_id' => $userId], 'id AS order_id,pay_money AS price,user_id,card_id,user_card_id', 'pay_time DESC');
        self::addUserBusinessRelation($userId, (int)$params['user_card_id']);//初始化用户数据
        Db::startTrans();
        try {
            $topRelationData = (new BusinessRelationModel())->getOneData($userId);//查询上级信息
           // $resBusinessRuleCardId = (new BusinessRuleModel())->getRuleValue(['partner_type' => 1], 'card_id');//查询是否是指定卡
           // if ($params['card_id'] != $resBusinessRuleCardId) {//如果不是指定的卡 只进行0业绩写入
            if (!in_array($params['card_id'],config('self_config.business_card_id'))) {//如果不是指定的卡 只进行0业绩写入
                if ($topRelationData) {
                    $channelId = (new BusinessOrganUserModel())->where(['user_id' => $topRelationData['user_id'], 'status' => 1])->value('channel_id');
                    $CommissionData['order_id'] = $params['order_id'];//年卡详细表中id
                    $CommissionData['buy_user_id'] = $userId;
                    $CommissionData['user_id'] = $topRelationData['user_id'];
                    $CommissionData['price'] = $params['price'];//价格
                    $CommissionData['type'] = 1;//推荐
                    $CommissionData['channel_id'] = $channelId ?? config('self_config.nature_person_id');//渠道
                    $resBusinessCommission = BusinessCommissionModel::createBusinessCommission($CommissionData);
                    //更新合伙人会员卡ID
                    BusinessUserModel::where('user_id', $userId)->update(['user_card_id' => $params['user_card_id']]);
                    if (!$resBusinessCommission) {//写入失败 回滚数据
                        Log::channel('pay')->info('写入0分佣数据失败');
                        Db::rollback();
                        return $this->error(100, '其他卡-上级记录业绩失败');
                    }
                    (new BusinessRelationModel())->businessRelationUpdate($userId);
                }
                //没有问题 提交数据 结束掌灯人的处理
                Db::commit();
                return $this->success();
            }
            //判断当前用户是否有掌灯人用户记录
            $thisBusinessUser = BusinessUserModel::getOneBusinessUserData(['user_id' => $userId]);//获取自己的信息
            $topBusinessUser = BusinessUserModel::getOneBusinessUserData(['user_id' => $topRelationData['user_id']]);//获取上级的信息
            $topChannelData = BusinessOrganUserModel::getOneData($topRelationData['user_id'], 1);//获取上级的渠道id
            if (!$thisBusinessUser) {//如果没有掌灯人信息 进行写入操作
                $resBusinessUser = BusinessUserModel::businessUserInsert($userId, (int)$params['user_card_id']);
                $resBusinessUserLog = (new BusinessUserLogModel())->businessUserLogInsert($userId, '会员开通共享合伙人-上级ID:无 渠道:自然人渠道');
                if (!$resBusinessUser || !$resBusinessUserLog) {
                    return $this->error(0, '创建掌灯人失败');
                }
                //写入渠道信息
                $resBusinessOrganUser = (new BusinessOrganUserModel())->BusinessOrganUserInsert($userId, $topChannelData['channel_id']);
                if (!$resBusinessOrganUser) {
                    return $this->error(0, '写入掌灯人渠道信息失败');
                }
            } else {
                //修改软绑为硬绑
                $resBusinessRelationUpdate = (new BusinessRelationModel())->businessRelationUpdate($userId);
                if (!$resBusinessRelationUpdate) {
                    return $this->error(0, '更改绑定关系失败');
                }
                //修改用户信息为成功掌灯人
                $resBusinessUserUpdate = (new BusinessUserModel())->businessUserUpdate($userId, 1, (int)$params['user_card_id']);
                //写入日志
                $resBusinessUserLog = (new BusinessUserLogModel())->businessUserLogInsert($userId, '会员开通共享合伙人 上级id：' . $topRelationData['user_id']);
                if (!$resBusinessUserUpdate || !$resBusinessUserLog) {
                    return $this->error(0, '更改用户掌灯人信息失败');
                }
            }
            Log::channel('pay')->info('用户基本信息写入完成 用户id:' . $userId);
            //开始写入分拥记录
            //判断用户是否是馆长
            $commissionType = 1;
            if ($topBusinessUser['is_curator']) {
                $commissionType = 5;
            }
            //如果用户已购卡 不再进行分佣
            if ($thisBusinessUser['type'] == 0) {
                self::subCommission($topBusinessUser, $userId, $topChannelData, $params, $commissionType);
                //查询上层是否有馆长级别 进行分佣
                self::lookForCurator($topBusinessUser['user_id'], $userId, $params);
            } else {//如果用户以购买过指定卡 写入空数据 用于点灯
                self::subCommission($topBusinessUser, $userId, $topChannelData, $params, $commissionType, 1);
            }
            Db::commit();
            Log::channel('pay')->info('掌灯人处理完成');
            return $this->success(200, '写入成功');
            //等级升级
        } catch (\Exception $ex) {
            Log::channel('pay')->info('掌灯人手动抛出错误:' . json_encode($ex, JSON_UNESCAPED_UNICODE));
            Db::rollback();
            return $this->error(100, '掌灯人手动抛出错误:' . json_encode($ex, JSON_UNESCAPED_UNICODE));
        }

    }

    /**寻找上层是否有馆长
     * @param $topId
     * @param $userId
     * @param $params
     * @author Poison
     * @date 2020/12/16 11:28 下午
     */
    public function lookForCurator($topId, $userId, $params)
    {
        //查询上级
        $topRelationData = (new BusinessRelationModel())->getOneData($topId);//查询上级信息
        if (!$topRelationData) {
            return;
        }
        $topBusinessUser = BusinessUserModel::getOneBusinessUserData(['user_id' => $topRelationData['user_id']]);
        if (!$topBusinessUser) {
            return;
        }
        if (!$topBusinessUser['is_curator']) {
            self::lookForCurator($topRelationData['user_id'], $userId, $params);
        } else {
            $topChannelData = BusinessOrganUserModel::getOneData($topRelationData['user_id'], 1);//获取上级的渠道id
            self::subCommission($topBusinessUser, $userId, $topChannelData, $params, 6);//写入
        }


    }

    /**写入掌灯人分佣
     * @param $topBusinessUser
     * @param $userId
     * @param $topBusinessOrganUser
     * @param $params
     * @param int $type 1 推荐 5 馆长 6 非管长直推
     * @param int $status 0 正常 1 记录不分
     * @return bool|void
     * @author Poison
     * @date 2020/12/16 6:35 下午
     */
    public function subCommission($topBusinessUser, $userId, $topBusinessOrganUser, $params, int $type = 1, int $status = 0)
    {
        //获取上级的年卡状态
        $topUserCard = UserCardsModel::getBusinessCard(['user_id' => $topBusinessUser['user_id']],config('self_config.business_card_id'));
        if (!$topUserCard) {//如果没有卡 直接返回
            return;
        }
        $commissionSetData = BusinessCommissionSetModel::getOneData($type);
        $topPrice = $commissionSetData['value'] / 100 * $params['price'];
        $commissionPrice = 0;
        if ($topBusinessUser['is_commission']) {
            $commissionPrice = $commissionSetData['commission_type'] == 1 ? $topPrice : $commissionSetData['value'];
        }
        $resCommission = (new BusinessCommissionModel())->insert(array(
            'order_id' => $params['order_id'],
            'user_id' => $topBusinessUser['user_id'],
            'buy_user_id' => $userId,
            'goods_type' => 1,
            'price' => (double)$params['price'],
            'type' => $type,
            'channel_id' => $topBusinessOrganUser['channel_id'],
            'commission_type' => (int)$commissionSetData['commission_type'],
            'commission_value' => (double)$commissionSetData['value'],
            'commission_price' => $status == 0 ? $commissionPrice : 0,
            'create_time' => time(),
        ));
        if (!$resCommission) {
            return false;
        }
        if ($topBusinessUser['is_commission'] && $status == 0) {
            $price = $commissionSetData['commission_type'] == 1 ? $commissionSetData['value'] / 100 * $params['price'] : $commissionSetData['value'];
            (new BusinessUserModel())->where('user_id', $topBusinessUser['user_id'])->update([
                'commission' => $topBusinessUser['commission'] + $price,
                'balance' => $topBusinessUser['balance'] + $price,
                'update_time' => time(),
            ]);
        }
        return true;
    }

    /**如果没有上级
     * 初始化掌灯人上级
     * @param int $userId
     * @param int $user_card_id
     * @return bool
     * @author Poison
     * @date 2020/12/16 2:23 下午
     */
    private function addUserBusinessRelation(int $userId, int $user_card_id = 0)
    {
        set_time_limit(0);
        $bindType = (new BusinessRelationModel())->where(['son_user_id' => $userId, 'depth' => 1])->value('bind_type');
        if ($bindType) {
            return false;
        }
        $resRelation = (new BusinessRelationModel())->insertAll([
            [
                'user_id' => config('self_config.company'),
                'son_user_id' => $userId,
                'depth' => 2,
                'bind_type' => 2,
                'create_time' => time()
            ],
            [
                'user_id' => config('self_config.nature_person'),
                'son_user_id' => $userId,
                'depth' => 1,
                'bind_type' => 2,
                'create_time' => time()
            ]
        ]);
        if (!$resRelation) {
            trace('(自动)写入自然人关系表失败', 'error');
        }
        $resOrganUser = (new BusinessOrganUserModel())->insert(array(
            'user_id' => $userId,
            'channel_id' => config('self_config.nature_person_id'),
            'status' => 1,
            'create_time' => time(),
            'update_time' => time()
        ));
        if (!$resOrganUser) {
            trace('(自动)写入掌灯人渠道用户表失败', 'error');
        }
        $B_user_data = array(
            'user_id' => $userId,
            'type' => 0,
            'is_curator' => 0,
            'update_time' => time(),
            'create_time' => time(),
            'user_card_id' => $user_card_id
        );
        $resBusinessUser = (new BusinessUserModel())->insert($B_user_data);
        if (!$resBusinessUser) {
            trace('(自动)写入掌灯人用户表失败', 'error');
        }
    }

    /**获取点灯数量
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2020/12/30 10:58 上午
     */
    public function lightOn(int $userId)
    {
        $count = (new BusinessRelationModel())
            ->alias('br')
            ->join('user_cards uc', 'br.son_user_id = uc.user_id')
            ->where(['br.status' => 1, 'br.depth' => 1, 'uc.is_refund' => 2, 'br.user_id' => $userId])
            ->where('uc.card_id NOT IN (3,6) ')
            ->group('br.son_user_id')
            ->count();
        return $this->success(200, '请求成功', ['count' => $count]);
    }

    public function team(array $params, int $userId)
    {
        $type = $params['type'];
        $page = $params['page'];
        $where = [];//初始化条件
        $timeWhere = [];
        if ($params['start_time'] > 0) {
            $where[] = ['br.create_time', '>', $params['start_time']];
            $timeWhere[] = ['create_time', '>', $params['start_time']];
        }
        if ($params['end_time'] > 0) {
            $where[] = ['br.create_time', '<', $params['end_time']];
            $timeWhere[] = ['create_time', '<', $params['end_time']];
        }
        $businessData = self::getCards($where, $userId, $type);
        if ($type != 3) {
            $resData = self::getTypeOne($businessData, $type, $userId, $timeWhere);
            goto listSuccess;//进行跳转
        }
        $data['list'] = [];
        $UserModel = new UserModel();
        foreach ($businessData as $k => $v) {
            $userData = $UserModel->field('user_name,nickname,head_pic,mobile')->where('user_id', $v['son_user_id'])->findOrEmpty()->toArray();
            if ($userData) {
                $data['list'][$k]['user_name'] = $userData['user_name'];
                $data['list'][$k]['mobile'] = $userData['mobile'];
                $data['list'][$k]['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
                $data['list'][$k]['nickname'] = $userData['nickname'];
                $data['list'][$k]['head_pic'] = $userData['head_pic'];
                unset($userData);
            }
        }
        $data['count'] = self::getBusinessLowerCount($userId, $timeWhere);
        $resData = $data;
        unset($data);
        listSuccess:
        if ($resData['list']) {
            $resData['list'] = array_slice($resData['list'], ($page - 1) * config('self_config.business_page'), config('self_config.business_page'));
        }

        //查询当前用户渠道
        $organ_user = BusinessOrganUserModel::getInfoByUserId($userId);
        $resData['is_show_mobile'] = $organ_user['is_show_mobile'] ?? 0;
        return $this->success(200, '获取成功', $resData);
    }
    public function getTeam(array $params, int $userId){
        $type = $params['type'];
        $page = $params['page'];
        $where = [];//初始化条件
        $timeWhere = [];
        if ($params['start_time'] > 0) {
            $where[] = ['br.create_time', '>', $params['start_time']];
            $timeWhere[] = ['create_time', '>', $params['start_time']];
        }
        if ($params['end_time'] > 0) {
            $where[] = ['br.create_time', '<', $params['end_time']];
            $timeWhere[] = ['create_time', '<', $params['end_time']];
        }
        $businessData = self::getCards($where, $userId, $type);
        if ($type != 3) {
            $resData = self::getTypeOne($businessData, $type, $userId, $timeWhere,true);
            goto listSuccess;//进行跳转
        }
        $data['list'] = [];
        $UserModel = new UserModel();
        foreach ($businessData as $k => $v) {
            $userData = $UserModel->field('user_name,nickname,head_pic,mobile')->where('user_id', $v['son_user_id'])->findOrEmpty()->toArray();
            if ($userData) {
                $data['list'][$k]['user_name'] = $userData['user_name'];
                $data['list'][$k]['mobile'] = $userData['mobile'];
                $data['list'][$k]['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
                $data['list'][$k]['nickname'] = $userData['nickname'];
                $data['list'][$k]['head_pic'] = $userData['head_pic'];
                unset($userData);
            }
        }
        $data['count'] = self::getNewBusinessLowerCount($userId, $timeWhere);
        $resData = $data;
        unset($data);
        listSuccess:
        if ($resData['list']) {
            $resData['list'] = array_slice($resData['list'], ($page - 1) * config('self_config.business_page'), config('self_config.business_page'));
        }

        //查询当前用户渠道
        $organ_user = BusinessOrganUserModel::getInfoByUserId($userId);
        $resData['is_show_mobile'] = $organ_user['is_show_mobile'] ?? 0;
        return $this->success(200, '获取成功', $resData);
    }

    /**查找购卡的下级
     * @param $businessData
     * @param $type
     * @param $userId
     * @param $timeWhere
     * @param $isNotType
     * @return array
     * @author Poison
     * @date 2020/12/30 8:46 下午
     */
    protected function getTypeOne($businessData, $type, $userId, $timeWhere,$isNotType = false)
    {
        $data = [];
        $data['list'] = [];
        $where = $timeWhere;
        $userCardsModel = new UserCardsModel();
        $userModel = new UserModel();
        $cardsModel = new CardsModel();
        foreach ($businessData as $k => $v) {//查询出来
            $userCard = $userCardsModel
                ->field('is_refund,card_id,create_time,user_id,refund_card_time')
                ->where($where)
                ->where('user_id', $v['son_user_id'])
                ->where('card_id NOT IN (3,6) ')
                ->order('id', 'DESC')
                ->findOrEmpty()->toArray();
            if (!$userCard) {
                continue;
            }
            if ($userCard['is_refund'] != $type) {
                continue;
            }
            $userCard['create_time'] = date('Y-m-d H:i:s', $userCard['create_time']);
            if ($type == 1) {
                $userCard['refund_card_time'] = date('Y-m-d H:i:s', $userCard['refund_card_time']);
            }
            $userData = $userModel->field('user_name,nickname,real_name,head_pic')->where('user_id', $v['son_user_id'])->findOrEmpty()->toArray();
            if (!$userData) {
                continue;
            }
            $userCard['card_name'] = $cardsModel->where('id', $userCard['card_id'])->value('name');
            $userCard['user_name'] = $userData['user_name'];
            $userCard['nickname'] = $userData['nickname'];
            $userCard['real_name'] = $userData['real_name'];
            $userCard['head_pic'] = $userData['head_pic'];
            //进行数据二次排序
            $data['list'][] = $userCard;
            unset($userCard);
            unset($userData);
        }
        $data['list'] = quickSort($data['list'], 'create_time');
        if($isNotType){
            $data['count'] = self::getNewBusinessLowerCount($userId, $timeWhere);
        }else{
            $data['count'] = self::getBusinessLowerCount($userId, $timeWhere);
        }

        return $data;
    }


    /**掌灯人邀请接口
     * @param array $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/4 6:07 下午
     */
    public function invite(array $params)
    {
        $inviteTopId = $params['top_id'];
        $params['top_id'] = self::getDecode($params['top_id']);
        $topId = $topIdArray = json_decode($params['top_id'], TRUE);
        if (is_array($topIdArray)) {
            $topId = $topIdArray['top_id'];
        }
        $usersModel = new UserModel();
        $busRelationModel = new BusinessRelationModel();
        $busUserModel = new BusinessUserModel();
        $nickname = (new UserModel())->getOneUser((int)$topId, 1, 'nickname');
        if(!$nickname){
            return self::error(100,'上级不存在');
        }
        $resInviteCheck = self::inviteCheck((int)$topId, (int)$params['user_id'], $usersModel, $busRelationModel, $busUserModel, $nickname);
        if ($resInviteCheck['code'] != 200) {
            if ($resInviteCheck['code'] == 100) {
                return $resInviteCheck;
            }
            return self::success(200, $resInviteCheck['message']);
        }
        $lowTopId = isset($resInviteCheck['data']['low_top_id']) ? $resInviteCheck['data']['low_top_id'] : 0;
        $userId = (int)$params['user_id'];
        Db::startTrans();
        try {
            //开始断关系
            if ($lowTopId) {
                $thisBusRelation = $busRelationModel->where(['son_user_id' => $userId, 'status' => 1])->update(['status' => 2, 'end_time' => time(), 'update_time' => time()]);
                if (!$thisBusRelation) {
                    Db::rollback();
                    return self::error(100, '失败序号13，请将页面截图给客服人员');
                }
            }
            /** 建立新的关系 **/
            //查询上级的关系
            $topBisRelation = $busRelationModel->field('user_id,depth')->where(['son_user_id' => $topId, 'status' => 1])->group('depth')->select()->toArray();
            foreach ($topBisRelation as $k => $v) {
                //开始建立关系
                $resBusRelation = $busRelationModel->businessRelationInsert($userId, $v['user_id'], $v['depth'] + 1, 1);
                if (!$resBusRelation) {
                    return self::error(100, '失败序号14，请将页面截图给客服人员');
                }
            }
            $resBusRelationTwo = $busRelationModel->businessRelationInsert($userId, $topId, 1, 1);
            if (!$resBusRelationTwo) {
                return self::error(100, '失败序号15，请将页面截图给客服人员');
            }
            /** 建立关系完成 **/
            //创建-修改 渠道关系
            $resUpRelation = self::updateRelation((int)$topId, $userId);
            if ($resUpRelation['code'] != 200) {
                return self::error(100, '失败序号16，请将页面截图给客服人员');
            }
            //写入用户信息
            $thisBusUser = $busUserModel->where('user_id', $userId)->findOrEmpty()->toArray();
            if (!$thisBusUser) {
                $resBusUser = $busUserModel->insert([
                    'user_id' => $userId,
                    'type' => 0,
                    'create_time' => time(),
                ], true);
                if (!$resBusUser) {
                    return self::error(100, '失败序号17，请将页面截图给客服人员');
                }
            }
            //写入日志
            if ($lowTopId > 0) {
                $str = "用户从ID:" . $lowTopId . " 解除软绑，绑定到了ID:" . $params['top_id'];
            } else {
                $str = '软绑成功-上级ID:' . $params['top_id'];
            }
            (new BusinessUserLogModel())->insert([
                'user_id' => $userId,
                'type_name' => $str,
                'create_time' => time(),
                'remark' => $str
            ]);
            (new LotteryService())->invite(['top_id'=>$inviteTopId,'channel'=>2,'lottery_id'=>$params['lottery_id']],$userId);
            //提交事务
            Db::commit();
            $resConnect = self::connectSuperior($userId);
            if($resConnect['code']!=200){
                self::sendDingError('掌灯人邀请异常 下级重建关系失败 原因:',$resConnect['message']);
            }
            return self::success(200, '恭喜您与微信昵称：' . $nickname . ' 绑定成功！', []);
        } catch (\Exception $e) {
            Db::rollback();
            trace('掌灯人-》邀请 异常 用户Id：' . $params['user_id'] . ' 异常信息:' . json_encode($e), 'error');
            self::sendDingError('掌灯人邀请异常:',$e);
            return self::error(100, '服务器异常，请联系客服');
        }
    }

    /**修改下级新的关系+渠道
     * @param int $topId
     * @param int $channelId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/7/5 10:58 上午
     */
    private function connectSuperior(int $topId): array
    {
        Db::startTrans();
        $t = time();
        //查询该用户下面 所以下级
        $data = BusinessRelationModel::getSubordinateListByUserId($topId);
        $topData = BusinessRelationModel::getParentUser($topId);

        $relationModel = new BusinessRelationModel();
        $channelId = BusinessOrganUserModel::getOneData($topId,1)['channel_id'];
        foreach ($data as $v){
            $resRelation = $relationModel->where('son_user_id',$v['son_user_id'])->where('depth','>',$v['depth'])->update(['status'=>2,'end_time'=>$t,'update_time'=>$t]);
            if(!$resRelation){
                Db::rollback();
                return errorArray('下级从新建立关系-》斩断关系失败');
            }
            //处理完成 开始写入新的关系
            foreach ($topData as $vue){
                $resCreate = $relationModel->businessRelationInsert($v['son_user_id'],$vue['user_id'],$v['depth']+$vue['depth'],$v['bind_type'],$t);
                if(!$resCreate) {
                    Db::rollback();
                    return errorArray('下级从新建立关系-》建立关系失败');
                }
            }
            //修改渠道信息
            //判断是否还在当前渠道 如果不是修改去到为新的渠道
            $relationData = BusinessOrganUserModel::getOneData($v['son_user_id'],1);
            if($relationData && $relationData['is_top'] == 0 && $relationData['channel_id'] != $channelId){//有值并不是渠道顶级
                $resOne = (new BusinessOrganUserModel())->updateDataByUserId($v['son_user_id'],$t);
                $resTwo = (new BusinessOrganUserModel())->BusinessOrganUserInsert($v['son_user_id'],$channelId);
                if(!$resOne || !$resTwo){
                    Db::rollback();
                    return errorArray('下级从新建立关系-》渠道关系移动失败');
                }
            }
        }
        Db::commit();
        return successArray();
    }

    /**获取掌灯人团队数据
     * @param $userId
     * @param $timeWhere
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2020/12/30 9:07 下午
     */
    protected function getBusinessLowerCount($userId, $timeWhere = [])
    {
        //先查出来它下面说有的下级
        $busRelationData = (new BusinessRelationModel())
            ->field('son_user_id,create_time')
            ->where(['user_id' => $userId, 'depth' => 1, 'status' => 1])
            ->group('son_user_id')
            ->select()->toArray();
        //初始化数据
        $data['end_team'] = 0;
        $data['team'] = 0;
        $data['not_team'] = 0;
        $cardData = (new CardsModel())->field('id,name')->where('id NOT IN (3,6) ')->order('price DESC,id DESC')->select()->toArray();
       // $cardData = (new CardsModel())->field('id,name')->order('price DESC,id DESC')->select()->toArray();
        foreach ($cardData as $k => $v) {
            $data['card_' . $v['id']]['number'] = 0;
            $data['card_' . $v['id']]['name'] = $v['name'];
        }
        $userCardsModel = new UserCardsModel();
        $usersModel = new UserModel();
        foreach ($busRelationData as $k => $v) {
            //判断用户是否买卡
            $UserCardData = $userCardsModel
                ->field('is_refund,card_id,create_time')
                //->where($timeWhere)
                ->where('user_id', $v['son_user_id'])
                ->where('card_id NOT IN (3,6) ')
                ->order('id', 'DESC')
                ->findOrEmpty()->toArray();
            if (!$UserCardData) {
                if ($timeWhere) {
                    if (isset($timeWhere[0][2])) {
                        if ($v['create_time'] < $timeWhere[0][2]) {
                            continue;
                        }
                    }
                    if (isset($timeWhere[1][2])) {
                        if ($v['create_time'] > $timeWhere[1][2]) {
                            continue;
                        }
                    }
                }
                $userName = $usersModel->getOneUser((int)$v['son_user_id']);
                if (!$userName) {
                    continue;
                }
                $data['not_team']++;
                continue;
            }
            if ($timeWhere) {
                if (isset($timeWhere[0][2])) {
                    if ($UserCardData['create_time'] < $timeWhere[0][2]) {
                        continue;
                    }
                }
                if (isset($timeWhere[1][2])) {
                    if ($UserCardData['create_time'] > $timeWhere[1][2]) {
                        continue;
                    }
                }
            }
            if ($UserCardData['is_refund'] != 2) {
                $data['end_team']++;
                continue;
            }
            $data['team']++;
            $data['card_' . $UserCardData['card_id']]['number']++;
        }
        return $data;
    }
    protected function getNewBusinessLowerCount($userId, $timeWhere = [])
    {
        //先查出来它下面说有的下级
        $busRelationData = (new BusinessRelationModel())
            ->field('son_user_id,create_time')
            ->where(['user_id' => $userId, 'depth' => 1, 'status' => 1])
            ->group('son_user_id')
            ->select()->toArray();
        //初始化数据
        $data['end_team'] = 0;
        $data['team'] = 0;
        $data['not_team'] = 0;
        $userCardsModel = new UserCardsModel();
        $usersModel = new UserModel();
        foreach ($busRelationData as $k => $v) {
            //判断用户是否买卡
            $UserCardData = $userCardsModel
                ->field('is_refund,card_id,create_time')
                //->where($timeWhere)
                ->where('user_id', $v['son_user_id'])
                ->where('card_id NOT IN (3,6) ')
                ->order('id', 'DESC')
                ->findOrEmpty()->toArray();
            if (!$UserCardData) {
                if ($timeWhere) {
                    if (isset($timeWhere[0][2])) {
                        if ($v['create_time'] < $timeWhere[0][2]) {
                            continue;
                        }
                    }
                    if (isset($timeWhere[1][2])) {
                        if ($v['create_time'] > $timeWhere[1][2]) {
                            continue;
                        }
                    }
                }
                $userName = $usersModel->getOneUser((int)$v['son_user_id']);
                if (!$userName) {
                    continue;
                }
                $data['not_team']++;
                continue;
            }
            if ($timeWhere) {
                if (isset($timeWhere[0][2])) {
                    if ($UserCardData['create_time'] < $timeWhere[0][2]) {
                        continue;
                    }
                }
                if (isset($timeWhere[1][2])) {
                    if ($UserCardData['create_time'] > $timeWhere[1][2]) {
                        continue;
                    }
                }
            }
            if ($UserCardData['is_refund'] != 2) {
                $data['end_team']++;
                continue;
            }
            $data['team']++;
        }
        return $data;
    }


    /**更具状态获取下级
     * @param $where
     * @param $userId
     * @param int $type
     * @return mixed
     * @author Poison
     * @date 2020/12/30 3:06 下午
     */
    protected function getCards($where, $userId, $type = 1)
    {
        if ($type != 3) {
            $where = [];
            $where[] = ['bu.user_card_id', '>', 0];
        } else {
            $where[] = ['bu.user_card_id', '=', 0];
        }
        $where[] = ['br.user_id', '=', $userId];
        $where[] = ['br.depth', '=', 1];
        $where[] = ['br.status', '=', 1];
        return (new BusinessRelationModel())
            ->alias('br')
            ->field('br.son_user_id,br.create_time')
            ->join('business_user bu', 'br.son_user_id = bu.user_id')
            ->where($where)
            ->order('br.create_time', 'DESC')
            ->group('br.son_user_id,bu.user_id')
            ->select()->toArray();
    }

    /**获取当前用户正常的卡
     * @param $userId
     * @param $startTime
     * @param $endTime
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/5/10 3:00 下午
     */
    public function  getTeamCard($userId,$startTime,$endTime){
        //先查出来它下面说有的下级
        $busRelationData = (new BusinessRelationModel())
            ->field('son_user_id,create_time')
            ->where(['user_id' => $userId, 'depth' => 1, 'status' => 1])
            ->group('son_user_id')
            ->select()->toArray();
        //初始化数据
        $data = [];
        $cardData = (new CardsModel())->field('id,name')->where('id NOT IN (3,6) ')->order('price DESC,id DESC')->select()->toArray();
        //$cardData = (new CardsModel())->field('id,name')->order('price DESC,id DESC')->select()->toArray();
        foreach ($cardData as $k => $v) {
            $data['card_' . $v['id']]['number'] = 0;
            $data['card_' . $v['id']]['name'] = $v['name'];
        }
        $userCardsModel = new UserCardsModel();
        $usersModel = new UserModel();
        foreach ($busRelationData as $k => $v) {
            //判断用户是否买卡
            $UserCardData = $userCardsModel
                ->field('is_refund,card_id,create_time')
                //->where($timeWhere)
                ->where('user_id', $v['son_user_id'])
                ->where('card_id NOT IN (3,6) ')
                ->order('id', 'DESC')
                ->findOrEmpty()->toArray();
            if (!$UserCardData) {
                continue;
            }
            $userName = $usersModel->getOneUser((int)$v['son_user_id']);
            if (!$userName) {
                continue;
            }
            if (!empty($startTime) || !empty($endTime)) {
                if (isset($startTime)) {
                    if ($UserCardData['create_time'] < $startTime) {
                        continue;
                    }
                }
                if (isset($endTime)) {
                    if ($UserCardData['create_time'] > $endTime) {
                        continue;
                    }
                }
            }
            $data['card_' . $UserCardData['card_id']]['number']++;
        }
        return successArray(['card_number'=>$data]);
    }

    /**验证掌灯人规则
     * @param int $topId
     * @param int $userId
     * @param $usersModel
     * @param $busRelationModel
     * @param $busUserModel
     * @param $nickname
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/22 2:36 下午
     */
    protected function inviteCheck(int $topId, int $userId, $usersModel, $busRelationModel, $busUserModel, $nickname)
    {
        //判断是否有上级标示
        if (!isset($topId) || !$topId) {
            return self::error(100, '没有获取到邀请ID，请重新扫描绑定二维码');
        }
        if ($topId == config('self_config.company')) {//判断上级用户是否为公司顶级
            return self::error(100, '微信昵称：' . $nickname . ' 不具备邀请权限');
        }
        if ($userId == config('self_config.company')) {//判断上级用户是否为公司顶级
            return self::error(100, '您是公司顶级 不能被转绑');
        }
        if($topId == $userId){
            return self::error(100, '自己不能邀请自己');
        }
        $userCardsData = UserCardsModel::getOneCards($userId);
        if ($userCardsData) {
            return self::error(100, '您已是购卡用户，购卡后不能绑定新的关系');
        }
        $topCardData = UserCardsModel::getCard(['user_id'=>$topId]);
        if (!$topCardData || (isset($topCardData['is_out_pay']) && $topCardData['is_out_pay'] == 1)) {
            return self::error(100, '微信昵称：' . $nickname . ' 已经退卡，不具备邀请权限');
        }
        $newTopBusUserData = $busUserModel->getOneBusinessUserData(['user_id' => $topId]);
        if (!$newTopBusUserData || $newTopBusUserData['type'] == 0) {
            return self::error(100, '微信昵称：' . $nickname . ' 不具备邀请权限');
        }
        //查询用户是否是渠道顶级
        $thisIsTop = (new BusinessOrganUserModel())->where('user_id',$userId)->value('is_top');
        if (isset($thisIsTop) && $thisIsTop == 1) {
            return self::error(100, '顶级不可以被转绑');
        }
        //判断是否有以前的上级 如果有判断是否不可转绑标示
        $lowBusRelation = $busRelationModel->getOneData($userId);
        if (!$lowBusRelation) {
            return self::success(200, 'check成功', []);
        }
        $lowBusUserData = $busUserModel->getOneBusinessUserData(['user_id' => $lowBusRelation['user_id']]);
        if (isset($lowBusUserData['is_change']) && $lowBusUserData['is_change'] == 2) {
            return self::error(100, '您已被设置不可转绑，不能绑定新的关系');
        }
        if ($lowBusRelation['user_id'] == $topId) {
            return self::error(10001, '恭喜您与微信昵称：' . $nickname . ' 绑定成功！');
        }
        return self::success(200, 'check成功', ['low_top_id' => $lowBusRelation['user_id'] ?? 0]);
    }

    /**添加或修改渠道信息
     * @param int $topId
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/22 2:38 下午
     */
    protected function updateRelation(int $topId, int $userId)
    {
        $busOrganUserModel = new BusinessOrganUserModel();
        $topBusOrganUser = $busOrganUserModel->getOneData($topId, 1);
        $thisBusOrganUser = $busOrganUserModel->getOneData($userId, 1);
        if (!$thisBusOrganUser) {
            $resInsert = $busOrganUserModel->BusinessOrganUserInsert($userId, $topBusOrganUser['channel_id']);
        } else {
            $resInsert = $busOrganUserModel->update(['channel_id' => $topBusOrganUser['channel_id'], 'update_time' => time()], ['user_id' => $userId]);
        }
        if (!$resInsert) {
            return self::error(100, '添加或修改失败');
        }
        return self::success();
    }

    /**入口查看
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/22 3:36 下午
     */
    public function look(int $userId)
    {
        $lockBusiness  = self::getCache('lock_business_look_'.$userId);
        if($lockBusiness){
            return self::error(100,'支付业务处理中～请稍后尝试');
        }
        $businessUserData = (new BusinessUserModel())->where('user_id', $userId)->order('id', 'DESC')->findOrEmpty()->toArray();
        $userData = (new UserModel())->field('real_name,is_out_pay')->where('user_id', $userId)->findOrEmpty()->toArray();
        $userCardData = (new UserCardsModel())->getCard(['user_id' => $userId]);
        if (!$userCardData) {
            return self::error(100, '您不是共享合伙人');
        }
        if (isset($businessUserData['type']) && $businessUserData['type'] > 0) {
            //判断用户的创建实际 是否大于设定的时间
           // if ($businessUserData['create_time'] > strtotime('2020-8-17 17:10:00')) {
                //判断用户是否提交过真实姓名
                if (!isset($userData['real_name']) || !$userData['real_name']) {
                    return self::error(100, '您还是共享合伙人~');
                }
           // }
            return self::success();
        }else{
            if(in_array((int)$userCardData['card_id'],config('self_config.business_card_id'))){
                if(!$businessUserData){//写入信息
                   (new BusinessRelationModel())->businessRelationInsert($userId, config('self_config.nature_person'), 1, 2);
                    (new BusinessRelationModel())->businessRelationInsert($userId, config('self_config.company'), 2, 2);
                    //写入共享合伙人信息
                    (new BusinessUserModel())->businessUserInsert($userId, (int)$userCardData['id'], 0);
                    //写入渠道信息
                    (new BusinessOrganUserModel())->BusinessOrganUserInsert($userId);
                }
                (new BusinessUserModel())->where('user_id', $userId)->update(['type' => 1, 'update_time' => time(), 'user_card_id' => (int)$userCardData['id'], 'partner_time'=>time()]);
                (new BusinessRelationModel())->where('son_user_id', $userId)->update(['bind_type' => 2, 'update_time' => time()]);
                (new BusinessUserLogModel())->businessUserLogInsert($userId, '会员自主开通共享合伙人 不记录 不分佣');
                return self::success();
            }
            return self::error(100, '您不是共享合伙人');
        }
    }

    /**获取银行卡信息
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/22 3:59 下午
     */
    public function cardInfo(int $userId)
    {
        $bankData = (new BusinessUSerBankModel())->field('real_name,id_card,card_no')->where(['user_id' => $userId, 'type' => 1])->findOrEmpty()->toArray();
        return self::success(200, '', $bankData);
    }

    /**删除卡信息
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/22 4:03 下午
     */
    public function delBank(int $userId)
    {
        $upBank = (new BusinessUserBankModel())->update(['type' => 99, 'delete_time' => time()], ['user_id' => $userId, 'type' => 1]);
        if (!$upBank) {
            return self::error(100, '删除失败');
        }
        return self::success(200, '', $upBank);
    }

    /**绑定卡
     * @param array $params
     * @return array
     * @author Poison
     * @date 2021/2/22 4:21 下午
     */
    public function upCards(array $params): array
    {
        $userId = (int)$params['user_id'];
        //请求接口 验证卡是否正常
        $appHost = config('self_config.host_api').'/';
        $url = $appHost . config('self_config.check_bank_url');
        $result = json_decode(self::curl_request($url, $params, 'POST'), TRUE);
        if (!$result) {
            return self::error(100, '网络异常，请稍后再试');
        }
        if ($result['error'] == 1) {
            return self::error(100, $result['message']);
        }
        //查询是否有可以的银行卡信息
        $bankData = (new BusinessUserBankModel())->where(['user_id' => $userId, 'type' => 1])->findOrEmpty()->toArray();
        $params['update_time'] = time();
        if ($bankData) {//如果存在 修改
            $resBank = (new BusinessUserBankModel())->update($params, ['user_id' => $userId, 'type' => 1]);
        } else {//新增
            $params['create_time'] = time();
            $resBank = (new BusinessUserBankModel())->insert($params, true);
        }
        if (!$resBank) {
            return self::error(100, '提交失败');
        }
        return self::success(200, '', $params);
    }

    /**获取首页数据
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/23 9:13 上午
     */
    public function index(int $userId)
    {
        $fields = 'rd_business_user.type,rd_business_user.is_curator,b.user_name,b.nickname,b.head_pic,rd_business_user.balance,b.real_name';
        $userData = (new BusinessUserModel())->field($fields)
            ->join('rd_users b', 'rd_business_user.user_id = b.user_id')
            ->where('rd_business_user.user_id', $userId)->findOrEmpty()->toArray();
        $withdrawSet = (new BusinessWithdrawSetModel())->where('id', 1)->findOrEmpty()->toArray();
        $count = (new BusinessCommissionModel())->where(['user_id' => $userId, 'depth' => 1, 'status' => 1])->sum('price');
        $userData['withdraw_num'] = $withdrawSet['withdraw_num'] ?? 1;
        $userData['least_price'] = $withdrawSet['least_price'] ?? 50;
        $userData['highest_price'] = $withdrawSet['highest_price'] ?? 10000;
        $userData['handling_fee'] = $withdrawSet['handling_fee'] ?? '0.00';
        $userData['is_share'] = $userId != config('self_config.company') ? 1 : 0;//1可以分享 0 不可以分享
        $userData['commission_price'] = $count;
        return self::success(200, '', $userData);
    }

    /**获取业绩
     * @param array $params
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/23 10:11 上午
     */
    public function performance(array $params, int $userId)
    {
        //开始查询时间
        $where = [];//初始化条件
        $dayWhere = [];
        //组装条件
        if (isset($params['start_time']) && $params['start_time']) {
            $where[] = ['bc.create_time', '>', strtotime($params['start_time'])];
        }
        if (isset($params['end_time']) && $params['end_time']) {
            $where[] = ['bc.create_time', '<', strtotime($params['end_time'])];
        }
        if (isset($params['mobile']) && $params['mobile']) {
            $where[] = ['us.mobile', '=', $params['mobile']];
        }

        if (count($where) == 0) {
            $dayWhere[] = ['bc.create_time', '>', strtotime(date('Y-m-d'))];
        }
        $where[] = ['bc.user_id', '=', $userId];
        $where[] = ['bc.commission_price', '>', 0];
        $where[] = ['bc.status', '=', 1];
        $field = "bc.id,bc.goods_type,bc.type,bc.commission_price,FROM_UNIXTIME(bc.create_time) AS create_time,ucd.user_id,ucd.card_id,cs.name,us.user_name,us.nickname,us.head_pic,us.real_name";
        if ($params['type'] == 2) {
            unset($dayWhere);
            $dayWhere[] = ['rd_business_comission.create_time', '>=', strtotime(date('Y-m'))];
        }
        //获取数据
        $resCommissionData = (new BusinessCommissionModel())->field($field)->alias('bc')
            ->join('rd_user_card_details ucd', 'bc.order_id = ucd.id')
            ->join('rd_users us', 'ucd.user_id = us.user_id')
            ->join('rd_cards cs', 'ucd.card_id = cs.id')
            ->where($dayWhere)
            ->where($where)->order('bc.create_time', 'DESC')
            ->limit(($params['page'] - 1) * config('self_config.business_page'), config('self_config.business_page'))
            ->select()->toArray();
        $resData['total'] = 0;
        foreach ($resCommissionData as $k=>$v){
            $resData['total']+= $v['commission_price'];
        }
        $resData['list'] = $resCommissionData;
        $resData['count'] = count($resCommissionData);
        $resData['my'] = self::selectPerformance($userId);
        return self::success(200, '获取成功', $resData);

    }

    /**获取今天+本月+总 的数量
     * @param $userId
     * @author Poison
     * @date 2021/2/23 10:10 上午
     */
    protected function selectPerformance($userId)
    {
        //今日
        $data['day'] = (new BusinessCommissionModel())
                ->where(['user_id' => $userId, 'status' => 1])
                ->where('create_time', '>', strtotime(date('Y-m-d')))
                ->sum('commission_price') ?? 0;
        //本月
        $data['month'] = (new BusinessCommissionModel())
                ->where(['user_id' => $userId, 'status' => 1])
                ->where('create_time', '>', strtotime(date('Y-m')))
                ->sum('commission_price') ?? 0;
        //总
        $data['sum'] = (new BusinessUserModel())->where('user_id', $userId)->order('id', 'DESC')->value('commission') ?? 0;
        return $data;
    }

    /**申请提现
     * @param array $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/23 11:56 上午
     */
    public function setWithdraw(array $params)
    {
        $userId = (int)$params['user_id'];
        $withdrawList = $params['withdraw_list'];
        if (!is_array($withdrawList)) {
            $withdrawList = explode(',', $withdrawList);
        }
        Db::startTrans();//开启事务
        $resCheck = self::checkWithdraw($withdrawList, $userId);//验证是否可以提现
        if ($resCheck['code'] != 200) {
            return self::error(100, $resCheck['message']);
        }
        $resCheck = $resCheck['data'];
        //查询是否是员工
        $isStaff = (new UserModel())->where('user_id', $userId)->value('is_staff');
        //计算手续费
        $chargeAmount = $resCheck['withdraw_set']['handling_fee'] * $resCheck['sum'];
        $balanceOne = ($resCheck['balance'] - $resCheck['sum'] - $chargeAmount);
        //开始写入到提现表中
        $withdrawData = array(
            'user_id' => $userId,
            'withdraw_amount' => $resCheck['sum'],
            'original_amount' => $resCheck['balance'],
            'balance' => $balanceOne,
            'remark' => '',
            'status' => 1,
            'create_time' => time(),
            'charge_amount' => $chargeAmount,
            'money_status' => 1,
            'withdraw_amount_actual' => $resCheck['sum'] - $chargeAmount,
            'is_staff' => $isStaff,
        );
        $resRecord = (new BusinessWithdrawRecordModel())->insert($withdrawData, true);
        if (!$resRecord) {
            Db::rollback();
            return self::error(100, '网络异常-写入提现失败');
        }
        //修改余额
        $resBusinessUser = (new BusinessUserModel())->update(['balance' => $balanceOne, 'update_time' => time()], ['user_id' => $userId]);
        if (!$resBusinessUser) {
            Db::rollback();
            return self::error(100, '网络异常-修改余额失败');
        }
        $resLog = LogService::BusinessUserLog($userId, $balanceOne);
        if (!$resLog) {
            Db::rollback();
            return self::error(100, '网络异常-写入日志失败');
        }
        //处理佣金表中提现
        $resCommission = (new BusinessCommissionModel())->whereIn('id', $withdrawList)->update(['withdraw_id' => $resRecord]);
        if (!$resCommission) {
            Db::rollback();
            return self::error(100, '网络异常-修改失败');
        }
        //发送通知到叮叮通知群
        $sendMessage = '你有一条提现申请，待处理，请注意查收。提现金额：' . $resCheck['sum'];
        self::sendDing($sendMessage, ['13717650500'], config('ding_config.commission_web_hook'));
        Db::commit();
        return self::success(200, '提现成功', ['withdraw_id' => $resRecord]);
    }

    /**提现验证
     * @param $withdrawList
     * @param $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/2/23 11:15 上午
     */
    protected function checkWithdraw($withdrawList, $userId)
    {
        $day = date('d');
        if ($day != 5 && $day != 20) {
            return self::error(100, '今天不是提现日');
        }
        $sum = 0;
        $commissionData = (new BusinessCommissionModel())->field('withdraw_id,user_id,commission_price')->whereIn('id', $withdrawList)->select()->toArray();
        foreach ($commissionData as $key => $vue) {
            if ($vue['withdraw_id']) {
                return self::error(100, '提现金额中包含已提现金额');
            }
            if ($vue['user_id'] != $userId) {
                return self::error(100, '提现列表中包含不属于您的金额');
            }
            $sum += $vue['commission_price'];
        }
        //获取提现配置
        $withdrawSet = BusinessWithdrawSetModel::getOneData();
        if (!$withdrawSet) {
            return self::error(100, '提现设置异常，请联系客服处理');
        }
        if ($sum < $withdrawSet['least_price']) {
            return self::error(100, '提现金额小于最低提现额度');
        }
        if ($sum > $withdrawSet['highest_price']) {
            return self::error(100, '提现金额大于最大提现额度');
        }
        $num = BusinessWithdrawRecordModel::getWithdrawData($userId);
        if ($num >= $withdrawSet['withdraw_num']) {
            return self::error(100, '今天提现次数已用完～');
        }
        $balance = (new BusinessUserModel())->where('user_id', $userId)->value('balance');//查询用户的余额
        $data['sum'] = $sum;
        $data['balance'] = $balance;
        $data['withdraw_set'] = $withdrawSet;
        return self::success(200, '', $data);
    }

    /**获取可提现列表
     * @param $params
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/2/23 2:17 下午
     */
    public function withdraw($params, int $userId)
    {
        $day = date('d');//当前几号
        $commissionData = (new BusinessCommissionModel())->getList($userId);
        $day5 = strtotime(date('Y-m-5'));
        $day20 = strtotime(date('Y-m-20', strtotime('last day of -1 month')));
        if ($params['type'] == 1) {
            foreach ($commissionData as $k => $v) {
                if ($v['refund_card_status'] == 2 || $v['refund_card_status'] == 3 || $v['refund_card_status'] == 1) {
                    unset($commissionData[$k]);
                }
                switch ($day) {
                    case 5:
                        if ($v['t_create_time'] > $day20) {
                            unset($commissionData[$k]);
                        } else {
                            $commissionData[$k]['is_withdraw'] = 1;
                        }
                        break;
                    case 20:
                        if ($v['t_create_time'] > $day5) {
                            unset($commissionData[$k]);
                        } else {
                            $commissionData[$k]['is_withdraw'] = 1;
                        }
                        break;
                    default:
                        unset($commissionData[$k]);
                        break;
                }
            }
        }else{
            foreach ($commissionData as $k => $v) {
                if ($v['refund_card_status'] == 0 || $v['refund_card_status'] == 4) {
                    switch ($day) {
                        case 5:
                            if ($v['t_create_time'] < $day20) {
                                unset($commissionData[$k]);
                            } else {
                                $commissionData[$k]['is_withdraw'] = 0;
                            }
                            break;
                        case 20:
                            if ($v['t_create_time'] < $day5) {
                                unset($commissionData[$k]);
                            } else {
                                $commissionData[$k]['is_withdraw'] = 0;
                            }
                            break;
                        default:
                            $commissionData[$k]['is_withdraw'] = 0;
                            break;
                    }
                } else {
                    if ($v['refund_card_time'] > 0) {
                        $times = (int)$v['refund_card_time'] + 30 * 86400;
                        if ($times < time()) {
                            unset($commissionData[$k]);
                        } else {
                            $commissionData[$k]['is_withdraw'] = 0;
                        }
                    } else {
                        $commissionData[$k]['is_withdraw'] = 0;
                    }

                }

            }
        }
        $resData = [];
        $i = 0;
        foreach ($commissionData as $k => $v) {
            if (!isset($v['t_create_time']) || !$v['t_create_time']) {
                unset($commissionData[$k]);
            }else{
                $resData[$i] = $v;
                $i++;
            }
        }
        return self::success(200,'',$resData);
    }


    /**
     * 获取用户名称 头像
     * @param array $data
     * @param int $user_id  用户ID
     * @return array
     * @author yangliang
     * @date 2021/2/25 10:06
     */
    public function getUserName(array $data, int $user_id){
        if(empty($data['session_key'])){
            return $this->error(100, 'session_key不存在');
        }

        $wx_user_info = [];
        if(!empty($data['encryptedData'])){
            if(empty($data['iv'])){
                return $this->error(100, 'iv不能为空');
            }

            $res = (new DataCrypt($data['session_key']))->decryptData($data['encryptedData'], $data['iv'], $wx_user_info);
            if($res['code'] != 200){
                return $this->error(100, $res['message']);
            }

            $wx_user_info = json_decode($wx_user_info, true);
            if($wx_user_info){
                $datas = [];
                header("Content-type:text/html;charset=utf-8");
                $datas['head_pic'] = $wx_user_info['avatarUrl'];
                $datas['nickname'] = $wx_user_info['nickName'];
                $datas['update_time'] = time();
                if (!$datas) {
                    return $this->success($wx_user_info, 200, '修改成功');
                }

                $res_user = UserModel::where('user_id', $user_id)->update($datas);
                if(!$res_user){
                    return $this->error(100, '修改失败');
                }

                return $this->success(200, '修改成功', $wx_user_info);
            }else{
                return $this->error(100, '获取微信头像昵称失败');
            }
        }else{
            return $this->error(100, '获取微信头像昵称失败');
        }

        return $this->success(200, '获取成功', $wx_user_info);
    }

    /**掌灯人提现记录
     * @param $data
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/3/3 2:31 下午
     */
    public function getWithdraw($data,int $userId){
        $fields = "withdraw_amount,charge_amount,remark,withdraw_amount_actual,status,FROM_UNIXTIME(create_time) AS create_time,money_status";
        if($data['type'] == 1){
            $where['user_id'] = $userId;
            $where['status'] = 1;
            $data = (new BusinessWithdrawRecordModel())
                ->field($fields)
                ->where($where)
                ->order('create_time','DESC')
                ->limit(($data['page'] -1) * config('self_config.business_page'),config('self_config.business_page'))
                ->select()->toArray();
        }else{
            $data = (new BusinessWithdrawRecordModel())
                ->field($fields)
                ->where('user_id',$userId)
                ->whereIn('status','2,3')
                ->order('create_time','DESC')
                ->limit(($data['page'] -1) * config('self_config.business_page'),config('self_config.business_page'))
                ->select()->toArray();
        }

        return $this->success(200,'',$data);
    }

    /**海报
     * @param $data
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/3/3 2:51 下午
     */
    public function pics($data){
        $roleId = $data['role_id'];
        $pics = (new DistributionRolePicturesModel())->field('img,id')
            ->where(['distribution_role_id'=>$roleId,'is_show'=>1])
            ->order('pic_sort','DESC')->select()->toArray();
        if (!$pics) {
            return $this->error(0, '暂无数据');
        }
        return $this->success(200,'',$pics);
    }
}