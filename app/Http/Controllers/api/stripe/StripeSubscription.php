<?php

namespace App\Http\Controllers\api\stripe;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\BankDetails;
use App\Models\Charges;
use App\Models\ParentChild;
use App\Models\ParentsModel;
use App\Models\Singleton;
use App\Models\Subscriptions;
use App\Models\Transactions;
use App\Notifications\AdminNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Stripe\Stripe;

class StripeSubscription extends Controller
{
    private $stripe;

    public function  __construct()
    {
        $lang = (isset($_POST['language']) && !empty($_POST['language'])) ? $_POST['language'] : 'en';
        App::setlocale($lang);

        if (isset($_POST['login_id']) && !empty($_POST['login_id']) && isset($_POST['user_type']) && !empty($_POST['user_type'])) {
            userFound($_POST['login_id'], $_POST['user_type']);
        }

        $this->stripe = new \Stripe\StripeClient(
            env('STRIPE_SECRET_KEY')
          );
    }

    public function index(Request $request)
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
            'plan_id' => [
                'required' ,
                Rule::in(['2','3']),
            ],
            // 'stripe_plan_id'   => 'required',
            // 'stripe_joint_plan_id'   => 'required_if:plan_id,3',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {

            $stripe = Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
            $user_id = $request->login_id;
            $user_type = $request->user_type;
            $other_user_ids  = $request->other_user_id ? explode(',',$request->other_user_id) : null;
            $other_user_type = $request->other_user_type;
            $plan2 = Subscriptions::where('id', '=', '2')->first();
            $plan3 = Subscriptions::where('id', '=', '3')->first();

