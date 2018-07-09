<?php
namespace Yef\Coroutine;

class SysCall
{
    protected $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function __invoke(Task $task)
    {
        $callback = $this->callback; // Can't call it directly in PHP :/
        return $callback($task, $scheduler);
    }

    public static function retval($value)
    {
        return new CoReturnValue($value);
    }
}
