<?php
declare (strict_types = 1);

namespace app\command;

use app\api\service\LogService;
use app\common\service\GoodsService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class SendPrice extends Command
{

    protected function configure()
    {
        // 指令配置
        $this->setName('send_price')
            ->setDescription('the sendprice command');
    }

    protected function execute(Input $input, Output $output)
    {
        $data = Db::name('t_goods_order')->alias('co')
            ->field('co.*,c.*,co.id order_ids,c.id co_ids,co.pay_amount pay_amounts,co.total_amount total_amounts')
            ->join('t_goods c', 'c.id = co.contract_id', 'LEFT')
            ->where(['co.status' => 0, 'co.deleted' => 0])->select();

        foreach ($data as $v){

            $res = Db::name('t_order_bonus')->where('order_id',$v['order_ids'])->where('is_send',0)->order('id','asc')->select();
            if(!$res){//关闭订单
                goto a;
            }
            $res = $res[0];
//            if($res['create_time'] > strtotime('2023-6-7 9:0:0')){//没有达到时间 下一个
            if($res['create_time'] > time()){//没有达到时间 下一个
                continue;
            }
            //将订单修改为已奖励
            Db::name('t_order_bonus')->where('id',$res['id'])->update(['is_send'=>1]);
            //将订单的钱 写入用户记录
            $userData = Db::name('tz_user')->where('user_id', $v['user_id'])->find();
            if(!$userData){
                var_dump('警告！！！该用户信息不存在 用户id:'.$v['user_id']);
                continue;
            }
            var_dump('处理订单号'.$res['order_id']);
            $insertUser['money'] = floatval($userData['money']) + floatval($res['money']);
            $insertUser['account'] = floatval($userData['account']) + floatval($res['money']);
            $hhhh = $res['money'];
            if(floatval($res['money']) > floatval($v['total_amounts'])){//如果包含本金
                $hhhh -= $v['total_amounts'];
                $bbbbb = floatval($userData['position']) - floatval($v['total_amounts']);
                if($bbbbb < 0) $bbbbb = 0;
                $insertUser['position'] = $bbbbb;
                //$insertUser['money'] = $userData['money'] + $res['money']- $v['total_amounts'];
                $insertUser['account'] = floatval($userData['account']) + floatval($res['money']) - floatval($v['total_amounts']);
                var_dump('处理订单号'.$res['order_id'].'返回本金');

                //LogService::userMoneyLog($userData, $v['total_amounts'], 1, '资产包本金返回', '资产包本金返回', 4,$res['create_time']);
            }
            Db::name('tz_user')->where('user_id', $v['user_id'])->update($insertUser);
            //LogService::userMoneyLog($userData, $hhhh, 1, '资产包收益', '资产包收益', 4,$res['create_time']);
            //查询总数量 剩余一条 关闭订单
            $resCount = Db::name('t_order_bonus')->where('order_id',$v['order_ids'])->where('is_send',0)->count();
            if($resCount == 0){
                a:
                Db::name('t_contract_order')->where('id',$v['order_ids'])->update(['status'=>1]);
            }
        }
        var_dump('执行完成');
    }
}
