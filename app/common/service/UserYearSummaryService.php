<?php
declare (strict_types=1);

namespace app\common\service;

use app\common\model\GoodsCatModel;
use app\common\model\GoodsModel;
use app\common\model\OrderGoodsModel;
use app\common\model\OrderModel;
use app\common\model\UserFourYearSummaryModel;
use app\common\model\UserModel;

/**
 * 用户数据总结
 * Class UserYearSummaryService
 * @package app\common\service
 * @author liyongsheng
 * @date 2020/12/3 14:18
 */
class UserYearSummaryService extends ComService
{

    /**
     * 数据获取
     * @param int $userId
     * @return array
     * @author liyongsheng
     * @date 2020/12/18 12:01
     */
    public function getInfo(int $userId)
    {
        $res = UserFourYearSummaryModel::getInfo($userId);
        if ($res) {
            $res = $res[0];
            $res['rank'] = UserFourYearSummaryModel::getRank($userId);
        } else {
            self::UserDeal($userId, time());
            $res = UserFourYearSummaryModel::getInfo($userId);

            $res = $res[0];
            $res['rank'] = UserFourYearSummaryModel::getRank($userId);
        }
        return $this->success(200, '获取成功', $res);
    }

    /**
     *  用户数据处理
     * @param int $userId
     * @param int $endTime
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author liyongsheng
     * @date 2020/12/3 15:12
     */
    public static function userDeal(int $userId, int $endTime = 0)
    {
        $data = [];
        // todo 用户id
        $data['user_id'] = $userId;
        // todo 用户基础信息
        $userInfo = (new UserModel())->getOneUser($userId);
        // todo 注册时间
        $data['reg_time'] = $userInfo['reg_time'];
        // todo 总下单次数
        $data['order_count'] = (new OrderModel())->getCount($userId, $endTime);
        // todo 总借阅本数
        $data['book_count'] = (new OrderGoodsModel())->getUserBookNum($userId);
        // todo 总节约XX买书钱
        $data['save_money'] = GoodsModel::userSaveMoney($userId);
        // todo 借阅图书的类别，准备使用雷达图来展示
        $goodIds = (new OrderGoodsModel())->getUserBorrowGoods($userId);
        $goodIds = array_unique(array_column($goodIds, 'goods_id'));

        // todo 自然科学==>'百科科普','交通机械','身体奥秘','自然生态','环境保护','宇宙天文','物理化学','恐龙百科','认知启蒙','宇宙天文','航空航天','卫生健康','科学实验','科技发明','数学天地','人文地理','少儿科普','科普启蒙','幼儿认知'
        $naturalScienceCatIds = [29, 38, 53, 62, 69, 75, 218, 427, 428, 724, 725, 726, 728, 729, 730, 731, 733, 734, 735, 736, 739, 741, 742, 750, 751, 752, 753, 755, 756, 757, 758, 761, 783, 784, 785, 786, 787, 788, 789, 790, 793, 795, 796, 797, 798, 822, 823, 824, 825, 842];
        $data['natural_science_num'] = 0;

        // todo 社会科学==>'宝宝安全','生活百科','安全教育','军事兵器','经济财商','财商启蒙','计算机与互联网','历史文明','认知启蒙','科技发明','卫生健康','少儿科普','科普启蒙','幼儿认知'
        $socialSciencesCatIds = [29, 36, 38, 45, 53, 69, 119, 218, 727, 732, 736, 740, 754, 758, 790, 794, 798, 799, 801, 821, 825, 826, 848, 850, 851, 864];
        $data['social_sciences_num'] = 0;

        // todo 人文艺术==>'创意想象','中华文化','国学启蒙','艺术体育','人文地理','历史文明','视听感知','五感刺激','趣味益智','传统经典'
        $humanitiesAndArtCatIds = [32, 39, 42, 116, 125, 427, 428, 432, 436, 738, 740, 741, 746, 749, 760, 768, 771, 792, 794, 795, 800, 805, 821, 822, 827, 828, 847, 853];
        $data['humanities_and_art_num'] = 0;

        // todo 文学==>'图画故事','语言开发','睡前故事','儿歌童谣','儿童故事','卡通动画','名人故事','国学启蒙','童话故事','神话寓言','传统经典','儿童名著','图画故事','儿童绘本','人物传记','动物系列','科学科幻','奇幻魔幻','探险冒险','校园日常','侦探小说','革命军事','生活成长','诗歌散文','国学经典','杂文随笔','青春文学','语文园地'
        $literatureCatIds = [30, 34, 35, 41, 42, 44, 46, 47, 48, 51, 57, 65, 67, 76, 125, 162, 166, 170, 200, 201, 203, 217, 219, 431, 591, 743, 744, 745, 746, 747, 748, 749, 767, 768, 769, 770, 771, 772, 774, 775, 776, 777, 778, 779, 780, 781, 803, 804, 805, 806, 808, 809, 810, 811, 812, 813, 814, 815, 817, 818, 819, 832, 833, 863];
        $data['literature_num'] = 0;

        // todo 品格素养==>'情感社交','品格培养','生活习惯','视听感知','益智启蒙','五感刺激','思维训练','趣味益智','幼小衔接'
        $characterAccomplishmentCatIds = [31, 32, 33, 39, 43, 112, 433, 434, 437, 444, 469, 472, 828, 829, 834, 835, 852, 853, 854, 855, 856, 857];
        $data['character_accomplishment_num'] = 0;

        foreach ($goodIds as $item) {
            $temp = (new GoodsCatModel())->getGoodCatByGoodId($item);
            if ($temp) {
                foreach ($temp as $gcat) {
                    if (in_array($gcat['cat_id'], $naturalScienceCatIds)) {
                        $data['natural_science_num']++;
                    }
                    if (in_array($gcat['cat_id'], $socialSciencesCatIds)) {
                        $data['social_sciences_num']++;
                    }
                    if (in_array($gcat['cat_id'], $humanitiesAndArtCatIds)) {
                        $data['humanities_and_art_num']++;
                    }
                    if (in_array($gcat['cat_id'], $literatureCatIds)) {
                        $data['literature_num']++;
                    }
                    if (in_array($gcat['cat_id'], $characterAccomplishmentCatIds)) {
                        $data['character_accomplishment_num']++;
                    }
                }
            }
        }
        $data['book_count_rank'] = 0;
        $data['create_time'] = time();
        $data['update_time'] = time();

        try {
            UserFourYearSummaryModel::where('user_id', $userId)->delete();
            UserFourYearSummaryModel::addData($data);
        } catch (\Exception $e) {
            dump(json_encode($data, JSON_UNESCAPED_UNICODE));
            dump($e);
        }
    }


}
