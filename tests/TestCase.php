<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase drops tables but, by default, not Postgres enum types.
     * A migration that renames enum values (participant_status) would then
     * leave the next run's base migrations facing the renamed type.
     */
    protected bool $dropTypes = true;
}
