<?php

namespace app\controller;

use app\BaseController;
use app\Request;
use app\validate\FilterValid;

class Index extends BaseController
{


    public function hello(Request $request)
    {
        $params['user_id'] = $request->comUserId;
        $params['parent_id'] = input('parent_id','');
        $rule = [
            'user_id' => ['must', '', 'token不能为空'],
            'parent_id' => ['must', '', '上级ID不能为空'],
        ];
        FilterValid::filterData($params, $rule);
    }
}
