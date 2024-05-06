<?php


namespace app\common\traits;


use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;

trait QrCodeTrait
{

    protected $param;

    public function __construct()
    {
        $this->param = [
            'setSize' => 300,//设置二维码尺寸
            'setWriterByName' => 'png',
            'setMargin' => 5,//设置二维码边界
            'setEncoding' => 'UTF-8',//设置编码
            'setErrorCorrectionLevel' => ErrorCorrectionLevel::HIGH(),
            'setLabelStatus' => false,//是否开启二维码标题
            'setLabel' => '这是二维码标题',//设置二维码标题
            'setLogoPathStatus' => false,//是否开启二维码中间logo
            'setLogoPath' => '',//设置二维码中间logo
            'setLogoSizeW' => 100,//设置二维码中间logo宽度
            'setLogoSizeH' => 100,//设置二维码中间logo高度
        ];
    }

    /**
     * 生成二维码，返回base64格式
     * @param string $content
     * @param array $param
     * @param int $userId
     * @return string
     */
    public function returnQrcodeImg(string $content = '这是二维码内容', array $param = [], int $userId = 0)
    {
        // Create a basic QR code创建一个基本的二维码
        $qrCode = new QrCode($content);
        //设置二维码尺寸
        $qrCode->setSize(isset($param['setSize']) ? $param['setSize'] : $this->param['setSize']);
        // Set advanced options设置高级选项
        $qrCode->setWriterByName(isset($param['setWriterByName']) ? $param['setWriterByName'] : $this->param['setWriterByName']);
        //设置二维码边界
        $qrCode->setMargin(isset($param['setMargin']) ? $param['setMargin'] : $this->param['setMargin']);
        //设置编码
        $qrCode->setEncoding(isset($param['setEncoding']) ? $param['setEncoding'] : $this->param['setEncoding']);
        //设置容错等级 等级越高识别度越高
        $qrCode->setErrorCorrectionLevel(isset($param['setErrorCorrectionLevel']) ? $param['setErrorCorrectionLevel'] : $this->param['setErrorCorrectionLevel']);
        //设置二维码颜色
        $qrCode->setForegroundColor(['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0]);
        //设置二维码背景颜色
        $qrCode->setBackgroundColor(['r' => 255, 'g' => 255, 'b' => 255, 'a' => 0]);
        //设置二维码标题 在图片下方显示文字
        //$qrCode->setLabel('Scan the code', 16, __DIR__.'/../assets/fonts/noto_sans.otf', LabelAlignment::CENTER());
        if (isset($param['setLabelStatus']) ? $param['setLabelStatus'] : $this->param['setLabelStatus']) {
            $qrCode->setLabel(isset($param['setLabel']) ? $param['setLabel'] : $this->param['setLabel'], 16, null, LabelAlignment::CENTER());
        }
        //设置二维码中间logo
        if (isset($param['setLogoPathStatus']) ? $param['setLogoPathStatus'] : $this->param['setLogoPathStatus']) {
            $logo = isset($param['setLogoPath']) ? $param['setLogoPath'] : $this->param['setLogoPath'];
            $qrCode->setLogoPath(__DIR__ . '/../../../public/static/images/' . $logo);
            //logo尺寸
            $setLogoSizeW = isset($param['setLogoSizeW']) ? $param['setLogoSizeW'] : $this->param['setLogoSizeW'];
            $setLogoSizeH = isset($param['setLogoSizeH']) ? $param['setLogoSizeH'] : $this->param['setLogoSizeH'];
            $qrCode->setLogoSize($setLogoSizeW, $setLogoSizeH);
        }
        //设置二维码内边距，true表示有内边距  false表示没有
        $qrCode->setRoundBlockSize(true);
        //启用内置的验证读取器(默认情况下禁用)
        $qrCode->setValidateResult(false);
        //排除xml声明
        $qrCode->setWriterOptions(['exclude_xml_declaration' => true]);

        // Directly output the QR code直接输出二维码
//        header('Content-Type: ' . $qrCode->getContentType());
//        return $qrCode->writeString();

        // 直接返回base64的类型
//        return $qrCode->writeDataUri();

        // 指定导出目录
        $path = public_path() . 'qrcode/';
        if (!is_dir($path)) {
            mkdir($path, 0777);
        }
        $filename = $userId . '_' . uniqid() . '_' . time() . '_' . rand(10000000, 99999999) . '.png';
        $qrCode->writeFile(public_path() . 'qrcode/' . $filename);
        return 'qrcode/' . $filename;
    }
}