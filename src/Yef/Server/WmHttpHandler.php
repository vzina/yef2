<?php
namespace Yef\Server;

use Workerman\Protocols\Http;
use Workerman\Worker;
use Yef\Contracts\Server\Server as ContractsServer;
use Yef\Request;

/**
 * wm httpserver
 */
class WmHttpHandler implements ContractsServer
{
    private $worker;

    private $config = [
        'host'      => '127.0.0.1',
        'port'      => '8081',
        'set'       => [
            'count'      => 4,
            'name'       => 'wm-httpserver',
            'user'       => 'nobody',
            'reloadable' => true,
        ],
        'pidFile'   => __RUNTIME__ . '/worker/worker.pid',
        'logFile'   => __RUNTIME__ . '/worker/httpserver.log',
        'daemonize' => !__DEBUG__,
    ];

    public static function run($address = null)
    {
        $http = new self($address);
        app('server.container', $http);
        $http->start();
    }

    public function getServer()
    {
        return $this->worker;
    }

    protected function __construct($address = null)
    {
        $info = $address ? parse_url($address) : [];
        $host = empty($info['host']) ? $this->config['host'] : $info['host'];
        $port = empty($info['port']) ? $this->config['port'] : $info['port'];
        // 合并用户配置
        $this->config = (array) app('wm.http', null) + $this->config;
        $this->worker = new Worker("http://{$host}:{$port}");
        foreach ($this->config['set'] as $key => $value) {
            $this->worker->$key = $value;
        }

        Worker::$pidFile         = $this->config['pidFile'];
        Worker::$logFile         = $this->config['logFile'];
        Worker::$daemonize       = $this->config['daemonize'];
        $this->worker->onMessage = [$this, 'onMessage'];
    }

    private function start()
    {
        // run all workers
        Worker::runAll();
    }

    public function onMessage($connection, $data)
    {
        if ($_SERVER['REQUEST_URI'] == '/favicon.ico') {
            $connection->send("");
            return;
        }
        try {
            // 创建请求对象
            $_request = new Request(
                $_GET, $_POST, $_REQUEST, $_COOKIE, $_FILES, $_SERVER
            );
            $_request->setServerReq($connection);
            $_response = app()->response();
            $_response->setServerResp($connection);
            // 执行方法
            $sendValueFunc = function () use ($connection, $_response) {
                list($headers, $body) = explode("\r\n\r\n", (string) $_response);
                Http::header($headers);
                $connection->send($body);
            };
            //
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
}
