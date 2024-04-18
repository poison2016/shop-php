<?php


namespace app\common\service;


use app\common\model\GoodsCatModel;
use app\common\model\RegionModel;
use app\common\model\UserCardsModel;
use app\common\model\UserModel;
use app\common\model\UsersLogModel;
use function Symfony\Component\String\b;

class BooksService extends ComService
{
    /**查询用户信息+本数
     * @param int $userId 用户id
     * @return array
     * @author Poison
     * @date 2021/4/13 10:36 上午
     */
    public function userInfo(int $userId): array
    {
        //查询用户信息
        $userInfo = UserModel::getUserInfoByUserId($userId, 'child_age,child_school,area');
        if (!$userInfo) {
            $userInfo['child_age'] = '';
            $userInfo['child_school'] = '';
            $userInfo['area'] = '';
        }
        $data['user_info'] = $userInfo;
        $age = 0;
        if (isset($userInfo['child_age'])) {
            $age = (int)$userInfo['child_age'];
        }
        $data['book_number'] = self::getBookNumber($age, 1);
        $data['area'] = RegionModel::getRegions();//获取区域信息
        return self::success(200, '获取成功', $data);

    }

    /**修改用户资料
     * @param array $param
     * @return array
     * @author Poison
     * @date 2021/4/13 10:56 上午
     */
    public function userUpdate(array $param): array
    {
        $resUser = (new UserModel())->updateUserInfo((int)$param['user_id'], ['child_age' => (int)$param['child_age'], 'child_school' => $param['child_school'], 'area' => $param['area'], 'update_time' => time()]);
        if (!$resUser) {
            return self::error(100, '修改失败');
        }
        $userName = (new UserModel())->getOneUser($param['user_id'], 1, 'user_name');
        //写入用户日志
        UsersLogModel::addUsersLog([
            'user_id' => $param['user_id'],
            'act_note' => '用户通过 精选书单-》修改资料',
            'add_time' => time(),
            'act_type' => 1,
            'user_name' => $userName
        ]);
        $age = (int)$param['child_age'];
        return successArray(['book_number' => self::getBookNumber($age, 1)]);
    }

    /**获取信息
     * @param int $userId
     * @return array
     * @author Poison
     * @date 2021/4/13 2:51 下午
     */
    public function bookLook(int $userId): array
    {
        //获取孩子的年龄
        $age = (new UserModel())->getOneUser($userId, 1, 'child_age');
        $age = (int)$age;
        //获取用户是否是年卡用户
        $userCard = UserCardsModel::getOneCards($userId);
        $list = config('self_config.book_cat_list.new');
        if ($userCard) {
            $list = config('self_config.book_cat_list.low');
        }
        $catId = self::getAge($age, $list);
        $resGoodsData = (new GoodsCatModel())->selectGoodsData($catId);
        //打散数据 截取前10个
        shuffle($resGoodsData);
        $resGoodsData = array_slice($resGoodsData, 0, 10);
        foreach ($resGoodsData as &$v){
            $v['original_img'] = $this->getGoodsImg($v['original_img']);
        }
        //查询年龄对应的书单
        $data['list'] = $resGoodsData;
        $priceArray = array_values(array_column($resGoodsData, 'price'));
        //计算书籍价格
        $data['price'] = sprintf("%.2f", array_sum($priceArray));
        $data['book_count'] = self::getBookNumber($age, 2);
        $data['save_money'] = sprintf("%.2f", $data['price'] * $data['book_count']);
        return successArray($data);
    }

    /**分装获取读书本数
     * @param int $age
     * @param int $type
     * @return int
     * @author Poison
     * @date 2021/4/13 4:35 下午
     */
    public function getBookNumber(int $age = 0, $type = 1)
    {
        switch ($age) {
            case 0:
            case 1:
            case 2:
                $bookNumber = $type == 1 ? 40 : 10;
                break;
            case 3:
            case 4:
            case 5:
                $bookNumber = $type == 1 ? 120 : 36;
                break;
            case 6:
            case 7:
            case 8:
            case 9:
            case 10:
            case 11:
            case 12:
                $bookNumber = $type == 1 ? 96 : 48;
                break;
            default:
                $bookNumber = $type == 1 ? 30 : 25;
                break;
        }
        return $bookNumber;
    }


    /**分装获取值
     * @param $age
     * @param $list
     * @return mixed
     * @author Poison
     * @date 2021/4/13 4:12 下午
     */
    protected function getAge($age, $list)
    {
        switch ($age) {
            case 1:
            case 2:
            case 3:
            case 4:
            case 5:
            case 6:
                $index = 0;
                break;
            case 7:
                $index = 1;
                break;
            case 8:
                $index = 2;
                break;
            case 9:
                $index = 3;
                break;
            case 10:
                $index = 4;
                break;
            case 11:
                $index = 5;
                break;
            case 12:
                $index = 6;
                break;
            default:
                $index = 7;
        }
        return $list[$index];
    }

}