<?php
namespace Yef;

class ExceptionHandler
{
    public function handleException($e)
    {
        $error = [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
            'type'    => $e->getCode(),
        ];

        return $this->renderHttpResponse($error);
    }

    /**
     * Render an exception as an HTTP response and send it.
     *
     * @param  \Exception  $e
     * @return void
     */
    protected function renderHttpResponse($e)
    {
        if (!is_array($e)) {
            $trace            = debug_backtrace();
            $error['message'] = $e;
            $error['file']    = $trace[0]['file'];
            $error['line']    = $trace[0]['line'];
            ob_start();
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $e['trace'] = ob_get_clean();
            $e          = $error;
        }

        return $this->trace($e);
    }

    protected function trace($error)
    {
        if (!is_array($error)) {
            return $error;
        }

        $error['trace'] = str_replace("#", "<br/>", $error['trace']);
        $str            = "<!DOCTYPE html><html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"><title>页面出错啦！</title></head><body><style>html, body {height: 100%;}body {margin: 0;padding: 0;width: 100%; display: table;font-weight: 100;font-family: 'Lato';}
.container {margin-top: 80px; vertical-align: middle;width: 1170px;margin-right: auto;margin-left: auto;}.content {text-align: left;display: inline-block;
}.title {font-size: 16px;}h3{color:#a94442;}p {color:#3c763d;}</style><div class=\"container\"><div class=\"content\" style=\"color:#8a6d3b\">
<h2>啊哦！出错了:</h2> </div> <br><div class=\"content\"><h3>错误文件名:</h3><p>{$error['file']}</p></div><br><div class=\"content\">
<h3>line:{$error['line']}</h3></div><br><div class=\"content\"><h3>错误信息:</h3> <p>{$error['message']}</p></div> <br><div class=\"content\"><h3>Trace:</h3><p>{$error['trace']}</p></div><br><div class=\"content\">
<p style=\"color:#31708f\">power by yef2 framework @author:yewj&nbsp;&nbsp;email:yeweijian299@163.ccom. </p></div></div></body></html>";

        return $str;
    }
}
