<?php
namespace Zan\Qiniu\Storage;

use Zan\Qiniu\Auth;
use Zan\Qiniu\Config;
use Zan\Qiniu\Http\Client;
use Zan\Qiniu\Http\Error;
use Zan\Qiniu\Http\Response;
use function Zan\Qiniu\entry;
use function Zan\Qiniu\base64_urlSafeEncode;
use function Zan\Qiniu\setWithoutEmpty;

/**
 * 主要涉及了空间资源管理及批量操作接口的实现，具体的接口规格可以参考
 *
 * @link http://developer.qiniu.com/docs/v6/api/reference/rs/
 */
final class BucketManager
{
    private $auth;

    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * 获取指定账号下所有的空间名。
     *
     * @return string[] 包含所有空间名
     */
    public function buckets()
    {
        yield $this->rsGet('/buckets');
    }

    /**
     * 列取空间的文件列表
     *
     * @param string $bucket 空间名
     * @param string $prefix 列举前缀
     * @param string $marker 列举标识符
     * @param int|string $limit 单次列举个数限制
     * @param string $delimiter 指定目录分隔符
     * @return array 包含文件信息的数组，类似：
     *                                          [
     *                                              {
     *                                                  "hash" => "<Hash string>",
     *                                                  "key" => "<Key string>",
     *                                                  "fsize" => "<file size>",
     *                                                  "putTime" => "<file modify time>"
     *                                              },
     *                                              ...
     *                                          ]
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/list.html
     */
    public function listFiles($bucket, $prefix = null, $marker = null, $limit = 1000, $delimiter = null)
    {
        $query = array('bucket' => $bucket);
        setWithoutEmpty($query, 'prefix', $prefix);
        setWithoutEmpty($query, 'marker', $marker);
        setWithoutEmpty($query, 'limit', $limit);
        setWithoutEmpty($query, 'delimiter', $delimiter);
        $url = Config::RSF_HOST . '/list?' . http_build_query($query);
        list($ret, $error) = (yield $this->get($url));
        if ($ret === null) {
            yield array(null, null, $error);
            return;
        }
        $marker = array_key_exists('marker', $ret) ? $ret['marker'] : null;
        yield array($ret['items'], $marker, null);
    }

    /**
     * 获取资源的元信息，但不返回文件内容
     *
     * @param string $bucket     待获取信息资源所在的空间
     * @param string $key        待获取资源的文件名
     *
     * @return array    包含文件信息的数组，类似：
     *                                              [
     *                                                  "hash" => "<Hash string>",
     *                                                  "key" => "<Key string>",
     *                                                  "fsize" => "<file size>",
     *                                                  "putTime" => "<file modify time>"
     *                                              ]
     *
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/stat.html
     */
    public function stat($bucket, $key)
    {
        $path = '/stat/' . entry($bucket, $key);
        yield $this->rsGet($path);
    }

    /**
     * 删除指定资源
     *
     * @param string $bucket     待删除资源所在的空间
     * @param string $key        待删除资源的文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/delete.html
     */
    public function delete($bucket, $key)
    {
        $path = '/delete/' . entry($bucket, $key);
        list(, $error) = (yield $this->rsPost($path));
        yield $error;
    }

    /**
     * 刷新指定资源
     * @param string $url 资源url
     * @return array 响应正常error为NULL，七牛响应代码以数组$res中code为准
     */
    public function refresh($url)
    {
        $ApiUrl = Config::CDN_HOST . '/refresh';
        $data = [
            'urls' => [$url],
        ];
        $headers = $this->auth->authorization($ApiUrl, null, 'application/json');
        $headers["Content-Type"] = 'application/json';
        list($res, $error) = (yield $this->postWithCustomHeaders($ApiUrl, json_encode($data), $headers));
        yield array($res, $error);
    }


