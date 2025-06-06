<?php

namespace App\Contracts;
class Listener {}

namespace App\Models;
class Flight {
    public $id = 1;
    public $user_id = 1;
    public $visible = true;
    public static function where($field, $value) {
        return new class {
            public function get() {
                return [new \App\Models\Flight];
            }
        };
    }
    public function delete() {}
    public function save() {}
}

class Pirep {
    public static function where($params) {
        return new class {
            public function first() { return null; }
        };
    }
}

class Bid {
    public static function where($field, $value) {
        return new class {
            public function count() { return 0; }
        };
    }
}

namespace App\Models\Enums;
class PirepState { const IN_PROGRESS = 1; }
class PirepStatus {}

namespace Modules\SmartCARS3phpVMS7Api\Tests;

use Modules\SmartCARS3phpVMS7Api\Listeners\DeleteCharterFlights;
use PHPUnit\Framework\TestCase;

class DeleteCharterFlightsTest extends TestCase
{
    public function testHandleDoesNotThrowWhenNoPirep(): void
    {
        $listener = new DeleteCharterFlights();
        $listener->handle((object)[]);
        $this->assertTrue(true);
    }
}

