<?php

namespace App\Http\Controllers\api\subscriptions;

use App\Http\Controllers\Controller;
use App\Models\ParentsModel;
use App\Models\PremiumFeatures;
use App\Models\Singleton;
use App\Models\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubscriptionPlans extends Controller
{
    public function  __construct()
    {
        $lang = (isset($_POST['language']) && !empty($_POST['language'])) ? $_POST['language'] : 'en';
        App::setlocale($lang);

        if (isset($_POST['login_id']) && !empty($_POST['login_id']) && isset($_POST['user_type']) && !empty($_POST['user_type'])) {
            userFound($_POST['login_id'], $_POST['user_type']);
        }
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            $pages = Subscriptions::where('status','=','Active')->get();
            $featureStatus = PremiumFeatures::whereId(1)->first();
            $features = [];
            foreach ($pages as $page) {
                if ($page->subscription_type == 'Basic') {
                    $page->price = 'Free';
                    $features = [__("msg.Only 5 Profile Views per day"), __("msg.Unrestricted profile search criteria")];
                }else {
                    $features = [__("msg.Unlimited swipes per day"), __("msg.Send instant match request (3 per week)"), __("msg.In-app telephone and video calls"), __("msg.Refer profiles to friends and family"), __("msg.Undo last swipe"), __("msg.Reset profile search and start again once a month")];
                }
                $page->features= !empty($features) ? $features : "";
                $page->feature_status = $featureStatus ? $featureStatus->status : '';
            }

            if(!empty($pages)){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.subscriptions.success'),
                    'data'      => $pages
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.subscriptions.failure'),
                ],400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        }
    }

    public function activeSubscription(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'   => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['singleton','parent']),
            ],
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            if ($request->user_type == 'singleton') {
                $page = Singleton::where([['subscriptions.status','=','Active'],['singletons.id',$request->login_id]])->leftJoin('subscriptions','singletons.active_subscription_id','=','subscriptions.id')->select('subscriptions.*','singletons.id as singleton_id')->first();
            } else {
                $page = ParentsModel::where([['subscriptions.status','=','Active'],['parents.id',$request->login_id]])->leftJoin('subscriptions','parents.active_subscription_id','=','subscriptions.id')->select('subscriptions.*','parents.id as parent_id')->first();
            }

            if(!empty($page)){
                if ($page->subscription_type == 'Basic') {
                    $page->price = 'Free';
                    $features = [__("msg.Only 5 Profile Views per day"), __("msg.Unrestricted profile search criteria")];
                }else {
                    $features = [__("msg.Unlimited swipes per day"), __("msg.Send instant match request (3 per week)"), __("msg.In-app telephone and video calls"), __("msg.Refer profiles to friends and family"), __("msg.Undo last swipe"), __("msg.Reset profile search and start again once a month")];
                }
                $page->features= !empty($features) ? $features : "";

                $featureStatus = PremiumFeatures::whereId(1)->first();
                if ((!empty($featureStatus) && $featureStatus->status == 'active')) {
                    $page->premium_features = 'disabled';
                }else{
                    $page->premium_features = 'enabled';
                }

                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.subscriptions.success'),
                    'data'      => $page
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.subscriptions.failure'),
                ],400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        }
    }

    public function isPremium(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'   => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['singleton','parent']),
            ],
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {

            if ($request->user_type == 'singleton') {
                $user = Singleton::where([['id', '=', $request->login_id],['status', '=', 'Unblocked']])->first();
            } elseif ($request->user_type == 'parent') {
                $user = ParentsModel::where([['id', '=', $request->login_id],['status', '=', 'Unblocked']])->first();
            }

            $featureStatus = PremiumFeatures::whereId(1)->first();
            if ((!empty($featureStatus) && $featureStatus->status == 'active')) {
                $user->premium_features = 'disabled';
            }else{
                $user->premium_features = 'enabled';
            }

            if($user->active_subscription_id != 1 || $user->premium_features == 'enabled'){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.premium.success'),
                ],200);
            }else {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.premium.failure'),
                ],400);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        }
    }

    public function nonPremiumSingletons(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'   => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            $profiles = DB::table('parent_children')
                            ->where([['parent_children.parent_id','=',$request->login_id], ['parent_children.status', '=', 'Linked']])
                            ->where('singletons.active_subscription_id', '=', '1')
                            ->join('singletons', 'singletons.id', '=', 'parent_children.singleton_id')
                            ->select('singletons.*')
                            ->get();

            if(!$profiles->isEmpty()){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.parents.get-linked-profiles.success'),
                    'data'      => $profiles
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.get-linked-profiles.invalid'),
                ],400);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        }
    }
}
