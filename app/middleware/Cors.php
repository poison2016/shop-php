<?php
namespace app\middleware;

class Cors
{
    public function handle($request, \Closure $next)
    {
        // 允许的域名，* 代表允许所有域名
        $allowOrigin = '*';
        // 允许的请求头
        $allowHeaders = 'Origin, Content-Type, Authorization, X-Requested-With';
        // 允许的请求方法
        $allowMethods = 'GET, POST, PUT, DELETE, OPTIONS';

        // 设置跨域头
        header('Access-Control-Allow-Origin: ' . $allowOrigin);
        header('Access-Control-Allow-Headers: ' . $allowHeaders);
        header('Access-Control-Allow-Methods: ' . $allowMethods);

        // 如果是预检请求（OPTIONS 请求），直接返回响应
        if ($request->isOptions()) {
            return response()->code(204);
        }

        // 继续执行请求
        return $next($request);
    }
}
