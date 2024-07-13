<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ContactUs as ModelsContactUs;
use Illuminate\Http\Request;

class ContactUs extends Controller
{
    private $admin_id;
    private $admin;
    
    public function  __construct()
    {
        $this->middleware(function ($request, $next) {
            if(Session()->get('loginId') == false && empty(Session()->get('loginId'))) {
                return redirect()->to('/')->with('warning', __('msg.Please Login First!'));
            }else {
                $this->admin_id = Session()->get('loginId');
                $this->admin = Admin::where('id', '=', $this->admin_id)->first();
            }
            return $next($request);
        });
    }

    public function index()
    {
        $data['admin']                    = $this->admin;
        $data['previous_title']           = __("msg.Dashboard");
        $data['url']                      = route('dashboard');
        $data['title']                    = __("msg.Manage Contact Us  Forms");
        $data['records']                  = ModelsContactUs::get();
        if ($data['records'] == null) {
            return back()->with('fail', __('msg.Somthing Went Wrong, Please Try Again...'));
        }
        $data['notifications']            = $this->admin->unreadNotifications->where('user_type','=','admin');
        $data['content']                  = view('contact_us.contact_us_list', $data);
        return view('layouts.main',$data);
    }
}
