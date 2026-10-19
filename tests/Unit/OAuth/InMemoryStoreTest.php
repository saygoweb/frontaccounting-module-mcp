<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\InMemoryStore;
use FA\Mcp\OAuth\Store;

class InMemoryStoreTest extends StoreContractTest
{
    protected function createStore(): Store
    {
        return new InMemoryStore();
    }
}
