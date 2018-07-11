<?php
namespace Yef\Contracts\Events;

/**
* 事件处理接口
*/
interface Events
{
    public function appInit();

    public function beforeDispatcher($request);

    public function afterDispatcher($routeInfo);

    public function beforeResponse($response);

    public function formatResponse(&$response, $res);

    public function appError(\Exception $e);

    public function appRfter();
}
