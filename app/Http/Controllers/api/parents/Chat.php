<?php

namespace App\Http\Controllers\api\parents;

use App\Http\Controllers\Controller;
use App\Models\BlockList;
use App\Models\ChatHistory;
use App\Models\Matches;
use App\Models\MessagedUsers;
use App\Models\MyMatches;
use App\Models\ParentChild;
use App\Models\ParentsModel;
use App\Models\RecievedMatches;
use App\Models\ReferredMatches;
use App\Models\Singleton;
use App\Models\ReportedUsers as ModelsReportedUsers;
use App\Models\UnMatches;
use App\Notifications\MutualMatchNotification;
use App\Notifications\ReferNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Chat extends Controller
{
    public function  __construct()
    {
        $lang = (isset($_POST['language']) && !empty($_POST['language'])) ? $_POST['language'] : 'en';
        App::setlocale($lang);
        if (isset($_POST['login_id']) && !empty($_POST['login_id']) && isset($_POST['user_type']) && !empty($_POST['user_type']) && isset($_POST['singleton_id']) && !empty($_POST['singleton_id'])) {
            parentExist($_POST['login_id'], $_POST['user_type'], $_POST['singleton_id']);
        }
    }

    // send message to parent
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
            'singleton_id'       => 'required||numeric',
            'messaged_user_id'   => 'required||numeric',
            'messaged_user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'messaged_user_singleton_id' => 'required||numeric',
            'message'   => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            $block = BlockList ::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['blocked_user_id', '=', $request->messaged_user_singleton_id], ['blocked_user_type', '=', 'singleton'], ['singleton_id', '=', $request->singleton_id]])
                                ->orWhere([['user_id', '=', $request->messaged_user_id], ['user_type', '=', $request->messaged_user_type], ['blocked_user_id', '=', $request->singleton_id], ['blocked_user_type', '=', 'singleton'], ['singleton_id', '=', $request->messaged_user_singleton_id]])->first();
            if (!empty($block)) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.send-message.failure'),
                ],400);
            }

            $report = ModelsReportedUsers ::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['reported_user_id', '=', $request->messaged_user_singleton_id], ['reported_user_type', '=', 'singleton'], ['singleton_id', '=', $request->singleton_id]])
            ->orWhere([['user_id', '=', $request->messaged_user_id], ['user_type', '=', $request->messaged_user_type], ['reported_user_id', '=', $request->singleton_id], ['reported_user_type', '=', 'singleton'], ['singleton_id', '=', $request->messaged_user_singleton_id]])->first();
            if (!empty($report)) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.send-message.failure'),
                ],400);
            }

            $unMatch = UnMatches ::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['un_matched_id', '=', $request->messaged_user_singleton_id], ['singleton_id', '=', $request->singleton_id]])
            ->orWhere([['user_id', '=', $request->messaged_user_id], ['user_type', '=', $request->messaged_user_type], ['un_matched_id', '=', $request->singleton_id], ['singleton_id', '=', $request->messaged_user_singleton_id]])->first();
            if (!empty($unMatch)) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.send-message.failure'),
                ],400);
            }

            $not_in_list4 = Matches::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type],['match_id', '=', $request->messaged_user_singleton_id],['match_type', '=', 'matched'], ['singleton_id', '=', $request->singleton_id]])
                                    ->orWhere([['user_id', '=', $request->messaged_user_id],['user_type', '=', 'parent'],['match_id', '=', $request->singleton_id],['match_type', '=', 'matched'], ['singleton_id', '=', $request->messaged_user_singleton_id]])
                                    ->first();

            if (empty($not_in_list4)) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.send-message.failure'),
                ],400);
            }


            $conversation = MessagedUsers::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type], ['singleton_id', '=', $request->singleton_id],['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', $request->messaged_user_type], ['messaged_user_singleton_id', '=', $request->messaged_user_singleton_id]])
                                            ->orWhere([['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type], ['messaged_user_singleton_id', '=', $request->singleton_id],['user_id', '=', $request->messaged_user_id],['user_type', '=', $request->messaged_user_type], ['singleton_id', '=', $request->messaged_user_singleton_id]])
                                            ->first();
           
            if (!empty($conversation)) {
                $sender = MessagedUsers:: where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type], ['singleton_id', '=', $request->singleton_id],['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', $request->messaged_user_type], ['messaged_user_singleton_id', '=', $request->messaged_user_singleton_id]])->first();
                if (empty($sender)) {
                    $data = [
                        'conversation' => 'yes',
                        'updated_at'   => date('Y-m-d H:i:s')
                    ];
    
                    $reply = MessagedUsers::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type], ['singleton_id', '=', $request->singleton_id],['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', $request->messaged_user_type], ['messaged_user_singleton_id', '=', $request->messaged_user_singleton_id]])
                                            ->orWhere([['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type], ['messaged_user_singleton_id', '=', $request->singleton_id],['user_id', '=', $request->messaged_user_id],['user_type', '=', $request->messaged_user_type], ['singleton_id', '=', $request->messaged_user_singleton_id]])
                                            ->update($data);
    
                }
            } else {
                $data = [
                    'user_id' => $request->login_id,
                    'user_type' => $request->user_type,
                    'singleton_id' => $request->singleton_id,
                    'messaged_user_id' => $request->messaged_user_id,
                    'messaged_user_type' => $request->messaged_user_type,
                    'messaged_user_singleton_id' => $request->messaged_user_singleton_id
                ];
                MessagedUsers::insert($data);
            }

            $message                     = new ChatHistory();
            $message->user_id            = $request->login_id ? $request->login_id : '';
            $message->user_type          = $request->user_type ? $request->user_type : '';
            $message->singleton_id       = $request->singleton_id ? $request->singleton_id : '';
            $message->messaged_user_id   = $request->messaged_user_id ? $request->messaged_user_id : '';
            $message->messaged_user_type = $request->messaged_user_type ? $request->messaged_user_type : '';
            $message->messaged_user_singleton_id = $request->messaged_user_singleton_id ? $request->messaged_user_singleton_id : '';
            $message->message            = $request->message ? $request->message : '';
            $messaged                    = $message->save();

            if (!empty($messaged)) {

                MessagedUsers::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type], ['singleton_id', '=', $request->singleton_id],['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', $request->messaged_user_type], ['messaged_user_singleton_id', '=', $request->messaged_user_singleton_id]])
                ->orWhere([['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type], ['messaged_user_singleton_id', '=', $request->singleton_id],['user_id', '=', $request->messaged_user_id],['user_type', '=', $request->messaged_user_type], ['singleton_id', '=', $request->messaged_user_singleton_id]])
                ->update(['deleted_by' => '0']);
                
                $unreadCounter = ChatHistory::where([['user_id', '=', $request->login_id],['user_type', '=', 'parent'],['singleton_id', '=', $request->singleton_id],
                                                    ['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', 'parent'],['messaged_user_singleton_id', '=', $request->messaged_user_singleton_id]])                        
                                                ->whereNull('read_at')->count();

                $overallUnreadCounter = ChatHistory::where([['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', 'parent'],['messaged_user_singleton_id', '=', $request->messaged_user_singleton_id]])                        
                                                ->whereNull('read_at')->count();
                $title = __('msg.New Message');
                $reciever = ParentsModel::where([['id', '=', $request->messaged_user_id], ['status', '=', 'Unblocked']])->first();
                if (isset($reciever) && !empty($reciever)) {
                    $fcm_regid[] = $reciever->fcm_token;
                    $body = $request->message;
                    $token = $reciever->fcm_token;
                    $data = array(
                        'notType' => "chat",
                        'unread_counter' => $unreadCounter,
                        'overall_unread_counter' => $overallUnreadCounter,
                    );
                    $result = sendFCMNotifications($token, $title, $body, $data);
                }

                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.parents.send-message.success'),
                    'data'      => $message
                ],200);
            } else {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.send-message.failure'),
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

    public function messagedUsers(Request $request)
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
            'singleton_id'       => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            $parent_id = $request->login_id;
            $loggedInUserChild = Singleton::find($request->singleton_id);

            $list = MessagedUsers::leftjoin('parents', function($join) use ($parent_id) {
                                        $join->on('parents.id','=','messaged_users.messaged_user_id')
                                            ->where('messaged_users.messaged_user_id','!=',$parent_id);
                                        $join->orOn('parents.id','=','messaged_users.user_id')
                                            ->where('messaged_users.user_id','!=',$parent_id);
                                    })
                                    ->leftJoin('singletons as sigleton', function($join) use ($parent_id) {
                                        $join->on('sigleton.id','=','messaged_users.messaged_user_singleton_id')
                                            ->where('messaged_users.messaged_user_id','!=',$parent_id);
                                        $join->orOn('sigleton.id','=','messaged_users.singleton_id')
                                            ->where('messaged_users.user_id','!=',$parent_id);
                                    })
                                    ->where([['messaged_users.user_id', '=', $request->login_id],['messaged_users.user_type', '=', $request->user_type], ['messaged_users.singleton_id', '=', $request->singleton_id], ['deleted_by', '!=', $request->login_id]])
                                    ->orWhere([['messaged_users.messaged_user_id', '=', $request->login_id],['messaged_users.messaged_user_type', '=', $request->user_type], ['messaged_users.messaged_user_singleton_id', '=', $request->singleton_id], ['deleted_by', '!=', $request->login_id]])
                                    ->select('messaged_users.user_id','messaged_users.singleton_id','messaged_users.messaged_user_id','messaged_users.messaged_user_singleton_id','parents.*','sigleton.gender as singleton_gender','sigleton.is_blurred')
                                    ->orderBy('messaged_users.id', 'desc')
                                    ->get();

            $filteredList = [];
            $ids = [];
            foreach ($list as $key => $value) {
                $list[$key]->is_singleton_blurred_photos = $loggedInUserChild->is_blurred;
                $match_type = Matches::where(function ($query) use ($request, $value) {
                    $query->where([
                        ['match_id', '=', $value->messaged_user_singleton_id],
                        ['user_type', '=', 'parent'],
                        ['user_id', '=', $value->user_id],
                        ['singleton_id', '=', $value->singleton_id]
                    ])->orWhere([
                        ['match_id', '=', $value->singleton_id],
                        ['user_type', '=', 'parent'],
                        ['user_id', '=', $value->messaged_user_id],
                        ['singleton_id', '=', $value->messaged_user_singleton_id]
                    ]);
                })->first();


                if (!empty($match_type)) {
                    if ($value->singleton_gender == 'Male') {
                        $list[$key]->blur_image = 'no';
                    } else{
                        if ($match_type->match_type == 'matched') {
                            $list[$key]->blur_image = $match_type->blur_image;
                        } else{
                            $list[$key]->blur_image = $value->is_blurred;
                        }
                    }
                    $list[$key]->match_type = $match_type->match_type;
                }

                
                // $block = BlockList::where([
                //     ['user_id', '=', $request->messaged_user_id],
                //     ['user_type', '=', 'parent'],
                //     ['singleton_id', '=', $value->messaged_user_singleton_id],
                //     ['blocked_user_id', '=', $request->singleton_id],
                //     ['blocked_user_type', '=', 'singleton']
                // ])->first();  
                
                // $report = ModelsReportedUsers::where([
                //     ['user_id', '=', $request->messaged_user_id],
                //     ['user_type', '=', 'parent'],
                //     ['singleton_id', '=', $value->messaged_user_singleton_id],
                //     ['reported_user_id', '=', $request->singleton_id],
                //     ['reported_user_type', '=', 'singleton']
                // ])->first();  

                // $unMatch = UnMatches::where(function ($query) use ($value, $request) {
                //     $query->where([
                //         ['user_id', '=', $request->login_id],
                //         ['user_type', '=', $request->user_type],
                //         ['singleton_id', '=', $request->singleton_id],
                //         ['un_matched_id', '=', $value->messaged_user_singleton_id]
                //     ])->orWhere([
                //         ['user_id', '=', $value->messaged_user_id],
                //         ['user_type', '=', 'parent'],
                //         ['singleton_id', '=', $value->messaged_user_singleton_id],
                //         ['un_matched_id', '=', $request->singleton_id]
                //     ]);
                // })->first();

                // $unMatched = Matches::where(function ($query) use ($request, $value) {
                //     $query->where([
                //         ['user_id', '=', $request->login_id],
                //         ['user_type', '=', 'parent'],
                //         ['match_id', '=', $value->messaged_user_singleton_id],
                //         ['singleton_id', '=', $request->singleton_id],
                //         ['match_type', '=', 'un-matched'],
                //     ])->orWhere([
                //         ['user_id', '=', $value->messaged_user_id],
                //         ['user_type', '=', 'parent'],
                //         ['match_id', '=', $request->singleton_id],
                //         ['singleton_id', '=', $value->messaged_user_singleton_id],
                //         ['match_type', '=', 'un-matched'],
                //     ]);
                // })->first();

                $last_message = ChatHistory::where([['chat_histories.user_id', '=', $value->user_id],['chat_histories.user_type', '=','parent'],['chat_histories.singleton_id', '=', $value->singleton_id],['chat_histories.messaged_user_id', '=', $value->messaged_user_id],['chat_histories.messaged_user_type', '=', 'parent'],['deleted_by', '!=', $request->login_id]])
                                            ->orWhere([['chat_histories.user_id', '=', $value->messaged_user_id],['chat_histories.user_type', '=', 'parent'],['chat_histories.messaged_user_id', '=', $value->user_id],['chat_histories.messaged_user_type', '=', 'parent'],['chat_histories.singleton_id', '=', $value->messaged_user_singleton_id],['deleted_by', '!=', $request->login_id]])                        
                                            ->select('chat_histories.message')
                                            ->orderBy('chat_histories.id', 'desc')
                                            ->first();

                $list[$key]->last_message = $last_message ? $last_message->message : trans('msg.Deleted');
                // if (!empty($unMatch) && !empty($unMatched)) {
                //     $list[$key]->chat_status = 'disabled';
                // }else{
                //     $list[$key]->chat_status = 'enabled';
                // }

                if ($value->user_id != $parent_id) {
                    $user_id = $value->messaged_user_id;
                    $singleton_id = $value->messaged_user_singleton_id;
                    $messaged_user_id = $value->user_id;
                    $messaged_user_singleton_id = $value->singleton_id;
                    $value->user_id = $user_id;
                    $value->singleton_id = $singleton_id;
                    $value->messaged_user_id = $messaged_user_id;
                    $value->messaged_user_singleton_id = $messaged_user_singleton_id;
                }

                $unreadCounter = ChatHistory::where([['user_id', '=', $value->messaged_user_id],['user_type', '=', 'parent'],['singleton_id', '=', $value->messaged_user_singleton_id],
                                                ['messaged_user_id', '=', $value->user_id],['messaged_user_type', '=', 'parent'],['messaged_user_singleton_id', '=', $value->singleton_id]])                        
                                            ->whereNull('read_at')->count();

                $list[$key]->unread_counter = $unreadCounter;
                // if (empty($block) && empty($report)) {
                //     $filteredList[] = $value;
                // }else{
                //     $ids[] = $value->messaged_user_id;
                // }
            }

            $overallUnreadCounter_db = ChatHistory::where([['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', 'parent'],['messaged_user_singleton_id', '=', $request->singleton_id]])->whereNull('read_at');                        
            
            // if (!empty($ids)) {
            //     $overallUnreadCounter_db->whereNotIn('id', $ids);
            // }
            
            $overallUnreadCounter = $overallUnreadCounter_db->count();
            
            if(!$list->isEmpty()){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.parents.messaged-users.success'),
                    'overall_unread_counter' => $overallUnreadCounter,
                    'data'      => $list
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.messaged-users.failure'),
                    'overall_unread_counter' => $overallUnreadCounter,
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

    public function chatHistory(Request $request)
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
            'singleton_id'       => 'required||numeric',
            'messaged_user_id'   => 'required||numeric',
            'messaged_user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'messaged_user_singleton_id'   => 'required||numeric',
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
            $total = ChatHistory::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type],['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', $request->messaged_user_type],['singleton_id', '=', $request->singleton_id]])
                                    ->orWhere([['user_id', '=', $request->messaged_user_id],['user_type', '=', $request->messaged_user_type],['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type],['singleton_id', '=', $request->messaged_user_singleton_id]])
                                    ->count();

            $chat = ChatHistory::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type],['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', $request->messaged_user_type],['singleton_id', '=', $request->singleton_id]])
                                    ->orWhere([['user_id', '=', $request->messaged_user_id],['user_type', '=', $request->messaged_user_type],['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type],['singleton_id', '=', $request->messaged_user_singleton_id]])
                                    ->orderBy('id', 'desc')
                                    ->offset(($page_number - 1) * $per_page)
                                    ->limit($per_page)
                                    ->get();

            if(!$chat->isEmpty()){
                $markAsRead = DB::table('chat_histories')->where([['user_id', '=', $request->messaged_user_id],['user_type', '=', $request->messaged_user_type],['singleton_id', '=', $request->messaged_user_singleton_id],
                                                                    ['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type],['messaged_user_singleton_id', '=', $request->singleton_id]])
                                                        ->update(['read_at' => Carbon::now()]);
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.parents.chat-history.success'),
                    'data'      => $chat,
                    'total'     => $total
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.chat-history.failure'),
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

    public function inviteChild(Request $request)
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
            'singleton_id'       => 'required||numeric',
            'messaged_user_id'   => 'required||numeric',
            'messaged_user_singleton_id'   => 'required||numeric',
            'messaged_user_type' => [
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
            $block = BlockList ::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['singleton_id', '=', $request->singleton_id],['blocked_user_id', '=', $request->messaged_user_singleton_id], ['blocked_user_type', '=', 'singleton']])->first();
            if (!empty($block)) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.swips.blocked'),
                ],400);
            }

            $report = ModelsReportedUsers ::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['singleton_id', '=', $request->singleton_id],['reported_user_id', '=', $request->messaged_user_singleton_id], ['reported_user_type', '=', 'singleton']])->first();
            if (!empty($report)) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.swips.reported'),
                ],400);
            }

            $unMatch = UnMatches ::where([['user_id', '=', $request->login_id], ['user_type', '=', $request->user_type], ['singleton_id', '=', $request->singleton_id], ['un_matched_id', '=', $request->messaged_user_singleton_id]])->first();
            if (!empty($unMatch)) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.swips.un-matched'),
                ],400);
            }
            

            $linked = ParentChild::where([['parent_id','=',$request->login_id],['singleton_id','=',$request->singleton_id],['status','=','Linked']])->first();
            if (!empty($linked)) {
                $block = BlockList ::where([['user_id', '=', $request->singleton_id], ['user_type', '=', 'singleton'], ['blocked_user_id', '=', $request->messaged_user_singleton_id], ['blocked_user_type', '=', 'singleton']])->first();
                if (!empty($block)) {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.invitation.blocked'),
                    ],400);
                }

                $report = ModelsReportedUsers ::where([['user_id', '=', $request->singleton_id], ['user_type', '=', 'singleton'], ['reported_user_id', '=', $request->messaged_user_singleton_id], ['reported_user_type', '=', 'singleton']])->first();
                if (!empty($report)) {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.invitation.reported'),
                    ],400);
                }

                $unMatch = UnMatches ::where([['user_id', '=', $request->singleton_id], ['user_type', '=', 'singleton'], ['un_matched_id', '=', $request->messaged_user_singleton_id]])->first();
                if (!empty($unMatch)) {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.invitation.un-matched'),
                    ],400);
                }

                $refer = ReferredMatches ::where([['user_id', '=', $linked->singleton_id], ['user_type', '=', 'singleton'],['referred_match_id', '=', $request->messaged_user_singleton_id]])->first();
                if (!empty($refer)) {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.swips.invite'),
                    ],400);
                }

                $invite = new ReferredMatches();
                $invite->user_id = $linked->singleton_id ? $linked->singleton_id : '';
                $invite->user_type = 'singleton';
                // $invite->singleton_id = $request->singleton_id ? $request->singleton_id : '';
                $invite->referred_match_id = $request->messaged_user_singleton_id ? $request->messaged_user_singleton_id : '';

                $sent = DB::table('referred_matches')->where([['user_id', '=', $linked->singleton_id], ['user_type', '=', 'singleton'], ['referred_match_id', '=', $request->messaged_user_singleton_id]])->first();
                if (!empty($sent)) {
                    $send = DB::table('referred_matches')->where([['user_id', '=', $linked->singleton_id], ['user_type', '=', 'singleton'], ['referred_match_id', '=', $request->messaged_user_singleton_id]])->update(['updated_at' => date('Y-m-d H:i:s')]);
                }else{
                    $invite->created_at  = date('Y-m-d H:i:s');
                    $send = $invite->save();
                }

                if ($send) {

                    $mutualSingletons = Matches ::where([['user_id', '=', $request->singleton_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->messaged_user_singleton_id], ['match_type', '=', 'matched']])
                                                ->orWhere([['user_id', '=', $request->messaged_user_singleton_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->singleton_id], ['match_type', '=', 'matched']])
                                                ->first();

                    $user = Singleton::where([['id','=',$linked->singleton_id],['status','!=','Deleted']])->first();
                    $parent = ParentsModel::where([['id','=',$request->login_id],['status','=','Unblocked']])->first();
                    $user->notify(new ReferNotification($parent, $user->user_type, 0, __('msg.has referred a match for single Muslims to connect')));

                    DB::table('my_matches')->updateORInsert(
                        ['user_id' => $linked->singleton_id, 'user_type' => 'singleton', 'matched_id' => $request->messaged_user_singleton_id],
                        [
                            'user_id' => $linked->singleton_id, 
                            'user_type' => 'singleton', 
                            'matched_id' => $request->messaged_user_singleton_id
                        ]
                    );
                    
                   if(!empty($mutualSingletons)) {
                        return response()->json([
                            'status'    => 'success',
                            'message'   => __('msg.parents.invitation.success'),
                            'data'      => $invite
                        ],200);
                   } else {
                    $mutual = Matches ::where([['user_id', '=', $linked->singleton_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->messaged_user_singleton_id]])
                            ->orWhere([['user_id', '=', $request->messaged_user_singleton_id], ['user_type', '=', 'singleton'], ['match_id', '=', $linked->singleton_id]])
                            ->first();

                    $user2 = Singleton::whereId($request->singleton_id)->first();
                    $user1 = Singleton::whereId($request->messaged_user_singleton_id)->first();

                    if (!empty($mutual)) {
                        // $busy = Matches::where([['user_id', '=', $request->swiped_user_id], ['user_type', '=', 'singleton'],['status', 'busy']])->first();
                        $matched = Matches::where([['user_id', '=', $request->messaged_user_singleton_id], ['user_type', '=', 'singleton'],['match_type', 'matched']])
                                            ->orWhere([['match_id', '=', $request->messaged_user_singleton_id], ['user_type', '=', 'singleton'], ['match_type', '=', 'matched']])
                                            ->first();
                        if (!empty($matched)) {
                            $queue_no = Matches::where([['user_id', '=', $request->messaged_user_singleton_id], ['user_type', '=', 'singleton']])
                                    ->orderBy('queue','desc')
                                    ->first();
                            $queue =  $queue_no ? $queue_no->queue + 1 : 0;
                            $match_type = 'hold';
                        }else{
                            $queue = 0;
                            $match_type = 'matched';

                            // send congratulations fcm notification
                            if (isset($user1) && !empty($user1) && isset($user2) && !empty($user2)) {
                            // database notification
                                $msg = __('msg.Congratulations! You got a new match with');
                                $user2->notify(new MutualMatchNotification($user1, $user2->user_type, 0, ($msg.' '.$user1->name)));
                                $user1->notify(new MutualMatchNotification($user2, $user1->user_type, 0, ($msg.' '.$user2->name)));

                                $title = __('msg.Profile Matched');
                                $body = __('msg.Congratulations It’s a Match!');
                                $token1 = $user1->fcm_token;
                                $data = array(
                                    'notType' => "profile_matched",
                                    'user1_id' => $user1->id,
                                    'user1_name' => $user1->name,
                                    'user1_profile' => $user1->photo1,
                                    'user1_blur_image' => ($user1->gender == 'Female' ? ($mutual->match_type == 'matched' ? $mutual->blur_image : $user1->is_blurred) : 'no'),
                                    'user2_id' => $user2->id,
                                    'user2_name' => $user2->name,
                                    'user2_profile' => $user2->photo1,
                                    'user2_blur_image' => ($user2->gender == 'Female' ? ($mutual->match_type == 'matched' ? $mutual->blur_image : $user2->is_blurred) : 'no'),
                                );
                                sendFCMNotifications($token1, $title, $body, $data);

                                $token2 = $user2->fcm_token;
                                $data1 = array(
                                    'notType' => "profile_matched",
                                    'user1_id' => $user2->id,
                                    'user1_name' => $user2->name,
                                    'user1_profile' => $user2->photo1,
                                    'user1_blur_image' => ($user2->gender == 'Female' ? ($mutual->match_type == 'matched' ? $mutual->blur_image : $user2->is_blurred) : 'no'),
                                    'user2_id' => $user1->id,
                                    'user2_name' => $user1->name,
                                    'user2_profile' => $user1->photo1,
                                    'user2_blur_image' => ($user1->gender == 'Female' ? ($mutual->match_type == 'matched' ? $mutual->blur_image : $user1->is_blurred) : 'no'),
                                );
                                sendFCMNotifications($token2, $title, $body, $data1);
                            }
                        }

                        Matches::where([['user_id', '=', $request->messaged_user_singleton_id], ['user_type', '=', 'singleton'], ['match_id', '=', $linked->singleton_id], ['is_rematched', '=', 'no']])
                                ->orWhere([['user_id', '=', $linked->singleton_id], ['user_type', '=', 'singleton'], ['match_id', '=', $request->messaged_user_singleton_id], ['is_rematched', '=', 'no']])
                                ->update(['match_type' => $match_type, 'queue' => $queue, 'matched_at' => $match_type == 'matched' ? date('Y-m-d H:i:s') : Null, 'updated_at' => date('Y-m-d H:i:s')]);
                    }else{
                        $data = [
                            'user_id' => $linked->singleton_id,
                            'user_type' => 'singleton',
                            'match_id' => $request->messaged_user_singleton_id,
                            'matched_parent_id' => $request->messaged_user_id,
                            'blur_image' => $user1->gender == 'Female' ? $user1->is_blurred : $user2->is_blurred,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        DB::table('matches')->insert($data);
                    }

                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('msg.parents.invitation.success'),
                        'data'      => $invite
                    ],200);
                   }
                
                } else {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.parents.invitation.failure'),
                    ],400);
                }

            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.parents.invitation.invalid'),
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

    function deleteChat(Request $request) {
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
            'singleton_id'       => 'required||numeric',
            'messaged_user_id'   => 'required||numeric',
            'messaged_user_type' => [
                'required' ,
                Rule::in(['parent']),
            ],
            'messaged_user_singleton_id'   => 'required||numeric',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {

            if($request->user_type == 'singleton'){
                $userExists = Singleton::find($request->login_id);
            }else{
                $userExists = ParentsModel::find($request->login_id);
            }

            $chatHistory = ChatHistory::where([['user_id', '=', $request->login_id],['user_type', '=', $request->user_type],['messaged_user_id', '=', $request->messaged_user_id],['messaged_user_type', '=', $request->messaged_user_type],['singleton_id', '=', $request->singleton_id]])
                                ->orWhere([['user_id', '=', $request->messaged_user_id],['user_type', '=', $request->messaged_user_type],['messaged_user_id', '=', $request->login_id],['messaged_user_type', '=', $request->user_type],['singleton_id', '=', $request->messaged_user_singleton_id]])
                                ->get();                  
            
            if(!$chatHistory->isEmpty()){
                foreach ($chatHistory as $chatRecord) {
                    if (!empty($chatRecord->deleted_by) && isset($chatRecord->deleted_by) && ($chatRecord->deleted_by !=  $request->login_id)) {
                        $delete = $chatRecord->delete();
                    } else {
                        $chatRecord->deleted_by = $request->login_id;
                        $delete = $chatRecord->save();
                    }
                }
            
                if ($delete) {
                    $chat = MessagedUsers::where(function ($query) use ($request) {
                        $query->where([
                            ['user_id', '=', $request->login_id],
                            ['user_type', '=', $request->user_type],
                            ['singleton_id', '=', $request->singleton_id],
                            ['messaged_user_id', '=', $request->messaged_user_id],
                            ['messaged_user_type', '=', $request->messaged_user_type],
                            ['messaged_user_singleton_id', '=', $request->messaged_user_singleton_id],
                        ])->orWhere([
                            ['user_id', '=', $request->messaged_user_id],
                            ['user_type', '=', $request->messaged_user_type],
                            ['singleton_id', '=', $request->messaged_user_singleton_id],
                            ['messaged_user_id', '=', $request->login_id],
                            ['messaged_user_type', '=', $request->user_type],
                            ['messaged_user_singleton_id', '=', $request->singleton_id],
                        ]);
                    })->first();

                    if (!empty($chat->deleted_by) && isset($chat->deleted_by) && ($chatRecord->deleted_by !=  $request->login_id)) {
                        $delete = $chat->delete();
                    } else {
                        $chat->deleted_by = $request->login_id;
                        $delete = $chat->save();
                    }
                }

                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.singletons.delete-chat.success'),
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.singletons.delete-chat.failure'),
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
}
