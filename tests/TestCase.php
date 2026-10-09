<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $application = parent::createApplication();
        if ($application['config']->get('database.default') !== 'mysql'
            || $application['config']->get('database.connections.mysql.database') !== 'pingofin_test') {
            throw new \RuntimeException('Tests may migrate only the dedicated MySQL pingofin_test database.');
        }

        return $application;
    }
}
