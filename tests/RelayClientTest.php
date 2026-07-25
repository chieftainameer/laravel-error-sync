<?php
// tests/RelayClientTest.php

namespace NativePHP\ErrorSync\Tests;

use Orchestra\Testbench\TestCase;
use NativePHP\ErrorSync\Services\RelayClient;

class RelayClientTest extends TestCase
{
    public function test_it_can_be_instantiated()
    {
        $client = new RelayClient('http://localhost:9999');
        $this->assertInstanceOf(RelayClient::class, $client);
    }

    public function test_it_returns_url()
    {
        $client = new RelayClient('http://192.168.1.100:9999');
        $this->assertEquals('http://192.168.1.100:9999', $client->getUrl());
    }

    public function test_ping_returns_false_when_server_is_down()
    {
        $client = new RelayClient('http://localhost:19999'); // Nothing running here
        $this->assertFalse($client->ping());
    }
}
