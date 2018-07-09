<?php
namespace Yef;

use Symfony\Component\HttpFoundation\Response as SfResponse;

class Response extends SfResponse
{
    /** @var sw/other [description] */
    private $_response;
    private $_sendCallback = null;

    public function setServerResp($_response)
    {
        $this->_response = $_response;
    }

    public function getServerResp()
    {
        return $this->_response;
    }

    public function setSendCallback(callable $callback)
    {
        $this->_sendCallback = $callback;
    }

    public function getSendCallback()
    {
        return $this->_sendCallback;
    }

    public function execSendCallback()
    {
        if (is_null($this->_sendCallback)) {
            return $this->send();
        }
        return call_user_func($this->_sendCallback);
    }
}