    /**
     * 给资源进行重命名，本质为move操作。
     *
     * @param string $bucket     待操作资源所在空间
     * @param string $oldname    待操作资源文件名
     * @param string $newname    目标资源文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     */
    public function rename($bucket, $oldname, $newname)
    {
        yield $this->move($bucket, $oldname, $bucket, $newname);
    }

    /**
     * 给资源进行重命名，本质为move操作。
     *
     * @param string $from_bucket     待操作资源所在空间
     * @param string $from_key        待操作资源文件名
     * @param string $to_bucket       目标资源空间名
     * @param string $to_key          目标资源文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/copy.html
     */
    public function copy($from_bucket, $from_key, $to_bucket, $to_key)
    {
        $from = entry($from_bucket, $from_key);
        $to = entry($to_bucket, $to_key);
        $path = '/copy/' . $from . '/' . $to;
        list(, $error) = (yield $this->rsPost($path));
        yield $error;
    }

    /**
     * 将资源从一个空间到另一个空间
     *
     * @param string $from_bucket     待操作资源所在空间
     * @param string $from_key        待操作资源文件名
     * @param string $to_bucket       目标资源空间名
     * @param string $to_key          目标资源文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/move.html
     */
    public function move($from_bucket, $from_key, $to_bucket, $to_key)
    {
        $from = entry($from_bucket, $from_key);
        $to = entry($to_bucket, $to_key);
        $path = '/move/' . $from . '/' . $to;
        list(, $error) = (yield $this->rsPost($path));
        yield $error;
    }

    /**
     * 主动修改指定资源的文件类型
     *
     * @param string $bucket     待操作资源所在空间
     * @param string $key        待操作资源文件名
     * @param string $mime       待操作文件目标mimeType
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/chgm.html
     */
    public function changeMime($bucket, $key, $mime)
    {
        $resource = entry($bucket, $key);
        $encode_mime = base64_urlSafeEncode($mime);
        $path = '/chgm/' . $resource . '/mime/' .$encode_mime;
        list(, $error) = (yield $this->rsPost($path));
        yield $error;
    }

    /**
     * 从指定URL抓取资源，并将该资源存储到指定空间中
     *
     * @param string $url        指定的URL
     * @param string $bucket     目标资源空间
     * @param string $key        目标资源文件名
     *
     * @return array    包含已拉取的文件信息。
     *                         成功时：  [
     *                                          [
     *                                              "hash" => "<Hash string>",
     *                                              "key" => "<Key string>"
     *                                          ],
     *                                          null
     *                                  ]
     *
     *                         失败时：  [
     *                                          null,
     *                                         Qiniu/Http/Error
     *                                  ]
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/fetch.html
     */
    public function fetch($url, $bucket, $key = null)
    {

        $resource = base64_urlSafeEncode($url);
        $to = entry($bucket, $key);
        $path = '/fetch/' . $resource . '/to/' . $to;
        
        yield $this->ioPost($path);
    }

    /**
     * 从镜像源站抓取资源到空间中，如果空间中已经存在，则覆盖该资源
     *
     * @param string $bucket     待获取资源所在的空间
     * @param string $key        代获取资源文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/prefetch.html
     */
    public function prefetch($bucket, $key)
    {
        $resource = entry($bucket, $key);
        $path = '/prefetch/' . $resource;
        list(, $error) = (yield $this->ioPost($path));
        yield $error;
    }

    /**
     * 在单次请求中进行多个资源管理操作
     *
     * @param string $operations     资源管理操作数组
     *
     * @return array 每个资源的处理情况，结果类似：
     *              [
     *                   { "code" => <HttpCode int>, "data" => <Data> },
     *                   { "code" => <HttpCode int> },
     *                   { "code" => <HttpCode int> },
     *                   { "code" => <HttpCode int> },
     *                   { "code" => <HttpCode int>, "data" => { "error": "<ErrorMessage string>" } },
     *                   ...
     *               ]
     * @link http://developer.qiniu.com/docs/v6/api/reference/rs/batch.html
     */
    public function batch($operations)
    {
        $params = 'op=' . implode('&op=', $operations);
        yield $this->rsPost('/batch', $params);
    }

