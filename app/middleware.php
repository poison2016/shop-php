<?php
// 全局中间件定义文件
return [
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    // \think\middleware\SessionInit::class
    //\app\middleware\Cors::class,// 跨域中间件
    // 别名或分组

    // 中间件列表
        \think\middleware\AllowCrossDomain::class,
];
