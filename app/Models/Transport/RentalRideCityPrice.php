<?php

namespace App\Models\Transport;

use App\Models\BaseModel;

class RentalRideCityPrice extends BaseModel
{
	protected $connection = 'transport';

	protected $hidden = [
     	'company_id', 'created_by', 'modified_type', 'modified_by', 'deleted_type', 'deleted_by', 'updated_at', 'deleted_at'
     ];

    public function rentalPackages()
    {
        return $this->hasMany('App\Models\Transport\RentalPackage','rental_ride_city_prices_id' ,'id');
    }
}
