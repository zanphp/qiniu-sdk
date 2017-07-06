<?php
namespace ZanPHP\SDK\Qiniu\Processing;

use ZanPHP\SDK\Qiniu\Auth;
use ZanPHP\SDK\Qiniu\Config;
use ZanPHP\SDK\Qiniu\Http\Client;
use ZanPHP\SDK\Qiniu\Http\Error;
use ZanPHP\SDK\Qiniu\Http\Response;
use function ZanPHP\SDK\Qiniu\setWithoutEmpty;

/**
 * 持久化处理类,该类用于主动触发异步持久化操作.
 *
 * @link http://developer.qiniu.com/docs/v6/api/reference/fop/pfop/pfop.html
 */
final class PersistentFop
{
    /**
     * @var $auth Auth 账号管理密钥对，Auth对象
     */
    private $auth;

    /**
     * @var $bucket string 操作资源所在空间
     */
    private $bucket;

    /**
     * @var $pipeline string 多媒体处理队列，详见 https://portal.qiniu.com/mps/pipeline
     */
    private $pipeline;

    /**
     * @var $notify_url string 持久化处理结果通知URL
     */
    private $notify_url;

    /**
     * @var boolean 是否强制覆盖已有的重名文件
     */
    private $force;


    public function __construct($auth, $bucket, $pipeline = null, $notify_url = null, $force = false)
    {
        $this->auth = $auth;
        $this->bucket = $bucket;
        $this->pipeline = $pipeline;
        $this->notify_url = $notify_url;
        $this->force = $force;
    }

    /**
     * 对资源文件进行异步持久化处理
     *
     * @param string $key   待处理的源文件
     * @param string|array $fops  待处理的pfop操作，多个pfop操作以array的形式传入。
     *                eg. avthumb/mp3/ab/192k, vframe/jpg/offset/7/w/480/h/360
     *
     * @return array 返回持久化处理的persistentId, 和返回的错误。
     *
     * @link http://developer.qiniu.com/docs/v6/api/reference/fop/
     */
    public function execute($key, $fops)
    {
        if (is_array($fops)) {
            $fops = implode(';', $fops);
        }
        $params = array('bucket' => $this->bucket, 'key' => $key, 'fops' => $fops);
        setWithoutEmpty($params, 'pipeline', $this->pipeline);
        setWithoutEmpty($params, 'notifyURL', $this->notify_url);
        if ($this->force) {
            $params['force'] = 1;
        }
        $data = http_build_query($params);
        $url = Config::API_HOST . '/pfop/';
        $headers = $this->auth->authorization($url, $data, 'application/x-www-form-urlencoded');
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        /* @var $response Response */
        $response = (yield Client::post($url, $data, $headers));
        if (!$response->ok()) {
            yield array(null, new Error($url, $response));
            return;
        }
        $r = $response->json();
        $id = $r['persistentId'];
        yield array($id, null);
    }

    public static function status($id)
    {
        $url = Config::API_HOST . "/status/get/prefop?id=$id";
        /* @var $response Response */
        $response = (yield Client::get($url));
        if (!$response->ok()) {
            yield array(null, new Error($url, $response));
            return;
        }
        yield array($response->json(), null);
    }
}
