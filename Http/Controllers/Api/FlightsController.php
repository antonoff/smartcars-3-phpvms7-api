<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Events\PirepPrefiled;
use App\Models\Acars;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Bid;
use App\Models\Enums\AcarsType;
use App\Models\Enums\FareType;
use App\Models\Enums\FlightType;
use App\Models\Enums\PirepSource;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\PirepFare;
use App\Models\Subfleet;
use App\Models\User;
use App\Services\BidService;
use App\Services\FareService;
use App\Services\FlightService;
use App\Services\PirepService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS3phpVMS7Api\Models\ActiveFlight;
use Modules\SmartCARS3phpVMS7Api\Models\PirepLog;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class FlightsController extends Controller
{
    public function __construct(public FlightService $flightService, public FareService $fareService, public BidService $bidService, public PirepService $pirepService)
    {
    }

    /**
     * Just send out a message
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function book(Request $request)
    {
        $flight = Flight::find($request->input('flightID'));
        $user = User::find($request->get('pilotID'));
        $bid = $this->bidService->addBid($flight, $user);
        return response()->json(["bidID" => $bid->id]);
    }
    public function bookings(Request $request)
    {
        $bids = $this->bidService->findBidsForUser(User::find($request->get('pilotID')));
        $output = [];

        foreach ($bids as $bid) {
            // Aircraft Array
            $aircraft = [];
            //if ($bid->flight->simbrief) {
            //    $aircraft[] = $bid->flight->simbrief->aircraft->id;
            //} else {
            //foreach ($bid->flight->subfleets as $subfleet) {
            //    foreach ($subfleet->aircraft as $acf) {
            //        $aircraft[] = $acf['id'];
            //    }
            //}
            //}
            $output[] = [
                "bidID"            => $bid->id,
                "number"           => $bid->flight->flight_number,
                "code"             => $bid->flight->airline->code,
                "departureAirport" => $bid->flight->dpt_airport_id,
                "arrivalAirport"   => $bid->flight->arr_airport_id,
                "route"            => null,
                "flightLevel"      => $bid->flight->level,
                "distance"         => $bid->flight->distance,
                "departureTime"    => $bid->flight->dpt_time,
                "arrivalTime"      => $bid->flight->arr_time,
                "flightTime"       => $bid->flight->flight_time,
                "daysOfWeek"       => $bid->flight->days,
                "flightID"         => $bid->flight->id,
                "type"             => $this->flightType($bid->flight->flight_type),
                "aircraft"         => $aircraft
            ];
        }

        return response()->json($output);
    }
    public function cancel(Request $request)
    {
        $af = ActiveFlight::where('bid_id', $request->input('bidID'))->first();
        $pirep = Pirep::find($af->pirep_id);
        $this->pirepService->cancel($pirep);
        $af->delete();
    }
    public function charter(Request $request)
    {
        $attrs = [
            'flight_number'  => $request->flight_number,
            'airline_id'     => $request->airline_id,
            'dpt_airport_id' => $request->dpt_airport_id,
            'arr_airport_id' => $request->arr_airport_id,
            'aircraft_id'    => $request->input('aircraftID'),
            'source'         => PirepSource::ACARS,
            'source_name'    => "smartCARS 3"
        ];
        // Check if the pirep already exists.
        $existing = Pirep::where(['user_id' => $request->id, 'state' => PirepState::IN_PROGRESS])->first();
        if (is_null($existing)) {
            try {
                $pirep = $this->pirepService->prefile(Auth::user(), $attrs);
            } catch (\Exception $e) {
                logger($e);
                return response()->json(['message' => $e->getMessage()], 500);
            }
            return response()->json($pirep);
        }
        return response()->json($existing);
    }
    public function complete(Request $request)
    {
        $input = $request->all();
        logger($input);
        //dd($request);
        $af = ActiveFlight::where('bid_id', $input['bidID'])->first();
        if ($af === null) {
            abort(404);
        }
        $pirep = Pirep::find($af->pirep_id);
        Log::info("Found Pirep to close out");
        $pirep->status = PirepStatus::ARRIVED;
        $pirep->state = PirepState::PENDING;
        $pirep->source = PirepSource::ACARS;
        $pirep->source_name = "smartCARS 3";
        $pirep->landing_rate = $input['landingRate'];
        $pirep->fuel_used = $input['fuelUsed'];
        $pirep->flight_time = $input['flightTime'] * 60;
        $pirep->submitted_at = Carbon::now('UTC');

        if (gettype($input['flightLog']) === "string") {
            $input['flightLog'] = base64_decode($input['flightLog'], true);
            $input['flightLog'] = explode("\n", $input['flightLog']);
            logger($input['flightLog']);
        }
        if (gettype($input['flightData']) === "string") {
            $input['flightData'] = base64_decode($input['flightData'], true);
            $input['flightData'] = json_decode($input['flightData'], true);
            logger($input['flightData']);
        }
        foreach ($input['flightData'] as $data) {
            $log_item = new Acars();
            $log_item->type = AcarsType::LOG;
            $log_item->log = $data['message'];
            $log_item->created_at = Carbon::createFromTimeString($data['eventTimestamp']);
            $pirep->acars_logs()->save($log_item);
        }
        if (!is_null($input['comments'])) {
            foreach ($input['flightLog'] as $comment) {
                if (str_contains($comment, " Comment:")) {
                    $pirep->comments()->create([
                        'user_id' => Auth::user()->id,
                        'comment' => $comment
                    ]);
                }
            }
        }
        // Now the raw data
        PirepLog::create([
            'pirep_id' => $pirep->id,
            'log'      => gzencode(json_encode($input['flightData']))
        ]);

        $pirep->save();
        $this->pirepService->submit($pirep);
        ActiveFlight::where('pirep_id', $pirep->id)->delete();
        return response()->json(['pirepID' => $pirep->id]);

    }
    public function search(Request $request)
    {
        $output = [];

        $query = [];
        $subfleet = null;
        if ($request->has('departureAirport') && $request->query('departureAirport') !== null) {
            $apt = Airport::where('icao', $request->query('departureAirport'))->first();
            if (!is_null($apt)) {
                $query['dpt_airport_id'] = $apt->id;
            }
        }

        if ($request->has('arrivalAirport') && $request->query('arrivalAirport') !== null) {
            $apt = Airport::where('icao', $request->query('arrivalAirport'))->first();
            if (!is_null($apt)) {
                $query['arr_airport_id'] = $apt->id;
            }
        }
        if ($request->has('aircraft') && $request->query('aircraft') !== null) {
            // Yank the subfleet by ID
            $apt = Subfleet::find($request->query('aircraft'));
            if (!is_null($apt)) {
                $subfleet = $apt->id;
            }
        }
        if (!empty($subfleet)) {
            if (empty($query)) {
                $flights = Flight::with('subfleets', 'subfleets.aircraft', 'airline')->whereHas('subfleets', function ($query) use ($subfleet) {
                    $query->where(['subfleets.id' => $subfleet, 'visible' => true]);
                })->take(100)->get();
            } else {
                $flights = Flight::where($query)->with('subfleets', 'subfleets.aircraft', 'airline')->whereHas('subfleets', function ($query) use ($subfleet) {
                    $query->where(['subfleets.id' => $subfleet, 'visible' => true]);
                })->take(100)->get();
            }
        } else {
            if (empty($query)) {
                $flights = Flight::with('subfleets', 'subfleets.aircraft', 'airline')->where('visible', true)->take(100)->get();
            } else {
                $flights = Flight::where($query)->with('subfleets', 'subfleets.aircraft', 'airline')->where('visible', true)->take(100)->get();
            }
        }

        foreach ($flights as $flight) {
            $aircraft = [];
            //dd($bid);
            if (is_null($flight->subfleets)) {
                continue;
            }
            foreach ($flight->subfleets as $subfleet) {
                $aircraft[] = $subfleet->type;
            }
            $output[] = [
                "id"               => $flight->id,
                "number"           => $flight->flight_number,
                "code"             => $flight->airline->code,
                "departureAirport" => $flight->dpt_airport_id,
                "arrivalAirport"   => $flight->arr_airport_id,
                "flightLevel"      => $flight->level,
                "distance"         => $flight->distance,
                "departureTime"    => $flight->dpt_time,
                "arrivalTime"      => $flight->arr_time,
                "flightTime"       => $flight->flight_time,
                "daysOfWeek"       => [],
                "type"             => $this->flightType($flight->flight_type),
                "subfleets"        => $aircraft
            ];
        }

        return response()->json($output);
    }
    public function prefile(Request $request)
    {
        $user = Auth::user();
        $bid = Bid::find($request->input('bidID'));
        logger($request->all());
        $flight = Flight::find($bid->flight_id);

        $attrs = [
            'flight_number'  => $flight->flight_number,
            'airline_id'     => $flight->airline_id,
            'route_code'     => $flight->route_code,
            'route_leg'      => $flight->route_leg,
            'flight_type'    => $flight->flight_type,
            'dpt_airport_id' => $flight->dpt_airport_id,
            'arr_airport_id' => $flight->arr_airport_id,
            'aircraft_id'    => $request->input('aircraftID'),
            'flight_id'      => $flight->id,
            'source'         => PirepSource::ACARS,
            'source_name'    => "smartCARS 3"
        ];
        // Check if the pirep already exists.
        //$existing = Pirep::where(['user_id' => $user->id, 'state' => PirepState::IN_PROGRESS])->first();
        //if (is_null($existing)) {
        try {
            $pirep = $this->pirepService->prefile(Auth::user(), $attrs);
        } catch (\Exception $e) {
            logger($e);
            return response()->json(['message' => $e->getMessage()], 500);
        }
        return response()->json($pirep);
        //}
        //return response()->json($existing);

    }
    public function unbook(Request $request)
    {

        $bid = Bid::where(['user_id' => $request->get('pilotID'), 'id' => $request->post('bidID')])->first();

        $this->bidService->removeBid(Flight::find($bid->flight_id), Auth::user());
    }
    public function update(Request $request)
    {
        $input = $request->all();
        // Check if there's an active flight under that bid.

        $af = ActiveFlight::where('bid_id', $input['bidID'])->first();

        if ($af === null) {
            // This should be a new PIREP that needs to be field. Create a draft PIREP.
            $bid = Bid::find($request->input('bidID'));
            logger($request->all());
            $flight = Flight::find($bid->flight_id);

            $attrs = [
                'user_id'          => Auth::user()->id,
                'flight_number'    => $flight->flight_number,
                'airline_id'       => $flight->airline_id,
                'route_code'       => $flight->route_code,
                'route_leg'        => $flight->route_leg,
                'flight_type'      => $flight->flight_type,
                'dpt_airport_id'   => $flight->dpt_airport_id,
                'arr_airport_id'   => $flight->arr_airport_id,
                'planned_distance' => $flight->distance,
                'aircraft_id'      => $request->input('aircraft'),
                'flight_id'        => $flight->id,
                'state'            => PirepState::IN_PROGRESS,
                'status'           => $this->phaseToStatus($input['phase']),
                'source'           => PirepSource::ACARS,
                'source_name'      => "smartCARS 3"
            ];
            $pirep = new Pirep($attrs);
            $pirep->save();
            $this->generateFares(Aircraft::find($request->input('aircraft')), $flight, $pirep);
            event(new PirepPrefiled($pirep));
            // Add new Active Flight
            ActiveFlight::create([
                'bid_id'   => $input['bidID'],
                'pirep_id' => $pirep->id
            ]);

        } else {
            $pirep = Pirep::find($af->pirep_id);
            $pirep->status = $this->phaseToStatus($input['phase']);
            $pirep->save();
            $pirep->acars()->create([
                'status'   => $this->phaseToStatus($input['phase']),
                'type'     => AcarsType::FLIGHT_PATH,
                'lat'      => $input['latitude'],
                'lon'      => $input['longitude'],
                'distance' => $pirep->planned_distance->local(2) - $input['distanceRemaining'],
                'heading'  => $input['heading'],
                'altitude' => $input['altitude'],
                'gs'       => $input['groundSpeed']
            ]);
        }
    }

    public function phaseToStatus(string $phase)
    {
        switch(strtolower($phase)) {
            case 'boarding':
                return PirepStatus::BOARDING;
            case 'push_back':
                return PirepStatus::PUSHBACK_TOW;
            case 'taxi':
                return PirepStatus::TAXI;
            case 'take_off':
                return PirepStatus::TAKEOFF;
            case 'rejected_take_off':
                return PirepStatus::TAXI;
            case 'climb_out':
                return PirepStatus::INIT_CLIM;
            case 'climb':
                return PirepStatus::ENROUTE;
            case 'cruise':
                return PirepStatus::ENROUTE;
            case 'descent':
                return PirepStatus::APPROACH;
            case 'approach':
                return PirepStatus::APPROACH_ICAO;
            case 'final':
                return PirepStatus::LANDING;
            case 'landed':
                return PirepStatus::LANDED;
            case 'go_around':
                return PirepStatus::APPROACH;
            case 'taxi_to_gate':
                return PirepStatus::LANDED;
            case 'deboarding':
                return PirepStatus::ARRIVED;
            case 'diverted':
                return PirepStatus::DIVERTED;
            default:
                return null;
        }
    }
    private function generateFares($aircraft, $flight, $pirep)
    {
        // Figure out the proper fares to use for this flight/aircraft
        $all_fares = $this->fareService->getFareWithOverrides($aircraft->subfleet->fares, $flight->fares);

        // TODO: Reconcile the fares for this aircraft w/ proper for the flight/subfleet

        // Get passenger and baggage weights with failsafe defaults
        if ($flight->flight_type === FlightType::CHARTER_PAX_ONLY) {
            $bag_weight = setting('simbrief.charter_baggage_weight', 28);
        } else {
            $bag_weight = setting('simbrief.noncharter_baggage_weight', 35);
        }

        // Get the load factors with failsafe for loadmax if nothing is defined
        $lfactor = $flight->load_factor ?? setting('flights.default_load_factor');
        $lfactorv = $flight->load_factor_variance ?? setting('flights.load_factor_variance');

        $loadmin = $lfactor - $lfactorv;
        $loadmin = max($loadmin, 0);

        $loadmax = $lfactor + $lfactorv;
        $loadmax = min($loadmax, 100);

        if ($loadmax === 0) {
            $loadmax = 100;
        }

        if (setting('flights.use_cargo_load_factor ', false)) {
            $cgolfactor = $flight->load_factor ?? setting('flights.default_cargo_load_factor');
            $cgolfactorv = $flight->load_factor_variance ?? setting('flights.cargo_load_factor_variance');

            $cgoloadmin = $cgolfactor - $cgolfactorv;
            $cgoloadmin = max($cgoloadmin, 0);

            $cgoloadmax = $cgolfactor + $cgolfactorv;
            $cgoloadmax = min($cgoloadmax, 100);

            if ($cgoloadmax === 0) {
                $cgoloadmax = 100;
            }
        } else {
            $cgoloadmin = $loadmin;
            $cgoloadmax = $loadmax;
        }

        // Load fares for passengers


        $pax_load_sheet = [];
        $tpaxfig = 0;

        /** @var Fare $fare */
        $fares = [];
        foreach ($all_fares as $fare) {
            if ($fare->type !== FareType::PASSENGER || empty($fare->capacity)) {
                continue;
            }
            $fares[] = new PirepFare([
                'fare_id' => $fare->id,
                'count'   => floor(($fare->capacity * rand($loadmin, $loadmax)) / 100)
            ]);

        }

        // Calculate total weights
        if (setting('units.weight') === 'kg') {
            $tbagload = round(($bag_weight * $tpaxfig) / 2.205);
        } else {
            $tbagload = round($bag_weight * $tpaxfig);
        }
        foreach ($all_fares as $fare) {
            if ($fare->type !== FareType::CARGO || empty($fare->capacity)) {
                continue;
            }
            $fares[] = new PirepFare([
                'fare_id' => $fare->id,
                'count'   => ceil((($fare->capacity - $tbagload) * rand($cgoloadmin, $cgoloadmax)) / 100)
            ]);
        }
        // Generate PIREP fares
        $this->fareService->saveToPirep($pirep, $fares);
    }
    public function flightType($type)
    {
        switch($type) {
            case 'J':
            case 'E':
            case 'C':
            case 'G':
            case 'O':
                return 'P';
                break;
            case 'A':
            case 'H':
            case 'I':
            case 'K':
            case 'M':
            case 'P':
            case 'T':
            case 'W':
            case 'X':
                return 'C';
                break;
        }
    }
}
