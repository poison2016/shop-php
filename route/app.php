<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;


Route::group('index', function () {
    Route::any('hello', '');
})->prefix('index/');

//图片上传
Route::group('image', function () {
    Route::any('upload', 'upload');
})->prefix('image/');

Route::group('apis/goods', function () {
    Route::any('list','list');
    Route::any('info','info');

})->prefix('apis/goods/');

Route::group('apis/test', function () {
    Route::any('index','index');
})->prefix('apis/test/');
Route::group('admins/admin', function () {
    Route::any('getGoodsList','getGoodsList');
    Route::any('getGoodsInfo','getGoodsInfo');
    Route::any('insertGoods','insertGoods');
    Route::any('updateGoods','updateGoods');
    Route::any('delGoods','delGoods');
    Route::any('orderList','orderList');
    Route::any('orderInfo','orderInfo');
    Route::any('delOrder','delOrder');
    Route::any('coinList','coinList');
    Route::any('delCoin','delCoin');
    Route::any('uploadImg','uploadImg');
    Route::any('getAdminAddressList','getAdminAddressList');
    Route::any('getAdminAddressInfo','getAdminAddressInfo');
    Route::any('insertAdminAddress','insertAdminAddress');
    Route::any('updateAdminAddress','updateAdminAddress');
    Route::any('delAdminAddress','delAdminAddress');
    Route::any('getCurrency','getCurrency');
    Route::any('setCurrency','setCurrency');
})->prefix('admins/admin/');

Route::group('apis/order', function () {
    Route::any('create', 'create')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('list', 'list');
    Route::any('info', 'info')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次

})->prefix('apis/order/')->middleware('\app\middleware\CheckToken');;

Route::group('apis/currency', function () {
    Route::any('addCurrency', 'addCurrency')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次

})->prefix('apis/currency/')->middleware('\app\middleware\CheckToken');;

Route::group('apis/usdt', function () {
    Route::any('test', 'test')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('userWalletList', 'userWalletList')->middleware('\app\middleware\CheckToken')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('walletPay', 'walletPay')->middleware('\app\middleware\CheckToken')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('transactionList', 'transactionList')->middleware('\app\middleware\CheckToken')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('getAddressInfo', 'getAddressInfo')->middleware('\app\middleware\CheckToken')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('saveAddress', 'saveAddress')->middleware('\app\middleware\CheckToken')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('getAdminList', 'getAdminList')->middleware('\app\middleware\CheckToken')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::any('delAddress', 'delAddress')->middleware('\app\middleware\CheckToken');//限制1秒只能请求一次
})->prefix('apis/usdt/');;

Route::group('apis/user', function () {
    Route::any('createAddress', 'createAddress')->middleware('\app\middleware\Throttle')->middleware('\app\middleware\CheckToken');;//限制1秒只能请求一次
    Route::any('importAddress', 'importAddress')->middleware('\app\middleware\Throttle')->middleware('\app\middleware\CheckToken');;//限制1秒只能请求一次;
    Route::any('login', 'login')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次;
    Route::any('addressList', 'addressList')->middleware('\app\middleware\CheckToken');;
    Route::any('getAddressInfo', 'getAddressInfo')->middleware('\app\middleware\CheckToken');;//限制1秒只能请求一次
    Route::any('setMoney', 'setMoney')->middleware('\app\middleware\CheckToken');;//限制1秒只能请求一次
    Route::any('getUserInfo', 'getUserInfo')->middleware('\app\middleware\CheckToken');;//限制1秒只能请求一次
})->prefix('apis/user/');

Route::group(function () {
    // 订单相关


})->middleware('\app\middleware\CheckToken');

//登陆相关
Route::group('signin', function () {
    Route::any('login', 'login')->middleware('\app\middleware\Throttle');//登陆接口
})->prefix('signin/');



// 测试类，扔一些测试内筒
Route::group('Test', function () {
    Route::any('textScan', 'textScan');
    Route::any('test', 'test');
    Route::any('jdTest', 'jdTest');
})->prefix('Test/');