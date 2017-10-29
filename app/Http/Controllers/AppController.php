<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Whitelist;
use App\User;
use App\Miner;
use App\Refinery;
use App\Payment;
use App\SolarSystem;

class AppController extends Controller
{

    /**
     * App homepage. Check if the user is currently signed in, and either show
     * a signin prompt or the homepage.
     *
     * @return Response
     */
    public function home()
    {

        // Calculate the total currently owed and total income generated.
        $total_amount_owed = DB::table('miners')->select(DB::raw('SUM(amount_owed) AS total'))->where('amount_owed', '>', 0)->where('alliance_id', env('EVE_ALLIANCE_ID'))->first();
        $total_income = DB::table('refineries')->select(DB::raw('SUM(income) AS total'))->first();

        // Grab the top miner, refinery and system.
        $top_payer = Payment::select(DB::raw('miner_id, SUM(amount_received) AS total'))->groupBy('miner_id')->orderBy('total', 'desc')->first();
        if (isset($top_payer))
        {
            $top_miner = Miner::where('eve_id', $top_payer->miner_id)->first();
            $top_miner->total = $top_payer->total;
        }
        $top_refinery = Refinery::orderBy('income', 'desc')->first();
        $top_refinery_system = Refinery::select(DB::raw('solar_system_id, SUM(income) AS total'))->groupBy('solar_system_id')->orderBy('total', 'desc')->first();
        if (isset($top_refinery_system))
        {
            $top_system = SolarSystem::find($top_refinery_system->solar_system_id);
            $top_system->total = $top_refinery_system->total;
        }

        return view('home', [
            'top_miner' => (isset($top_miner)) ? $top_miner : null,
            'top_refinery' => (isset($top_refinery)) ? $top_refinery : null,
            'top_system' => (isset($top_system)) ? $top_system : null,
            'miners' => Miner::where('amount_owed', '>', 0)->where('alliance_id', env('EVE_ALLIANCE_ID'))->orderBy('amount_owed', 'desc')->get(),
            'ninjas' => Miner::whereNull('alliance_id')->orwhere('alliance_id', '<>', env('EVE_ALLIANCE_ID'))->get(),
            'total_amount_owed' => $total_amount_owed->total,
            'refineries' => Refinery::orderBy('income', 'desc')->get(),
            'total_income' => $total_income->total,
        ]);

    }

    /**
     * Access management user list. List all the current whitelisted users, together
     * with the person that authorised them.
     * 
     * @return Response
     */
    public function showAuthorisedUsers()
    {

        return view('users', [
            'whitelisted_users' => Whitelist::all(),
            'access_history' => User::whereNotIn('eve_id', function ($q) {
                $q->select('eve_id')->from('whitelist');
            })->get(),
        ]);
        
    }

    /**
     * Whitelist a new user.
     */
    public function whitelistUser($id = NULL)
    {
        if ($id == NULL)
        {
            return redirect('/access/new');
        }
        $user = Auth::user();
        $whitelist = new Whitelist;
        $whitelist->eve_id = $id;
        $whitelist->added_by = $user->eve_id;
        $whitelist->save();
        return redirect('/access/new');
    }

    /**
     * Blacklist a new user. (Well, it's not really a blacklist, just de-whitelist them.)
     */
    public function blacklistUser($id = NULL)
    {
        if ($id == NULL)
        {
            return redirect('/access');
        }
        $user = Whitelist::where('eve_id', $id);
        $user->delete();
        return redirect('/access');
    }

    /**
     * Logout the currently authenticated user.
     *
     * @return Response
     */
    public function logout()
    {
        Auth::logout();
        return redirect('/');
    }

}
