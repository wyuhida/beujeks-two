<?php

namespace App\Models\Transport;

use App\Models\BaseModel;

class RentalPackage extends BaseModel
{
    protected $connection = 'transport';

    protected $hidden = [
     	'created_by', 'modified_type', 'modified_by', 'deleted_type', 'deleted_by', 'updated_at', 'deleted_at'
     ];
}
