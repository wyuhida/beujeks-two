<?php

namespace App\Models\Common;

use App\Models\BaseModel;

class Ticket extends BaseModel
{
    protected $connection = 'common';

    protected $fillable = [
        'user_id', 'category_id', 'ticket_id', 'title', 'priority', 'status'
    ];
     protected $hidden = [
     	'created_type', 'created_by', 'modified_type', 'modified_by', 'deleted_type', 'deleted_by', 'deleted_at'
    ];

    public function ticketCategory()
    {
        return $this->belongsTo('App\Models\Common\TicketCategory', 'category_id', 'id');
    }
    public function ticketComments()
    {
        return $this->hasMany('App\Models\Common\TicketComment');
    }
    public function user()
    {
        return $this->belongsTo('App\Models\Common\User');
    }
    public function provider()
    {
        return $this->belongsTo('App\Models\Common\Provider','user_id','id');
    }
    public function scopeSearch($query, $searchText='') {
        return $query
            ->where('ticket_id', 'like', "%" . $searchText . "%")
            ->orWhere('title', 'like', "%" . $searchText . "%") 
            ->orWhere('type', 'like', "%" . $searchText . "%");
    }
    
}
