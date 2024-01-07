<?php

use App\Contracts\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Class CreateScPirepLogsTable
 */
class CreateScPirepLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sc_pirep_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('pirep_id')->index();
            $table->binary('log');
            $table->timestamps();
        });
        Schema::create('sc_active_flights', function (Blueprint $table){
            $table->increments('id');
            $table->bigInteger('bid_id');
            $table->string('pirep_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sc_pirep_logs');
        Schema::dropIfExists('sc_active_flights');
    }
}
