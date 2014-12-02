<?php
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
    protected $url;
    protected $scheme;
    protected $host;
    protected $port = null;
    protected $page;
    protected $query = array();
    protected $auth = null;
    protected $cookie = null;
    protected $errno;
    protected $errstr;
    protected $sockettimeout = 30;
    protected $head;
    protected $body;
    protected $status;
    protected $header = array();
    protected $content;
    protected $addheader = '';
    protected $fp;
    protected $proxy = null;

    public function __construct($url)
    {
        $this->url = $url;
        $this->query['get'] = null;
        $this->query['post'] = null;
        $url_array = @parse_url($url);
        $this->scheme = $url_array['scheme'];
        $this->host = $url_array['host'];
        $this->port = isset($url_array['port']) ? $url_array['port'] : null;
        $this->page = isset($url_array['path']) ? $url_array['path'] : '/';
        $this->query['get'] = isset($url_array['query']) ? $url_array['query'] : null;
        if (isset($url_array['user'])) {
            $this->setAuth($url_array['user'], $url_array['pass']);
        }
    }

    public function __destruct()
    {
        if ($this->fp) {
            fclose($this->fp);
        }
    }

    public function run()
    {
        $this->connect();
        $this->write();
        $this->read();
    }

    public function connect()
    {
        if ($this->port === null) {
            $this->port = $this->scheme == 'https' ? 443 : 80;
        }
        $ip = @gethostbyname($this->proxy === null ? $this->host : $this->proxy['proxy']);
        $target = ($this->scheme == 'https' ? 'sslv2://' : '') . $ip;
        $this->fp = @fsockopen(
            $target,
            $this->proxy === null ? $this->port : $this->proxy['port'],
            $this->errno,
            $this->errstr,
            $this->sockettimeout
        );
        return $this->errno;
    }

    public function write()
    {
        $output = '';
        $page = str_replace(
            ' ',
            '+',
            $this->page . ($this->query['get'] !== null ? '?' . $this->query['get'] : '')
        );
        $url = $this->proxy === null ? $page : $this->scheme . '://' . $this->host . $page;
        $output .= $this->method . ' ' . $url . ' ' . $this->protocol . "\r\n";
        $output .= 'Connection: Close' . "\r\n";
        $output .= 'Host: ' . $this->host . "\r\n";
        if ($this->method == self::POST) {
            $output .= 'Content-Type: ' . self::URLENCODED . "\r\n";
            $output .= 'Content-Length: ' . strlen($this->query['post']) . "\r\n";
        }
        if ($this->auth !== null) {
            $output .= 'Authorization: Basic ' . $this->auth . "\r\n";
        }
        $output .= $this->addheader;
        if ($this->cookie !== null) {
            $output .= 'Cookie: ' . $this->cookie . "\r\n";
        }
        $output .= "\r\n";
        if ($this->query['post'] !== null) {
            $output .= $this->query['post'];
        }
        if ($this->fp) {
            fwrite($this->fp, $output);
        }
    }

    public function read()
    {
        $input = '';
        if ($this->fp) {
            while (!feof($this->fp)) {
                $input .= fgets($this->fp, 1024);
            }
        }
        $head_body_array = preg_split("/(\n\n|\r\r|\r\n\r\n)/", $input, 2);
        if (isset($head_body_array[0])) {
            $this->head = $head_body_array[0];
        }
        if (isset($head_body_array[1])) {
            $this->body = $head_body_array[1];
        }
        $header = preg_split("/\r?\n/", $this->head);
        $header_line = array_shift($header);
        list($dummy, $this->status, $dummy) = explode(' ', $header_line);
        while (($header_line = array_shift($header)) !== null) {
            list($header_key, $header_value) = explode(':', $header_line, 2);
            $header_key = str_replace('-', '_', strtoupper(trim($header_key)));
            if (isset($this->header[$header_key]) === true) {
                if (is_array($this->header[$header_key]) === true) {
                    $this->header[$header_key][] = trim($header_value);
                } else {
                    $temp_header_value = $this->header[$header_key];
                    $this->header[$header_key] = array();
                    $this->header[$header_key][] = $temp_header_value;
                    $this->header[$header_key][] = trim($header_value);
                }
            } else {
                $this->header[$header_key] = trim($header_value);
            }
        }
        $body = $this->body;
        if (strtolower($this->getHeader('Transfer-Encoding')) == 'chunked') {
            $body = $this->decodeChunkedBody($body);
        }
        if ($this->getHeader('Content-Encoding') !== null) {
            $body = $this->decodeContent($body);
        }
        $this->content = $body;
    }

    private function decodeChunkedBody($body)
    {
        $rest = $body;
        $length = 0;
        $body = '';
        while (strlen($rest) > 0) {
            list($len, $rest) = explode("\r\n", $rest, 2);
            $len = hexdec(trim($len));
            $length += $len;
            $body .= substr($rest, 0, $len);
            $rest = substr($rest, $len);
        }
        return $body;
    }

    private function decodeContent($body)
    {
        switch (strtolower($this->getHeader('Content-Encoding'))) {
            case 'gzip' :
                return gzinflate(substr($body, 10));
                break;
            case 'deflate' :
                return gzuncompress($body);
                break;
            case 'identity' :
                return $body;
                break;
            default :
                return $body;
                break;
        }
    }

    public function setMethod($method)
    {
        $this->method = strtoupper($method);
    }

    public function setAuth($user, $password)
    {
        $this->auth = base64_encode($user . ':' . $password);
    }

    public function addGet($key, $value = null)
    {
        if ($this->query['get'] === null) {
            $this->query['get'] = '';
        }

        if ($value === null) {
            $array = explode('=', $key);
            $key = $array[0];
            $value = (strlen($array[1]) > 0) ? $array[1] : '';
        }
        $this->query['get'] .= ($this->query['get'] !== '' ? '&' : '') . $key . "=" . $value;
    }

    public function addPost($key, $value = false)
    {
        if ($this->query['post'] === null) {
            $this->query['post'] = '';
        }
        $this->setMethod(self::POST);
        if ($value === false) {
            $array = explode('=', $key);
            $key = $array[0];
            $value = (strlen($array[1]) > 0) ? $array[1] : '';
        }
        $this->query['post'] .= ($this->query['post'] != '' ? '&' : '') . $key . "=" . $value;
    }

    public function useCookie($str)
    {
        $this->cookie = $str;
    }

    public function setTimeout($sec)
    {
        $this->sockettimeout = $sec;
    }

    public function addHeader($key, $value)
    {
        $this->addheader .= $key . ': ' . $value . "\r\n";
    }

    public function getHeader($key = null)
    {
        if ($key === null) {
            $out = '';
            foreach ($this->header as $k => $v) {
                if (is_array($v) === false) {
                    $out .= str_replace('_', '-', ucfirst(strtolower($k))) . ': ' . $v . "\n";
                } else {
                    foreach ($v as $v2) {
                        $out .= str_replace('_', '-', ucfirst(strtolower($k))) . ': ' . $v2 . "\n";
                    }
                }
            }
            return $out;
        } else {
            if (isset($this->header[str_replace('-', '_', strtoupper(trim($key)))])) {
                return $this->header[str_replace('-', '_', strtoupper(trim($key)))];
            } else {
                return null;
            }
        }
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getCookie()
    {
        $x = $this->getHeader('Set-Cookie');
        if ($x === null) {
            return null;
        } elseif (is_array($x)) {
            $parts = array();
            foreach ($x as $value) {
                list($part, $rest) = explode(';', $value, 2);
                $parts[] = trim($part);
            }
            $cookie = implode('; ', $parts);
            return $cookie;
        } elseif (isset($x)) {
            list($cookie, $rest) = explode(';', $x, 2);
            return trim($cookie);
        }
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function useProxy($proxy, $port, $user = '', $password = '')
    {
        $this->proxy['proxy'] = $proxy;
        $this->proxy['port'] = $port;
        if ($user != '' && $password != '') {
            $this->addHeader('Proxy-Authorization', 'Basic ' . base64_encode($user . ':' . $password));
        }
    }
}
