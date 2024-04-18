<?php


namespace app\common\Tools;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

/**
 * 内容安全-检测相关
 * Class ALiYunGreen
 * @package app\common\Tools
 * @author liyongsheng
 * @date 2021/4/14 10:12
 */
class ALiYunGreen
{
    /**
     * ALiYunSendSms constructor.
     * @throws ClientException
     */
    public function __construct()
    {
        AlibabaCloud::accessKeyClient(config('ali_config.access_key_id'), config('ali_config.access_secret'))
            ->regionId('cn-hangzhou')// replace regionId as you need
            ->asDefaultClient();
    }


    public function scanCheck(array $tasks): array
    {
        //文字鉴定
        $type = 'text';
        $scenes = 'antispam';
        $checkMessage = '';
        $checkErrMessage = '';
        $checkCode = 0;
        $result = $this->textScan($tasks, $type, $scenes);
        if ($result['code'] == 200 && $result['data'][0]['code'] == 200) {
            $successData = $result['data'][0];
            $taskId = $successData['taskId'];
            //开始取出值 进行判断
            if ($successData['results'][0]['suggestion'] == 'block') {
                $checkCode = 2;
            } elseif ($successData['results'][0]['suggestion'] == 'review') {
                $checkCode = 1;
            } else {
                $checkCode = 1;
            }
            if ($checkCode == 2) {
                $checkMessage = isset($successData['filteredContent']) ? $successData['filteredContent'] : '';
                $checkErrMessage = self::checkText($successData['results'][0]['label']);
            }
            return successArray(['task_id' => $taskId, 'check_message' => $checkMessage, 'check_code' => $checkCode, 'check_err_message' => $checkErrMessage]);
        } else {
            return errorArray('网络异常，请求失败', 500);
        }
    }

    public function imageCheck(array $tasks)
    {
        // 图片鉴别
        $type = 'image';
        $scenes = ['porn', 'terrorism', 'ad'];
        $checkMessage = '';
        $checkErrMessage = '';
        $checkCode = 0;
        $result = $this->textScan($tasks, $type, $scenes);
        if ($result['code'] == 200 && $result['data'][0]['code'] == 200) {
            $successData = $result['data'][0];
            $taskId = $successData['taskId'];
            //遍历取值
            foreach ($successData['results'] as $k => $v) {
                if ($v['suggestion'] == 'block') {
                    $checkCode = 2;
                } elseif ($v['suggestion'] == 'review') {
                    if ($checkCode == 0) {
                        $checkCode = 1;
                    }
                } else {
                    if ($checkCode == 0) {
                        $checkCode = 1;
                    }
                }
                if ($checkCode == 2) {
                    $checkMessage = $successData['url'];
                    $checkErrMessage = self::checkText($v['label']);
                    break;
                }
            }
            return successArray(['task_id' => $taskId, 'check_message' => $checkMessage, 'check_code' => $checkCode, 'check_err_message' => $checkErrMessage ?? '']);
        } else {
            return errorArray('网络异常，请求失败', 500);
        }
    }

    /**
     * 文本图片检测
     * @param array $tasks
     * @param string $type
     * @param  $scenes
     * @return array|string
     * @author liyongsheng
     * @date 2021/4/14 11:52
     */
    private function textScan(array $tasks, $type = 'text', $scenes)
    {
        $body = [
            'scenes' => $scenes,
            'tasks' => $tasks
        ];

        try {
            $result = AlibabaCloud::roa()
                ->product('Green')
                // ->scheme('https') // https | http
                ->version('2018-05-09')
                ->pathPattern('/green/' . $type . '/scan')
                ->method('POST')
                ->options([
                    'query' => [],
                ])
                ->body(json_encode($body))
                ->request();
            return $result->toArray();
        } catch (ClientException $e) {
            return $e->getErrorMessage() . PHP_EOL;
        } catch (ServerException $e) {
            return $e->getErrorMessage() . PHP_EOL;
        }
    }

    /**返回详细错误
     * @param $code
     * @return mixed
     * @author Poison
     * @date 2021/4/16 3:26 下午
     */
    protected function checkText($code)
    {
        $data = config('self_config.text_check_error_message');
        return $data[$code];
    }
}