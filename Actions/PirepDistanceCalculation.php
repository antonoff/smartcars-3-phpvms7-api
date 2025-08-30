<?php

namespace Modules\SmartCARS3phpVMS7Api\Actions;

use App\Models\Pirep;
use App\Services\GeoService;
use Illuminate\Support\Facades\Log;
use League\Geotools\Coordinate\Coordinate;
use League\Geotools\Geotools;

class PirepDistanceCalculation
{
    /**
     * Calculate the total great-circle distance for a PIREP.
     *
     * If the PIREP has ACARS path points, the distance is the sum of great-circle distances
     * between consecutive path points. If there are no path points, the distance is the
     * great-circle distance between the departure and arrival airports.
     *
     * Note: If the PIREP has exactly one path point the method returns 0 (no leg to measure).
     *
     * @param Pirep $pirep The PIREP model to calculate distance for (expects dpt_airport, arr_airport and acars relationship).
     * @return float Total distance in the units configured by `phpvms.internal_units.distance` (defaults to 'nmi').
     */
    public static function calculatePirepDistance(Pirep $pirep) : float
    {
        //
        $path_points = $pirep->acars()->get();

        $distance = 0;
        $units = config('phpvms.internal_units.distance', 'nmi');
        Log::debug("PathPoints:".$path_points->count());
        if ($path_points->count() == 0) {
            $geotools = new Geotools();
            $start = new Coordinate([$pirep->dpt_airport->lat, $pirep->dpt_airport->lon]);
            $end = new Coordinate([$pirep->arr_airport->lat, $pirep->arr_airport->lon]);
            $dist = $geotools->distance()->setFrom($start)->setTo($end);

            return $dist->in($units)->greatCircle();
        }

        for($i = 0; $i + 1 < $path_points->count(); $i++) {
            $from = $path_points[$i];
            $to = $path_points[$i + 1];

            $geotools = new Geotools();
            $start = new Coordinate([$from->lat, $from->lon]);
            $end = new Coordinate([$to->lat, $to->lon]);
            $dist = $geotools->distance()->setFrom($start)->setTo($end);
            $distance += $dist->in($units)->greatCircle();

        }

        Log::debug("Pirep Distance Calculation: ".$distance);
        return $distance;
    }
}
