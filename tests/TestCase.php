<?php

declare(strict_types=1);

namespace ParcelTrap\AusPost\Tests;

use ParcelTrap\AusPost\AusPostServiceProvider;
use ParcelTrap\ParcelTrapServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ParcelTrapServiceProvider::class, AusPostServiceProvider::class];
    }
}
