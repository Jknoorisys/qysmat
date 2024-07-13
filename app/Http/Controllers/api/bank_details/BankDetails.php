<?php

namespace App\Http\Controllers\api\bank_details;

use App\Http\Controllers\Controller;
use App\Models\BankDetails as ModelsBankDetails;
use App\Models\ParentChild;
use App\Models\ParentsModel;
use App\Models\Singleton;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BankDetails extends Controller
{
    public function  __construct()
    {
        $lang = (isset($_POST['language']) && !empty($_POST['language'])) ? $_POST['language'] : 'en';
        App::setlocale($lang);

        if (isset($_POST['login_id']) && !empty($_POST['login_id']) && isset($_POST['user_type']) && !empty($_POST['user_type'])) {
            userExist($_POST['login_id'], $_POST['user_type']);
        }
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
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            $account = ModelsBankDetails::where([['status','=','Active'],['user_id','=',$request->login_id],['user_type','=', $request->user_type]])->get();
            if(!$account->isEmpty()){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.bank.get-details.success'),
                    'data'      => $account
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.bank.get-details.failure'),
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

    public function addCard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'user_type' => [
                'required' ,
                Rule::in(['singleton','parent']),
            ],
            'login_id'              => 'required||numeric',
            'card_holder_name'      => 'required',
            'bank_name'             => 'required',
            'card_number'           => 'required',
            'month_year'            => 'required',
            'cvv'                   => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'    => 'failed',
                'message'   => __('msg.Validation Failed!'),
                'errors'    => $validator->errors()
            ],400);
        }

        try {
            
            $account_details = ModelsBankDetails::updateOrCreate(
                ['user_id' => $request->login_id, 'user_type' => $request->user_type],
                [
                    'user_id'   => $request->login_id ? $request->login_id : '', 
                    'user_type' => $request->user_type ,
                    'card_holder_name' => $request->card_holder_name ? $request->card_holder_name : '',
                    'bank_name' => $request->bank_name ? $request->bank_name : '',
                    'card_number' => $request->card_number ? $request->card_number : '',
                    'month_year' => $request->month_year ? $request->month_year : '',
                    'cvv' => $request->cvv ? $request->cvv : ''
                ]
            );

            if($account_details){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.bank.add.success'),
                    'data'    => $account_details
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.bank.add.failure'),
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

    public function deleteCard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => [
                'required' ,
                Rule::in(['en','hi','ur','bn','ar','in','ms','tr','fa','fr','de','es']),
            ],
            'login_id'   => 'required||numeric',
            'method_id'   => 'required||numeric',
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
            $account = ModelsBankDetails::where([['id','=',$request->method_id],['status','=','Active'],['user_id','=',$request->login_id],['user_type','=', $request->user_type]])->delete();
            if($account){
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('msg.bank.delete.success'),
                ],200);
            }else{
                return response()->json([
                    'status'    => 'failed',
                    'message'   => __('msg.bank.delete.failure'),
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
