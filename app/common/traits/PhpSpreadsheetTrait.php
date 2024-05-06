<?php


namespace app\common\traits;


use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * phpExxel操作类
 * Class PhpSpreadsheet
 * @package app\common\traits
 */
trait PhpSpreadsheetTrait
{
    /**
     * 导出excel表
     * @param string $fileName excel表的表名
     * @param array $data 要导出excel表的数据，接受一个二维数组
     * @param array $head excel表的表头，接受一个一维数组
     * @param array $keys $data中对应表头的键的数组，接受一个一维数组
     * @param string $fileType
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function exportExcel(string $fileName = 'test', array $data = [], array $head = [], array $keys = [], string $fileType = 'Xlsx')
    {
        ini_set('memory_limit', '2048M');

        // 指定导出目录
        $path = runtime_path() . 'export/';
        if (!is_dir($path)) {
            mkdir($path, 0777);
        }

        $style_arr = [
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center'
            ]
        ];

        //复合表头标识
        $is_mutil_head = false;
        if(count($head) != count($head, 1)){
            $is_mutil_head = true;
        }
        $count = !$is_mutil_head ? count($head) : count($head[0]);  //计算表头数量

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $tmp = '';  //临时记录上一个单元格值
        $tmp_cell = '';  //临时记录上一个单元格标识
        if(!$is_mutil_head) {
            //单表头
            for ($i = 65; $i < $count + 65; $i++) {
                //数字转字母从65开始，循环设置表头：
                $sheet->setCellValue(strtoupper(chr($i)) . '1', $head[$i - 65]);
                $sheet->getStyle(strtoupper(chr($i)) . '1')->applyFromArray($style_arr);

            }
        }else {
            //复合表头
            for ($j = 1; $j <= count($head); $j++) {
                for ($i = 65; $i < $count + 65; $i++) {
                    //合并单元格（前一个单元格值与当前单元格值相同则进行合并）
                    if (!empty($tmp) && $tmp == $head[$j - 1][$i - 65]) {
                        $sheet->mergeCells($tmp_cell . ':' . strtoupper(chr($i)) . $j);
                    } else {
                        $tmp = $head[$j - 1][$i - 65];
                        $tmp_cell = strtoupper(chr($i)) . $j;
                    }
                    $sheet->setCellValue(strtoupper(chr($i)) . $j, $head[$j - 1][$i - 65]);
                    $sheet->getStyle(strtoupper(chr($i)) . $j)->applyFromArray($style_arr);
                }
            }
            unset($tmp, $tmp_cell);
        }

        // todo 开始从数据库提取信息插入Excel表中
        foreach ($data as $key => $item) {
            // 循环设置单元格：
            // $key+2,因为第一行是表头，所以写到表格时,从第二行开始写
            for ($i = 65; $i < $count + 65; $i++) {
                if(!$is_mutil_head) {
                    $sheet->setCellValue(strtoupper(chr($i)) . ($key + 2), $item[$keys[$i - 65]]);
                    $sheet->getStyle(strtoupper(chr($i)) . ($key + 2))->applyFromArray($style_arr);
                }else{
                    $sheet->setCellValue(strtoupper(chr($i)) . ($key + (count($head) + 1)), $item[$keys[$i - 65]]);
                    $sheet->getStyle(strtoupper(chr($i)) . ($key + (count($head) + 1)))->applyFromArray($style_arr);
                }
                // 固定列宽
                $spreadsheet->getActiveSheet()->getColumnDimension(strtoupper(chr($i)))->setWidth(20);
            }
        }

        //1.下载到服务器
        $writer = IOFactory::createWriter($spreadsheet, $fileType);
        $writer->save($fileName . '.' . $fileType);
    }



    public function zipDir($out_file, $files)
    {
        if(!empty($files)){
            $zip = new \ZipArchive();
            if($zip->open($out_file . '.zip', \ZipArchive::OVERWRITE | \ZipArchive::CREATE) == true){
                foreach($files as $k => $file){
                    $zip->addFile($file, basename($file));
                }

                $zip->close();
                foreach($files as $key => $file){
                    unlink($file);
                }

                return true;
            }
        }

        return false;
    }
}