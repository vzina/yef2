<?php
namespace Yef;

use Symfony\Component\HttpFoundation\Request as SfRequest;

class Request extends SfRequest
{
    /** @var sw/other [description] */
    private $_request;

    public function setServerReq($_request)
    {
        $this->_request = $_request;
    }

    public function getServerReq()
    {
        return $this->_request;
    }
}