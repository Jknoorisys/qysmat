<?php

namespace App\Http\Controllers\api\parents;

use App\Http\Controllers\Controller;
use App\Models\BlockList;
use App\Models\Matches as ModelsMatches;
use App\Models\MyMatches;
use App\Models\ParentChild;
use App\Models\ParentsModel;
use App\Models\PremiumFeatures;
use App\Models\RecievedMatches;
use App\Models\ReferredMatches;
use App\Models\ReportedUsers;
use App\Models\Singleton;
use App\Models\UnMatches;
use App\Notifications\ReMatchNotification;
use App\Notifications\UnmatchNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Matches extends Controller
{
    public function  __construct()
    {
        $lang = (isset($_POST['language']) && !empty($_POST['language'])) ? $_POST['language'] : 'en';
        App::setlocale($lang);

        if (isset($_POST['login_id']) && !empty($_POST['login_id']) && isset($_POST['user_type']) && !empty($_POST['user_type']) && isset($_POST['singleton_id']) && !empty($_POST['singleton_id'])) {
            parentExist($_POST['login_id'], $_POST['user_type'], $_POST['singleton_id']);
        }
    }

    // unmatch user
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'  => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'singleton_id'    => 'required||numeric',
            'un_matched_id'   => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            $userExists = Singleton::find($request->un_matched_id);
            $singeletonExists = Singleton::find($request->singleton_id);

            if(empty($userExists) || $userExists->status == 'Deleted' || $userExists->status == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.un-match.invalid'),
                ],400);
            }

            if(empty($singeletonExists) || $singeletonExists->status == 'Deleted' || $singeletonExists->status == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.un-match.invalid'),
                ],400);
            }

            $matchExists = MyMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', $request->singleton_id],['matched_id', '=', $request->un_matched_id]])->first();
            $receievdMatchExists = RecievedMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', $request->singleton_id],['recieved_match_id', '=', $request->un_matched_id]])->first();
            $referredMatchExists = ReferredMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', $request->singleton_id],['referred_match_id', '=', $request->un_matched_id]])->first();
            $matched = ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->un_matched_id], ['match_type', '=', 'matched'],['singleton_id', '=', $request->singleton_id]])
                                        ->orWhere([['user_id', '=', $userExists->parent_id], ['user_type', '=', 'parent'],['match_id', '=', $request->singleton_id], ['singleton_id', '=', $request->un_matched_id], ['match_type', '=', 'matched']])
                                        ->first();            

            $is_blurred = $singeletonExists->gender == 'Female' ? $singeletonExists->is_blurred : $userExists->is_blurred;
            if(!empty($matchExists) || !empty($receievdMatchExists) || !empty($referredMatchExists) || !empty($matched)){

                $user = new UnMatches();
                $user->un_matched_id             = $request->un_matched_id;
                $user->singleton_id              = $request->singleton_id;
                $user->user_id                   = $request->login_id;
                $user->user_type                 = $request->user_type;
                $user_details                    = $user->save();

                if($user_details){

                    if (!empty($matched)) {
                        ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->un_matched_id], ['match_type', '=', 'matched'],['singleton_id', '=', $request->singleton_id]])
                                        ->orWhere([['user_id', '=', $userExists->parent_id], ['user_type', '=', 'parent'],['match_id', '=', $request->singleton_id], ['singleton_id', '=', $request->un_matched_id], ['match_type', '=', 'matched']])
                                        ->update(['match_type' => 'un-matched', 'matched_at' => Null, 'blur_image' => $is_blurred, 'updated_at' => date('Y-m-d H:i:s'), 'status' => 'available']);
                    }

                    if (!empty($matchExists)) {
                        MyMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', $request->singleton_id],['matched_id', '=', $request->un_matched_id]])->delete();
                    }

                    if (!empty($receievdMatchExists)) {
                        RecievedMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', $request->singleton_id],['recieved_match_id', '=', $request->un_matched_id]])->delete();
                    }

                    if (!empty($referredMatchExists)) {
                        ReferredMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', $request->singleton_id],['referred_match_id', '=', $request->un_matched_id]])->delete();
                    }

                    $sender = ParentsModel::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
                    $reciever = ParentsModel::where([['id', '=', $userExists->parent_id], ['status', '=', 'Unblocked']])->first();
                    $msg = __("msg.has unmatched you");
                    $reciever->notify(new UnmatchNotification($sender, $reciever->user_type, $request->un_matched_id, $msg));

                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.parents.un-match.success'),
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.un-match.failure'),
                    ],400);
                }
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.un-match.not-found'),
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

    public function myMatches(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'  => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'singleton_id'    => 'required||numeric',
            'page_number'     => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

       try {

            $per_page = 10;
            $page_number = $request->input(key:'page_number', default:1);
            $total = DB::table('my_matches')
                        ->where([['my_matches.user_id', '=', $request->login_id], ['my_matches.user_type', '=', $request->user_type], ['my_matches.singleton_id', '=', $request->singleton_id]])
                        ->join('singletons', 'my_matches.matched_id', '=', 'singletons.id')
                        ->count();

            $match = DB::table('my_matches')
                            ->where([['my_matches.user_id', '=', $request->login_id], ['my_matches.user_type', '=', $request->user_type], ['my_matches.singleton_id', '=', $request->singleton_id]])
                            ->join('singletons', 'my_matches.matched_id', '=', 'singletons.id')
                            ->join('parents', 'singletons.parent_id', '=', 'parents.id')
                            ->offset(($page_number - 1) * $per_page)
                            ->limit($per_page)
                            ->get(['my_matches.user_id','my_matches.user_type','singletons.*','parents.name as parent_name', 'parents.profile_pic as parent_profile_pic', 'parents.relation_with_singleton']);
                            
            $loggedInUser = ParentsModel::find($request->login_id);
            $loggedInUserChild = Singleton::find($request->singleton_id);
            foreach ($match as $m) {
                $lat1 = $m->lat;
                $long1 = $m->long;
                $lat2 = $loggedInUser->lat;
                $long2 = $loggedInUser->long;
                $m->distance = $this->getDistance($lat1, $long1, $lat2, $long2);
            }

            if(!$match->isEmpty()){
                $users = [];
                foreach ($match as $m) {
                    $singleton_id = $m->id;
                    $parent_id = $m->parent_id;
                    $m->is_singleton_blurred_photos = $loggedInUserChild->is_blurred;

                    $mutual = ModelsMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id],
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['match_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id],
                        ]);
                    })->first();

                    if (!empty($mutual)) {
                        $m->match_type = $mutual->match_type;
                        if ($m->gender == 'Male') {
                            $m->blur_image = 'no';
                        } else{
                            if ($m->match_type == 'matched') {
                                $m->blur_image = $mutual->blur_image;
                            } else{
                                $m->blur_image = $m->is_blurred;
                            }
                        }
                    }

                    $block = BlockList::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['blocked_user_id', $m->id],
                            ['blocked_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['blocked_user_id', $request->singleton_id],
                            ['blocked_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $report = ReportedUsers::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['reported_user_id', $m->id],
                            ['reported_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['reported_user_id', $request->singleton_id],
                            ['reported_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatch = UnMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['un_matched_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatched = ModelsMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['match_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id],
                            ['match_type', '=', 'un-matched'],
                        ]);
                    })->first();

                    if (empty($block) && empty($report)) {
                        $users[] = $m;
                    }

                    if (empty($unMatch) && empty($unMatched)) {
                        $m->visibility = 'enabled';
                    }else{
                        $m->visibility = 'disabled';
                    }
                }

                if(!empty($users)){
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.parents.match.success'),
                        'data'      => $users,
                        'total'     => count($users)
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.match.invalid'),
                    ],400);
                }
            }else{
                return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.parents.match.failure'),
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

    public function RecievedMatches(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'  => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'singleton_id'    => 'required||numeric',
            'page_number'  => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {

            $per_page = 10;
            $page_number = $request->input(key:'page_number', default:1);
            $total = DB::table('recieved_matches')
                        ->where([['recieved_matches.user_id', '=', $request->login_id], ['recieved_matches.user_type', '=', $request->user_type], ['recieved_matches.singleton_id', '=', $request->singleton_id]])
                        ->join('singletons','recieved_matches.recieved_match_id','=','singletons.id')
                        ->count();

            $match = DB::table('recieved_matches')
                        ->where([['recieved_matches.user_id', '=', $request->login_id], ['recieved_matches.user_type', '=', $request->user_type], ['recieved_matches.singleton_id', '=', $request->singleton_id]])
                        ->join('singletons','recieved_matches.recieved_match_id','=','singletons.id')
                        ->join('parents', 'singletons.parent_id', '=', 'parents.id')
                        ->offset(($page_number - 1) * $per_page)
                        ->limit($per_page)
                        ->get(['recieved_matches.user_id','recieved_matches.user_type','recieved_matches.singleton_id','singletons.*','parents.name as parent_name', 'parents.profile_pic as parent_profile_pic', 'parents.relation_with_singleton']);

            $loggedInUser = ParentsModel::find($request->login_id);
            $loggedInUserChild = Singleton::find($request->singleton_id);
            foreach ($match as $m) {
                $lat1 = $m->lat;
                $long1 = $m->long;
                $lat2 = $loggedInUser->lat;
                $long2 = $loggedInUser->long;
                $m->distance = $this->getDistance($lat1, $long1, $lat2, $long2);
            }
            
            if(!$match->isEmpty()){
                $users = [];
                foreach ($match as $m) {
                    $singleton_id = $m->id;
                    $m->is_singleton_blurred_photos = $loggedInUserChild->is_blurred;
                    $mutual = ModelsMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id],
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['match_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id],
                        ]);
                    })->first();

                    if (!empty($mutual)) {
                        $m->match_type = $mutual->match_type;
                        if ($m->gender == 'Male') {
                            $m->blur_image = 'no';
                        } else{
                            if ($m->match_type == 'matched') {
                                $m->blur_image = $mutual->blur_image;
                            } else{
                                $m->blur_image = $m->is_blurred;
                            }
                        }
                    }

                    $block = BlockList::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['blocked_user_id', $m->id],
                            ['blocked_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['blocked_user_id', $request->singleton_id],
                            ['blocked_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $report = ReportedUsers::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['reported_user_id', $m->id],
                            ['reported_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['reported_user_id', $request->singleton_id],
                            ['reported_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatch = UnMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['un_matched_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatched = ModelsMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['match_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id],
                            ['match_type', '=', 'un-matched'],
                        ]);
                    })->first();

                    if (empty($block) && empty($report)) {
                        $users[] = $m;
                    }

                    if (empty($unMatch) && empty($unMatched)) {
                        $m->visibility = 'enabled';
                    }else{
                        $m->visibility = 'disabled';
                    }
                }

                if(!empty($users)){
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.parents.received-match.success'),
                        'data'      => $users,
                        'total'     => count($users)
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.received-match.invalid'),
                    ],400);
                }
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.received-match.failure'),
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

    public function RefferedMatches(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'  => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'singleton_id'    => 'required||numeric',
            'page_number'  => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {

            $per_page = 10;
            $page_number = $request->input(key:'page_number', default:1);
            $total = DB::table('referred_matches')
                        ->where([['referred_matches.user_id', '=', $request->login_id], ['referred_matches.user_type', '=', $request->user_type], ['referred_matches.singleton_id', '=', $request->singleton_id]])
                        ->join('singletons', 'referred_matches.referred_match_id', '=', 'singletons.id')
                        ->count();

            $match = DB::table('referred_matches')
                    ->where([['referred_matches.user_id', '=', $request->login_id], ['referred_matches.user_type', '=', $request->user_type], ['referred_matches.singleton_id', '=', $request->singleton_id]])
                    ->join('singletons', 'referred_matches.referred_match_id', '=', 'singletons.id')
                    ->join('parents', 'singletons.parent_id', '=', 'parents.id')
                    ->offset(($page_number - 1) * $per_page)
                    ->limit($per_page)
                    ->get(['referred_matches.user_id','referred_matches.user_type','referred_matches.singleton_id','singletons.*','parents.name as parent_name', 'parents.profile_pic as parent_profile_pic', 'parents.relation_with_singleton']);

            $loggedInUser = ParentsModel::find($request->login_id);
            $loggedInUserChild = Singleton::find($request->singleton_id);
            foreach ($match as $m) {
                $lat1 = $m->lat;
                $long1 = $m->long;
                $lat2 = $loggedInUser->lat;
                $long2 = $loggedInUser->long;
                $m->distance = $this->getDistance($lat1, $long1, $lat2, $long2);
            }
        
            if(!$match->isEmpty()){
                $users = [];
                foreach ($match as $m) {
                    $singleton_id = $m->id;
                    $m->is_singleton_blurred_photos = $loggedInUserChild->is_blurred;
                    $mutual = ModelsMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id],
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['match_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id],
                        ]);
                    })->first();

                    if (!empty($mutual)) {
                        $m->match_type = $mutual->match_type;
                        if ($m->gender == 'Male') {
                            $m->blur_image = 'no';
                        } else{
                            if ($m->match_type == 'matched') {
                                $m->blur_image = $mutual->blur_image;
                            } else{
                                $m->blur_image = $m->is_blurred;
                            }
                        }
                    }

                    $block = BlockList::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['blocked_user_id', $m->id],
                            ['blocked_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['blocked_user_id', $request->singleton_id],
                            ['blocked_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $report = ReportedUsers::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['reported_user_id', $m->id],
                            ['reported_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['reported_user_id', $request->singleton_id],
                            ['reported_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatch = UnMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['un_matched_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatched = ModelsMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['match_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id],
                            ['match_type', '=', 'un-matched'],
                        ]);
                    })->first();

                    if (empty($block) && empty($report)) {
                        $users[] = $m;
                    }

                    if (empty($unMatch) && empty($unMatched)) {
                        $m->visibility = 'enabled';
                    }else{
                        $m->visibility = 'disabled';
                    }
                }

                if(!empty($users)){
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.parents.referred-match.success'),
                        'data'      => $users,
                        'total'     => count($users)
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.referred-match.invalid'),
                    ],400);
                }
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.referred-match.failure'),
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

    public function MutualMatches(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'  => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'singleton_id'    => 'required||numeric',
            'page_number'  => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }
        try {

            $per_page = 10;
            $singleton_id = $request->singleton_id;
            $login_id = $request->login_id;
            $page_number = $request->input(key:'page_number', default:1);
            $total = DB::table('matches')
                        ->where(function($query) use ($login_id, $singleton_id) {
                            $query->where([['matches.user_id', '=', $login_id], ['matches.user_type', '=', 'parent'], ['matches.singleton_id', '=', $singleton_id]])
                            ->orWhere([['matches.matched_parent_id', '=', $login_id],['matches.user_type', '=', 'parent'], ['matches.match_id', '=', $singleton_id]]);
                        })
                        ->where(function($query) {
                            $query->where('match_type', '=', 'matched')
                                ->orWhere('match_type', '=', 'un-matched')
                                ->orWhere('match_type', '=', 're-matched');
                        })
                        ->count();
            $match = DB::table('matches')
                        ->leftjoin('singletons', function($join) use ($singleton_id) {
                            $join->on('singletons.id','=','matches.match_id')
                                 ->where('matches.match_id','!=',$singleton_id);
                            $join->orOn('singletons.id','=','matches.singleton_id')
                                 ->where('matches.singleton_id','!=',$singleton_id);
                        })
                        ->where(function($query) use ($login_id, $singleton_id) {
                            $query->where([['matches.user_id', '=', $login_id], ['matches.user_type', '=', 'parent'], ['matches.singleton_id', '=', $singleton_id]])
                            ->orWhere([['matches.matched_parent_id', '=', $login_id],['matches.user_type', '=', 'parent'], ['matches.match_id', '=', $singleton_id]]);
                        })
                        ->where(function($query) {
                            $query->where('match_type', '=', 'matched')
                                  ->orWhere('match_type', '=', 'un-matched')
                                  ->orWhere('match_type', '=', 're-matched');
                        })
                        ->offset(($page_number - 1) * $per_page)
                        ->limit($per_page)
                        ->get(['matches.user_id','matches.user_type','matches.match_type','matches.is_rematched','matches.blur_image','singletons.*']);
                        
            $loggedInUser = ParentsModel::find($request->login_id);
            $loggedInUserChild = Singleton::find($request->singleton_id);
            foreach ($match as $m) {
                $lat1 = $m->lat;
                $long1 = $m->long;
                $lat2 = $loggedInUser->lat;
                $long2 = $loggedInUser->long;
                $m->distance = $this->getDistance($lat1, $long1, $lat2, $long2);
            }

            if(!$match->isEmpty()){
                $users = [];
                foreach ($match as $m) {
                    $singleton_ids = $m->id;
                    $m->match_type = $m->match_type;
                    $m->is_singleton_blurred_photos = $loggedInUserChild->is_blurred;
                    if ($m->gender == 'Male') {
                        $m->blur_image = 'no';
                    } else{
                        if ($m->match_type == 'matched') {
                            $m->blur_image = $m->blur_image;
                        } else{
                            $m->blur_image = $m->is_blurred;
                        }
                    }

                    $block = BlockList::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['blocked_user_id', $m->id],
                            ['blocked_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['blocked_user_id', $request->singleton_id],
                            ['blocked_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $report = ReportedUsers::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', $request->login_id],
                            ['user_type', $request->user_type],
                            ['reported_user_id', $m->id],
                            ['reported_user_type', 'singleton'],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['reported_user_id', $request->singleton_id],
                            ['reported_user_type', 'singleton'],
                            ['user_id', $m->parent_id],
                            ['user_type', 'parent'],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatch = UnMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id]
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['un_matched_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id]
                        ]);
                    })->first();
                    
                    $unMatched = ModelsMatches::where(function ($query) use ($request, $m) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $m->id],
                            ['singleton_id', '=', $request->singleton_id],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['user_id', '=', $m->parent_id],
                            ['user_type', '=', 'parent'],
                            ['match_id', '=', $request->singleton_id],
                            ['singleton_id', '=', $m->id],
                            ['match_type', '=', 'un-matched'],
                        ]);
                    })->first();

                    if (empty($block) && empty($report)) {
                        $users[] = $m;
                    }

                    if (empty($unMatch) && empty($unMatched)) {
                        $m->visibility = 'enabled';
                    }else{
                        $m->visibility = 'disabled';
                    }
                }

                if(!empty($users)){
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.singletons.match.success'),
                        'data'      => $users,
                        'total'     => count($users)
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.singletons.match.failure'),
                    ],400);
                }
                
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.match.invalid'),
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

    public function reMatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'  => 'required||numeric',
            'user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'singleton_id'    => 'required||numeric',
            're_matched_id'   => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {

            $premium = ParentsModel::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
            $featureStatus = PremiumFeatures::whereId(1)->first();
            if ((!empty($featureStatus) && $featureStatus->status == 'active') && (!empty($premium) && $premium->active_subscription_id == '1')) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.re-match.premium'),
                ],400);
            }

            $userExists = Singleton::find($request->re_matched_id);
            if(empty($userExists) || $userExists->status == 'Deleted' || $userExists->status == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.re-match.invalid'),
                ],400);
            }

            $rematched = ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->re_matched_id],['singleton_id', '=', $request->singleton_id]])
                                        ->orWhere([['user_id', '=', $userExists->parent_id], ['user_type', '=', 'parent'],['match_id', '=', $request->singleton_id], ['singleton_id', '=', $request->re_matched_id]])
                                        ->first();   

            if(!empty($rematched) && $rematched->is_rematched == 'yes'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.re-match.rematched'),
                ],400);
            }

            $unmatched = ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->re_matched_id], ['is_rematched', '=', 'no'],['singleton_id', '=', $request->singleton_id], ['match_type', '=', 'un-matched']])
                                        ->orWhere([['user_id', '=', $userExists->parent_id], ['user_type', '=', 'parent'],['match_id', '=', $request->singleton_id], ['singleton_id', '=', $request->re_matched_id], ['is_rematched', '=', 'no'], ['match_type', '=', 'un-matched']])
                                        ->first();
            if(!empty($unmatched)){
               
                $data = [
                    'user_id' => $request->login_id,
                    'user_type' => $request->user_type,
                    'singleton_id' => $request->singleton_id,
                    'matched_table_id' => $unmatched->id,
                    'match_id' => $request->re_matched_id,
                    'matched_parent_id' => $unmatched->matched_parent_id,
                    'created_at' => Carbon::now()
                ];
                
                $uniqueKeys = [
                    'user_id' => $request->login_id,
                    'user_type' => $request->user_type,
                    'singleton_id' => $request->singleton_id,
                    'matched_table_id' => $unmatched->id,
                    'match_id' => $request->re_matched_id,
                    'matched_parent_id' => $unmatched->matched_parent_id,
                ];
                
                $re_matched = DB::table('rematch_requests')->updateOrInsert($uniqueKeys, $data);

                if($re_matched){
                    $sender = ParentsModel::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
                    $reciever = ParentsModel::where([['id', '=', $userExists->parent_id], ['status', '=', 'Unblocked']])->first();
                    $reciever->notify(new ReMatchNotification($sender, $reciever->user_type, $request->re_matched_id));

                    UnMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['un_matched_id', '=', $request->re_matched_id]])
                            ->orWhere([['user_id', '=', $userExists->parent_id], ['user_type', '=', 'parent'], ['un_matched_id', '=', $request->singleton_id]])
                            ->delete();
                
                    $myMatch = MyMatches::updateOrInsert(
                            ['user_id' => $request->login_id, 'user_type' => $request->user_type, 'matched_id' => $request->re_matched_id, 'singleton_id' => $request->singleton_id],
                            ['user_id' => $request->login_id, 'user_type' => $request->user_type, 'matched_id' => $request->re_matched_id, 'singleton_id' => $request->singleton_id]
                        ); 
                    
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.parents.re-match.success'),
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.re-match.failure'),
                    ],400);
                }                    
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.re-match.not-found'),
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

    public function getDistance($lat1, $lon1, $lat2, $lon2) {
        $radius = 6371; // Earth's radius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $radius * $c;
        return $distance;
    }
}
