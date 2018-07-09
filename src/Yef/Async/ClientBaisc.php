<?php
namespace Yef\Async;

/**
*
*/
abstract class ClientBasic
{
    protected $ip;
    protected $port;
    protected $timeout = 5;
    protected $data;
    protected $client;

    abstract public function call(callable $callback);
}
