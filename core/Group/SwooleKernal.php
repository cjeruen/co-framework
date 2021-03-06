<?php

namespace Group;

use Group\App\App;
use Group\Coroutine\Scheduler;
use Group\Container\Container;
use Group\Config\Config;
use Group\Process\RedisRegistryProcess;
use swoole_process;
use swoole_http_server;

class SwooleKernal
{   
    protected $http;

    protected $scheduler;

    protected $app;

    protected $pidPath;

    protected $registryAddress;

    public function init($check = true)
    {   
        $this->pidPath = __ROOT__."runtime/pid";
        if ($check) $this->checkStatus();

        $host = Config::get('app::host') ? : "127.0.0.1";
        $port = Config::get('app::port') ? : 9777;
        $setting = Config::get('app::setting');

        $this->http = new swoole_http_server($host, $port);
        $this->http->set($setting);

        $this->http->on('Start', [$this, 'onStart']);
        $this->http->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->http->on('WorkerStop', [$this, 'onWorkerStop']);
        $this->http->on('WorkerExit', [$this, 'onWorkerExit']);
        $this->http->on('Request', [$this, 'onRequest']);
        $this->http->on('shutdown', [$this, 'onShutdown']);

        $this->addProcesses();
        
        $this->subscribe();

        $this->start();
    }

    public function onStart($serv)
    {
        if (PHP_OS !== 'Darwin') {
            swoole_set_process_name("php http server: master");
        }

        echo "HTTP Server Start...".PHP_EOL;

        $pid = $serv->master_pid;
        $this->mkDir($this->pidPath);
        file_put_contents($this->pidPath, $pid);
    }

    public function onShutdown($serv)
    {   
        @unlink($this->pidPath);
        echo "HTTP Server Shutdown...".PHP_EOL;
    }

    public function onWorkerStart($serv, $workerId)
    {   
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        //$this->scheduler = new Scheduler();
        $this->maxTaskId = 0;
        $this->app = new App();
        $this->app->init();
        //设置不同进程名字,方便grep管理
        if (PHP_OS !== 'Darwin') {
            swoole_set_process_name("php http server: worker");
        }
        
        //启动的时候拉取一次服务
        $this->getServicesList();

        echo "HTTP Worker Start...".PHP_EOL;
    }

    public function onWorkerStop($serv, $workerId) {}

    public function onWorkerExit($serv, $workerId)
    {
        $this->app->releasePool();
    }

    public function onRequest($request, $response)
    {
        $request->get = isset($request->get) ? $request->get : [];
        $request->post = isset($request->post) ? $request->post : [];
        $request->cookie = isset($request->cookie) ? $request->cookie : [];
        $request->files = isset($request->files) ? $request->files : [];
        $request->server = isset($request->server) ? $request->server : [];
        $request->server['REQUEST_URI'] = isset($request->server['request_uri']) ? $request->server['request_uri'] : '';
        preg_match_all("/^(.+\.php)(\/.*)$/", $request->server['REQUEST_URI'], $matches);

        $request->server['REQUEST_URI'] = isset($matches[2][0]) ? $matches[2][0] : $request->server['REQUEST_URI'];
        foreach ($request->server as $key => $value) {
            $request->server[strtoupper($key)] = $value;
        }

        if ($request->server['request_uri'] == '/favicon.ico' || substr($request->server['REQUEST_URI'], 0, 7) == "/assets") {
            $response->end();
            return;
        }
        
        if ($this->maxTaskId >= PHP_INT_MAX) {
            $this->maxTaskId = 0;
        }
        $taskId = ++$this->maxTaskId;
        $container = new Container();
        $task = new \Group\Coroutine\Task($taskId, $container, $this->app->terminate($request, $response));
        $task->run();

        unset($container);
        unset($task);
        unset($request);
        unset($response);
        //$this->fix_gpc_magic($request);
        // $this->scheduler->newTask($this->app->terminate($request, $response));
        // $this->scheduler->run();
    }

    public function start()
    {   
        $this->http->start();
    }

    public function addProcesses()
    {
        $processes = Config::get('app::process') ? : [];
        foreach ($processes as $process) {
            $p = new $process($this->http);
            $this->http->addProcess($p->register());
        }
    }