            $stripe_plan_id = $plan2 ? $plan2->stripe_plan_id : '';
            $stripe_joint_plan_id = $plan3 ? $plan3->stripe_plan_id : '';
            if (!$stripe_plan_id || !$stripe_joint_plan_id) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.stripe.session.failure'),
                ],400);
            }

            if ($user_type == 'singleton') {
                $user = Singleton::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
                $user_name = $user->name;
                $user_email = $user->email;
            } else {
                $user = ParentsModel::where([['id', '=', $request->login_id], ['status', '=', 'Unblocked']])->first();
                $user_name = $user->name;
                $user_email = $user->email;
            }

            $success_url = url('api/stripe/success');
            $cancel_url = url('api/stripe/fail');

            if ($request->plan_id == 2) {
                $line_items = [
                    ['price' => $stripe_plan_id, 'quantity' => 1]
                ];
            } elseif ($request->plan_id == 3) {

                if ($request->user_type == 'singleton') {
                    $parent = ParentChild::leftJoin('parents','parent_children.parent_id','=','parents.id')
                                        ->where([['parent_children.singleton_id', '=', $request->login_id], ['parent_children.status','=','Linked']])
                                        ->where('parents.active_subscription_id', '!=', '1')
                                        ->first();
                    if (!empty($parent)) {
                        $line_items = [
                            [
                                'price' => $stripe_joint_plan_id,
                                'quantity' => 1
                            ]
                        ];
                    } else {
                        return response()->json([
                            'status'    => 'failed',
                            'message'   => __('msg.stripe.session.parent-not-premium'),
                        ],400);
                    }
                } elseif ($request->user_type == 'parent') {
                    $validator = Validator::make($request->all(), [
                        'other_user_id'   => ['required_if:plan_id,3' ,'required_if:user_type,parent'],
                        'other_user_type' => [
                            'required_if:plan_id,3' ,'required_if:user_type,parent',
                            Rule::in(['singleton']),
                        ],
                    ]);

                    if($validator->fails()){
                        return response()->json([
                            'status'    => 'failed',
                            'message'   => __('msg.Validation Failed!'),
                            'errors'    => $validator->errors()
                        ],400);
                    }

                    $line_items = [
                        ['price' => $stripe_plan_id, 'quantity' => 1],
                        [
                            'price' => $stripe_joint_plan_id,
                            'quantity' => count($other_user_ids)
                        ]
                    ];
                } else {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.stripe.session.failure'),
                    ],400);
                }

            } else {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.stripe.session.invalid'),
                ],400);
            }

            if (!$line_items) {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.stripe.session.failure'),
                ],400);
            }

            $session = \Stripe\Checkout\Session::Create([
                'success_url' => $success_url,
                'cancel_url' => $cancel_url,
                'payment_method_types' => ['card'],
                'line_items' => $line_items,
                'mode' => 'subscription',
                'currency' => env('STRIPE_CURRENCY'),
            ]);

            $sub_booking_data = [
                'session_id' => $session->id,
                'user_id' => $user_id,
                'user_type' => $user_type,
                'user_name' => $user_name,
                'user_email' => $user_email,
                'other_user_id' => $request->other_user_id ? $request->other_user_id : '',
                'other_user_type' => $other_user_type ? $other_user_type : '',
                'active_subscription_id' => $request->plan_id,
                'subscription_id' => $session->subscription ? $session->subscription : '',
                'customer_id' => $session->customer ? $session->customer : '',
                'amount_paid' => $session->amount_total/100,
                'currency' => $session->currency,
                'payment_status' => $session->payment_status,
                'session_status' => $session->status,
                'created_at' => date('Y-m-d H:i:s', $session->created)
            ];

            $booking_id = DB::table('bookings')->insertGetId($sub_booking_data);
            if($booking_id){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.stripe.session.success'),
                    'data'      => $session
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.stripe.session.failure'),
                ],400);
            }
        } catch (\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Exception\CardException will be caught
            $err  = 'Status:' . $e->getHttpStatus() . '<br>';
            $err  .= 'Type:' . $e->getError()->type . '<br>';
            $err  .= 'Code:' . $e->getError()->code . '<br>';
            // param is '' in this case
            $err  .= 'Param:' . $e->getError()->param . '<br>';
            $err  .= 'Message:' . $e->getError()->message . '<br>';
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $err
            ],500);
            // $this->session->set_flashdata('error',  $err);
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests made to the API too quickly
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
            // $this->session->set_flashdata('error',  $err);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network communication with Stripe failed
             return response()->json([
                 'status'    => 'failed',
                 'message'   => __('msg.error'),
                 'error'     => $e->getMessage()
             ],500);
         } catch (\Throwable $e) {
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        }
    }

    public function paymentSuccess(Request $request){
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'session_id'   => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try{
            $stripe = Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
            $session_id = $request->session_id;
            $payment_details = DB::table('bookings')->where('session_id', '=', $session_id)->first();
            if (!empty($payment_details)) {
                $user_id = $payment_details->user_id ? $payment_details->user_id : '';
                $user_type = $payment_details->user_type ? $payment_details->user_type : '';
                $user_name = $payment_details->user_name ? $payment_details->user_name : '';
                $user_email = $payment_details->user_email ? $payment_details->user_email : '';
                $other_user_id = $payment_details->other_user_id ? $payment_details->other_user_id : '';
                $other_user_ids = $other_user_id ? explode(',', $other_user_id) : null;
                $other_user_type = $payment_details->other_user_type ? $payment_details->other_user_type : '';
                $booking_id = $payment_details->id;
                $active_subscription_id = $payment_details->active_subscription_id;

                $session = \Stripe\Checkout\Session::Retrieve(
                    $payment_details->session_id,
                    []
                );

                if($session->payment_status == "paid" && $session->status == "complete"){
                    $subscription = \Stripe\Subscription::Retrieve($session->subscription);
                    $item1 = $subscription->items->data[0];
                    $item2 = count($subscription->items->data) == 2 ? $subscription->items->data[1] : null;

                    $update_booking  =  [
                        'subscription_id' => $session->subscription,
                        'customer_id' => $session->customer,
                        'amount_paid' => $session->amount_total/100,
                        'payment_status' => $session->payment_status,
                        'session_status' => $session->status,
                        'updated_at'     => date('Y-m-d H:i:s'),
                    ];

                    $update = DB::table('bookings')->where('id', '=', $payment_details->id)->update($update_booking);

                    $sub_data = [
                        'booking_id' => $booking_id,
                        'user_id' => $user_id,
                        'user_type' => $user_type,
                        'user_name' => $user_name,
                        'user_email' => $user_email,
                        'other_user_id' => $other_user_id ? $other_user_id : '',
                        'other_user_type' => $other_user_type ? $other_user_type : '',
                        'active_subscription_id' => $active_subscription_id,
                        'customer_id' => $session->customer,
                        'subscription_id' => $session->subscription,
                        'subscription_item1_id' => $item1->id,
                        'subscription_item2_id' => $item2 ? $item2->id : '',
                        'item1_plan_id' => $item1->plan->id,
                        'item2_plan_id' => $item2 ? $item2->plan->id : '',
                        'item1_unit_amount' => $item1->plan->amount/100,
                        'item2_unit_amount' => $item2 ? $item2->plan->amount/100 : '',
                        'item1_quantity' => $item1->quantity,
                        'item2_quantity' => $item2 ? $item2->quantity : '',
                        'amount_paid' => $session->amount_total/100,
                        'currency' => $session->currency,
                        'plan_interval' => $item1->plan->interval,
                        'plan_interval_count' => $item1->plan->interval_count,
                        'payer_email' => $session->customer_details ? $session->customer_details->email : '',
                        'plan_period_start' => $subscription ? date("Y-m-d H:i:s", $subscription->current_period_start) : '',
                        'plan_period_end' => $subscription ? date("Y-m-d H:i:s", $subscription->current_period_end) : '',
                        'payment_status' => $session->payment_status,
                        'subs_status' => $subscription->status,
                        'created_at' => Carbon::now()
                    ];

                    $insert = DB::table('transactions')->insert($sub_data);

                    // if ($other_user_ids) {
                    //     foreach ($other_user_ids as $id) {
                    //         if ($other_user_type == 'singleton') {
                    //             $other = Singleton::where([['id','=',$id],['status','=','Unblocked']])->first();
                    //         } elseif ($other_user_type == 'parent') {
                    //             $other = ParentsModel::where([['id','=',$id],['status','=','Unblocked']])->first();
                    //         }

                    //         $sub_data1 = [
                    //             'booking_id' => $booking_id,
                    //             'user_id' => $id,
                    //             'user_type' => $other_user_type,
                    //             'user_name' => $other->name,
                    //             'user_email' => $other->email,
                    //             'active_subscription_id' => $active_subscription_id,
                    //             'subscription_id' => $session->subscription,
                    //             'subscription_item_id' => $item2->id,
                    //             'customer_id' => $session->customer,
                    //             'plan_id' => $item2->plan->id,
                    //             'unit_amount' => $item2->plan->amount/100,
                    //             'amount_paid' => $session->amount_total/100,
                    //             'quantity' => $item2->quantity,
                    //             'currency' => $session->currency,
                    //             'plan_interval' => $item2->plan->interval,
                    //             'plan_interval_count' => $item2->plan->interval_count,
                    //             'payer_email' => $session->customer_details ? $session->customer_details->email : '',
                    //             'plan_period_start' => $subscription ? date("Y-m-d H:i:s", $subscription->current_period_start) : '',
                    //             'plan_period_end' => $subscription ? date("Y-m-d H:i:s", $subscription->current_period_end) : '',
                    //             'payment_status' => $session->payment_status,
                    //             'subs_status' => $subscription->status,
                    //             'created_at' => date("Y-m-d H:i:s", $subscription->created)
                    //         ];

                    //         $insert = DB::table('transactions')->insert($sub_data1);
                    //     }
                    // }

                    if($update){
                        $update_sub_data = [
                            'customer_id'            => $session->customer,
                            'active_subscription_id' => $active_subscription_id,
                            'stripe_plan_id'         => $item1->plan->id,
                            'subscription_item_id'   => $item1->id
                        ];

                        if ($user_type == 'singleton') {
                            Singleton::where([['id','=',$user_id],['status','=','Unblocked']])->update($update_sub_data);
                        } else {
                            ParentsModel::where([['id','=',$user_id],['status','=','Unblocked']])->update($update_sub_data);
                        }

                        $update_sub_data1 = [
                            'customer_id'            => $session->customer,
                            'active_subscription_id' => $active_subscription_id,
                            'stripe_plan_id'         => $item2 ? $item2->plan->id : $item1->plan->id,
                            'subscription_item_id'   => $item2 ? $item2->id : $item1->id
                        ];

                        if ($active_subscription_id == 3 && $other_user_id) {
                            if ($other_user_type == 'singleton') {
                                foreach ($other_user_ids as $id) {
                                    Singleton::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data1);
                                }
                            } elseif ($other_user_type == 'parent') {
                                foreach ($other_user_ids as $id) {
                                    ParentsModel::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data1);
                                }
                            }
                        }

                        $admin = Admin::find(1);
                        $details = [
                            'title' => __('msg.New Subscription'),
                            'msg'   => __('msg.has Subscribed.'),
                        ];
                        if ($user_type == 'singleton') {
                            $user = Singleton::where([['id','=',$user_id],['status','=','Unblocked']])->first();
                        } else {
                            $user = ParentsModel::where([['id','=',$user_id],['status','=','Unblocked']])->first();
                        }
                        $admin->notify(new AdminNotification($user, 'admin', 0, $details));
                        return response()->json([
                            'status'    => 'success',
                            'message'   => __('msg.stripe.success'),
                        ],200);
                    }
                } else if ($session->payment_status == "unpaid" && $session->status == "open") {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.stripe.failure'),
                        'stripe'  => [
                            'session_id'  => $session['id'],
                            'url'         => $session['url'],
                        ],
                    ],400);
                } else {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.stripe.failure'),
                    ],400);
                }
            } else {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.stripe.invalid'),
                ],400);
            }
        }  catch (\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Exception\CardException will be caught
            $err  = 'Status:' . $e->getHttpStatus() . '<br>';
            $err  .= 'Type:' . $e->getError()->type . '<br>';
            $err  .= 'Code:' . $e->getError()->code . '<br>';
            // param is '' in this case
            $err  .= 'Param:' . $e->getError()->param . '<br>';
            $err  .= 'Message:' . $e->getError()->message . '<br>';
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $err
            ],500);
            // $this->session->set_flashdata('error',  $err);
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests made to the API too quickly
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
            // $this->session->set_flashdata('error',  $err);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Stripe\Exception\ApiConnectionException $e) {
           // Network communication with Stripe failed
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Throwable $e) {
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        }
    }

    public function paymentFail(Request $request){
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'session_id'   => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try{
            $stripe = Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
            $session_id = $request->session_id;
            $payment_details = DB::table('bookings')->where('session_id', '=', $session_id)->first();
            if (!empty($payment_details)) {
                $user_id = $payment_details->user_id ? $payment_details->user_id : '';
                $user_type = $payment_details->user_type ? $payment_details->user_type : '';
                $user_name = $payment_details->user_name ? $payment_details->user_name : '';
                $user_email = $payment_details->user_email ? $payment_details->user_email : '';
                $other_user_id = $payment_details->other_user_id ? $payment_details->other_user_id : '';
                $other_user_ids = $other_user_id ? explode(',', $other_user_id) : null;
                $other_user_type = $payment_details->other_user_type ? $payment_details->other_user_type : '';
                $booking_id = $payment_details->id;

                $session = \Stripe\Checkout\Session::Retrieve(
                    $payment_details->session_id,
                    []
                );
                if($session->status == "open"){
                    $expire = $this->stripe->checkout->sessions->expire(
                        $payment_details->session_id,
                        []
                    );

                    $update_booking  =  [
                        'active_subscription_id' => 1,
                        'payment_status'         => $expire->payment_status,
                        'session_status' => $expire->status,
                        'updated_at'     => date('Y-m-d H:i:s'),
                    ];

                    $update = DB::table('bookings')->where('id', '=', $payment_details->id)->update($update_booking);

                    $sub_data = [
                        'booking_id' => $booking_id,
                        'user_id' => $user_id,
                        'user_type' => $user_type,
                        'user_name' => $user_name,
                        'user_email' => $user_email,
                        'other_user_id' => $other_user_id ? $other_user_id : '',
                        'other_user_type' => $other_user_type ? $other_user_type : '',
                        'active_subscription_id' => 1,
                        'payment_status' => $session->payment_status,
                        'subs_status' => 'inactive',
                        'created_at' => date('Y-m-d h:i:s')
                    ];

                    $insert = DB::table('transactions')->insert($sub_data);

                    // if ($other_user_ids) {
                    //     foreach ($other_user_ids as $id) {
                    //         if ($other_user_type == 'singleton') {
                    //             $other = Singleton::where([['id','=',$id],['status','=','Unblocked']])->first();
                    //         } elseif ($other_user_type == 'parent') {
                    //             $other = ParentsModel::where([['id','=',$id],['status','=','Unblocked']])->first();
                    //         }

                    //         $sub_data1 = [
                    //             'booking_id' => $booking_id,
                    //             'user_id' => $id,
                    //             'user_type' => $other_user_type,
                    //             'user_name' => $other->name,
                    //             'user_email' => $other->email,
                    //             'active_subscription_id' => 1,
                    //             'currency' => $session->currency,
                    //             'payment_status' => $session->payment_status,
                    //             'subs_status' => 'inactive',
                    //             'created_at' => date('Y-m-d h:i:s')
                    //         ];

                    //         $insert = DB::table('transactions')->insert($sub_data1);
                    //     }
                    // }

                    if($update){
                        return response()->json([
                            'status'    => 'failed',
                            'message'   => __('msg.stripe.failure'),
                        ],400);
                    }
                } else if ($session->status == "complete") {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.stripe.paid'),
                    ],400);
                } else if ($session->status == "expired") {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.stripe.invalid'),
                    ],400);
                } else {
                    return response()->json([
                        'status'    => 'failed',
                        'message'   => __('msg.stripe.cancel'),
                    ],400);
                }
            } else {
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.stripe.invalid'),
                ],400);
            }
        } catch (\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Exception\CardException will be caught
            $err  = 'Status:' . $e->getHttpStatus() . '<br>';
            $err  .= 'Type:' . $e->getError()->type . '<br>';
            $err  .= 'Code:' . $e->getError()->code . '<br>';
            // param is '' in this case
            $err  .= 'Param:' . $e->getError()->param . '<br>';
            $err  .= 'Message:' . $e->getError()->message . '<br>';
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $err
            ],500);
            // $this->session->set_flashdata('error',  $err);
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests made to the API too quickly
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
            // $this->session->set_flashdata('error',  $err);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Stripe\Exception\ApiConnectionException $e) {
           // Network communication with Stripe failed
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        } catch (\Throwable $e) {
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.error'),
                'error'     => $e->getMessage()
            ],500);
        }
    }

    public function webhookHandler(Request $request)
    {

        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        $payload = $request->getContent();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
        } catch(\UnexpectedValueException $e) {
        // Invalid payload
            http_response_code(400);
            exit();
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
        // Invalid signature
            http_response_code(400);
            exit();
        }

        switch ($event->type) {
            case 'charge.failed':
                $charge = $event->data->object;
                $charge_id = $charge->id;
                $object = $charge->object;
                $customer_id = $charge->customer;
                $balance_transaction = $charge->balance_transaction;
                $amount_captured     = $charge->amount_captured;
                $name     = $charge->billing_details->name;
                $currency = $charge->currency;
                $charge_time     = $charge->created;
                $sub_convert_date = date('Y-m-d H:i:s', $charge_time);
                $description     = $charge->description;
                $invoice = $charge->invoice;
                $paid_status = $charge->paid;
                $ayment_intent = $charge->payment_intent;
                $payment_method = $charge->payment_method;
                $card_brand = $charge->payment_method_details->card->brand;
                $country = $charge->payment_method_details->card->country;
                $exp_month = $charge->payment_method_details->card->exp_month;
                $exp_year = $charge->payment_method_details->card->exp_year;
                $funding = $charge->payment_method_details->card->funding;
                $last4 = $charge->payment_method_details->card->last4;
                $network = $charge->payment_method_details->card->network;
                $card_type = $charge->payment_method_details->type;
                $paid_status = $charge->paid;
                $status = $charge->status;
                $seller_message = $charge->outcome['seller_message'];
                $charge_data = [
                    'charge_id' => $charge_id,
                    'object' => $object,
                    'charge_customer_id' => $customer_id,
                    'balance_transaction' => $balance_transaction,
                    'plan_amount' => $amount_captured/100,
                    'payer_email' => $name,
                    'plan_amount_currency' => $currency,
                    'charge_create' => $sub_convert_date,
                    'charge_currency' => $currency,
                    'charge_description' => $description,
                    'charge_invoice' => $invoice,
                    'seller_message' => $seller_message,
                    'payment_intent' => $ayment_intent,
                    'payment_method' => $payment_method,
                    'paid_status' => $paid_status,
                    'charge_country' => $country,
                    'exp_month' => $exp_month,
                    'exp_year' => $exp_year,
                    'funding' => $funding,
                    'last4' => $last4,
                    'network' => $network,
                    'type'=> $card_type,
                    'status'=> $status,
                    'updated_at' => date('Y-m-d h:i:s')
                ];

                $query = Charges::insert($charge_data);


                if($status == 'failed')
                {
                    $user_sub_data= Transactions::where('customer_id', $customer_id)->first();

                    $user_id   = $user_sub_data->user_id;
                    $user_type = $user_sub_data->user_type;
                    $user_name = $user_sub_data->user_name;
                    $user_email = $user_sub_data->user_email;
                    $other_user_id = $user_sub_data->other_user_id;
                    $other_user_type = $user_sub_data->other_user_type;
                    $sub_table_id = $user_sub_data->id;

                    $update_sub_data = ['active_subscription_id' => '1'];

                    if ($user_type == 'singleton') {
                        $update = Singleton::where([['id','=',$user_id],['stripe_id','=', $customer_id],['status','=','Unblocked']])->update($update_sub_data);
                    } else {
                        $update = ParentsModel::where([['id','=',$user_id],['stripe_id','=', $customer_id],['status','=','Unblocked']])->update($update_sub_data);
                    }

                    if ($other_user_id) {
                        $other_user_ids  = explode(',',$other_user_id);
                        if ($other_user_type == 'singleton') {
                            foreach ($other_user_ids as $id) {
                                Singleton::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data);
                            }
                        } else {
                            foreach ($other_user_ids as $id) {
                                ParentsModel::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data);
                            }
                        }
                    }

                    if($update)
                    {
                        $inactive = ['subs_status' => 'inactive'];
                        Transactions::where('id', $user_sub_data->id)->update($inactive);
                    }
                }
                break;
            case 'charge.succeeded':
                $charge = $event->data->object;
                $charge_id = $charge->id;
                $object = $charge->object;
                $customer_id = $charge->customer;
                $balance_transaction = $charge->balance_transaction;
                $amount_captured     = $charge->amount_captured;
                $name     = $charge->billing_details->name;
                $currency = $charge->currency;
                $charge_time     = $charge->created;
                $sub_convert_date = date('Y-m-d H:i:s', $charge_time);
                $description     = $charge->description;
                $invoice = $charge->invoice;
                $paid_status = $charge->paid;
                $ayment_intent = $charge->payment_intent;
                $payment_method = $charge->payment_method;
                $card_brand = $charge->payment_method_details->card->brand;
                $country = $charge->payment_method_details->card->country;
                $exp_month = $charge->payment_method_details->card->exp_month;
                $exp_year = $charge->payment_method_details->card->exp_year;
                $funding = $charge->payment_method_details->card->funding;
                $last4 = $charge->payment_method_details->card->last4;
                $network = $charge->payment_method_details->card->network;
                $card_type = $charge->payment_method_details->type;
                $paid_status = $charge->paid;
                $status = $charge->status;
                $seller_message = $charge->outcome['seller_message'];

                if ($status == 'succeeded') {
                    $charge_data = [
                        'charge_id' => $charge_id,
                        'object' => $object,
                        'charge_customer_id' => $customer_id,
                        'balance_transaction' => $balance_transaction,
                        'plan_amount' => $amount_captured/100,
                        'payer_email' => $name,
                        'plan_amount_currency' => $currency,
                        'charge_create' => $sub_convert_date,
                        'charge_currency' => $currency,
                        'charge_description' => $description,
                        'charge_invoice' => $invoice,
                        'seller_message' => $seller_message,
                        'payment_intent' => $ayment_intent,
                        'payment_method' => $payment_method,
                        'paid_status' => $paid_status,
                        'charge_country' => $country,
                        'exp_month' => $exp_month,
                        'exp_year' => $exp_year,
                        'funding' => $funding,
                        'last4' => $last4,
                        'network' => $network,
                        'type'=> $card_type,
                        'status'=> $status,
                        'created_at' => date('Y-m-d h:i:s')
                    ];
                    $query = Charges::insert($charge_data);
                }
                break;
            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                // $item1 = $subscription->items->data[0];
                // $item2 = $subscription->items->data[1];

                $sub_id = $subscription->id;
                $status = $subscription->status;
                $sub_created = $subscription->created;
                $sub_period_start = $subscription->current_period_start;
                $plan_period_end = $subscription->current_period_end;
                $sub_convert_date = date('Y-m-d H:i:s', $sub_period_start);
                $plan_convert_date = date('Y-m-d H:i:s', $plan_period_end);
                $customer_id = $subscription->customer;
                $plan_amount_currency = $subscription->plan->currency;

                $user_sub_data= Transactions::where('subscription_id', $sub_id)->first();

                $sub_table_id = $user_sub_data->id;
                $user_id   = $user_sub_data->user_id;
                $user_type = $user_sub_data->user_type;
                $user_name = $user_sub_data->user_name;
                $user_email = $user_sub_data->user_email;
                $other_user_id = $user_sub_data->other_user_id;
                $other_user_type = $user_sub_data->other_user_type;
                $active_subscription_id = $user_sub_data->active_subscription_id;

                $sub_booking_data = [
                    'user_id' => $user_id,
                    'user_type' => $user_type,
                    'user_name' => $user_name,
                    'user_email' => $user_email,
                    'other_user_id' => $other_user_id ? $other_user_id : '',
                    'other_user_type' => $other_user_type ? $other_user_type : '',
                    'active_subscription_id' => $active_subscription_id,
                    'subscription_id' => $sub_id,
                    'customer_id' => $customer_id,
                    'currency' => $plan_amount_currency,
                    'payment_status' => $status == 'active' ? 'paid' : 'unpaid',
                    'created_at' => date('Y-m-d H:i:s', $sub_created)
                ];
    
                $booking_id = DB::table('bookings')->insertGetId($sub_booking_data);

                if ($status == 'active') {
                    $update_status = [
                        'booking_id' => $booking_id,
                        'plan_period_start' => $sub_convert_date,
                        'plan_period_end' => $plan_convert_date,
                        'updated_at' => date('Y-m-d H:i:s', $sub_created),
                    ];

                    $update_time = Transactions::where('id', '=',  $sub_table_id)->update($update_status);
                }else{
                    $update_sub_data = ['active_subscription_id' => '1'];

                    if ($user_type == 'singleton') {
                       $update = Singleton::where([['id','=',$user_id],['status','=','Unblocked']])->update($update_sub_data);
                    } else {
                        $update = ParentsModel::where([['id','=',$user_id],['status','=','Unblocked']])->update($update_sub_data);
                    }

                    if ($other_user_id) {
                        $other_user_ids  = explode(',',$other_user_id);
                        if ($other_user_type == 'singleton') {
                            foreach ($other_user_ids as $id) {
                                Singleton::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data);
                            }
                        } else {
                            foreach ($other_user_ids as $id) {
                                ParentsModel::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data);
                            }
                        }
                    }

                    if($update)
                    {
                        $inactive = ['status' => 'inactive'];
                        Transactions::where('id', '=',  $sub_table_id)->update($inactive);
                    }
                }
                break;
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
            case 'payment_intent.canceled':
                $paymentIntent = $event->data->object;
            case 'payment_intent.created':
                $paymentIntent = $event->data->object;
            case 'invoice.paid':
                $invoice = $event->data->object;
                generateInvoicePdf($invoice);
                break;
            case 'account.updated':
                $account = $event->data->object;
            case 'charge.captured':
                $charge = $event->data->object;
            case 'charge.expired':
                $charge = $event->data->object;
            case 'charge.pending':
                $charge = $event->data->object;
            case 'charge.refunded':
                $charge = $event->data->object;
            case 'charge.updated':
                $charge = $event->data->object;
            case 'checkout.session.completed':
                $session = $event->data->object;
            case 'checkout.session.expired':
                $session = $event->data->object;
            case 'customer.created':
                $customer = $event->data->object;
            case 'customer.deleted':
                $customer = $event->data->object;
            case 'customer.updated':
                $customer = $event->data->object;
            case 'customer.subscription.created':
                $subscription = $event->data->object;
            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                $transaction= Transactions::where('stripe_subscription_id', $subscription->id)->first();

                $sub_table_id = $transaction->id;
                $user_id   = $transaction->user_id;
                $user_type = $transaction->user_type;
                $user_name = $transaction->user_name;
                $user_email = $transaction->user_email;
                $other_user_id = $transaction->other_user_id;
                $other_user_type = $transaction->other_user_type;
                $active_subscription_id = $transaction->active_subscription_id;
                Transactions::where('id','=', $transaction->id)->update(['subs_status' => $subscription->status, 'updated_at' => date('Y-m-d H:i:s')]);
                $update_sub_data = [
                    'customer_id'            => '',
                    'active_subscription_id' => 1,
                    'stripe_plan_id'         => '',
                    'subscription_item_id'   => ''
                ];

                if ($active_subscription_id == 3 && $other_user_id) {
                    $other_user_ids = $transaction->other_user_id ? explode(',',$other_user_id) : null;
                    if ($transaction->other_user_type == 'singleton') {
                        foreach ($other_user_ids as $id) {
                            Singleton::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data);
                        }
                    } elseif ($transaction->other_user_type == 'parent') {
                        foreach ($other_user_ids as $id) {
                            ParentsModel::where([['id','=',$id],['status','=','Unblocked']])->update($update_sub_data);
                        }
                    }
                }
            break;
            case 'customer.subscription.paused':
                $subscription = $event->data->object;
            case 'customer.subscription.resumed':
                $subscription = $event->data->object;
            case 'invoice.created':
                $invoice = $event->data->object;
            case 'invoice.deleted':
                $invoice = $event->data->object;
            case 'invoice.finalization_failed':
                $invoice = $event->data->object;
            case 'invoice.finalized':
                $invoice = $event->data->object;
            case 'invoice.sent':
                $invoice = $event->data->object;
            case 'invoice.updated':
                $invoice = $event->data->object;
            case 'payment_intent.processing':
                $paymentIntent = $event->data->object;
            case 'payment_intent.requires_action':
                $paymentIntent = $event->data->object;
            case 'payment_method.attached':
                $paymentMethod = $event->data->object;
            case 'payment_method.detached':
                $paymentMethod = $event->data->object;
            case 'payment_method.updated':
                $paymentMethod = $event->data->object;
            case 'refund.created':
                $refund = $event->data->object;
            case 'refund.updated':
                $refund = $event->data->object;
            case 'subscription_schedule.canceled':
                $subscriptionSchedule = $event->data->object;
            case 'subscription_schedule.completed':
                $subscriptionSchedule = $event->data->object;
            case 'subscription_schedule.created':
                $subscriptionSchedule = $event->data->object;
            case 'subscription_schedule.expiring':
                $subscriptionSchedule = $event->data->object;
            case 'subscription_schedule.updated':
                $subscriptionSchedule = $event->data->object;
            // ... handle other event types
            default:
              echo 'Received unknown event type ' . $event->type;
          }

        http_response_code(200);
    }
}
