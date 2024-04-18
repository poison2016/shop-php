<?php


namespace app\common\service;


use app\common\model\PickUpModel;
use app\common\model\UserAddressModel;
use app\common\model\UserModel;
use think\facade\Db;

class AddressService extends ComService
{
    /**获取所有地址信息+配送点信息
     * @param int $userId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author Poison
     * @date 2021/1/23 10:03 上午
     */
    public function getList(int $userId)
    {
        $data['address'] = UserAddressModel::getList($userId);
        //查询配送点信息
        $pickupId = (new UserModel())->getOneUser($userId, 1, 'pickup_id');
        $data['pick_up'] = (new PickUpModel())->getOneData($pickupId);
        return self::success(200, '获取成功', $data);
    }

    /**新增或修改地址
     * @param array $data
     * @return array
     * @author Poison
     * @date 2021/1/23 5:25 下午
     */
    public function upAddress(array $data): array
    {
        try {
            if (!isset($data['pickup_id']) || !$data['pickup_id']) {
                if(mb_strlen($data['province'], 'utf-8') > 16){
                    return $this->error(0, '省份不能超过16个字');
                }
                if(mb_strlen($data['city'], 'utf-8') > 16){
                    return $this->error(0, '城市不能超过16个字');
                }
                if(mb_strlen($data['district_name'], 'utf-8') > 16){
                    return $this->error(0, '区/县不能超过16个字');
                }
                if(mb_strlen($data['address'], 'utf-8') > 128){
                    return $this->error(0, '详细地址不能超过128个字');
                }

                if ($data['is_default'] == 1) {//选择默认地址的时候 将之前的地址修改为不默认
                    (new UserAddressModel())->update(['is_default' => 0], ['user_id' => $data['user_id']]);
                }
                $data['address'] = self::replaceChar($data['address'], 1);//过滤特殊字符
                $resAddress = (new UserAddressModel())->upOrCreateData($data);
                if (!$resAddress) {
                    return self::error(100, '修改/新增地址失败');
                }
                if ($data['type'] == 1) {
                    $data['address_id'] = $resAddress;
                }
            } else {
                $res = self::updatePickup((int)$data['user_id'], (int)$data['pickup_id']);
                if ($res['code']!=200) {
                    return self::error(100, $res['message']);
                }
            }
        } catch (\Exception $e) {
            self::sendDingError('修改或新增地址/修改配送点异常',$e);
            return self::error(100, '服务异常，请联系客服处理');
        }
        return self::success(200, '提交成功', isset($data) ? $data : []);
    }

    /**删除地址
     * @param array $param
     * @return array
     * @author Poison
     * @date 2021/1/23 5:30 下午
     */
    public function deleteAddress(array $param)
    {
        $userId = (int)$param['user_id'];
        $addressId = (int)$param['address_id'];
        $res = (new UserAddressModel())->where(['user_id' => $userId, 'address_id' => $addressId])->update(['is_delete' => 1, 'is_default' => 0]);
        if (!$res) {
            return self::error(100, '删除失败');
        }
        return self::success(200, '删除成功');
    }

    /**设置为默认地址
     * @param int $userId
     * @param int $addressId
     * @return array
     * @author Poison
     * @date 2021/1/25 10:05 上午
     */
    public function isDefault(int $userId, int $addressId, $lat, $lng)
    {
        //设置所有为为选择
        (new UserAddressModel())->update(['is_default' => 0], ['user_id' => $userId]);
        //设置传递活来的ID为选中状态
        $res = (new UserAddressModel())->update(['is_default' => 1, 'lat' => $lat, 'lng' => $lng], ['user_id' => $userId, 'address_id' => $addressId]);
        if (!$res) {
            return self::error();
        }
        return self::success();
    }

    /**配送点绑定
     * @param int $userId
     * @param int $pickupId
     * @return array
     * @author Poison
     * @date 2021/2/20 5:50 下午
     */
    public function updatePickup(int $userId, int $pickupId)
    {
        $userInfo = (new UserModel())->getOneUser($userId);
        $pickupModel = new PickUpModel();
        $lastPickupId = $userInfo['pickup_id'];
        if ($lastPickupId) {
            // 配送点有更新
            if ($lastPickupId != $pickupId) {
                $pickupName = '暂无';
                $longitude = '';
                $latitude = '';
                // 获取原配送点信息
                $lastPickup = $pickupModel->getOneData($lastPickupId);
                $lastPickupName = isset($lastPickup['pickup_name'])?$lastPickup['pickup_name']:'暂无';
                if ($pickupId) {
                    // 获取新配送点信息
                    $pickupInfo = $pickupModel->getOneData($pickupId);
                    $pickupName = $pickupInfo['pickup_name'];
                    $longitude = $pickupInfo['longitude'];
                    $latitude = $pickupInfo['latitude'];
                }
                $userData['pickup_id'] = $pickupId;
                $userData['pickup_time'] = time();
                $userData['pickup_status'] = 2;// 更改配送点
                // 更新配送点的时候，需要把用户的经纬度坐标，也更新了，取值为 配送点的经纬度坐标
                $userData['longitude'] = $longitude;
                $userData['latitude'] = $latitude;
                $resUserUpdate = (new UserModel())->update($userData, ['user_id' => $userId]);
                if (!$resUserUpdate) {
                    return self::error(100, '更新失败');
                }
                $actNote = '用户配送点由<<' . $lastPickupName . '>>更改为:' . $pickupName;
                LogService::userLog($userInfo['user_name'], $userId, $actNote, 0, 2);
            }
        } else {
            if ($pickupId) {
                $pickupInfo = $pickupModel->getOneData($pickupId);
                $pickupName = isset($pickupInfo['pickup_name'])?$pickupInfo['pickup_name']:'暂无';
                $userData['pickup_id'] = $pickupId;
                $userData['pickup_time'] = time();
                $userData['longitude'] = $pickupInfo['longitude'];
                $userData['latitude'] = $pickupInfo['latitude'];
                $lastChangePickupId = $userInfo['change_pickup_id'];
                if ($lastChangePickupId) {
                    $userData['pickup_status'] = 2;// 更改配送点
                    $actNote = '用户配送点由暂无更改为:' . $pickupName;
                } else {// 首次绑定配送点
                    $userData['change_pickup_id'] = $pickupId;
                    $userData['change_pickup_time'] = time();
                    $userData['pickup_status'] = 1;// 首次绑定配送点
                    $actNote = '用户首次绑定配送点:' . $pickupName;
                }
                $resUserUpdate = (new UserModel())->update($userData, ['user_id' => $userId]);
                if (!$resUserUpdate) {
                    return self::error(100, '更新失败');
                }
                LogService::userLog($userInfo['user_name'], $userId, $actNote, 0, 2);
            }
        }
        return self::success(200, '更新成功');
    }


}