    public function subscribe()
    {   
        $registry = $this->getRegistryProcess();
        if (!$registry) return;

        $this->http->on('pipeMessage', [$this, 'onPipeRegistryMessage']);
        $registry->setServer($this->http);
        $this->http->addProcess($registry->subscribe());
    }

    public function onPipeRegistryMessage($serv, $src_worker_id, $data)
    {   
        list($service, $addresses) = explode("::", $data);
        $addresses = json_decode($addresses, true);
        if (empty($addresses)) {
            \StaticCache::set("ServiceList:".$service, null, false);
            \StaticCache::set("Service:".$service, null, false);
            return;
        }

        shuffle($addresses);
        \StaticCache::set("ServiceList:".$service, $addresses, false);

        //如果当前服务地址已经失效
        $current = \StaticCache::get("Service:".$service, false);
        if ($addresses && !in_array($current, $addresses)) {
            \StaticCache::set("Service:".$service, $addresses[0], false);
        }
    }

    private function getRegistryProcess()
    {
        preg_match("/^(.*):\/\/(.*):(.*)$/", Config::get('service::registry_address'), $matches);
        if (!$matches) {
            return false;
        }

        switch ($matches[1]) {
            case 'redis':
                return new \Group\Process\RedisRegistryProcess($matches[2], $matches[3]);
                break;
            default:
                break;
        }

        return false;
    }

    private function unSubscribe()
    {   
        $registry = $this->getRegistryProcess();
        if (!$registry) return;
        $registry->unSubscribe();
    }

    private function getServicesList()
    {   
        $registry = $this->getRegistryProcess();
        if (!$registry) return;
        $registry->getList();
    }

    private function mkDir($dir)
    {
        $parts = explode('/', $dir);
        $file = array_pop($parts);
        $dir = '';
        foreach ($parts as $part) {
            if (!is_dir($dir .= "$part/")) {
                 mkdir($dir);
            }
        }
    }

    private function checkStatus()
    {   
        $args = getopt('s:');
        if(isset($args['s'])) {

            if (!file_exists($this->pidPath)) {
                echo "pid不存在".PHP_EOL;
                exit;
            }

            switch ($args['s']) {
                case 'reload':
                    $pid = file_get_contents($this->pidPath);
                    echo "当前进程".$pid.PHP_EOL;
                    echo "热重启中".PHP_EOL;
                    if ($pid) {
                        if (swoole_process::kill($pid, 0)) {
                            swoole_process::kill($pid, SIGUSR1);
                        }
                    }
                    echo "重启完成".PHP_EOL;
                    swoole_process::daemon(true);
                    break;
                case 'stop':
                    $this->serverStop();
                    break;
                case 'restart':
                    $this->serverStop();
                    echo "正在启动...".PHP_EOL;
                    $this->init(false);
                    echo "启动完成".PHP_EOL;
                    break;
                default:
                    break;
            }
            exit;
        }
    }

    private function serverStop()
    {
        $this->unSubscribe();
        $pid = file_get_contents($this->pidPath);
        echo "当前进程".$pid.PHP_EOL;
        echo "正在关闭".PHP_EOL;
        if ($pid) {
            if (swoole_process::kill($pid, 0)) {
                swoole_process::kill($pid, SIGTERM);
            }
        }

        while (file_exists($this->pidPath)) {
            sleep(1);
        }
        echo "关闭完成".PHP_EOL;
    }

    public function fix_gpc_magic($request)
    {
        static $fixed = false;
        if (!$fixed && ini_get('magic_quotes_gpc')) {

            array_walk($request->get, '_fix_gpc_magic');
            array_walk($request->post, '_fix_gpc_magic');
            array_walk($request->cookie, '_fix_gpc_magic');
            array_walk($request->files, '_fix_gpc_magic_files');

        }
        $fixed = true;
    }

    private static function _fix_gpc_magic(&$item)
    {
        if (is_array($item)) {
            array_walk($item, '_fix_gpc_magic');
        }
        else {
            $item = stripslashes($item);
        }
    }

    private static function _fix_gpc_magic_files(&$item, $key)
    {
        if ($key != 'tmp_name') {

            if (is_array($item)) {
              array_walk($item, '_fix_gpc_magic_files');
            }
            else {
              $item = stripslashes($item);
            }

        }
    }
}

