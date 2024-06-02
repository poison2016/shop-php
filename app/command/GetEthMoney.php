<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class GetEthMoney extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('get_eth_money')
            ->setDescription('获取以太坊交易列表并充值');
    }

    protected function execute(Input $input, Output $output)
    {
        // 指令输出
        $output->writeln('getethmoney');
    }
}
