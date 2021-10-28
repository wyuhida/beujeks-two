<?php

namespace App\Http\Controllers\V1\Common\Admin\Resource;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Traits\Actions;
use App\Models\Common\TicketCategory;
use App\Helpers\Helper;
use Auth;

class TicketCategoryController extends Controller
{
    
    use Actions;

    private $model;
    private $request; 

    public function __construct(TicketCategory $model) 
    {
        $this->model = $model;
    }

    public function index(Request $request)
    {
        $datum = TicketCategory::where('company_id', Auth::user()->company_id);

        if($request->has('search_text') && $request->search_text != null) {
            $datum->Search($request->search_text);
        }

        if($request->has('order_by')) {
            $datum->orderby($request->order_by, $request->order_direction);
        }

        
        if($request->has('page') && $request->page == 'all') {
            $data = $datum->get();
        } else {
            $data = $datum->paginate(10);
        }

        return Helper::getResponse(['data' => $data]);
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required', 
            'status' => 'required',   
            'admin_service' => 'required',          
        ]);

        try{
            $ticket_category = new TicketCategory;
            $ticket_category->company_id = Auth::user()->company_id;  
            $ticket_category->admin_service = $request->admin_service;
            $ticket_category->name = $request->name;
            $ticket_category->status = $request->status;           
            $ticket_category->save();
            
            return Helper::getResponse(['status' => 200, 'message' => trans('admin.create')]);
        } 

        catch (\Throwable $e) {
            return Helper::getResponse(['status' => 404, 'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        try {
                $reason = TicketCategory::findOrFail($id);
                    return Helper::getResponse(['data' => $reason]);
        } catch (\Throwable $e) {
            return Helper::getResponse(['status' => 404,'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'name' => 'required',
            'admin_service' => 'required',         
        ]);

        try {

	            $ticket_category = TicketCategory::findOrFail($id);
	            $ticket_category->admin_service = $request->admin_service;
	            $ticket_category->name = $request->name;
	            $ticket_category->status = $request->status;           
	            $ticket_category->save();
	            return Helper::getResponse(['status' => 200, 'message' => trans('admin.update')]);
            } catch (\Throwable $e) {
                return Helper::getResponse(['status' => 404,'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
            }
    }
    public function updateStatus(Request $request, $id)
    {
  
        try {

            $datum = TicketCategory::findOrFail($id);
            
            if($request->has('status')){
                if($request->status == 1){
                    $datum->status = 0;
                }else{
                    $datum->status = 1;
                }
            }
            $datum->save();
           
            return Helper::getResponse(['status' => 200, 'message' => trans('admin.activation_status')]);

        } 

        catch (\Throwable $e) {
            return Helper::getResponse(['status' => 404, 'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }


    public function destroy($id)
    {
        return $this->removeModel($id);
    }
    //Tickets list value
    public function ticketCategory($type)
    {
        $ticket_category_list = TicketCategory::where('status',1)->where('admin_service',$type)->get();
        return Helper::getResponse(['data' => $ticket_category_list]);
    }

}
