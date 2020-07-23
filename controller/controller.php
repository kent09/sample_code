<?php
namespace App\Http\Controllers;

use App\User;
use App\UserAddress;
use App\FusePlanProduct;
use App\UserSubscription;
use App\Accounts;
use App\Plans;
use App\InfsCountry;
use App\Helpers\Helpers;
use App\Services\InfusionSoftService;
use App\Services\UserSubscriptionService;
use Illuminate\Http\Request;
use App\Http\Requests\StoreBillingDetailRequest;
use Laravel\Cashier\Exceptions\IncompletePayment;
use App\Http\Requests\ProcessOrderRequest; 
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Carbon\Carbon;
use Session;

class UserSubscriptionController extends Controller
{
    protected $userSubscriptionService;
    protected $infusionSoftService;

    /** Constructor */
    public function __construct(UserSubscriptionService $userSubscriptionService, InfusionSoftService $infusionSoftService)
    {
        $this->middleware('auth');
        $this->userSubscriptionService = $userSubscriptionService;
        $this->infusionSoftService = $infusionSoftService;
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
    }


    /**
    * Show the billing page that contains selection of plans and payment form V2.
    * Just a static Layout   
    * @return \Illuminate\Http\Response
    */

    public function index() {
        
        $user = Auth::user();

        $stripe_data = '';
        if($user->hasPaymentMethod()) {
            $stripe_data = $user->defaultPaymentMethod();
        }

        $subscriptions_plan = '';

        if($user->stripe_id) {
            $subscriptions_plan = \Stripe\Subscription::all(['customer' => $user->stripe_id]);
            $subscriptions_plan = $subscriptions_plan->data;
        }

        $invoices = $user->subscribed('main') ? $user->invoicesIncludingPending() : null;

        $plans = Plans::all();
        $countries = InfsCountry::all();
        $account = Accounts::where('owner_id', $user->id)->first();

        $subscription = '';
        if($user->subscribed('main')) {
            $subscription = $user->usersubscription->latest()->first();
        }

        $user_address = UserAddress::where('user_id', $user->id)->first();

        $intent = $user->createSetupIntent();

        if($account) {
            $user_address = UserAddress::where('account_id',$account->id)->first();
        }
        
        return view('v2.manageBilling.index', compact('user', 'plans', 'latest_payment_id','subscription', 'invoices', 'countries', 'subscriptions_plan', 'user_address', 'stripe_data', 'intent'));
    }


    /**
    * Process the submission of the payment form from index method.
    * Store the billing details to DB, store session and redirect to confirmation.
    *
    * @return redirect /billing/confirm
    */
    public function store(StoreBillingDetailRequest $request)
    {
        $user = Auth::user();
        
        $respond = $this->userSubscriptionService->store_user_detail($request);

        if($user->stripe_id) {
            $user->updateStripeCustomer(['name' => $request->first_name.' '.$request->last_name]);
        } else {
            $user->createAsStripeCustomer(['name' => $request->first_name.' '.$request->last_name]);
        }

        return redirect()->back()->with(Helpers::toastr('Billing Address is updated', 'success')); 
    }

 
    
    /**
    * Perform the actual payment transaction using Stripe API c/o laravel-cashier
    *
    * @return redirect /billing/success if the transaction succeed
    * @return redirect /billing/failed if the transaction failed
    */
    public function processOrder(ProcessOrderRequest $request) {
        
        $user = Auth::user();
        $account = Accounts::firstOrCreate(['owner_id' => $user->id]);
        $subscription = UserSubscription::where('account_id', $account->id)->first();
        $plan = Plans::find($request->planid);

        $this->userSubscriptionService->store_user_detail($request);
       
        if(!$user->stripe_id) {
            $user->createAsStripeCustomer();
        }
        
        $add_payment_method = $this->userSubscriptionService->add_payment_method($request->paymentid);
        
        if($add_payment_method['status'] == 'error') {
            return redirect()->back()->with(Helpers::toastr($add_payment_method['msg'], 'error'));
        }

        $update_payment = $this->userSubscriptionService->update_payment($request, $user);

        if($update_payment['status'] == "error") {
            return redirect()->back()->with(Helpers::toastr($update_payment['msg'], 'error')); 
        }
        
        if(!$subscription) {

            $newSubscription = $this->userSubscriptionService->newSubscription($user, $plan, $account, $request);

            if($newSubscription['status'] == "error") {
                return redirect()->route(
                    'cashier.payment',
                    [$newSubscription['msg']->payment->id, 'redirect' => url('/billing')]
                );
            }

            return redirect()->back()->with(Helpers::toastr('You succcessful subscribe to '.$plan->label, 'success'));

        }

        if($user->subscribedToPlan($plan->stripe_sub_id, 'main')) {

            return redirect()->back()->with(Helpers::toastr('You already subscribe in this plan.' , 'error')); 

        } 

        $prev_plan = Plans::where('stripe_sub_id', $subscription->stripe_plan)->first();

        // Change plan
        try {
            
            $data = $user->subscription('main')->swapAndInvoice($plan->stripe_sub_id);

        } catch (IncompletePayment $exception) {

            return redirect()->route(
                'cashier.payment',
                [$exception->payment->id, 'redirect' => url('/billing')]
            );

        }

        $retrieve_sub = $this->userSubscriptionService->retrieveSubscription($data);

        if($retrieve_sub['status'] == "error") {
            return redirect()->back()->with(Helpers::toastr($add_payment_method['msg'], 'error'));
        }

        $diff_token = $plan->monthly_token_amount - $prev_plan->monthly_token_amount;

        $subscription = UserSubscription::find($data->id);
        $subscription->user_plan_id = $plan->id;
        $subscription->prev_bill_date = Carbon::createFromTimestamp($retrieve_sub['msg']->current_period_start);
        $subscription->next_bill_date = Carbon::createFromTimestamp($retrieve_sub['msg']->current_period_end);
        
        // if customer upgrade subscription
        if($prev_plan->id < $plan->id) {
            $subscription->token_count = $subscription->token_count + abs($diff_token);
        }
        
        // if customer downgrade subscription
        if($prev_plan->id > $plan->id) {
            $subscription->token_count = $subscription->token_count - abs($diff_token);
        }

        $subscription->save();

        $this->userSubscriptionService->send_email($user, $plan);
    
        return redirect()->back()->with(Helpers::toastr('You have succcessful updated your subscription to '.$plan->label.' '.$plan->billing_period , 'success'));
    }

    /**
    * Responsible in handling submission after the user decided to change plan
    *
    * @return redirect /billing/confirm
    */
    public function changePlan(Request $request)
    {
        $user = Auth::user();

        //server-side validation
        if (!isset($user->stripe_id) || empty($user->stripe_id)) {
            return redirect('billing')->withErrors(['Customer ID not set!']);
        }

        $this->validate($request, [
            "Package" => "required|exists:fuse_plans_products,id"
        ]);

        $product = FusePlanProduct::find($request->Package);
        if (!$product) {
            return Redirect::back()->withErrors(['No such product ID!']);
        }
        
        \Session::put('product_id', $request->Package);
        \Session::put('change_plan', true);

        return redirect('/billing/confirm');
    }


}
