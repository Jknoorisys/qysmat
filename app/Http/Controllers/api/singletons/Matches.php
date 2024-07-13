<?php

namespace App\Http\Controllers\api\singletons;

use App\Http\Controllers\Controller;
use App\Models\BlockList;
use App\Models\Matches as ModelsMatches;
use App\Models\MessagedUsers;
use App\Models\MyMatches;
use App\Models\ParentChild;
use App\Models\ParentsModel;
use App\Models\PremiumFeatures;
use App\Models\RecievedMatches;
use App\Models\ReferredMatches;
use App\Models\RematchRequests;
use App\Models\ReportedUsers;
use App\Models\Singleton;
use App\Models\UnMatches;
use App\Notifications\MutualMatchNotification;
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

        if (isset($_POST['login_id']) && !empty($_POST['login_id']) && isset($_POST['user_type']) && !empty($_POST['user_type'])) {
            userExist($_POST['login_id'], $_POST['user_type']);
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
                Rule::in(['singleton']),
            ],
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
            $singleton_id = $request->login_id;
            $un_matched_id = $request->un_matched_id;
            $userExists = Singleton::find($request->un_matched_id);
            $singletonDetails = Singleton::find($request->login_id);

            if(empty($userExists) || $userExists->staus == 'Deleted' || $userExists->staus == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.un-match.invalid'),
                ],400);
            }

            if(empty($userExists) || $userExists->staus == 'Deleted' || $userExists->staus == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.un-match.invalid'),
                ],400);
            }

            $is_blurred = $singletonDetails->gender == 'Female' ? $singletonDetails->is_blurred : $userExists->is_blurred;

            $matchExists = MyMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', '0'],['matched_id', '=', $request->un_matched_id]])->first();
            $receievdMatchExists = RecievedMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', '0'],['recieved_match_id', '=', $request->un_matched_id]])->first();
            $referredMatchExists = ReferredMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', '0'],['referred_match_id', '=', $request->un_matched_id]])->first();
            $matched = ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->un_matched_id], ['match_type', '=', 'matched']])
                                        ->orWhere([['user_id', '=', $request->un_matched_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->login_id], ['match_type', '=', 'matched']])
                                        ->first();

            if(!empty($matchExists) || !empty($receievdMatchExists) || !empty($referredMatchExists) || !empty($matched)){
                if (!empty($matched)) {
                    ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->un_matched_id], ['match_type', '=', 'matched']])
                                    ->orWhere([['user_id', '=', $request->un_matched_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->login_id], ['match_type', '=', 'matched']])
                                    ->update(['match_type' => 'un-matched', 'is_reset' => 'no', 'blur_image' => $is_blurred, 'matched_at' => Null, 'updated_at' => date('Y-m-d H:i:s'), 'status' => 'available']);

                    $queue = ModelsMatches::
                                            leftjoin('singletons', function($join) use ($singleton_id) {
                                                $join->on('singletons.id','=','matches.match_id')
                                                    ->where('matches.match_id','!=',$singleton_id);
                                                $join->orOn('singletons.id','=','matches.user_id')
                                                    ->where('matches.user_id','!=',$singleton_id);
                                            })
                                            ->where('singletons.chat_status', '=','available')
                                            ->where(function($query) use ($singleton_id){
                                                $query->where([['matches.user_id', '=', $singleton_id], ['matches.user_type', '=', 'singleton'], ['matches.match_type', '=', 'hold'], ['matches.status', '=', 'available'], ['is_rematched', '=', 'no']])
                                                      ->orWhere([['matches.match_id', '=', $singleton_id], ['matches.user_type', '=', 'singleton'], ['matches.match_type', '=', 'hold'], ['matches.status', '=', 'available'], ['is_rematched', '=', 'no']]);
                                            })
                                            ->orderBy('matches.queue')->first(['matches.*']);

                   if (!empty($queue)) {
                        ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_type', '=', 'hold'], ['queue', '=', $queue->queue]])
                                    ->orWhere([['match_id', '=', $request->login_id], ['user_type', '=', 'singleton'], ['match_type', '=', 'hold'], ['queue', '=', $queue->queue]])
                                    ->update(['match_type' => 'matched', 'is_reset' => 'no', 'queue' => 0, 'matched_at' => date('Y-m-d H:i:s') ,'updated_at' => date('Y-m-d H:i:s')]);
                        
                        $notify = ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_type', '=', 'matched']])
                        ->orWhere([['match_id', '=', $request->login_id], ['user_type', '=', 'singleton'], ['match_type', '=', 'matched']])
                        ->first();

                        if (!empty($notify)) {
                            // send congratulations fcm notification
                            $user2 = Singleton::where([['id', '=', $notify->user_id],['user_type', '=', 'singleton']])->first();
                            $user1 = Singleton::where([['id', '=', $notify->match_id],['user_type', '=', 'singleton']])->first();

                            if (isset($user1) && !empty($user1) && isset($user2) && !empty($user2)) {
                                $msg = __('msg.Congratulations! You got a new match with');
                                $user2->notify(new MutualMatchNotification($user1, $user2->user_type, 0, ($msg.' '.$user1->name)));
                                $user1->notify(new MutualMatchNotification($user2, $user1->user_type, 0, ($msg.' '.$user2->name)));

                                $title = __('msg.Profile Matched');
                                $body = __('msg.Congratulations It’s a Match!');
                                $token1 = $user1->fcm_token;
                                $data = array(
                                    'notType' => "profile_matched",
                                    'user1_id' => $user1 ? $user1->id : '',
                                    'user1_name' => $user1 ?  $user1->name : '',
                                    'user1_profile' => $user1 ?  $user1->photo1 : '',
                                    'user1_blur_image' => $user1 ? ($user1->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                    'user2_id' => $user2 ? $user2->id : '',
                                    'user2_name' => $user2 ? $user2->name : '',
                                    'user2_profile' => $user2 ? $user2->photo1 : '',
                                    'user2_blur_image' => $user2 ? ($user2->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                );
                                sendFCMNotifications($token1, $title, $body, $data);

                                $token2 = $user2->fcm_token;
                                $data1 = array(
                                    'notType' => "profile_matched",
                                    'user1_id' => $user2 ? $user2->id : '',
                                    'user1_name' => $user2 ?  $user2->name : '',
                                    'user1_profile' => $user2 ?  $user2->photo1 : '',
                                    'user1_blur_image' => $user2 ? ($user2->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                    'user2_id' => $user1 ? $user1->id : '',
                                    'user2_name' => $user1 ? $user1->name : '',
                                    'user2_profile' => $user1 ? $user1->photo1 : '',
                                    'user2_blur_image' => $user1 ? ($user1->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                );
                                sendFCMNotifications($token2, $title, $body, $data1);
                            }
                        }
                   }

                   $other_queue = ModelsMatches::leftjoin('singletons', function($join) use ($un_matched_id) {
                                                $join->on('singletons.id','=','matches.match_id')
                                                    ->where('matches.match_id','!=',$un_matched_id);
                                                $join->orOn('singletons.id','=','matches.user_id')
                                                    ->where('matches.user_id','!=',$un_matched_id);
                                            })
                                            ->where('singletons.chat_status', '=','available')
                                            ->where(function($query) use ($un_matched_id){
                                                $query->where([['matches.user_id', '=', $un_matched_id], ['matches.user_type', '=', 'singleton'], ['matches.match_type', '=', 'hold'], ['matches.status', '=', 'available'], ['is_rematched', '=', 'no']])
                                                      ->orWhere([['matches.match_id', '=', $un_matched_id], ['matches.user_type', '=', 'singleton'], ['matches.match_type', '=', 'hold'], ['matches.status', '=', 'available'], ['is_rematched', '=', 'no']]);
                                            })
                                            ->orderBy('matches.queue')->first(['matches.*']);

                    if (!empty($other_queue)) {
                        ModelsMatches::where([['user_id', '=', $request->un_matched_id], ['user_type', '=', 'singleton'], ['match_type', '=', 'hold'], ['queue', '=', $other_queue->queue]])
                                        ->orWhere([['match_id', '=', $request->un_matched_id], ['user_type', '=', 'singleton'], ['match_type', '=', 'hold'], ['queue', '=', $other_queue->queue]])
                                        ->update(['match_type' => 'matched','queue' => 0, 'matched_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

                        $notify = ModelsMatches::where([['user_id', '=', $request->un_matched_id], ['user_type', '=', 'singleton'], ['match_type', '=', 'matched']])
                        ->orWhere([['match_id', '=', $request->un_matched_id], ['user_type', '=', 'singleton'], ['match_type', '=', 'matched']])
                        ->first();

                        if (!empty($notify)) {
                            // send congratulations fcm notification
                            $user2 = Singleton::where([['id', '=', $notify->user_id],['user_type', '=', 'singleton']])->first();
                            $user1 = Singleton::where([['id', '=', $notify->match_id],['user_type', '=', 'singleton']])->first();

                            if (isset($user1) && !empty($user1) && isset($user2) && !empty($user2)) {
                                $msg = __('msg.Congratulations! You got a new match with');
                                $user2->notify(new MutualMatchNotification($user1, $user2->user_type, 0, ($msg.' '.$user1->name)));
                                $user1->notify(new MutualMatchNotification($user2, $user1->user_type, 0, ($msg.' '.$user2->name)));

                                $title = __('msg.Profile Matched');
                                $body = __('msg.Congratulations It’s a Match!');
                                $token1 = $user1->fcm_token;
                                $data = array(
                                    'notType' => "profile_matched",
                                    'user1_id' => $user1 ? $user1->id : '',
                                    'user1_name' => $user1 ?  $user1->name : '',
                                    'user1_profile' => $user1 ?  $user1->photo1 : '',
                                    'user1_blur_image' => $user1 ? ($user1->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                    'user2_id' => $user2 ? $user2->id : '',
                                    'user2_name' => $user2 ? $user2->name : '',
                                    'user2_profile' => $user2 ? $user2->photo1 : '',
                                    'user2_blur_image' => $user2 ? ($user2->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                );
                                sendFCMNotifications($token1, $title, $body, $data);

                                $token2 = $user2->fcm_token;
                                $data1 = array(
                                    'notType' => "profile_matched",
                                    'user1_id' => $user2 ? $user2->id : '',
                                    'user1_name' => $user2 ?  $user2->name : '',
                                    'user1_profile' => $user2 ?  $user2->photo1 : '',
                                    'user1_blur_image' => $user2 ? ($user2->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                    'user2_id' => $user1 ? $user1->id : '',
                                    'user2_name' => $user1 ? $user1->name : '',
                                    'user2_profile' => $user1 ? $user1->photo1 : '',
                                    'user2_blur_image' => $user1 ? ($user1->gender == 'Female' ? $notify->blur_image : 'no') : '',
                                );
                                sendFCMNotifications($token2, $title, $body, $data1);
                            }
                        }
                    }
                }

                if (!empty($matchExists)) {
                    MyMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', '0'],['matched_id', '=', $request->un_matched_id]])->delete();
                }

                if (!empty($receievdMatchExists)) {
                    RecievedMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', '0'],['recieved_match_id', '=', $request->un_matched_id]])->delete();
                }

                if (!empty($referredMatchExists)) {
                    ReferredMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type],['singleton_id', '=', '0'],['referred_match_id', '=', $request->un_matched_id]])->delete();
                }

                $user = new UnMatches();
                $user->un_matched_id             = $request->un_matched_id;
                $user->user_id                   = $request->login_id;
                $user->user_type                 = $request->user_type;
                $user_details                    = $user->save();

                if($user_details){
                    $close = Singleton::where([['id', '=', $request->login_id],['user_type', '=', $request->user_type],['status', '=', 'Unblocked']])
                                ->orWhere([['id', '=', $request->un_matched_id],['user_type', '=', 'singleton'],['status', '=', 'Unblocked']])            
                                ->update(['chat_status' => 'available']);
                        
                    if($close){
                        ModelsMatches::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type],['match_id', '=', $request->un_matched_id],['match_type', '=', 'matched']])
                                        ->orWhere([['user_id', '=', $request->un_matched_id],['user_type', '=', 'singleton'],['match_id', '=', $request->login_id],['match_type', '=', 'matched']])
                                        ->update([
                                            'status' => 'available',
                                            'updated_at'   => date('Y-m-d H:i:s')
                                        ]);
                        $data = [
                            'conversation' => 'no',
                            'updated_at'   => date('Y-m-d H:i:s')
                        ];

                        MessagedUsers::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type],['messaged_user_id', '=', $request->un_matched_id],['user_type', '=', 'singleton']])
                                                ->orWhere([['user_id', '=', $request->un_matched_id],['user_type', '=', 'singleton'],['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type]])
                                                ->update($data);
                    }
                    
                    $sender = Singleton::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
                    $reciever = Singleton::where([['id', '=', $request->un_matched_id], ['status', '=', 'Unblocked']])->first();
                    $msg = __('msg.has unmatched you');
                    $reciever->notify(new UnmatchNotification($sender, $reciever->user_type, 0, $msg));
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.singletons.un-match.success'),
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.singletons.un-match.failure'),
                    ],400);
                }
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.un-match.not-found'),
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
                Rule::in(['singleton']),
            ],
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
            $singleton_id = $request->login_id;
            $page_number = $request->input(key:'page_number', default:1);
            $total = DB::table('my_matches')
                        ->where([['my_matches.user_id', '=', $request->login_id], ['my_matches.user_type', '=', $request->user_type]])
                        ->join('singletons', 'my_matches.matched_id', '=', 'singletons.id')
                        ->count();

            $match = DB::table('my_matches')
                        ->where([['my_matches.user_id', '=', $request->login_id], ['my_matches.user_type', '=', $request->user_type]])
                        ->join('singletons', 'my_matches.matched_id', '=', 'singletons.id')
                        ->offset(($page_number - 1) * $per_page)
                        ->limit($per_page)
                        ->get(['my_matches.user_id','my_matches.user_type','singletons.*']);

            $loggedInUser = Singleton::find($request->login_id);
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
                    $mutual = ModelsMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $singleton_id],
                        ])->orWhere([
                            ['match_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
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

                    $block = BlockList::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['blocked_user_id', $singleton_id],
                        ['blocked_user_type', 'singleton']
                    ])->orWhere([
                        ['blocked_user_id', $request->login_id],
                        ['blocked_user_type', $request->user_type],
                        ['user_id', $singleton_id],
                        ['user_type', 'singleton']
                    ])->first();
                    
                    $report = ReportedUsers::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['reported_user_id', $singleton_id],
                        ['reported_user_type', 'singleton']
                    ])->orWhere([
                        ['reported_user_id', $request->login_id],
                        ['reported_user_type', $request->user_type],
                        ['user_id', $singleton_id],
                        ['user_type', 'singleton']
                    ])->first();

                    $unMatch = UnMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $singleton_id],
                        ])->orWhere([
                            ['un_matched_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
                        ]);
                    })->first();

                    $unMatched = ModelsMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $singleton_id],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['match_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
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

                    // if (empty($unMatch)) {
                    //     $users[] = $m;
                    // }

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
                    'message'   => __('msg.singletons.match.failure'),
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
                Rule::in(['singleton']),
            ],
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
                        ->where([['recieved_matches.user_id', '=', $request->login_id], ['recieved_matches.user_type', '=', $request->user_type]])
                        ->join('singletons','recieved_matches.recieved_match_id','=','singletons.id')
                        ->count();

            $match = DB::table('recieved_matches')
                        ->where([['recieved_matches.user_id', '=', $request->login_id], ['recieved_matches.user_type', '=', $request->user_type]])
                        ->join('singletons','recieved_matches.recieved_match_id','=','singletons.id')
                        ->offset(($page_number - 1) * $per_page)
                        ->limit($per_page)
                        ->get(['recieved_matches.user_id','recieved_matches.user_type','recieved_matches.singleton_id','singletons.*']);

            $loggedInUser = Singleton::find($request->login_id);
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

                    $mutual = ModelsMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $singleton_id],
                        ])->orWhere([
                            ['match_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
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

                    $block = BlockList::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['blocked_user_id', $singleton_id],
                        ['blocked_user_type', 'singleton']
                    ])->orWhere([
                        ['blocked_user_id', $request->login_id],
                        ['blocked_user_type', $request->user_type],
                        ['user_id', $singleton_id],
                        ['user_type', 'singleton']
                    ])->first();
                    
                    $report = ReportedUsers::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['reported_user_id', $singleton_id],
                        ['reported_user_type', 'singleton']
                    ])->orWhere([
                        ['reported_user_id', $request->login_id],
                        ['reported_user_type', $request->user_type],
                        ['user_id', $singleton_id],
                        ['user_type', 'singleton']
                    ])->first();

                    $unMatch = UnMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $singleton_id],
                        ])->orWhere([
                            ['un_matched_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
                        ]);
                    })->first();

                    $unMatched = ModelsMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $singleton_id],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['match_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
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

                    // if (empty($unMatch)) {
                    //     $users[] = $m;
                    // }
                }

                if(!empty($users)){
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.singletons.received-match.success'),
                        'data'      => $users,
                        'total'     => count($users)
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.singletons.received-match.failure'),
                    ],400);
                }
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.received-match.failure'),
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
                Rule::in(['singleton']),
            ],
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
                        ->where([['referred_matches.user_id', '=', $request->login_id], ['referred_matches.user_type', '=', $request->user_type]])
                        ->join('singletons', 'referred_matches.referred_match_id', '=', 'singletons.id')
                        ->count();
                        
            $match = DB::table('referred_matches')
                        ->where([['referred_matches.user_id', '=', $request->login_id], ['referred_matches.user_type', '=', $request->user_type]])
                        ->join('singletons', 'referred_matches.referred_match_id', '=', 'singletons.id')
                        ->offset(($page_number - 1) * $per_page)
                        ->limit($per_page)
                        ->get(['referred_matches.user_id','referred_matches.user_type','referred_matches.singleton_id','singletons.*']);

            $loggedInUser = Singleton::find($request->login_id);
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
                    $mutual = ModelsMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $singleton_id],
                        ])->orWhere([
                            ['match_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
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

                    $block = BlockList::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['blocked_user_id', $singleton_id],
                        ['blocked_user_type', 'singleton']
                    ])->orWhere([
                        ['blocked_user_id', $request->login_id],
                        ['blocked_user_type', $request->user_type],
                        ['user_id', $singleton_id],
                        ['user_type', 'singleton']
                    ])->first();
                    
                    $report = ReportedUsers::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['reported_user_id', $singleton_id],
                        ['reported_user_type', 'singleton']
                    ])->orWhere([
                        ['reported_user_id', $request->login_id],
                        ['reported_user_type', $request->user_type],
                        ['user_id', $singleton_id],
                        ['user_type', 'singleton']
                    ])->first();

                    $unMatch = UnMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $singleton_id],
                        ])->orWhere([
                            ['un_matched_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
                        ]);
                    })->first();

                    $unMatched = ModelsMatches::where(function ($query) use ($request, $singleton_id) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $singleton_id],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['match_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_id],
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

                    // if (empty($unMatch)) {
                    //     $users[] = $m;
                    // }
                }

                if(!empty($users)){
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.singletons.referred-match.success'),
                        'data'      => $users,
                        'total'     => count($users)
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.singletons.referred-match.failure'),
                    ],400);
                }
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.referred-match.invalid'),
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
                Rule::in(['singleton']),
            ],
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
            $singleton_id = $request->login_id;
            $page_number = $request->input(key:'page_number', default:1);
            $total = DB::table('matches')
                        ->where(function($query) use ($singleton_id) {
                            $query->where([['matches.user_id', '=', $singleton_id], ['matches.user_type', '=', 'singleton']])
                                  ->orWhere([['matches.match_id', '=', $singleton_id], ['matches.user_type', '=', 'singleton']]);
                        })
                        ->where(function($query) {
                            $query->where('match_type', '=', 'matched')
                                  ->orWhere('match_type', '=', 'un-matched')
                                  ->orWhere('match_type', '=', 're-matched');
                        })
                        ->count();

            $match = DB::table('matches')
                        ->where(function($query) use ($singleton_id) {
                            $query->where([['matches.user_id', '=', $singleton_id], ['matches.user_type', '=', 'singleton']])
                                  ->orWhere([['matches.match_id', '=', $singleton_id], ['matches.user_type', '=', 'singleton']]);
                        })
                        ->where(function($query) {
                            $query->where('match_type', '=', 'matched')
                                  ->orWhere('match_type', '=', 'un-matched')
                                  ->orWhere('match_type', '=', 're-matched');
                        })
                        ->leftjoin('singletons', function($join) use ($singleton_id) {
                            $join->on('singletons.id','=','matches.match_id')
                                 ->where('matches.match_id','!=',$singleton_id);
                            $join->orOn('singletons.id','=','matches.user_id')
                                 ->where('matches.user_id','!=',$singleton_id);
                        })
                        ->offset(($page_number - 1) * $per_page)
                        ->limit($per_page)
                        ->get(['matches.user_id','matches.user_type','matches.match_type','matches.is_rematched','matches.blur_image','singletons.*']);      

            $loggedInUser = Singleton::find($request->login_id);
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
                    if ($m->gender == 'Male') {
                        $m->blur_image = 'no';
                    } else{
                        if ($m->match_type == 'matched') {
                            $m->blur_image = $m->blur_image;
                        } else{
                            $m->blur_image = $m->is_blurred;
                        }
                    }

                    $block = BlockList::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['blocked_user_id', $singleton_ids],
                        ['blocked_user_type', 'singleton']
                    ])->orWhere([
                        ['blocked_user_id', $request->login_id],
                        ['blocked_user_type', $request->user_type],
                        ['user_id', $singleton_ids],
                        ['user_type', 'singleton']
                    ])->first();
                    
                    $report = ReportedUsers::where([
                        ['user_id', $request->login_id],
                        ['user_type', $request->user_type],
                        ['reported_user_id', $singleton_ids],
                        ['reported_user_type', 'singleton']
                    ])->orWhere([
                        ['reported_user_id', $request->login_id],
                        ['reported_user_type', $request->user_type],
                        ['user_id', $singleton_ids],
                        ['user_type', 'singleton']
                    ])->first();

                    $unMatch = UnMatches::where(function ($query) use ($request, $singleton_ids) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['un_matched_id', '=', $singleton_ids],
                        ])->orWhere([
                            ['un_matched_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_ids],
                        ]);
                    })->first();

                    $unMatched = ModelsMatches::where(function ($query) use ($request, $singleton_ids) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['match_id', '=', $singleton_ids],
                            ['match_type', '=', 'un-matched'],
                        ])->orWhere([
                            ['match_id', '=', $request->login_id],
                            ['user_type', '=', 'singleton'],
                            ['user_id', '=', $singleton_ids],
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

                // return response()->json([
                //     'status'    => 'success',
                //     'message'   => __('msg.singletons.match.success'),
                //     'data'      => $users,
                //     'total'     => $total
                // ],200);

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
                    'message'   => __('msg.singletons.match.invalid'),
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
                Rule::in(['singleton']),
            ],
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
            $singleton_id = $request->login_id;
            $re_matched_id = $request->re_matched_id;
            $premium = Singleton::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
            $featureStatus = PremiumFeatures::whereId(1)->first();
            if ((!empty($featureStatus) && $featureStatus->status == 'active') && (!empty($premium) && $premium->active_subscription_id == '1')) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.re-match.premium'),
                ],400);
            }

            $userExists = Singleton::find($request->re_matched_id);
            if(empty($userExists) || $userExists->staus == 'Deleted' || $userExists->staus == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.re-match.invalid'),
                ],400);
            }

            $rematched = ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->re_matched_id]])
                                        ->orWhere([['user_id', '=', $request->re_matched_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->login_id]])
                                        ->first();

            if(!empty($rematched) && $rematched->is_rematched == 'yes'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.re-match.rematched'),
                ],400);
            }

            $blocked = BlockList::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['blocked_user_id', '=', $request->re_matched_id], ['blocked_user_type', '=', 'singleton']])
                                        ->orWhere([['user_id', '=', $request->re_matched_id], ['user_type', '=', 'singleton'], ['blocked_user_id', '=', $request->login_id], ['blocked_user_type', '=', 'singleton']])
                                        ->first();

            if(!empty($blocked)){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.re-match.failure'),
                ],400);
            }

            $reported = ReportedUsers::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['reported_user_id', '=', $request->re_matched_id], ['reported_user_type', '=', 'singleton']])
                                        ->orWhere([['user_id', '=', $request->re_matched_id], ['user_type', '=', 'singleton'], ['reported_user_id', '=', $request->login_id], ['reported_user_type', '=', 'singleton']])
                                        ->first();

            if(!empty($reported)){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.re-match.failure'),
                ],400);
            }

            $unmatched = ModelsMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['match_id', '=', $request->re_matched_id], ['match_type', '=', 'un-matched'], ['is_rematched', '=', 'no']])
                                        ->orWhere([['user_id', '=', $request->re_matched_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->login_id], ['match_type', '=', 'un-matched'], ['is_rematched', '=', 'no']])
                                        ->first();

            if(!empty($unmatched)){
                
                $data = [
                    'user_id' => $request->login_id,
                    'user_type' => $request->user_type,
                    'matched_table_id' => $unmatched->id,
                    'match_id' => $request->re_matched_id,
                    'matched_parent_id' => $unmatched->matched_parent_id,
                    'created_at' => Carbon::now()
                ];
                
                $uniqueKeys = [
                    'user_id' => $request->login_id,
                    'user_type' => $request->user_type,
                    'matched_table_id' => $unmatched->id,
                    'match_id' => $request->re_matched_id,
                    'matched_parent_id' => $unmatched->matched_parent_id,
                ];
                
                $re_matched = DB::table('rematch_requests')->updateOrInsert($uniqueKeys, $data);
                
                
                if($re_matched){
                    $sender = Singleton::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
                    $reciever = Singleton::where([['id', '=', $request->re_matched_id], ['status', '=', 'Unblocked']])->first();
                    $reciever->notify(new ReMatchNotification($sender, $reciever->user_type, 0));
                    
                    UnMatches::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['un_matched_id', '=', $request->re_matched_id]])
                                ->orWhere([['user_id', '=', $request->re_matched_id], ['user_type', '=', 'singleton'], ['un_matched_id', '=', $request->login_id]])
                                ->delete();

                    $myMatch = MyMatches::updateOrInsert(
                        ['user_id' => $request->login_id, 'user_type' => $request->user_type, 'matched_id' => $request->re_matched_id],
                        ['user_id' => $request->login_id, 'user_type' => $request->user_type, 'matched_id' => $request->re_matched_id]
                    );
                    
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.singletons.re-match.success'),
                    ],200);
                }else{
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.singletons.re-match.failure'),
                    ],400);
                }
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.re-match.not-found'),
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

    public function matchFound(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'user1_id'  => 'required||numeric',
            'user2_id' => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            $user1 = Singleton::where([['id', '=', $request->user1_id], ['status', '=', 'Unblocked']])->first();
            if(empty($user1) || $user1->staus == 'Deleted' || $user1->staus == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.matchFound.user1'),
                ],400);
            }

            $user2 = Singleton::where([['id', '=', $request->user2_id], ['status', '=', 'Unblocked']])->first();
            if(empty($user2) || $user2->staus == 'Deleted' || $user2->staus == 'Blocked'){
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.matchFound.user2'),
                ],400);
            }

            if (!empty($user1) && !empty($user2)) {
                if ($user1->parent_id && $user1->parent_id != 0) {
                    $parent = ParentsModel::where('id','=', $user1->parent_id)->first();
                    $user1->parent_name = $parent ? $parent->name : '';
                    $user1->parent_profile = $parent ? $parent->profile_pic : '';
                }

                if ($user2->parent_id && $user2->parent_id != 0) {
                    $parent = ParentsModel::where('id','=', $user2->parent_id)->first();
                    $user2->parent_name = $parent ? $parent->name : '';
                    $user2->parent_profile = $parent ? $parent->profile_pic : '';
                }

                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.matchFound.success'),
                    'user1'     => $user1,
                    'user2'     => $user2,
                ],200);
            } else {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.matchFound.failure'),
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
