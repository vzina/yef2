<?php
use Noodlehaus\Config;
use Yef\App;
use Yef\ExceptionHandler;
use Yef\Request;
use Yef\Response;

/**
 * 框架引导类
 */
class BootYef
{
    private static $app;

    public static function app($name = '', $value = null, $default = null)
    {
        if (!empty($name)) {
            if (!is_null($value)) {
                self::$app[$name] = $value;
                return;
            }
            return isset(self::$app[$name]) ? self::$app[$name] : $default;
        }
        return self::$app;
    }

    private static function init()
    {
        defined('__DEBUG__') or define('__DEBUG__', false);
        defined('__ROOT__') or define('__ROOT__', dirname(__DIR__));
        defined('__CONF__') or define('__CONF__', __ROOT__ . '/conf');
        defined('__RUNTIME__') or define('__RUNTIME__', __ROOT__ . '/runtime');
        defined('__CONTROLLER__') or define('__CONTROLLER__', __ROOT__ . '/apps/Controllers');
        defined('__VIEW__') or define('__VIEW__', __ROOT__ . '/views');

        $conf = Config::load(__CONF__);
        /* Simply build the application around your URLs */
        self::$app = new App($conf->all());
        // 错误
        \set_error_handler(['Bootstrap', 'exceptionErrorHandler']);
        // 初始化
        self::$app['event']->emit('app.init');
        self::$app->parseRouteByController();
    }

    public static function exceptionErrorHandler($errno, $errstr, $errfile, $errline)
    {
        $displayErrors = ini_get("display_errors");
        $displayErrors = strtolower($displayErrors);
        $level         = error_reporting();
        if ($level === 0 && $displayErrors === "off") {
            return;
        }
        $severity = 1 * $level;
        $ex       = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        if (($ex->getSeverity() & $severity) != 0) {
            throw $ex;
        }
    }

    public static function exec($request = null, $response = null)
    {
        try {
            $request = $request ?: new Request(
                $_GET, $_POST, $_REQUEST, $_COOKIE, $_FILES, $_SERVER
            );
            return self::$app->run($request, $response);
        } catch (\Exception $e) {
            show_err_page($e, $response);
        }
    }

    public static function start($address = '')
    {
        self::init();
        if ($address) {
            if (empty(self::$app['httpServerHandler'])) {
                echo '未配置httpServerHandler参数' . PHP_EOL;
                return;
            }
            $ref       = new \ReflectionClass(self::$app['httpServerHandler']);
            $interface = 'Yef\Contracts\HttpServer\HttpServer';
            if (!$ref->implementsInterface($interface)) {
                echo '未实现接口：' . $interface . PHP_EOL;
                return;
            }
            call_user_func([self::$app['httpServerHandler'], 'run'], $address);
            return;
        }
        try {
            // 框架运行
            $resp = self::exec();
            if (empty($resp)) {
                return;
            }
            // 异步
            $resp->execSendCallback();
        } catch (\Exception $e) {
            show_err_page($e, $_response);
        } finally {
            // 响应结束
            self::$app['event']->emit('app.after');
        }
    }
}

/**
 * app对象
 *
 * @return Yef\App
 */
function app($name = '', $value = null, $default = null)
{
    return Bootstrap::app($name, $value, $default);
}

/**
 * 数据库操作类
 *
 * @param  string $conf [description]
 * @return Medoo\Medoo
 */
function db($conf = 'localDb')
{
    $db = Bootstrap::app('db');
    if (empty($db) || empty($obj = $db($conf))) {
        throw new \Exception('db操作对象不存在！');
    }
    return $obj;
}

/**
 * [log description]
 * @param  [type] $msg  [description]
 * @param  string $type [description]
 * @return [type]       [description]
 */
function logs($msg, $level = 'info')
{
    $log = Bootstrap::app();
    if (empty($log)) {
        throw new \Exception('日志操作对象不存在！');
    }

    return $log->log(strtoupper($level), $msg);
}

/**
 * http客户端
 *
 * @param  string $url     [description]
 * @param  string $method  [description]
 * @param  string $content [description]
 * @param  array  $headers [description]
 * @return [type]          [description]
 */
function httpClient($url, $method = 'GET', $content = '', $headers = [])
{
    if (empty($url)) {
        return false;
    }
    $opts = [
        'http' => [
            'method'  => $method,
            'header'  => $headers ?: "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.99 Safari/537.36\r\n",
            'content' => $content,
        ],
    ];

    return file_get_contents($url, false, stream_context_create($opts));
}

function abort_app($msg = 'yef_jump_exit')
{
    throw new \Exception($msg);
}

function show_err_page(\Exception $e, Response $response = null, $statusCode = 500)
{
    $msg = $e->getMessage();
    if ($msg == 'yield_task') {
        return;
    }
    app('event')->emit('app.error', [$e]);
    if ($msg == 'yef_jump_exit') {
        $error      = '';
        $statusCode = 200;
    } else {
        $exception = new ExceptionHandler();
        $error     = $exception->handleException($e);
    }

    if ($response) {
        $response->setStatusCode($statusCode);
        $response->setContent($error);
    } else {
        $response = app()->response($statusCode, $error);
    }
    $response->execSendCallback();
}
