<?php
namespace Yef;

use Evenement\EventEmitter;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Pimple\Container;
use Yef\Contracts\View\View as ContractsView;
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
        $cacheFile = !empty($this['route.cacheFile']) ? $this['route.cacheFile'] : true;
        $this->_dispatcher = \FastRoute\cachedDispatcher(function (RouteCollector $rCollector) {
            $controllerSuffix = 'Controller.php';
            $ctrollerFile = [];
            get_file_recursive(__CONTROLLER__, $ctrollerFile);
            foreach ($ctrollerFile as $file) {
                if (empty(strpos($file, $controllerSuffix))) {
                    continue;
                }
                $controller = ucfirst(str_replace([__ROOT__ . '/', '/', '.php'], ['', '\\', ''], $file));

                try {
                    $reflector = new \ReflectionClass($controller);
                    if (!$reflector->isSubclassOf(Controller::class)) {
                        continue;
                    }
                    $controllerName = str_replace([__CONTROLLER__, $controllerSuffix], '', $file);
                    $methods = $reflector->getMethods(\ReflectionMethod::IS_PUBLIC);
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
                        [$controller, $methodName, $as]);
                }
            }
        }, [
            'cacheFile' => $cacheFile, /* required */
            'cacheDisabled' => empty($cacheFile), /* optional, enabled by default */
        ]);
    }

    public function run(Request $request, Response $response = null)
    {
        $response = $response ?: $this->response();
        $this['event']->emit('before.dispatcher', [$request]);
        // Fetch method and URI from somewhere
        $httpMethod = $request->getMethod();
        $uri = $request->getRequestUri() ?: $request->get('u');

        // Strip query string (?foo=bar) and decode URI
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }

        $uri = rawurldecode($uri);
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
        list(, list($controller, $method, $as), $vars) = $routeInfo;
        // code
        $res = call_user_func_array(
            [new $controller($request, $response), $method], $vars);
        $isGen = $res instanceof \Generator;
        if ($isGen || isset($as['async'])) {
            if ($this->maxTaskId == PHP_INT_MAX) {
                $this->maxTaskId = 0;
            }
            ++$this->maxTaskId;
            $task = new Task($this->maxTaskId,
                $this->handleAsyncController($request, $response, $res, $as, $isGen));
            $task->run();
            abort_app('yield_task');
        }
        // 格式化返回的结果
        $this->formatResponse($request, $response, $res, $as);
        return $response;
    }

    private function handleAsyncController($_request, $_response, $res, $as = [], $isGen = true)
    {
        if ($isGen) {
            $res = (yield $res);
        }

        // 格式化返回的结果
        $this->formatResponse($_request, $_response, $res, $as);
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
            $response = new Response($content, $statusCode);
        } elseif (func_num_args() == 1) {
            //1 argument setter
            $response = func_get_arg(0);
            $response = is_int($response) ? new Response('', $response) : new Response($response);
        } else {
            $response = new Response();
        }
        return $response->setProtocolVersion('1.1');
    }

    public function formatResponse($request, &$response, $res, $as)
    {
        if ($res instanceof ContractsView || $res instanceof Response) {
            $res = $res->getContent();
        } elseif (is_array($res) || is_object($res)) {
            $res = json_encode($res, JSON_UNESCAPED_UNICODE);
        }
        $format = empty($as['return']) ? 'string' : $as['return'];
        switch (strtolower($format)) {
            case 'jsonp':
                $res = sprintf("/**/%s(%s)", $request->get('callback', 'callback'), $res);
            case 'json':
                $response->headers->set('Content-Type', 'application/json');
                break;
            case 'cros':
                $response->headers->set('Content-Type', 'application/json');
                break;
            default:
                # code...
                break;
        }
        $response->setContent($res);
        $this['event']->emit('before.response', [$response]);
    }
}