    private function rsPost($path, $body = null)
    {
        $url = Config::RS_HOST . $path;
        yield $this->post($url, $body);
    }

    private function rsGet($path)
    {
        $url = Config::RS_HOST . $path;
        yield $this->get($url);
    }

    private function ioPost($path, $body = null)
    {
        $url = Config::IO_HOST . $path;
        yield $this->post($url, $body);
    }

    private function get($url)
    {
        $headers = $this->auth->authorization($url);
        /* @var $ret Response */
        $ret = (yield Client::get($url, $headers));
        if (!$ret->ok()) {
            yield array(null, new Error($url, $ret));
            return;
        }
        yield array($ret->json(), null);
    }

    /**
     * @param string $url
     * @param string $body
     * @return \Generator -> array
     */
    private function post($url, $body)
    {
        $contentType = "application/x-www-form-urlencoded";
        $headers = $this->auth->authorization($url, $body, $contentType);
        // TODO
        $headers["Content-Type"] = $contentType;
        // swoole_http_client 只允许body为以下两种类型
        // 空数组会报错http_build_query fail, so, ""
        if (!is_array($body) || !is_string($body)) {
            $body = "";
        }

        /* @var $ret Response */
        $ret = (yield Client::post($url, $body, $headers));
        if (!$ret->ok()) {
            yield array(null, new Error($url, $ret));
            return;
        }

        $r = ($ret->body === null) ? array() : $ret->json();
        yield array($r, null);
    }

    /**
     * 自定义头部的Post请求
     * @param string $url
     * @param string $body
     * @param array $headers
     * @return \Generator -> array
     */
    private function postWithCustomHeaders($url, $body, $headers)
    {
        // swoole_http_client 只允许body为以下两种类型
        if (!is_array($body) && !is_string($body)) {
            $body = "";
        }
        // 空数组会报错http_build_query fail, so, ""
        if (is_array($body) && empty($body)) {
            $body = "";
        }
        /* @var $ret Response */
        $ret = (yield Client::post($url, $body, $headers));
        if (!$ret->ok()) {
            yield array(null, new Error($url, $ret));
            return;
        }
        $r = ($ret->body === null) ? array() : $ret->json();
        yield array($r, null);
    }

    public static function buildBatchCopy($source_bucket, $key_pairs, $target_bucket)
    {
        return self::twoKeyBatch('copy', $source_bucket, $key_pairs, $target_bucket);
    }


    public static function buildBatchRename($bucket, $key_pairs)
    {
        return self::buildBatchMove($bucket, $key_pairs, $bucket);
    }


    public static function buildBatchMove($source_bucket, $key_pairs, $target_bucket)
    {
        return self::twoKeyBatch('move', $source_bucket, $key_pairs, $target_bucket);
    }


    public static function buildBatchDelete($bucket, $keys)
    {
        return self::oneKeyBatch('delete', $bucket, $keys);
    }


    public static function buildBatchStat($bucket, $keys)
    {
        return self::oneKeyBatch('stat', $bucket, $keys);
    }

    private static function oneKeyBatch($operation, $bucket, $keys)
    {
        $data = array();
        foreach ($keys as $key) {
            array_push($data, $operation . '/' . entry($bucket, $key));
        }
        return $data;
    }

    private static function twoKeyBatch($operation, $source_bucket, $key_pairs, $target_bucket)
    {
        if ($target_bucket === null) {
            $target_bucket = $source_bucket;
        }
        $data = array();
        foreach ($key_pairs as $from_key => $to_key) {
            $from = entry($source_bucket, $from_key);
            $to = entry($target_bucket, $to_key);
            array_push($data, $operation . '/' . $from . '/' . $to);
        }
        return $data;
    }
}
