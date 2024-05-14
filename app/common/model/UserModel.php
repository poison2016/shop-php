<?php

namespace app\common\model;

use think\Model;

class UserModel extends Model
{
    protected $name = 'tz_user';

    public function getUserInfo(int $userId){
        return $this->where('id',$userId)->find();
    }

}