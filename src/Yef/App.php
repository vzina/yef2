<?php
namespace Yef;

use Evenement\EventEmitter;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Pimple\Container;
use Yef\Contracts\View\View;
use Yef\Coroutine\Task;
use zpt\anno\Annotations;

/**
 * 应用类
 */
class App extends Container
{
    private $_dispatcher;
    private $maxTaskId = 0;

    public function __construct(array $config = array())
    {
        $this['event'] = new EventEmitter();
        parent::__construct($config);
        if (isset($this['template'])) {
            View\Template::config($this['template']);
        }
    }

    public function parseRouteByController()
    {
        $this->_dispatcher = \FastRoute\cachedDispatcher(function (RouteCollector $rCollector) {
            $controllerSuffix = 'Controller.php';
            $ctrollerFile     = $this->globRecursive(__CONTROLLER__ . '/*');
            foreach ($ctrollerFile as $file) {
                if (empty(strpos($file, $controllerSuffix))) {
                    continue;
                }
                $controller = str_replace([__ROOT__ . '/', '/', '.php'], ['', '\\', ''], $file);

                try {
                    $reflector = new \ReflectionClass(ucfirst($controller));
                    if (!$reflector->isSubclassOf(Controller::class)) {
                        continue;
                    }
                    $controllerName = str_replace([__CONTROLLER__, $controllerSuffix], '', $file);
                    $methods        = $reflector->getMethods(\ReflectionMethod::IS_PUBLIC);
                } catch (\Exception $e) {
                    continue;
                }

                foreach ($methods as $method) {
                    $methodName = $method->getName();
                    if (strpos($methodName, 'Action') === false) {
                        continue;
                    }
                    $as = (new Annotations($method))->asArray();
                    // 默认方法名
                    if (empty($as['path'])) {
                        $as['path'] = $controllerName . '/' . strstr($methodName, 'Action', true);
                    }

                    // 解析请求方式
                    if (empty($as['method'])) {
                        $as['method'] = '*';
                    } elseif (is_string($as['method']) && strpos($as['method'], ',') !== false) {
                        $as['method'] = explode(',', strtoupper($as['method']));
                    } elseif (is_array($as['method'])) {
                        foreach ($as['method'] as &$qm) {
                            $qm = strtoupper($qm);
                        }
                    }

                    $rCollector->addRoute($as['method'], $as['path'],
                        [$controller, $methodName]);
                }
            }
        }, [
            'cacheFile' => __RUNTIME__ . '/route.cache', /* required */
            'cacheDisabled' => __DEBUG__, /* optional, enabled by default */
        ]);
    }

    private function globRecursive($pattern, $flags = 0)
    {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, $this->globRecursive($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }

    public function run(Request $request, Response $response = null)
    {
        $response = $response ?: $this->response();
        $this['event']->emit('before.dispatcher', [$request]);
        // Fetch method and URI from somewhere
        $httpMethod = $request->getMethod();
        $uri        = $request->getRequestUri();

        // Strip query string (?foo=bar) and decode URI
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }

        $uri       = rawurldecode($uri);
        $routeInfo = $this->_dispatcher->dispatch($httpMethod, $uri);
        $this['event']->emit('after.dispatcher', [$routeInfo]);
        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
            case Dispatcher::METHOD_NOT_ALLOWED:
                $code = $routeInfo[0] ? 405 : 404;
                $response->setStatusCode($code);
                break;
            case Dispatcher::FOUND:
                $response = $this->handleController($request, $response, $routeInfo);
                break;
        }

        return $response;
    }

    public function handleController($request, $response, $routeInfo)
    {
        list(, list($controller, $method), $vars) = $routeInfo;
        // code
        $res = call_user_func_array(
            [new $controller($request, $response), $method], $vars);
        if ($res instanceof \Generator) {
            if ($this->maxTaskId == PHP_INT_MAX) {
                $this->maxTaskId = 0;
            }
            ++$this->maxTaskId;
            $task = new Task($this->maxTaskId,
                $this->handleAsyncController($request, $response, $res));
            $task->run();
            abort_app('yield_task');
        }
        // 格式化返回的结果
        $this->formatResponse($response, $res);
        return $response;
    }

    public function handleAsyncController($_request, $_response, $res)
    {
        $res = (yield $res);
        // 格式化返回的结果
        $this->formatResponse($_response, $res);
        $_response->execSendCallback();
        yield;
    }

    /**
     * Get/Set current response object
     */
    public function response()
    {
        //setter
        if (func_num_args() >= 2) {
            //2 args setter
            list($statusCode, $content) = func_get_args();
            $response                   = new Response($content, $statusCode);
        } elseif (func_num_args() == 1) {
            //1 argument setter
            $response = func_get_arg(0);
            $response = is_int($response) ? new Response('', $response) : new Response($response);
        } else {
            $response = new Response();
        }
        return $response->setProtocolVersion('1.1');
    }

    public function formatResponse(&$response, $res)
    {
        $this['event']->emit('format.response', [$response, $res]);
        if ($res instanceof View) {
            $response->setContent($res->getContent());
        } elseif (!($res instanceof Response)) {
            if (is_array($res) || is_object($res)) {
                $res = json_encode($res);
            }
            $response->setContent($res);
        } else {
            $response = $res;
        }
        $this['event']->emit('before.response', [$response]);
    }
}
