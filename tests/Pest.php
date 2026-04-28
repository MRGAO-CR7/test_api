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

// User tests touch Eloquent (forceFill against a model with datetime/array
// casts) which calls into the Laravel container for cast resolution, so we
// need a booted application even though no migrations / DB calls happen.
pest()->extend(Tests\TestCase::class)->in('Unit/User');
