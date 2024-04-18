<?php


namespace app\common\Tools;


use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

/**
 * 阿里云短信通知
 * Class ALiYunSendSms
 * @package app\common\Tools
 * @author liyongsheng
 * @date 2020/12/22 14:08
 */
class ALiYunSendSms
{
    /**
     * ALiYunSendSms constructor.
     * @throws ClientException
     */
    public function __construct()
    {
        AlibabaCloud::accessKeyClient(config('ali_config.send_msg.access_key_id'), config('ali_config.send_msg.access_secret'))
            ->regionId('cn-hangzhou')// replace regionId as you need
            ->asDefaultClient();
    }

    /**
     * 真实发送的前一部，做异常过滤处理
     * @param string $mobiles 手机号,多个用,号分割
     * @param string $templateCode 使用的短信模板id
     * @param array $option 短信模板中要传的参数
     * @return array
     * @author liyongsheng
     * @date 2020/12/22 14:27
     */
    public function sendSms(string $mobiles = '', string $templateCode = '', array $option = []): array
    {
        $res = [
            'code' => 200,
            'msg' => ''
        ];

        $result = $this->sendSmsDeal($mobiles, $templateCode, $option);
        if ($result['Code'] == 'OK') {
            return $res;
        } else {
            if ($result['Code'] == 'isv.MOBILE_NUMBER_ILLEGAL') {
                $str = '手机号码格式错误';
            } else if ($result['Code'] == 'isv.OUT_OF_SERVICE') {
                $str = '业务停机，请联系客服';
            } else {
                $str = '系统异常，发送失败';
            }
            $res['code'] = 0;
            $res['msg'] = $str;
            return $res;
        }
    }

    /**
     * 验证码等短信通知，真实发送场景
     * @param string $mobiles 手机号,多个用,号分割
     * @param string $templateCode 使用的短信模板id
     * @param array $option 短信模板中要传的参数
     * @return array|string
     * @author liyongsheng
     * @date 2020/12/22 14:27
     */
    private function sendSmsDeal(string $mobiles = '', string $templateCode, array $option)
    {
        try {
            $result = AlibabaCloud::rpc()
                ->product('Dysmsapi')
                // ->scheme('https') // https | http
                ->version('2017-05-25')
                ->action('SendSms')
                ->method('POST')
                ->host('dysmsapi.aliyuncs.com')
                ->options([
                    'query' => [
                        'RegionId' => "default",
                        'PhoneNumbers' => $mobiles,
                        'SignName' => config('ali_config.send_msg.sign_name'),
                        'TemplateCode' => $templateCode,
                        'TemplateParam' => $option ? json_encode($option) : '',
                    ],
                ])
                ->request();
            return $result->toArray();
        } catch (ClientException $e) {
            return $e->getErrorMessage() . PHP_EOL;
        } catch (ServerException $e) {
            return $e->getErrorMessage() . PHP_EOL;
        }
    }
}