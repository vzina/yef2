<?php
use Medoo\Medoo;
use Yef\App;
use Yef\Config;
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
        defined('__VIEW__') or define('__VIEW__', __ROOT__ . '/views');
        // 不允许修改控制器目录
        define('__CONTROLLER__', __ROOT__ . '/apps/Controllers');

        $conf = Config::load(__CONF__);
        /* Simply build the application around your URLs */
        self::$app = new App($conf->all());
        // 错误
        \set_error_handler([__CLASS__, 'exceptionErrorHandler']);
        // 初始化
        self::initEvents();
        self::$app['event']->emit('app.init');
        self::$app->parseRouteByController();
    }

    private static function initEvents()
    {
        if (!empty(self::$app['events'])) {
            return;
        }
        $events = ['appInit', 'beforeDispatcher', 'afterDispatcher', 'beforeResponse', 'formatResponse', 'appError', 'appAfter'];
        $eventHandlerList = (array) self::$app['events'];
        $interface = 'Yef\Contracts\Events\Events';
        foreach ($eventHandlerList as $eventHandler) {
            $ref = new \ReflectionClass($eventHandler);
            if (!$ref->implementsInterface($interface)) {
                continue;
            }
            $eventInstance = $ref->newInstance(self::$app);
            foreach ($events as $event) {
                $eventName = strtolower(preg_replace(
                    '/((?<=[a-z])(?=[A-Z]))/', '.', $event));
                self::$app['event']->on($eventName, [$eventInstance, $event]);
            }
        }
    }

    public static function exceptionErrorHandler($errno, $errstr, $errfile, $errline)
    {
        $displayErrors = ini_get("display_errors");
        $displayErrors = strtolower($displayErrors);
        $level = error_reporting();
        if ($level === 0 && $displayErrors === "off") {
            return;
        }
        $severity = 1 * $level;
        $ex = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
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
            if (empty(self::$app['ServerHandler'])) {
                echo '未配置httpServerHandler参数' . PHP_EOL;
                return;
            }
            $ref = new \ReflectionClass(self::$app['ServerHandler']);
            $interface = 'Yef\Contracts\Server\Server';
            if (!$ref->implementsInterface($interface)) {
                echo '未实现接口：' . $interface . PHP_EOL;
                return;
            }
            call_user_func([self::$app['ServerHandler'], 'run'], $address);
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
    return BootYef::app($name, $value, $default);
}

/**
 * 数据库操作类
 *
 * @param  string $conf [description]
 * @return Medoo\Medoo
 */
function db($confName = 'localDb', $isMaster = false)
{
    $app = BootYef::app();
    if (empty($confName)) {
        $confName = 'localDb';
    }
    $key = 'db.' . $confName . '.' . intval($isMaster);
    if (empty($app[$key])) {
        $app[$key] = $app->factory(function () use ($confName, $isMaster) {
            $confName = $confName ?: app('defaultDbConf', null, 'localDb');
            if (!$conf = app($confName)) {
                return false;
            }
            $conf['server'] = $conf['master'];
            if (!$isMaster) {
                $conf['server'] = $conf['slave'][array_rand($conf['slave'], 1)];
            }
            // Initialize
            return new Medoo($conf);;
        });
    }
    return $app[$key];
}

/**
 * [log description]
 * @param  [type] $msg  [description]
 * @param  string $type [description]
 * @return [type]       [description]
 */
function logs($msg, $level = 'info')
{
    $log = BootYef::app('log');
    if (empty($log)) {
        $config = (array) BootYef::app('logs') + [
            "name" => "app",
            "file" => __RUNTIME__ . '/app/' . date('Ymd') . '.log',
            "log_level" => Monolog\Logger::DEBUG,
        ];
        if (empty($config)) {
            return false;
        }
        // create a log channel
        $log = new Monolog\Logger($config['name']);
        $log->pushHandler(new Monolog\Handler\StreamHandler($config['file'], $config['log_level']));
        BootYef::app('log', $log);
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
            'method' => $method,
            'header' => $headers ?: "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.99 Safari/537.36\r\n",
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
        $error = '';
        $statusCode = 200;
    } else {
        $exception = new ExceptionHandler();
        $error = $exception->handleException($e);
    }

    if ($response) {
        $response->setStatusCode($statusCode);
        $response->setContent($error);
    } else {
        $response = app()->response($statusCode, $error);
    }
    $response->execSendCallback();
}

function get_file_recursive($path, &$files)
{
    if (is_dir($path)) {
        $dp = dir($path);
        while ($file = $dp->read()) {
            if ($file != "." && $file != "..") {
                get_file_recursive($path . "/" . $file, $files);
            }
        }
        $dp->close();
    }
    if (is_file($path)) {
        $files[] = $path;
    }
}

function glob_recursive($pattern, $flags = 0)
{
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
        $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
    }
    return $files;
}
