<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Router;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'stats' => [
                'customers' => Customer::count(),
                'active' => Customer::where('status', 'active')->count(),
                'suspended' => Customer::where('status', 'suspended')->count(),
                'routers' => Router::where('is_active', true)->count(),
                'revenue' => Payment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
            ],
            'expiring' => Customer::with('package')->whereBetween('expires_at', [today(), today()->addDays(7)])->orderBy('expires_at')->limit(8)->get(),
            'payments' => Payment::with('customer')->latest('paid_at')->limit(6)->get(),
        ]);
    }
}
