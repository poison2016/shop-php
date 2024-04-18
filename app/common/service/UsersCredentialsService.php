<?php


namespace app\common\service;


use app\common\model\UsersCredentialsInfoModel;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

class UsersCredentialsService extends ComService
{

    /**
     * 添加用户证件信息
     * @param array $params
     * @return array
     * @author yangliang
     * @date 2021/4/15 16:14
     */
    public function addUserCredent(array $params):array
    {
        $result = self::verifyUser($params['name'], $params['number']);
        if($result['code'] != 200){
            return $this->error(100, $result['message']);
        }

        if($params['type'] == 1){
            $params['gender'] = self::getSex($params['number']);
        }

        $params['create_time'] = time();
        $params['update_time'] = time();
        $res = UsersCredentialsInfoModel::create($params);
        if(!$res){
            return $this->error(100, '用户信息添加失败');
        }

        return $this->success(200, '用户信息添加成功');
    }


    /**
     * 获取用户证件信息列表
     * @param int $user_id  用户ID
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/15 16:41
     */
    public function getList(int $user_id):array
    {
        return $this->success(200, '获取成功', UsersCredentialsInfoModel::getListByUserId($user_id));
    }


    /**
     * 修改证件信息
     * @param array $params
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author yangliang
     * @date 2021/4/15 17:14
     */
    public function changeUserCredent(array $params):array
    {
        $info = UsersCredentialsInfoModel::getById($params['id']);
        if(empty($info)){
            return $this->error(100, '证件信息不存在');
        }

        if($info['user_id'] != $params['user_id']){
            return $this->error(100, '您没有权限修改');
        }

        $result = self::verifyUser($params['name'], $params['number']);
        if($result['code'] != 200){
            return $this->error(100, $result['message']);
        }

        if($params['type'] == 1){
            $params['gender'] = self::getSex($params['number']);
        }

        $params['update_time'] = time();
        $res = UsersCredentialsInfoModel::where('id', $params['id'])->update($params);
        if(!$res){
            return $this->error(100, '证件信息更新失败');
        }

        return $this->success(200, '证件信息更新成功');
    }


    /**
     * 验证用户实名认证
     * @param string $real_name  用户姓名
     * @param string $id_card  身份证号码
     * @return array
     * @author yangliang
     * @date 2021/4/15 16:25
     */
    private function verifyUser(string $real_name, string $id_card):array
    {
        //实名认证
        $appHost = (env('server_env') === 'dev') ? config('self_config.host').'/' : config('self_config.host_api').'/';
        $url = $appHost . config('self_config.verify_id');
        $result = curl_http($url, false, ['real_name' => $real_name, 'id_card' => $id_card]);
        if (!$result) {
            return self::error(100, '网络异常，请稍后再试');
        }
        if($result['code'] != 200){
            return $this->error(100, $result['message']);
        }

        return $this->success(200, 'success');
    }


    /**
     * 根据身份证号判断性别
     * @param string $idCard
     * @return int
     * @author: yangliang
     * @date: 2021/4/28 13:51
     */
    private function getSex(string $idCard)
    {
        $position = (strlen($idCard) == 15 ? -1 : -2);

        if (substr($idCard, $position, 1) % 2 == 0) {
            return 2;  //女
        }

        return 1;  //男
    }
}