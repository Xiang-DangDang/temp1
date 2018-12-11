<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class HttpTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'http:test {url} {--repeat=-1} {--concurrent=5} {--method=get}';

    protected $http_success;

    protected $http_error;

    protected $http_timeout;

    protected $proxy_ips;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->http_success = new \swoole_atomic(0);
        $this->http_error = new \swoole_atomic(0);
        $this->http_timeout = new \swoole_atomic(0);
        parent::__construct();

        declare (ticks = 1);

        pcntl_signal(SIGINT, function () {
            $this->signal();
        });

        \pcntl_signal(SIGQUIT, function () {
            $this->signal();
        });

        $this->proxy_ips = $this->getProxyIps();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $repeat = $this->option('repeat');
        $url = $this->argument('url');
        $concurrent = $this->option('concurrent');
        $method = $this->option('method');
        $this->info('http 并发请求 测试');

        $concurrent = 200;
        while (true) {
            $process = new \swoole_process(function () use ($concurrent, $url, $method) {
                for ($i = 1; $i <= $concurrent; $i++) {
                    $this->http($url, function ($response) {
                        if ($response->statusCode == 200) {
                            $this->http_success->add(1);
                        } elseif ($response->statusCode > 0) {
                            $this->http_error->add(1);
                        } else {
                            $this->http_timeout->add(1);
                        }
                    }, $method);
                }

                \swoole_event_wait();
            }, false);

            $pid = $process->start();

            $concurrent++;
            \swoole_process::wait();
            usleep(200 * 1000);
            $accuracy = $this->http_success->get() / ($concurrent - 1);
            if ($concurrent > 1) {
                echo sprintf("当前并发 [%d] success [%d] error [%d] timeout [%d] accuracy [%2f] \r", ($concurrent - 1), $this->http_success->get(), $this->http_error->get(), $this->http_timeout->get(), $accuracy);

                // if ($accuracy < 0.5) {
                //     $this->warn("\n目标地址 [$url] 响应率低于 50% ....");
                //     exit;
                // }

                $this->http_success->set(0);
                $this->http_error->set(0);
                $this->http_timeout->set(0);
            }
        }
    }

    /**
     * 发起一个异步 http 请求
     *
     * @param string $url
     * @param $callback
     * @param string $method
     */
    private function http($url, $callback = null, $method = 'get')
    {
        $httpArray = $this->parse_url($url);

        $cli = new \swoole_http_client($httpArray['host'], $httpArray['port'], $httpArray['enable_ssl']);

        $cli->setHeaders([
            'Host' => $httpArray['host'],
            "User-Agent" => $this->getRandUserAgent(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml,application/json',
            'Accept-Encoding' => 'gzip',
            'Cookie' => 'ASP.NET_SessionId=cjafiec1zolwqicz1v4myqvn; BentleyAG=ID=59&UserName=cs01&Password=e04755387e5b5968ec213e41f70c1d46&Flag=1',
        ]);

        $ip = $this->getProxyIp();
        $opt = ['keep_alive' => true, 'timeout' => 5, 'socks5_host' => $ip['ip'], 'socks5_port' => $ip['port']];
        // $cli->set(['keep_alive' => true, 'timeout' => 5]);
        $cli->set($opt);

        $ccallback = function ($response) use ($callback) {
            $callback($response);
            $response->close();
        };

        if ($method == 'get') {
            $cli->get($httpArray['path'] . (empty($httpArray['query']) ? '' : '?' . $httpArray['query']), $ccallback);
        } else {
            $cli->post($httpArray['path'], ['id' => '1'], $ccallback);
        }
    }

    /**
     * http url 链接解析
     *
     * @param string $url
     *
     * @return array
     */
    private function parse_url(string $url)
    {
        $scheme = substr($url, 0, 5);

        if (!preg_match('/:\/\//', $url)) {
            $url = 'http://' . $url;
        }

        $httpArray = parse_url($url);

        if ($httpArray['scheme'] != 'http' && $httpArray['scheme'] != 'https') {
            throw new \Exception('URL:[' . $url . ']( 协议不支持 )');
        }

        if ($httpArray['scheme'] == 'https') {
            array_key_exists('port', $httpArray) || $httpArray['port'] = 443;
            $httpArray['enable_ssl'] = true;
        } else {
            array_key_exists('port', $httpArray) || $httpArray['port'] = 80;
            $httpArray['enable_ssl'] = false;
        }

        if (!array_key_exists('path', $httpArray)) {
            $httpArray['path'] = '/';
        }

        return $httpArray;
    }

    /**
     * 处理 php 信号
     */
    private function signal()
    {
        $this->line("process exit ....");
        // \Swoole\Event::wait();
        // $this->line($this->http_success->get());
        // $this->line($this->http_error->get());
        exit;
    }

    /**
     * 获取随机useragent
     */
    private function getRandUserAgent()
    {
        $arr = array(
            'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/536.11 (KHTML, like Gecko) Chrome/20.0.1132.11 TaoBrowser/2.0 Safari/536.11',
            'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.71 Safari/537.1 LBBROWSER',
            'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729;Media Center PC 6.0; .NET4.0C; .NET4.0E; LBBROWSER)',
            'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; QQDownload 732; .NET4.0C; .NET4.0E; LBBROWSER)',
            'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.84 Safari/535.11 LBBROWSER',
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729;Media Center PC 6.0; .NET4.0C; .NET4.0E)',
            'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729;Media Center PC 6.0; .NET4.0C; .NET4.0E; QQBrowser/7.0.3698.400)',
            'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; QQDownload 732; .NET4.0C; .NET4.0E)',
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4.0; SV1; QQDownload 732; .NET4.0C; .NET4.0E; 360SE)',
            'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; QQDownload 732; .NET4.0C; .NET4.0E)',
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729;Media Center PC 6.0; .NET4.0C; .NET4.0E)',
            'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1',
            'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1',
            'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; QQDownload 732; .NET4.0C; .NET4.0E)',
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729;Media Center PC 6.0; .NET4.0C; .NET4.0E)',
            'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729;Media Center PC 6.0; .NET4.0C; .NET4.0E)',
            'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.84 Safari/535.11 SE 2.X MetaSr 1.0',
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4.0; SV1; QQDownload 732; .NET4.0C; .NET4.0E; SE 2.X MetaSr 1.0)',
            'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:16.0) Gecko/20121026 Firefox/16.0',
            'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:2.0b13pre) Gecko/20110307 Firefox/4.0b13pre',
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:16.0) Gecko/20100101 Firefox/16.0',
            'Mozilla/5.0 (Windows; U; Windows NT 6.1; zh-CN; rv:1.9.2.15) Gecko/20110303 Firefox/3.6.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.64 Safari/537.11',
            'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.64 Safari/537.11',
            'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.16 (KHTML, like Gecko) Chrome/10.0.648.133 Safari/534.16',
            'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Win64; x64; Trident/5.0)',
            'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0)',
            'Mozilla/5.0 (X11; U; Linux x86_64; zh-CN; rv:1.9.2.10) Gecko/20100922 Ubuntu/10.10 (maverick) Firefox/3.6.10',
            'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.221 Safari/537.36 SE 2.X MetaSr 1.0',
        );
        return $arr[array_rand($arr)];
    }

    /**
     * 获取代理 IP 列表
     */
    private function getProxyIps()
    {
        $client = new Client([
            'base_uri' => 'http://webapi.http.zhimacangku.com',
            'timeout' => 2.0,
        ]);

        $response = $client->get('/getip?num=200&type=2&pro=0&city=0&yys=0&port=2&pack=36734&ts=1&ys=1&cs=1&lb=1&sb=0&pb=4&mr=1&regions=');
        if ($response->getStatusCode() != '200') {
            throw new \Exception("get ips, network error ....");
        }

        $ipdata = json_decode((string)$response->getBody(), true);
        $ipdata = $ipdata['data'];

        if (empty($ipdata)) {
            throw new \Exception("get ips, ip is empty ....");
        }
        return $ipdata;
    }

    /**
     * 随机从 ip 池子中获取一个 ip
     */
    private function getProxyIp()
    {
        return $this->proxy_ips[array_rand($this->proxy_ips, 1)];
    }
}
