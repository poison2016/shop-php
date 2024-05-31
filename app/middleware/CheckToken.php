<?php
declare (strict_types=1);

namespace app\middleware;

use app\common\model\UserModel;
use think\facade\Log;
use think\Response;

class CheckToken
{
    /**
     * 处理请求
     *
     * @param \think\Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        //echo "中间件开始执行==><br/>";
        $userToken = $request->header('Api-Token', '');
        $token = $request->header('api-token', '');
        if ($token == '' && $userToken == '') {
            $data = [
                'code' => 403,
                'msg' => 'token不能为空'
            ];
            return Response::create($data, 'json');
        }
        if ($userToken != '') {
            $token = $userToken;
        }
        //trace('token->'.$token,'error');
        // token正确性判断
        $tokens = \Jwt::jwtDecode($token);
        if ($tokens['status'] == 0) {
            $data = [
                'code' => 403,
                'msg' => $tokens['msg']
            ];
            return Response::create($data, 'json');
        }

        // 获取用户userId
        $userId = $tokens['data'] && $tokens['data']['data'] && $tokens['data']['data']->user_id ? $tokens['data']['data']->user_id : 0;
        //$session_key = $tokens['data'] && $tokens['data']['data'] && $tokens['data']['data']->session_key ? $tokens['data']['data']->session_key : '';
        if ($userId == 0) {
            $data = [
                'code' => 403,
                'msg' => 'userId解析异常'
            ];
            return Response::create($data, 'json');
        }


        // 注入参数
        $request->comUserId = $userId;

        return $next($request);
    }

}
