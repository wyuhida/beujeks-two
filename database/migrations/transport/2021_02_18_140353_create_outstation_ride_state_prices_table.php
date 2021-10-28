<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOutstationRideStatePricesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('transport')->create('outstation_ride_state_prices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('state_id');
            $table->unsignedInteger('vehicle_service_id')->nullable();
            $table->unsignedInteger('ride_category_id')->nullable();
            $table->unsignedInteger('ride_delivery_vehicle_id');
            $table->unsignedInteger('company_id');
            $table->decimal('oneway_price', 10, 2)->default(0);
            $table->decimal('roundtrip_price', 10, 2)->default(0);
            $table->decimal('fixed', 10, 2)->default(0);
            $table->decimal('distance', 10, 2)->default(0);
            $table->decimal('driver_allowance', 10, 2)->default(0);
            $table->decimal('night_time_allowance', 10, 2)->default(0);
            $table->decimal('per_hour_price', 10, 2)->default(0);
            $table->decimal('per_km_price', 10, 2)->default(0);
            $table->decimal('commission', 10, 2)->default(0);
            $table->decimal('fleet_commission', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->tinyInteger('status')->default(1); 
            $table->enum('created_type', ['ADMIN','USER','PROVIDER','SHOP'])->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->enum('modified_type', ['ADMIN','USER','PROVIDER','SHOP'])->nullable();
            $table->unsignedInteger('modified_by')->nullable();
            $table->enum('deleted_type', ['ADMIN','USER','PROVIDER','SHOP'])->nullable();
            $table->unsignedInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ride_category_id')->references('id')->on('ride_categories')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('outstation_ride_city_prices');
    }
}
