<?php

namespace App\Http\Controllers\V1\Common\Admin\Resource;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Helpers\Helper;
use Illuminate\Support\Facades\Hash;
use App\Traits\Actions;
use App\Models\Common\Promocode;
use DB;
use Auth;
use Carbon\Carbon;

class PromocodeController extends Controller
{
    use Actions;

    private $model;
    private $request;
     /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(PromoCode $model)
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
        $store_id = Auth::guard('shop')->user() ? Auth::guard('shop')->user()->id: '';

        $company_id = Auth::guard('shop')->user() ? Auth::guard('shop')->user()->company_id: Auth::user()->company_id;

        $datum = Promocode::with('store')->whereHas('service' ,function($query){  
            $query->where('status',1);  
        })->where('company_id', $company_id);
        
        if($request->has('search_text') && $request->search_text != null) {
            $datum->Search($request->search_text);
        }

        if($request->has('order_by')) {
            $datum->orderby($request->order_by, $request->order_direction);
        }
        if($store_id)
        {
            $datum->where('store_id',$store_id);
        } 
        
        if($request->has('page') && $request->page == 'all') {
            $data = $datum->get();
        } else {
            $data = $datum->paginate(10);
        }

        return Helper::getResponse(['data' => $data]);

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.promocode.create');
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
            'promo_code' => 'required|max:100|unique:promocodes',
            'percentage' => 'required|numeric',
            'max_amount' => 'required|numeric',
            'min_amount' => 'required|numeric',
            'expiration' => 'required',
            'service' => 'required',
            'picture' => 'required|mimes:jpeg,jpg,bmp,png|max:5242880', 
        ]);

        try{
            
            $store_id = Auth::guard('shop')->user() ? Auth::guard('shop')->user()->id : '';
        
            $company_id = Auth::guard('shop')->user() ? Auth::guard('shop')->user()->company_id: Auth::user()->company_id;

            $promo_code = new Promocode;
            $promo_code->company_id = $company_id;  
            $promo_code->service = $request->service; 
            

            if($request->hasFile('picture')) {
                $imagedetails = getimagesize($_FILES['picture']['tmp_name']);
                $height = $imagedetails[1];
                if($height < 190 || $height > 200){
                    return Helper::getResponse(['status' => 404,'message' => 'image Height must be 200px. this image height is '.$height.' px', 'error' => '']);
                }
                $promo_code['picture'] = Helper::upload_file($request->file('picture'), 'promocode');
            }
            if(!empty($store_id)) 
                $promo_code->store_id = $store_id;

            $promo_code->promo_code = $request->promo_code; 
            $promo_code->percentage = $request->percentage;  
            $promo_code->max_amount = $request->max_amount;  
            $promo_code->min_amount = $request->min_amount;
            $promo_code->user_limit = $request->user_limit;
            $promo_code->eligibility = $request->eligibility;
            if($request->eligibility == 3) {
                $promo_code->startdate =date("Y-m-d H:i:s", strtotime($request->startdate));
            }elseif ($request->eligibility == 2) {
                $promo_code->user_id = $request->user_id1;
            }
                                            
            $promo_code->expiration = date("Y-m-d H:i:s", strtotime($request->expiration));
            $promo_code->promo_description = $request->promo_description;               
            $promo_code->save();
            return Helper::getResponse(['status' => 200, 'message' => trans('admin.create')]);
        } 
        catch (ModelNotFoundException $e) {
            return Helper::getResponse(['status' => 404, 'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Promocode  $promocode
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            $promocode = Promocode::with('user')->findOrFail($id);
            $expiration = $promocode['expiration'];
            $promocode['expiration']=date('d/m/Y',strtotime($promocode['expiration']));
            $promocode['startdate']=date('d/m/Y',strtotime($promocode['startdate']));
            $promocode['expiration_date'] = date('m/d/Y',strtotime($expiration));
            
            return Helper::getResponse(['data' => $promocode]); 
        } catch (\Throwable $e) {
            return Helper::getResponse(['status' => 404,'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Promocode  $promocode
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'promo_code' => 'required|max:100',
            'percentage' => 'required|numeric',
            'max_amount' => 'required|numeric',
            'expiration' => 'required',
            'service' => 'required', 
        ]);

        try {

            $store_id = Auth::guard('shop')->user() ? Auth::guard('shop')->user()->id: '';

            $promo = Promocode::findOrFail($id);
            $promo->service = $request->service; 
            $promo->promo_code = $request->promo_code;
            if($request->hasFile('picture')) {
                $promo['picture'] = Helper::upload_file($request->file('picture'), 'provider/profile');
            }
            if($store_id) 
                $promo->store_id = $store_id; 

            $promo->percentage = $request->percentage;
            $promo->max_amount = $request->max_amount;
            $promo->expiration =date("Y-m-d H:i:s", strtotime($request->expiration));
            $promo->promo_description = $request->promo_description;
            $promo->min_amount = $request->min_amount;
            $promo->user_limit = $request->user_limit;
            $promo->eligibility = $request->eligibility;
            if($request->eligibility == 3) {
                $promo->startdate = date("Y-m-d H:i:s", strtotime($request->startdate));
            }elseif ($request->eligibility == 2) {
                $promo->user_id = $request->user_id1;
            }
            else{
                $promo->user_id = null;
                $promo->startdate = null;
            }      
            $promo->save();

            return Helper::getResponse(['status' => 200, 'message' => trans('admin.update')]);    
        } 

        catch (ModelNotFoundException $e) {
            return Helper::getResponse(['status' => 404,'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Promocode  $promocode
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        return $this->removeModel($id);
    }
}
