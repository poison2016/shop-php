<?php

use Phinx\Db\Adapter\MysqlAdapter;
use think\migration\Migrator;
use think\migration\db\Column;

class CreateTableUser extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function up()
    {
        if (!$this->hasTable('shop_goods')) {
            $table = $this->table('shop_goods', ['engine' => 'InnoDB'])
                ->setId('id')
                ->setPrimaryKey('id')
                ->setComment('商品表');
            $table->addColumn('name', 'string', ['limit' => 500, 'null' => false, 'comment' => '商品名称'])
                ->addColumn('name_en', 'string', ['limit' =>  500, 'null' => false,  'comment' => '商品名称英文版'])
                ->addColumn('is_show', 'integer', ['limit' =>  MysqlAdapter::INT_TINY, 'null' => false,'default' => '0',  'comment' => '是否上架商品'])
                ->addColumn('low_number', 'integer', ['limit' =>  10, 'null' => false,'default' => '0',  'comment' => '商品剩余数量'])
                ->addColumn('sum_number', 'integer', ['limit' =>  10, 'null' => false,'default' => '0',  'comment' => '商品总数量'])
                ->addColumn('pay_money', 'decimal', ['precision' => 20, 'scale' => 2, 'null' => false,'default' => 0, 'comment' => '支付金额'])
                ->addColumn('is_day', 'integer', ['limit' =>  MysqlAdapter::INT_TINY, 'null' => false,'default' => '0',  'comment' => '是否按天 如果不按天就按小时分配'])
                ->addColumn('yield', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false,'default' => 0, 'comment' => '收益率'])
                ->addColumn('content', 'string', ['limit' => 10000, 'null' => false, 'default' => '', 'comment' => '详情'])
                ->addColumn('content_en', 'string', ['limit' => 10000, 'null' => false, 'default' => '', 'comment' => '详情英文版'])
                ->addColumn('create_time', 'integer', ['limit' => 10, 'null' => false, 'default' => '0', 'comment' => '创建时间'])
                ->addColumn('update_time', 'integer', ['limit' => 10, 'null' => false, 'default' => '0', 'comment' => '更新时间'])
                ->create();
        }
    }

    public function down()
    {
        if ($this->hasTable('shop_goods')) {
            $this->dropTable('shop_goods');
        }
    }
}
