<?php

namespace App\Models\Common;

use App\Models\BaseModel;

class TicketCategory extends BaseModel
{
    protected $connection = 'common';
  	protected $table = 'ticket_categories';

  	protected $hidden = [
     	'created_type', 'created_by', 'modified_type', 'modified_by', 'deleted_type', 'deleted_by'
    ];

  	public function scopeSearch($query, $searchText='') {
        return $query
            ->where('name', 'like', "%" . $searchText . "%");
    }
}
