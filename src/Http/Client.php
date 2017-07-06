<?php/** * Created by PhpStorm. * User: chuxiaofeng * Date: 16/7/24 * Time: 下午5:11 */namespace ZanPHP\SDK\Qiniu\Http;use ZanPHP\SDK\Qiniu\Config;use Zan\Framework\Network\Common\Exception\HttpClientTimeoutException;use Zan\Framework\Network\Common\Response as ZanResponse;final class Client{    /**     * @param string $url     * @param array $headers     * @param int $timeout     * @return Response     */    public static function get($url, array $headers = [], $timeout = 5000) {        $request = new Request("GET", $url, $headers, strval(null), $timeout);        yield static::sendRequest($request);    }    /**     * @param string $url     * @param string $body     * @param array $headers     * @param int $timeout     * @return Response     * @throws HttpClientTimeoutException     */    public static function post($url, $body, array $headers = [], $timeout = 5000) {        $request = new Request("POST", $url, $headers, $body, $timeout);        yield static::sendRequest($request);    }    /**     * @param string $url     * @param array $fields     * @param string $name     * @param string $fileName     * @param string $fileBody     * @param string $mimeType     * @param array $headers     * @param int $timeout     * @return Response     */    public static function multipartPost(        $url,        array $fields,        $name,        $fileName,        $fileBody,        $mimeType = null,        array $headers = [],        $timeout = 5000    ) {        $mimeType = empty($mimeType) ? 'application/octet-stream' : $mimeType;        $fileName = self::escapeQuotes($fileName);        $mimeBoundary = md5(microtime());        $data = [];        foreach ($fields as $key => $val) {            $data[] = "--$mimeBoundary";            $data[] = "Content-Disposition: form-data; name=\"$key\"";            $data[] = '';            $data[] = $val;        }        $data[] = "--$mimeBoundary";        $data[] = "Content-Disposition: form-data; name=\"$name\"; filename=\"$fileName\"";        $data[] = "Content-Type: $mimeType";        $data[] = "";        $data[] = $fileBody;        $data[] = "--$mimeBoundary--";        $data[] = "";        $body = implode("\r\n", $data);        $headers["Content-Type"] = "multipart/form-data; boundary=$mimeBoundary";        $request = new Request("POST", $url, $headers, $body, $timeout);        yield static::sendRequest($request);    }    /**     * @param Request $request     * @return Response     */    private static function sendRequest(Request $request) {        $start = microtime(true);        try {            // !!! override Ua            $request->headers["User-Agent"] = static::userAgent();            /* @var $res ZanResponse */            $res = (yield $request->toZanHttpClient());            $duration = round(microtime(true)-$start, 3);            if ($res === null) {                yield new Response(-1, $duration, [], null, "Internal Error Or Request Timeout");            } else {                yield new Response($res->getStatusCode(), $duration, $res->getHeaders(), $res->getBody(), null);            }        } catch (HttpClientTimeoutException $ex) {            $duration = round(microtime(true)-$start, 3);            yield new Response(-1, $duration, [], null, "Request Timeout");        } catch (\Throwable $t) { // 向上兼容PHP7, 低版本此处不会报错,会被忽略            $duration = round(microtime(true)-$start, 3);            yield new Response(-1, $duration, [], null, $t->getMessage());        } catch (\Exception $ex) {            $duration = round(microtime(true)-$start, 3);            yield new Response(-1, $duration, [], null, $ex->getMessage());        }    }    /**     * @return string     */    private static function userAgent() {        $sdkInfo = "QiniuPHP/" . Config::SDK_VER;        $systemInfo = php_uname("s");        $machineInfo = php_uname("m");        $envInfo = "($systemInfo/$machineInfo)";        $phpVer = phpversion();        $ua = "$sdkInfo $envInfo PHP/$phpVer";        return $ua;    }    /**     * @param string $str     * @return string mixed     */    private static function escapeQuotes($str) {        $find = ["\\", "\""];        $replace = ["\\\\", "\\\""];        return str_replace($find, $replace, $str);    }}