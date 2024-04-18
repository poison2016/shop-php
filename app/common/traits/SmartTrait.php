<?php


namespace app\common\traits;


use think\facade\Log;

trait SmartTrait
{
    use CacheTrait, CurlTrait;

    /**
     * 发送小程序订阅消息
     * @param $openid
     * @param $templateId
     * @param $data
     * @param $type
     * @return bool|mixed|string
     */
    public function send($openid, $templateId, $data, $type = 0)
    {
        if (empty($openid) || empty($templateId) || empty($data)) {
            return false;
        }
        if ($type == 0) {//如果为0 发送小程序
            // 获取smart access token
            $smartAccessToken = $this->getSmartAccessToken();
            if (!$smartAccessToken) {
                return false;
            }
            // 小程序订阅消息
            $template = [
                'access_token' => $smartAccessToken,
                'touser' => $openid,
                'template_id' => $templateId,
                'page' => isset($data['page']) ? $data['page'] : '',// 点击模板卡片后的跳转页面，仅限本小程序内的页面。支持带参数,（示例index?foo=bar）。该字段不填则模板无跳转。
                'data' => $data['data'],// 模板内容，格式形如 { "key1": { "value": any }, "key2": { "value": any } }
                'miniprogram_state' => config('app.server_env') == 'dev' ? 'trial' : 'formal'// 跳转小程序类型：developer为开发版；trial为体验版；formal为正式版；默认为正式版
            ];
            $jsonTemplate = json_encode($template);
            Log::info('------' . json_encode($template));
            // 获取发送小程序订阅消息接口
            $url = sprintf(config('wx_config.api.smart.smart_template'), $smartAccessToken);
        } else {//如果为1 发送公众号消息
            // 公众号模板消息
            $template = [
                'touser' => $openid,
                'template_id' => $templateId,
                'url' => isset($data['url']) ? $data['url'] : '',
                'miniprogram' => isset($data['miniprogram']) ? $data['miniprogram'] : '',
                'data' => $data['data'],
                'topcolor' => '#FF0000',
            ];
            $jsonTemplate = json_encode($template);
            // 获取发送公众号订阅消息接口
            $url = sprintf(config('wx_config.api.send_template'), $this->getAccessToken());
        }
        // 发送小程序订阅信息
        $res = $this->curl_request($url, urldecode($jsonTemplate), 'POST');
        $res = json_decode($res, true);
        if (isset($res['errcode']) && $res['errcode'] > 0) {
            // errcode=40001:access_token无效,刷新access_token
            if ($res['errcode'] == 40001 || $res['errcode'] == 41001) {
                if ($type == 0) {
                    $accessToken = $this->getSmartAccessToken(1);
                } else {
                    $accessToken = $this->getAccessToken(1);
                }
                $template['access_token'] = $accessToken;
                $jsonTemplate = json_encode($template);
                $res = $this->curl_request($url, urldecode($jsonTemplate), 'POST');
                $res = json_decode($res, true);
                if (isset($res['errcode'])) {
                    Log::error(json_encode($res, JSON_UNESCAPED_UNICODE));
                    return false;
                }

                return $res;
            }
            Log::error(json_encode($res, JSON_UNESCAPED_UNICODE));
            return false;
        }
        return $res;
    }

    /**
     * 获取小程序access token
     * @param bool $refresh
     * @return bool|mixed|string
     */
    public function getSmartAccessToken($refresh = false)
    {
        $smartAccessToken = $this->getCache('smart_access_token');
        if (!$refresh && $smartAccessToken) {
            return $smartAccessToken;
        }
        // 获取access token微信接口
        $tokenUrl = sprintf(config('wx_config.api.get_access_token'), config('wx_config.base.smart.appid'), config('wx_config.base.smart.appsecret'));
        $result = $this->curl_request($tokenUrl);
        $result = json_decode($result, true);
        if (isset($result['access_token'])) {
            $accessToken = $result['access_token'];
            $this->setCache('smart_access_token', $accessToken, 6000);
            return $accessToken;
        }
        return '';
    }

    /**获取公众号access_token
     * @param bool $refresh
     * @return bool|mixed|string
     */
    private function getAccessToken($refresh = false)
    {
        $access_token = $this->getCache('access_token');
        if (!$refresh && $access_token) {
            return $access_token;
        }
        $tokenUrl = sprintf(config('wx_config.api.get_access_token'), config('wx_config.base.appid'), config('wx_config.base.appsecret'));
        $result = $this->curl_request($tokenUrl);
        $result = json_decode($result, true);
        if (isset($result['access_token'])) {
            $access_token = $result['access_token'];
            $this->setCache('access_token', $access_token, 6000);
            return $result['access_token'];
        }
        return '';
    }

