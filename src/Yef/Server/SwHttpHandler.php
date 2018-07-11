<?php
namespace Yef\HttpServer;

use Yef\Contracts\Server\Server as ContractsServer;
use Yef\Request;

/**
 * sw httpserver
 */
class SwHttpHandler implements ContractsServer
{
    private $http;

    private $config = [
        'host' => '127.0.0.1',
        'port' => '8081',
        'set'  => [
            'reactor_num'           => 2, //reactor thread num
            'worker_num'            => 4, //worker process num
            'backlog'               => 128, //listen backlog
            'max_request'           => 100,
            'dispatch_mode'         => 1,
            'document_root'         => __ROOT__ . '/public/static',
            'enable_static_handler' => true,
            'daemonize'             => false,
        ],
    ];

    public static function run($address)
    {
        $http = new self($address);
        app('server.container', $http);
        $http->start();
    }

    public function getServer()
    {
        return $this->http;
    }

    protected function __construct($address = null)
    {
        $info = $address ? parse_url($address) : [];
        // 合并用户配置
        $this->config = (array) app('sw.http', null) + $this->config;
        $this->http   = new \swoole_http_server(
            empty($info['host']) ? $this->config['host'] : $info['host'],
            empty($info['port']) ? $this->config['port'] : $info['port']
        );

        $this->http->set($this->config['set']);

        $this->http->on("start", [$this, 'onStart']);
        $this->http->on("request", [$this, 'onRequest']);
    }

    private function start()
    {
        $this->http->start();
    }

    public function onStart($server)
    {
        echo "Swoole http server is started at {$this->config['host']}:{$this->config['port']}\n";
    }

    public function onRequest($request, $response)
    {
        if ($request->server['path_info'] == '/favicon.ico') {
            $response->end("");
            return;
        }
        // 设置全局参数
        $_GET     = isset($request->get) ? $request->get : [];
        $_POST    = isset($request->post) ? $request->post : [];
        $_COOKIE  = isset($request->cookie) ? $request->cookie : [];
        $_FILES   = isset($request->files) ? $request->files : [];
        $_REQUEST = array_merge($_GET, $_POST);
        $_SERVER  = array_change_key_case($request->server, CASE_UPPER);
        foreach ($request->header as $key => $value) {
            $_SERVER['HTTP_' . strtoupper($key)] = $value;
        }
        try {
            // 创建请求对象
            $_request = new Request(
                $_GET, $_POST, $_REQUEST, $_COOKIE, $_FILES, $_SERVER
            );
            $_request->setServerReq($request);
            $_response = app()->response();
            $_response->setServerResp($response);
            // 异步执行方法
            $sendValueFunc = function () use ($response, $_response) {
                $headers = $_response->headers->all();
                foreach ($_response->headers as $key => $value) {
                    $response->header($key, join($value, ','));
                }
                $response->status($_response->getStatusCode());
                $response->end($_response->getContent());
            };
            $_response->setSendCallback($sendValueFunc);

            $rs = \BootYef::exec($_request, $_response);
            if (empty($rs)) {
                return;
            }
            // send data to client
            $sendValueFunc();
        } catch (\Exception $e) {
            show_err_page($e, $_response);
        }
    }

    public function __toString()
    {
        return "sw httpserver";
    }
}
