<?php

namespace App\Models\Common;

use App\Models\BaseModel;
use App\Models\Common\State;
use Auth;
use DateTime;
use DateTimeZone;
class TicketComment extends BaseModel
{
    protected $connection = 'common';
     protected $hidden = [
     	'created_type', 'created_by', 'modified_type', 'modified_by', 'deleted_type', 'deleted_by', 'deleted_at'
    ];
    protected $appends = ['created_date'];

    public function getCreatedDateAttribute() {

    	$type = strtolower($this->attributes['type']);
    	$timezone=isset(Auth::guard($type)->user()->state_id) ? State::find(Auth::guard($type)->user()->state_id)->timezone:"UTC";

    	return (isset($this->attributes['created_at'])) ? (\Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $this->attributes['created_at'], 'UTC'))->setTimezone($timezone)->format('l, F d Y h:i:s') : '' ;


        
    }
}