    /**
     * 获取小程序scheme码，适用于短信、邮件、外部网页等拉起小程序的业务场景。
     * 通过该接口，可以选择生成到期失效和永久有效的小程序码，目前仅针对国内非个人主体的小程序开放，详见获取URL scheme码。
     * @param string $path
     * @param string $query
     * @param bool $isExpire
     * @param int $expireTime
     * @return bool|string
     * @author liyongsheng
     * @date 2021/1/26 17:04
     */
    private function getUrlSchemeGenerate($path = '', $query = '', $isExpire = true, $expireTime = 0)
    {
        $smartAccessToken = $this->getSmartAccessToken(true);
        if (!$smartAccessToken) {
            return 'smartAccessToken不存在......';
        }
        // 小程序订阅消息
        $option = [
            'jump_wxa' => [ // 整体非必填	跳转到的目标小程序信息。
                'path' => $path, // 必填 通过scheme码进入的小程序页面路径，必须是已经发布的小程序存在的页面，不可携带query。path为空时会跳转小程序主页。
                'query' => $query // 必填 通过scheme码进入小程序时的query，最大128个字符，只支持数字，大小写英文以及部分特殊字符：!#$&'()*+,/:;=?@-._~
            ],
            'is_expire' => $isExpire, // 生成的scheme码类型，到期失效：true，永久有效：false。
            'expire_time' => $expireTime,// 点到期失效的scheme码的失效时间，为Unix时间戳。生成的到期失效scheme码在该时间前有效。最长有效期为1年。生成到期失效的scheme时必填。
        ];

        // 获取发送小程序订阅消息接口
        $url = sprintf(config('wx_config.api.smart.scheme_generate'), $smartAccessToken);

        $result = $this->curl_request($url, urldecode(json_encode($option)), 'POST');
        $result = json_decode($result, true);
        return $result;
    }

    /**
     * 获取临时凭证，js_ticket
     * @return mixed
     * @author liyongsheng
     * @date 2021/1/27 13:51
     */
    private function getJsApiTicket()
    {
        $jsApiTicket = $this->getCache('js_ticket');
        if ($jsApiTicket) {
            return $jsApiTicket;
        }
        // 获取access_token
        $accessToken = self::getAccessToken();
        // 获取ticket
        $url = sprintf(config('wx_config.api.ticket_url'), $accessToken);
        $json = file_get_contents($url);
        $result = json_decode($json, true);
        $ticket = $result['ticket'];
        // 更新缓存数据
        $expire = $this->getTtl('access_token');
        $this->setCache('js_ticket', $ticket, $expire);
        return $ticket;
    }


    /**
     * Create limit qrcode by weixin api
     * @param string $sceneStr
     * @return mixed|null
     * @author yangliang
     * @date 2021/2/18 16:59
     */
    public function createLimitQrcode(string $sceneStr)
    {
        $param = [
            "action_name" => "QR_LIMIT_STR_SCENE",
            "action_info" => [
                "scene" => [
                    "scene_str" => $sceneStr
                ]
            ]
        ];

        $url = sprintf(config('wx_config.api.qrcode_create_url'), $this->getAccessToken());
        $res = $this->curl_request($url, urldecode(json_encode($param)), 'POST');
        $res = json_decode($res, true);
        if (!empty($res['errcode']) && $res['errcode'] == 40001) {
            $url = sprintf(config('wx_config.api.qrcode_create_url'), $this->getAccessToken(true));
            $res = $this->curl_request($url, urldecode(json_encode($param)), 'POST');
            $res = json_decode($res, true);
        }

        if (!empty($res['errcode']) || empty($res['ticket'])) {
            return null;
        }

        return $res;
    }

    /**通过code获取openid（小程序登陆凭证校验）
     * @param $code
     * @return bool|string
     * @author Poison
     * @date 2021/2/20 9:07 上午
     */
    public function handleAuthWx($code)
    {
        $code2sessionUrl = sprintf(config('wx_config.api.smart.code_to_session'), config('wx_config.base.smart.appid'), config('wx_config.base.smart.appsecret'), $code);
        return $this->curl_request($code2sessionUrl);
    }

    /**
     * 微信公众号，根据用户opneId获取用户的基本信息
     * @param string $openId
     * @return bool|mixed|string
     * @author liyongsheng
     * @date 2021/3/11 14:10
     */
    public function getUserInfoByopenId(string $openId)
    {
        $accessToken = self::getAccessToken();
        $url = sprintf(config('wx_config.api.get_user_info_url'), $accessToken, $openId);
        $result = $this->curl_request($url);
        $result = json_decode($result, true);
        return $result;
    }
}
