<?php
namespace Yef\Coroutine;

class Task
{
    protected $taskId;
    protected $coroutine;
    protected $sendValue = null;
    protected $exception = null;
    protected $stack     = null;

    public function __construct($taskId, \Generator $coroutine)
    {
        $this->taskId    = $taskId;
        $this->coroutine = $coroutine;
        $this->stack     = new \SplStack;
    }

    public function getTaskId()
    {
        return $this->taskId;
    }

    public function setException($exception)
    {
        $this->exception = $exception;
    }

    public function setSendValue($sendValue)
    {
        $this->sendValue = $sendValue;
    }

    public function run()
    {
        for (;;) {
            try {
                if ($this->exception) {
                    $this->coroutine->throw($this->exception);
                    $this->exception = null;
                    continue;
                }

                $value = $this->coroutine->current();

                if ($value instanceof \Generator) {
                    $this->stack->push($this->coroutine);
                    $this->coroutine = $value;
                    continue;
                }

                //如果为null，而且栈不为空，出栈
                if (is_null($value) && !$this->stack->isEmpty()) {
                    $this->coroutine = $this->stack->pop();
                    $this->coroutine->send($this->sendValue);
                    continue;
                }

                if ($value instanceof SysCall) {
                    call_user_func($value, $this);
                    return;
                }

                $isReturnValue = $value instanceof CoReturnValue;
                if ($this->isFinished() || $isReturnValue) {
                    if ($this->stack->isEmpty()) {
                        return;
                    }

                    $this->coroutine = $this->stack->pop();
                    $this->coroutine->send($isReturnValue ? $value->getValue() : null);
                    continue;
                }

                // 异步客户端
                if ($value instanceof Basic) {
                    $this->stack->push($this->coroutine);
                    $value->call(function ($response, $error, $calltime) {
                        $this->coroutine = $this->stack->pop();
                        $cbData          = [
                            'response' => $response,
                            'error'    => $error,
                            'calltime' => $calltime,
                        ];
                        $this->coroutine->send($cbData);
                        $this->run();
                    });
                    return;
                }

                if ($this->stack->isEmpty()) {
                    return;
                }

                $this->coroutine = $this->stack->pop();
                $this->coroutine->send($value);
            } catch (\Exception $e) {
                if ($this->stack->isEmpty()) {
                    throw $e;
                }

                $this->coroutine = $this->stack->pop();
                $this->exception = $e;
            }
        }
    }

    public function isFinished()
    {
        return !$this->coroutine->valid();
    }
}
