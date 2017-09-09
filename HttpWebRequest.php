<?php

namespace HttpWebRequest;

class HttpWebRequest
{
    const GET = 'GET';
    const POST = 'POST';
    const PUT = 'PUT';
    const HEAD = 'HEAD';
    const DELETE = 'DELETE';
    const TRACE = 'TRACE';
    const OPTIONS = 'OPTIONS';
    const CONNECT = 'CONNECT';
    const HTTP_11 = 'HTTP/1.1';
    const HTTP_10 = 'HTTP/1.0';
    const URLENCODED = 'application/x-www-form-urlencoded';
    const FORMDATA = 'multipart/form-data';

    protected $method = self::GET;
    protected $protocol = self::HTTP_11;
    protected $url = null;
    protected $urlParts = null;

    public function __construct($url = null)
    {
        if (null !== $url) {
            $this->setUrl($url);
        }
    }

    public function __destruct()
    {
    }

    public function setUrl($url)
    {
        $this->url = $url;
    }

    public function parseUrl($url = null)
    {
        if (null === $url) {
            $url = $this->url;
        }
        if (null === $url) {
            throw new \Exception('The URL is not set.');
        }
        $urlParts = @parse_url($this->url);
        if (false === $urlParts) {
            throw new \Exception('The URL can not be parsed.');
        }
        $this->urlParts = $urlParts;
        return $urlParts;
    }

}
