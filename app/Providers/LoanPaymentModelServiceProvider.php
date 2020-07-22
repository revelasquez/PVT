<?php

namespace App\Providers;

use App\LoanPayment;
use App\Observers\LoanPaymentObserver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class LoanPaymentModelServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        LoanPayment::observe(LoanPaymentObserver::class);
    }
}
