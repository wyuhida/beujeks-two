<?php

namespace App\Http\Controllers\V1\Common\Admin\Resource;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Traits\Actions;
use App\Models\Common\TicketCategory;
use App\Models\Common\Ticket;
use App\Models\Common\TicketComment;
use App\Models\Common\Setting;
use App\Helpers\Helper;
use App\Traits\Encryptable;
use Auth;
class TicketsController extends Controller
{

    use Actions;

    private $model;
    private $request; 

    public function __construct(Ticket $model) 
    {
        $this->model = $model;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $type = strtolower($request->type);
        $datum = Ticket::with('ticketCategory')->where('user_id', Auth::guard($type)->user()->id)->where('status',$request->status)->where('type',$request->type)->orderBy('created_at', 'desc');

        if($request->has('search_text') && $request->search_text != null) {
            $datum->Search($request->search_text);
        }
       
        if($request->has('page') && $request->page == 'all') {
            $data = $datum->get();
        } else {
            $data = $datum->paginate(10);
        }
        
        return Helper::getResponse(['data' => $data]);
    }
    public function indexAll(Request $request)
    {
        if($request->status == 'ALL')
            $datum = Ticket::with('ticketCategory')->orderBy('created_at', 'desc');
        else
            $datum = Ticket::with('ticketCategory')->where('status',$request->status)->orderBy('created_at', 'desc');

        if($request->has('search_text') && $request->search_text != null) {
            $datum->Search($request->search_text);
        }
       
        if($request->has('page') && $request->page == 'all') {
            $data = $datum->get();
        } else {
            $data = $datum->paginate(10);
        }
        
        return Helper::getResponse(['data' => $data]);
    }
    public function closedIndex(Request $request)
    {
        $type = strtolower($request->type);
        $datum = Ticket::with('ticketCategory')->where('user_id', Auth::guard($type)->user()->id)->where('status','!=',0)->where('type',$request->type)->orderBy('created_at', 'desc');

        if($request->has('search_text') && $request->search_text != null) {
            $datum->Search($request->search_text);
        }
       
        if($request->has('page') && $request->page == 'all') {
            $data = $datum->get();
        } else {
            $data = $datum->paginate(10);
        }
        
        return Helper::getResponse(['data' => $data]);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
       
        $this->validate($request, [
            'title' => 'required',
            'ticket_category' => 'required',
            'admin_service' => 'required',
            'description' => 'required',
            'type' => 'required'
        ]);
        try{
            $type = strtolower($request->type);
            $ticket = new Ticket;
            $ticket->admin_service = $request->admin_service;
            $ticket->user_id = Auth::guard($type)->user()->id;
            $ticket->ticket_id = mt_rand(10000000,99999999);

            if($request->ticket_category != "OTHER")
                $ticket->category_id = $request->ticket_category;

            $ticket->company_id =  Auth::guard($type)->user()->company_id;
            $ticket->type = $request->type;
            $ticket->title = $request->title;
            $ticket->status = 0;
            $ticket->save();

            $ticket_comment = new TicketComment;
            $ticket_comment->type = $request->type;
            $ticket_comment->ticket_id = $ticket->id;
            $ticket_comment->user_id = Auth::guard($type)->user()->id;
            $ticket_comment->comment = $request->description;
            if($request->hasFile('picture')) {
               $ticket_comment->picture = Helper::upload_file($request->file('picture'), 'Tickets');
            }
            $ticket_comment->save();

            $settings = json_decode(json_encode(Setting::where('company_id', $ticket->company_id)->first()->settings_data));
            $siteConfig = $settings->site;  
            if( !empty($siteConfig->send_email) && $siteConfig->send_email == 1) {
                
                //  SEND OTP TO MAIL
                    $subject='#'.$ticket->ticket_id.' New Ticket';
                    $templateFile='mails/ticketmail';
                    $data=['body'=>$request->description,'username'=>Auth::guard($type)->user()->first_name,'salt_key'=>$ticket->company_id];
                    $result= Helper::send_emails($templateFile,$siteConfig->contact_email,$subject, $data);               
                  
            }

             return Helper::getResponse(['status' => 200, 'message' => trans('admin.create')]);
        }
        catch (\Throwable $e) {
             return Helper::getResponse(['status' => 404, 'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }

        
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function ticketComment($id)
    {
        try {
                $tickets = Ticket::with(['ticketComments','user','provider','ticketCategory'])->findOrFail($id);
                    return Helper::getResponse(['data' => $tickets]);
        } catch (\Throwable $e) {
             return Helper::getResponse(['status' => 404,'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function postComment(Request $request)
    {
        $this->validate($request, [
            'description' => 'required',
            'type' => 'required'
        ]);
        try{
            $type = strtolower($request->type);

            $ticket = Ticket::find($request->id);
            $ticket_comment = new TicketComment;
            $ticket_comment->type = $request->type;
            $ticket_comment->ticket_id = $ticket->id;
            $ticket_comment->user_id = Auth::guard($type)->user()->id;
            $ticket_comment->comment = $request->description;
            if($request->hasFile('picture')) {
               $ticket_comment->picture = Helper::upload_file($request->file('picture'), 'Tickets');
            }
            $ticket_comment->save();

            $settings = json_decode(json_encode(Setting::where('company_id', $ticket->company_id)->first()->settings_data));
            $siteConfig = $settings->site;  

            if( !empty($siteConfig->send_email) && $siteConfig->send_email == 1) {
                if($request->type !="ADMIN")
                    $email = $this->cusencrypt(Auth::guard($type)->user()->email,env('DB_SECRET'));
                else
                    $email = $siteConfig->contact_email;
                //  SEND OTP TO MAIL
                    $subject='#'.$ticket->ticket_id.' Ticket';
                    $templateFile='mails/ticketmail';
                    $data=['body'=>$request->description,'username'=>Auth::guard($type)->user()->first_name,'salt_key'=>$ticket->company_id];
                    $result= Helper::send_emails($templateFile,$email,$subject, $data);               
                  
            }

            return Helper::getResponse(['status' => 200, 'message' => trans('admin.update')]);
        }
        catch (\Throwable $e) {
             return Helper::getResponse(['status' => 404, 'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        
        try {

            $datum =  Ticket::find($id);
            if($request->status == 0){
                $datum->status = 1;
            }else{
                $datum->status = 2;
            }
            
            $datum->save();
            return Helper::getResponse(['status' => 200, 'message' => trans('admin.update')]);

        } 

        catch (\Throwable $e) {
            return Helper::getResponse(['status' => 404, 'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
