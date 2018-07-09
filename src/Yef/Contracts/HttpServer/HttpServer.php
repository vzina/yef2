<?php
namespace Yef\Contracts\HttpServer;

interface HttpServer
{
    public static function run($address);
    public function getHttpServer();
}
