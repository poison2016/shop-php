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
    Route::get('hello', '');
})->prefix('index/');

//图片上传
Route::group('image', function () {
    Route::post('upload', 'upload');
})->prefix('image/');

Route::group('apis/goods', function () {
    Route::post('list','list')->middleware('\app\middleware\CheckToken');
    Route::post('info','info')->middleware('\app\middleware\CheckToken');

})->prefix('apis/goods/');;

Route::group('apis/test', function () {
    Route::get('index','index');
})->prefix('apis/test/');

Route::group('apis/order', function () {
    Route::post('create', 'create')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::post('list', 'list');
    Route::post('info', 'info')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次

})->prefix('apis/order/')->middleware('\app\middleware\CheckToken');

Route::group('apis/user', function () {
    Route::post('createAddress', 'createAddress')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次
    Route::post('importAddress', 'importAddress')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次;
    Route::post('login', 'login')->middleware('\app\middleware\Throttle');//限制1秒只能请求一次;
    Route::post('addressList', 'addressList');
    Route::post('getAddressInfo', 'getAddressInfo');//限制1秒只能请求一次
    Route::post('setMoney', 'setMoney');//限制1秒只能请求一次
})->prefix('apis/user/')->middleware('\app\middleware\CheckToken');

Route::group(function () {
    // 订单相关


})->middleware('\app\middleware\CheckToken');

//登陆相关
Route::group('signin', function () {
    Route::post('login', 'login')->middleware('\app\middleware\Throttle');//登陆接口
})->prefix('signin/');



// 测试类，扔一些测试内筒
Route::group('Test', function () {
    Route::post('textScan', 'textScan');
    Route::get('test', 'test');
    Route::get('jdTest', 'jdTest');
})->prefix('Test/');