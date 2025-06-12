<?php

use PHPUnit\Framework\TestCase;
use App\Models\Enums\PirepState;

class DeleteCharterFlightsTest extends TestCase
{
    public function testNullPirepCheck()
    {
        $pirep = null;
        $this->assertFalse($pirep && $pirep->state == PirepState::IN_PROGRESS);
    }
}
