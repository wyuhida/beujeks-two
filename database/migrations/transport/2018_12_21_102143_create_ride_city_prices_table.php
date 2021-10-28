<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRideCityPricesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('transport')->create('ride_city_prices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('geofence_id')->nullable();
            $table->unsignedInteger('city_id');
            $table->enum('service_type', ['RIDE','RENTAL'])->default('RIDE')->nullable();
            $table->unsignedInteger('vehicle_service_id')->nullable();
            $table->unsignedInteger('ride_category_id')->nullable();
            $table->unsignedInteger('ride_delivery_vehicle_id');
            $table->unsignedInteger('company_id');
            $table->enum('calculator', ['DISTANCE','MIN','HOUR','DISTANCEMIN','DISTANCEHOUR','RENTAL']);
            $table->decimal('rental_hour_price',8, 2)->default(0.00)->nullable();
            $table->decimal('rental_km_price',8, 2)->default(0.00)->nullable();
            $table->decimal('fixed', 10, 2)->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('minute', 10, 2)->default(0);
            $table->decimal('hour', 10, 2)->default(0);
            $table->decimal('distance', 10, 2)->default(0);
            $table->decimal('base_price', 10, 2)->default(0);
            $table->decimal('base_unit', 10, 2)->default(0);
            $table->decimal('base_hour', 10, 2)->default(0);
            $table->decimal('per_unit_price', 10, 2)->default(0);
            $table->decimal('per_minute_price', 10, 2)->default(0);
            $table->decimal('per_km_price', 10, 2)->default(0);
            $table->decimal('per_hour', 10, 2)->default(0);
            $table->decimal('per_hour_distance', 10, 2)->default(0);
            $table->decimal('waiting_free_mins', 10, 2)->default(0);
            $table->decimal('waiting_min_charge', 10, 2)->default(0);
            $table->decimal('commission', 10, 2)->default(0);
            $table->decimal('fleet_commission', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('peak_commission', 10, 2)->default(0);
            $table->decimal('waiting_commission', 10, 2)->default(0);
            $table->decimal('city_limit', 10, 2)->default(0);
            $table->tinyInteger('status')->default(1);
            $table->tinyInteger('pricing_differs')->default(0);
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
        Schema::dropIfExists('ride_prices');
    }
}
