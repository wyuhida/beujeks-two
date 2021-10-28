<?php

namespace App\Models\Delivery;

use Illuminate\Database\Eloquent\Model;
use App\Models\BaseModel;

class DeliveryRequestDispute extends BaseModel
{
    protected $connection = 'delivery';


    protected $hidden = [
     	'company_id','created_type', 'created_by', 'modified_type', 'modified_by', 'deleted_type', 'deleted_by', 'created_at', 'updated_at', 'deleted_at'
    ];
    public function provider()
    {
        return $this->belongsTo('App\Models\Common\Provider', 'provider_id');
    }

    public function request()
    {
        return $this->belongsTo('App\Models\Delivery\DeliveryRequest','delivery_request_id');
    }
}
