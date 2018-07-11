<?php
namespace Yef\Contracts\Server;

interface Server
{
    public static function run($address);
    public function getServer();
}
