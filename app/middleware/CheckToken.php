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
        $userToken = $request->header('user-token', '');
        $token = $request->header('token', '');
        if ($token == '' && $userToken == '') {
            $data = [
                'code' => 101,
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
                'code' => 101,
                'msg' => $tokens['msg']
            ];
            return Response::create($data, 'json');
        }

        // 获取用户userId
        $userId = $tokens['data'] && $tokens['data']['data'] && $tokens['data']['data']->user_id ? $tokens['data']['data']->user_id : 0;
        $session_key = $tokens['data'] && $tokens['data']['data'] && $tokens['data']['data']->session_key ? $tokens['data']['data']->session_key : '';
        if ($userId == 0) {
            $data = [
                'code' => 101,
                'msg' => 'userId解析异常'
            ];
            return Response::create($data, 'json');
        }


        // 验证是否切换手机号码
        $smartOpenid = $tokens['data'] && $tokens['data']['data'] && isset($tokens['data']['data']->smart_openid) ? $tokens['data']['data']->smart_openid : '';
        if (isset($smartOpenid) && $smartOpenid) {
            $newSmartOpenId = (new UserModel())->getOneUser($userId);
            if (!empty($newSmartOpenId) && isset($newSmartOpenId['smart_openid']) && $newSmartOpenId['smart_openid'] != $smartOpenid) {
                return Response::create(['code' => 101, 'msg' => '此微信已切换手机号码'], 'json');
            }
        }

        // 注入参数
        $request->comUserId = $userId;
        $request->session_key = $session_key;
        return $next($request);
    }

}
