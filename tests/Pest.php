<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest binds the closures below to a TestCase. Feature tests boot the full
| application (and respect phpunit.xml's :memory: sqlite setup), Unit tests
| are pure PHP without the framework.
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit/Jwt');
