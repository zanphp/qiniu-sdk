<?php

use ZanPHP\SDK\Qiniu\Storage\UploadManager;

require __DIR__ . "/../vendor/autoload.php";
require "zanphp/zan/vendor/autoload.php"; // PATH to ZANPHP



call_user_func(function() {
    $task = function() {
        $token = ""; // TOKEN

        $key = "hello";
        $data = ""; // BINARY data
        $params = null;

        $zone = new \ZanPHP\SDK\Qiniu\Zone("http://up.qiniu.com", "http://up.qiniu.com");
        $config = new \ZanPHP\SDK\Qiniu\Config($zone);
        $mgr = new UploadManager($config);
        $r = yield $mgr->put($token, $key, $data, $params);
        var_dump($r);
    };

    \Zan\Framework\Foundation\Coroutine\Task::execute($task());
});
