<?php

use Goldnead\Marketing\Tests\Integration\SiblingsTestCase;
use Goldnead\Marketing\Tests\MigrationPathTestCase;
use Goldnead\Marketing\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(SiblingsTestCase::class)->in('Integration');

// The migration tests drive migrations by hand, against a database of their
// own — see MigrationPathTestCase. The rest of the suite meets a database that
// RefreshDatabase has already migrated to head, which is the one shape a
// migration can never be wrong about.
uses(MigrationPathTestCase::class)->in('Migrations');
