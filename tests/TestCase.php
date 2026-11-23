<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\UsesPanels;

abstract class TestCase extends BaseTestCase
{
    use UsesPanels;
}
