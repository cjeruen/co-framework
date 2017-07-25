<?php

namespace Group\Async\Client;

use swoole_client;

class Tcp extends Base
{
    protected $ip;

    protected $port;

    protected $data;

    protected $timeout = 5;

    protected $calltime;

    protected $client;

    protected $isInit = false;

    protected $isFinish = false;

    protected $setting = [];

    public function __construct($ip, $port)
    {
        $this->ip = $ip;
        $this->port = $port;

        $this->client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        $this->client->set($this->setting);
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    public function setData($data)
    {
        $this->data = $data;
    }

    public function parse($data)
    {
        return $data;
    }

    public function call(callable $callback)
    {
        if (!$this->isInit) {
            $this->client->on("connect", function ($cli) use ($callback) {
                $this->calltime = microtime(true);
                $cli->send($this->data);

                swoole_timer_after(floatval($this->timeout) * 1000, function () use ($callback) {
                    if (!$this->isFinish) {
                        $this->client->close();
                        $this->isFinish = true;
                        call_user_func_array($callback, array('response' => false, 'calltime' => $this->timeout, 'error' => 'timeout'));
                    }
                });
            });

            $this->client->on('close', function ($cli) {
            });

            $this->client->on('error', function ($cli) use ($callback) {
                $this->calltime = microtime(true) - $this->calltime;
                call_user_func_array($callback, array('response' => false, 'error' => socket_strerror($cli->errCode), 'calltime' => $this->calltime));
            });

            $this->client->on("receive", function ($cli, $data) use ($callback) {
                if (!$this->isFinish) {
                    $data = $this->parse($data);
                    $this->isFinish = true;
                    $this->calltime = microtime(true) - $this->calltime;
                    call_user_func_array($callback, array('response' => $data, 'error' => null, 'calltime' => $this->calltime));
                    $cli->close();
                }
            });
            $this->isInit = true;
        }

        $this->isFinish = false;
        $this->client->connect($this->ip, $this->port, $this->timeout, 1);
    }
}