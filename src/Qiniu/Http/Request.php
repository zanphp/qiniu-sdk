<?php
namespace Zan\Qiniu\Http;

use Zan\Framework\Network\Common\HttpClient as ZanHttpClient;

final class Request
{
    public $url;
    public $headers;
    public $body;
    public $method;
    public $timeout;

    public function __construct($method, $url, array $headers = array(), $body = null, $timeout = 5000)
    {
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeout = $timeout;
    }

    /**
     * @param string $url
     * @return ZanHttpClient
     */
    private static function getClientByUrl($url) {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: 80;
        return ZanHttpClient::newInstance($host, $port);
    }

    /**
     * @return ZanHttpClient
     */
    public function toZanHttpClient() {
        // 不使用Zan的->post($url, [], $timeout)
        // 自定义POST调用, 因为Zan中post方法中调用build()方法会强制改写body和Content-Type
        return static::getClientByUrl($this->url)
                ->setMethod($this->method)
                ->setUri($this->url)
                ->setHeader($this->headers) // http://wiki.swoole.com/wiki/page/542.html [host => www.xxx.zzz]
                ->setBody($this->body)      // http://wiki.swoole.com/wiki/page/543.html 类型为 string or array
                ->setTimeout($this->timeout);
    }
}
