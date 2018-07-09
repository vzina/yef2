<?php

namespace Yef\Async\Client;

use React\HttpClient\Client;
use Workerman\Worker;

class Http extends Basic
{
    protected $methods = ["GET", "PUT", "POST", "DELETE", "HEAD", "PATCH"];

    protected $ip;

    protected $port;

    protected $timeout = 5;

    protected $calltime;

    protected $client;

    protected $path = null;

    protected $headers = [];

    protected $method = 'GET';

    public function __construct($ip = null, $port = 80, $ssl = false)
    {
        $loop         = Worker::getEventLoop();
        $this->client = new Client($loop);
    }

    public function setHeaders($headers)
    {
        $this->headers = $headers;
    }

    public function setMethod($method)
    {
        if (in_array($method, $this->methods)) {
            $this->method = $method;
        }
    }

    public function setPath($path)
    {
        $this->path = $path;
    }

    public function call(callable $callback)
    {
        if (!$this->path) {
            call_user_func_array($callback, [
                'response' => false,
                'error'    => 'path not found',
                'calltime' => 0,
            ]);
            return;
        }

        $this->calltime = microtime(true);
        $request        = $this->client->request($this->method, $this->path);
        $request->on('error', function (\Exception $e) use ($callback) {
            call_user_func_array($callback, [
                'response' => false,
                'error'    => $e->getMessage(),
                'calltime' => 0,
            ]);
            return;
        });
        $request->on('response', function ($response) use ($callback) {
            $response->on('data', function ($data) use ($callback) {
                call_user_func_array($callback, [
                    'response' => $data,
                    'error'    => null,
                    'calltime' => $this->calltime,
                ]);
            });
        });
        $request->end();
    }
}
