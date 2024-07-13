<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\CallHistory;
use App\Models\Charges;
use App\Models\ChatHistory;
use App\Models\DeletedUsers;
use App\Models\Matches;
use App\Models\MyMatches;
use App\Models\ParentsModel;
use App\Models\ReportedUsers;
use App\Models\Singleton;
use App\Models\Transactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;

class Dashboard extends Controller
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
        $data['title']               = "no_breadcrumb";
        $data['main-title']          = __("msg.Dashboard");
        $data['admin']               = $this->admin;
        $data['singletons']          = Singleton::where([['status', '!=' ,'Deleted'], ['is_email_verified', '=' ,'verified']])->count();
        $data['parents']             = ParentsModel::where([['status', '!=' ,'Deleted'], ['is_email_verified', '=' ,'verified']])->count();
        $data['joint_singletons']    = Singleton::where([['status', '!=' ,'Deleted'], ['is_email_verified', '=' ,'verified'], ['parent_id', '!=', 0]])->count();

        $data['active']              = Singleton::where([['status', '=' ,'Unblocked'],['is_email_verified', '=' ,'verified']])->count() + ParentsModel::where([['status', '=' ,'Unblocked'],['is_email_verified', '=' ,'verified']])->count();
        $data['blocked']             = Singleton::where([['status', '=' ,'Blocked'],['is_email_verified', '=' ,'verified']])->count() + ParentsModel::where([['status', '=' ,'Blocked'],['is_email_verified', '=' ,'verified']])->count();
        $data['deleted']             = Singleton::where([['status', '=' ,'Deleted'],['is_email_verified', '=' ,'verified']])->count() + ParentsModel::where([['status', '=' ,'Deleted'],['is_email_verified', '=' ,'verified']])->count() + DeletedUsers::count();

        $data['reported']            = ReportedUsers::count();
        $data['matches']             = Matches::where('match_type', '=', 'matched')->orWhere('match_type', '=', 're-matched')->count();

        $data['conversations']       = ChatHistory::count();

        $data['records']             = Singleton::where([['status', '!=' ,'Deleted'], ['is_email_verified', '=' ,'verified']])->latest()->take(5)->get();
        $data['parents_records']     = ParentsModel::where([['status', '!=' ,'Deleted'], ['is_email_verified', '=' ,'verified']])->latest()->take(5)->get();

        $data['app_not_usefull']     = DeletedUsers::where('reason_type','=','Didn’t Find the App Useful')->count();
        $data['taking_break']     = DeletedUsers::where('reason_type','=','Taking a Break')->count();
        $data['met_someone']     = DeletedUsers::where('reason_type','=','Met Someone/Getting Married')->count();
        $data['other']     = DeletedUsers::where('reason_type','=','Other')->count();

        $data['audio']     = CallHistory::where('call_type','=','audio')->count();
        $data['video']     = CallHistory::where('call_type','=','video')->count();

        // $data['revenue']             = Charges::where('status', '=', 'succeeded')->avg('plan_amount');
        $data['revenue']             = Booking::where('payment_status', '=', 'paid')->avg('amount_paid');

        $data['notifications']       = $this->admin->unreadNotifications->where('user_type','=','admin');
        $data['content']             = view('admin.dashboard', $data);
        return view('layouts.main', $data);
    }

    public function changePassword()
    {
        $data['admin']               = $this->admin;
        $data['previous_title']      = __("msg.Dashboard");
        $data['url']                 = route('dashboard');
        $data['title']               = __("msg.Change Password");
        $data['notifications']       = $this->admin->unreadNotifications->where('user_type','=','admin');
        $data['content']             = view('admin.change_password', $data);
        return view('layouts.main',$data);
    }

    public function changePasswordFun(Request $request)
    {
        $request->validate([
            'old_password' => 'required|min:5',
            'new_password' => 'required|min:5',
            'cnfm_password' => 'required|min:5'
        ],
        [
            'old_password.required' => trans('msg.Enter Old Password'),
            'new_password.required' => trans('msg.Enter New Password'),
            'cnfm_password.required' => trans('msg.Confirm Password'),
            'old_password.min' => 'The Old Password must be at least 5 character',
            'new_password.min' => 'The New Password must be at least 5 character',
            'cnfm_password.min' => 'The Confirm Password must be at least 5 character',
        ],
    );

        if (!Hash::check($request->old_password, $this->admin->password)) {
            return back()->with('fail', __('msg.Old Password Do Not Match!'));
        } else {
           $password =  Admin :: whereId($this->admin_id)->update(['password' => Hash::make($request->new_password), 'updated_at' => date('Y-m-d H:i:s')]);
           if($password)
           {
            return redirect()->to('dashboard')->with('success', __('msg.Password Changed!'));
           }else{
            return back()->with('fail', __('msg.Please Try Again....'));
           }
        }
    }

    public function readNotifications($id)
    {
        $notification = $this->admin->unreadNotifications->where('id','=',$id);
        if ($notification) {
            // $this->admin->unreadNotifications()->where('user_type','=','admin')->delete();
            $this->admin->unreadNotifications->where('id','=',$id)->markAsRead();
            return back()->with('success', __('msg.Notification Marked as Read!'));
        } else {
            return back()->with('fail', __('msg.Please Try Again....'));
        }

    }

    public function markAllread()
    {
        $notification = $this->admin->unreadNotifications->where('user_type','=','admin');
        if ($notification) {
            // $this->admin->unreadNotifications()->where('user_type','=','admin')->delete();
            $this->admin->unreadNotifications->where('user_type','=','admin')->markAsRead();
            return back()->with('success', __('msg.Notification Marked as Read!'));
        } else {
            return back()->with('fail', __('msg.Please Try Again....'));
        }

    }

}
