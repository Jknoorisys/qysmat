<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PremiumFeatures;
use App\Models\Subscriptions as ModelsSubscriptions;
use Illuminate\Http\Request;
use Stripe\Plan;
use Stripe\Stripe;

class Subscriptions extends Controller
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

    public function index(Request $request)
    {
        $data['admin']               = $this->admin;
        $data['previous_title']      = __("msg.Dashboard");
        $data['url']                 = route('dashboard');
        $data['title']               = __("msg.Manage Subscriptions");
        $data['records']             =  ModelsSubscriptions::paginate(10);
        if ($data['records'] == null) {
            return back()->with('fail', __('msg.Somthing Went Wrong, Please Try Again...'));
        }
        $data['premiumStatus']       =  PremiumFeatures::whereId(1)->first();
        $data['notifications']       = $this->admin->unreadNotifications->where('user_type','=','admin');

        $features = [];
        foreach ($data['records'] as $page) {
            if ($page->subscription_type == 'Basic') {
                $page->price = 'Free';
                $features = [__("msg.Only 5 Profile Views per day"), __("msg.Unrestricted profile search criteria")];
            }else {
                $features = [__("msg.Unlimited swipes per day"), __("msg.Send instant match request (3 per week)"), __("msg.In-app telephone and video calls"), __("msg.Refer profiles to friends and family"), __("msg.Undo last swipe"), __("msg.Reset profile search and start again once a month")];
            }
            $page->features= !empty($features) ? $features : "";
        }

        $data['content']             = view('subscriptions.subscriptions_list', $data);
        return view('layouts.main',$data);
    }

    public function changeSubscriptionStatus(Request $request)
    {
        $id = $request->id;
        $status = $request->status;
        $statusChange =  ModelsSubscriptions :: whereId($id)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($statusChange) {
            if ($status == 'Inactive') {
                return back()->with('success', __('msg.Subscription Inactivated'));
            } else {
                return back()->with('success', __('msg.Subscription Activated'));
            }

        } else {
            return back()->with('fail', __('msg.Somthing Went Wrong, Please Try Again...'));
        }
    }

    public function updatePrice(Request $request)
    {
        $id                          = $request->id;
        $data['admin']               = $this->admin;
        $data['previous_title']      = __("msg.Manage Subscriptions");
        $data['url']                 = route('subscriptions');
        $data['title']               = __("msg.Update Subscription Price");
        $data['records']             =  ModelsSubscriptions::find($id);
        if ($data['records'] == null) {
            return back()->with('fail', __('msg.Somthing Went Wrong, Please Try Again...'));
        }
        $data['notifications']       = $this->admin->unreadNotifications->where('user_type','=','admin');
        $data['content']             = view('subscriptions.subscriptions_update', $data);
        return view('layouts.main',$data);
    }

    public function updatePriceFun(Request $request)
    {
        $request->validate([
            'subscription_type' => 'required',
            'price'             => 'required',
        ]);

        $update =  ModelsSubscriptions :: whereId($request->id)->update(['subscription_type' => $request->subscription_type, 'price' => $request->price, 'updated_at' => date('Y-m-d H:i:s')]);
        if($update)
        {
            Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
            $plan = Plan::create(
                [
                    'amount' => $request->price * 100,
                    'currency' => env('STRIPE_CURRENCY'),
                    'interval' => 'month',
                    'nickname' => $request->subscription_type,
                    'product'  => [
                        'name' => $request->subscription_type,
                        'unit_label' => 'person'
                    ]
                ]
            );

            if ($plan) {
                ModelsSubscriptions :: whereId($request->id)->update(['stripe_plan_id' => $plan->id]);
            }

            return redirect()->to('subscriptions')->with('success', __('msg.Subscription Price Updated!'));
        }else{
            return back()->with('fail', __('msg.Please Try Again....'));
        }
    }

    public function changeFeatureStatus(Request $request)
    {
        $status = $request->status;
        $statusChange =  PremiumFeatures :: whereId(1)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($statusChange) {
            if ($status == 'inactive') {
                return back()->with('success', __('msg.Features Inactivated'));
            } else {
                return back()->with('success', __('msg.Features Activated'));
            }

        } else {
            return back()->with('fail', __('msg.Somthing Went Wrong, Please Try Again...'));
        }
    }
}
