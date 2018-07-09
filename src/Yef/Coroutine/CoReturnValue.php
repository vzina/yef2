<?php
namespace Yef\Coroutine;

/**
 * Class CoroutineReturnValue
 * wrap variable as object
 * @package Yef\Coroutine
 */
class CoReturnValue
{
    protected $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function getValue()
    {
        return $this->value;
    }
}
