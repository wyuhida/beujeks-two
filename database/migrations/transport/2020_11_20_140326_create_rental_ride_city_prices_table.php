<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRentalRideCityPricesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('transport')->create('rental_ride_city_prices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('city_id');
            $table->unsignedInteger('vehicle_service_id')->nullable();
            $table->unsignedInteger('ride_category_id')->nullable();
            $table->unsignedInteger('ride_delivery_vehicle_id');
            $table->unsignedInteger('company_id');
            $table->decimal('rental_hour_price',8, 2)->default(0.00)->nullable();
            $table->decimal('rental_km_price',8, 2)->default(0.00)->nullable();
            $table->decimal('commission', 10, 2)->default(0);
            $table->decimal('fleet_commission', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('peak_commission', 10, 2)->default(0);
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
        Schema::dropIfExists('rental_ride_city_prices');
    }
}
