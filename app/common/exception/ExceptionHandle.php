<?php

namespace app\common\exception;

use app\common\service\ComService;
use app\Request;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\RouteNotFoundException;
use think\exception\ValidateException;
use think\facade\Db;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        ApiException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        Db::rollback();
        if ($e instanceof ApiException) {
            $code = ($e->getCode() > 0) ? $e->getCode() : 100;
            $fail = ['code' => $code, 'message' => $e->getMessage(), 'data' => $e->getArgv()];
            return Response::create($fail, 'json');
        }elseif ($e instanceof RouteNotFoundException){
            header("Location: ".config('self_config.host_api').'/index.html');exit();
        }

        // 添加自定义异常处理机制
        $data = [
            'header' => $request->header(),
            'url' => $request->url(true),
            'time' => date('Y-m-d H:i:s', $request->time()),
            'baseFile' => $request->baseFile(true),
            'port' => $request->port(true),
            'rule' => $request->rule(),
            'method' => $request->method(),
            'controller' => $request->controller() . '/' . $request->action(),
            'param' => $request->param(false),
            'ip' => getClientIP(),
            'errorInfo' => $e,

            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ];
        trace('异常信息记录：' . json_encode($data), 'error');

        if (env('server_env') != 'dev') {
            return Response::create(['code' => 100, 'message' => '系统异常，请稍后重试'], 'json');
        }

        // 其他错误交给系统处理
        return parent::render($request, $e);
    }
}
