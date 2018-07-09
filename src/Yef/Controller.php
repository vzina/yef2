<?php
namespace Yef;

abstract class Controller
{
    private $_request;
    private $_response;

    final public function __construct(Request $request, Response $response)
    {
        $this->_request  = $request;
        $this->_response = $response;
        $this->init();
    }

    protected function init()
    {}

    final public function request()
    {
        return $this->_request;
    }

    final public function response()
    {
        return $this->_response;
    }

    /**
     * Return instance of Yef\View\Template
     *
     * @param string $name Template name
     * @param array $params Array of params to set
     */
    public function view($name, array $params = array())
    {
        $tpl = new View\Template($name);
        $tpl->set($params);
        return $tpl;
    }

    /**
     * Print out an array or object contents in preformatted text
     * Useful for debugging and quickly determining contents of variables
     */
    public function dump()
    {
        $objects = func_get_args();
        $content = "\n<pre>\n";
        foreach ($objects as $object) {
            $content .= print_r($object, true);
        }
        return $content . "\n</pre>\n";
    }
}
