<?php
namespace Qiniu\Storage;

use Qiniu\Config;
use Qiniu\Http\Client;
use Qiniu\Http\Error;
use Qiniu\Http\Response;

final class FormUploader
{

    /**
     * 上传二进制流到七牛, 内部使用
     *
     * @param string $upToken 上传凭证
     * @param string $key 上传文件名
     * @param string $data 上传二进制流
     * @param Config $config
     * @param array $params 自定义变量，规格参考
     *                    http://developer.qiniu.com/docs/v6/api/overview/up/response/vars.html#xvar
     * @param string $mime 上传数据的mimeType
     * @param bool $checkCrc 是否校验crc32
     * @return array 包含已上传文件的信息，类似：
     *                                              [
     * "hash" => "<Hash string>",
     * "key" => "<Key string>"
     * ]
     */
    public static function put(
        $upToken,
        $key,
        $data,
        $config,
        $params,
        $mime,
        $checkCrc
    ) {

        $fields = array('token' => $upToken);
        if ($key === null) {
            $fname = 'filename';
        } else {
            $fname = $key;
            $fields['key'] = $key;
        }
        if ($checkCrc) {
            $fields['crc32'] = \Qiniu\crc32_data($data);
        }
        if ($params) {
            foreach ($params as $k => $v) {
                $fields[$k] = $v;
            }
        }

        /* @var $response Response */
        $response = (yield Client::multipartPost($config->getUpHost(), $fields, 'file', $fname, $data, $mime));

        if (!$response->ok()) {
            yield array(null, new Error($config->getUpHost(), $response));
            return;
        }
        yield array($response->json(), null);
    }

    /**
     * 上传文件到七牛，内部使用
     *
     * @param string $upToken 上传凭证
     * @param string $key 上传文件名
     * @param string $filePath 上传文件的路径
     * @param Config $config
     * @param array $params 自定义变量，规格参考
     *                    http://developer.qiniu.com/docs/v6/api/overview/up/response/vars.html#xvar
     * @param string $mime 上传数据的mimeType
     * @param bool $checkCrc 是否校验crc32
     * @return array 包含已上传文件的信息，类似：
     *                                              [
     *                                                  "hash" => "<Hash string>",
     *                                                  "key" => "<Key string>"
     *                                              ]
     * TODO
     * $fields = array('token' => $upToken, 'file' => self::createFile($filePath, $mime));
     * 是针对Curl扩展的方法,需要处理一下!!!
     */
    public static function putFile(
        $upToken,
        $key,
        $filePath,
        $config,
        $params,
        $mime,
        $checkCrc
    ) {

        $fields = array('token' => $upToken, 'file' => self::createFile($filePath, $mime));
        if ($key !== null) {
            $fields['key'] = $key;
        }
        if ($checkCrc) {
            $fields['crc32'] = \Qiniu\crc32_file($filePath);
        }
        if ($params) {
            foreach ($params as $k => $v) {
                $fields[$k] = $v;
            }
        }
        $fields['key'] = $key;
        $headers =array('Content-Type' => 'multipart/form-data');
        /* @var $response Response */
        $response = (yield Client::post($config->getUpHost(), $fields, $headers));
        if (!$response->ok()) {
            yield array(null, new Error($config->getUpHost(), $response));
            return;
        }
        yield array($response->json(), null);
    }

    private static function createFile($filename, $mime)
    {
        // PHP 5.5 introduced a CurlFile object that deprecates the old @filename syntax
        // See: https://wiki.php.net/rfc/curl-file-upload
        if (function_exists('curl_file_create')) {
            return curl_file_create($filename, $mime);
        }

        // Use the old style if using an older version of PHP
        $value = "@{$filename}";
        if (!empty($mime)) {
            $value .= ';type=' . $mime;
        }

        return $value;
    }
}
