<?php

namespace Tests\MySql;

use Illuminate\Foundation\Application;
use Tests\Support\MySqlSafety;
use Tests\Support\MySqlSuiteGuard;
use Tests\TestCase;

require_once __DIR__.'/../Support/mysql-preflight.php';

abstract class MySqlTestCase extends TestCase
{
    final public function createApplication(): Application
    {
        MySqlSafety::testClass(static::class);
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        return MySqlSafety::application('test', true);
    }

    final protected function setUp(): void
    {
        // These checks run before Laravel can initialize testing traits.
        MySqlSafety::testClass(static::class);
        if (! class_exists(MySqlSuiteGuard::class, false)
            || ! MySqlSuiteGuard::$executionChecked) {
            $this->fail('Test class '.static::class.': mandatory MySQL suite bootstrap/extension did not validate execution.');
        }
        // Direct PHPUnit invocation must pass exactly the same guards.
        if (getenv('ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN') !== '1') {
            $this->fail('Test class '.static::class.': Gate C execution approval/opt-in required; no Laravel bootstrap or DB access performed.');
        }
        MySqlSafety::environment();
        parent::setUp();
        $connection = MySqlSafety::connection($this->app);
        MySqlSafety::schema($connection->getPdo());
        MySqlSafety::session($connection->getPdo(), $connection->getConfig());
        $connection->beginTransaction();
        $this->beforeApplicationDestroyed(function () use ($connection): void {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->disconnect();
        });
    }
}
