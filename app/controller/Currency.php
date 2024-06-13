<?php

namespace app\controller;

use app\BaseController;
use app\common\service\CurrencyService;
use app\Request;
use app\validate\FilterValid;
use think\App;

class Currency extends BaseController
{
    protected CurrencyService $currencyService;

    public function __construct(App $app,CurrencyService $currencyService)
    {
        parent::__construct($app);
        $this->currencyService = $currencyService;
    }

    public function addCurrency(Request $request)
    {
        $params['user_id'] = $request->comUserId;
        $params['address_id'] = input('address_id',0);
        $params['number'] = input('number',0);
        $rule = [
            'user_id' => ['must', '', 'token不能为空'],
            'address_id' => ['must', '', '地址不能为空'],
            'number' => ['must', '', '数量不能为空'],
        ];
        FilterValid::filterData($params, $rule);
        return $this->requestData($this->currencyService->insertCurrency($params));
    }


}