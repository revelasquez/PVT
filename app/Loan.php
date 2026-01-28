<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Fico7489\Laravel\Pivot\Traits\PivotEventTrait;
use Carbon\CarbonImmutable;
use App\LoanGlobalParameter;
use App\Rules\LoanIntervalTerm;
use App\Http\Controllers\Api\V1\CalculatorController;
use Carbon;
use Util;
use App\Affiliate;
use App\LoanBorrower;
use App\LoanGuarantor;
use Illuminate\Support\Str;
use App\LoanProcedure;
use App\LoanPaymentState;
use App\LoanModalityParameter;

class Loan extends Model
{
    use Traits\EloquentGetTableNameTrait;
    //use Traits\RelationshipsTrait;
    use PivotEventTrait;
    use SoftDeletes;

    protected $dates = [
        //'disbursement_date',
        'request_date'
    ];
    // protected $appends = ['balance', 'estimated_quota', 'defaulted'];
    public $timestamps = true;
    // protected $hidden = ['pivot'];
    public $guarded = ['id'];

    public function generate($model)
    {
        $model->uuid = (string) Str::uuid();
        return $model;
    }
    //funcion para agregar uuid a todos los registros 

    public $fillable = [
        'code',
        'uuid',
        'procedure_modality_id',
        'disbursement_date',
        'disbursement_time',
        'num_accounting_voucher',
        'parent_loan_id',
        'parent_reason',
        'request_date',
        'amount_requested',
        'city_id',
        'interest_id',
        'state_id',
        'amount_approved',
        'indebtedness_calculated',
        'indebtedness_calculated_previous',
        'liquid_qualification_calculated',
        'loan_term',
        'refinancing_balance',
        'guarantor_amortizing',
        'payment_type_id',
        'number_payment_type',
        'destiny_id',
        'financial_entity_id',
        'validated',
        'user_id',
        'delivery_contract_date',
        'return_contract_date',
        'regional_delivery_contract_date',
        'regional_return_contract_date',
        'payment_plan_compliance',
        'affiliate_id',
        'loan_procedure_id',
        'authorize_refinancing',
        'wf_states_id',
        'contract_signature_date',
        'loan_payment_procedures_id'
    ];

    function __construct(array $attributes = [])
    {
        $this->uuid = (string) Str::uuid();
        parent::__construct($attributes);
        if (!$this->request_date) {
            $this->request_date = Carbon::now();
        }
        if (!$this->state_id) {
            $state = LoanState::whereName('En Proceso')->first();
            if ($state) {
                $this->state_id = $state->id;
            }
        }
        if (!$this->code) {
            if ($this->parent_reason == 'REPROGRAMACIÓN' && $this->parent_loan) {
                if (substr($this->parent_loan->code, -3) != substr($this->parent_reason, 0, 3))
                    $this->code = Loan::find($this->parent_loan_id)->code . " - " . substr($this->parent_reason, 0, 3);
                else
                    $this->code = 'R-'.$this->parent_loan->code;
            }
        }
    }
    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id', 'id');
    }

    public function loan_payment_procedure()
    {
        return $this->belongsTo(LoanPaymentProcedure::class, 'loan_payment_procedures_id', 'id');
    }

    public function loan_plan()
    {
        return $this->hasMany(LoanPlanPayment::class)->orderBy('quota_number');
    }


    public function setProcedureModalityIdAttribute($id)
    {
        $this->attributes['procedure_modality_id'] = $id;
        $this->attributes['interest_id'] = $this->modality->current_interest->id;
    }

    public function notes()
    {
        return $this->morphMany(Note::class, 'annotable');
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('user_id', 'date')->withTimestamps();
    }

    public function parent_loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function state()
    {
        return $this->belongsTo(LoanState::class, 'state_id', 'id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function payment_type()
    {
        return $this->belongsTo(PaymentType::class, 'payment_type_id', 'id');
    }

    public function financial_entity()
    {
        return $this->belongsTo(FinancialEntity::class, 'financial_entity_id', 'id');
    }

    public function submitted_documents()
    {
        return $this->belongsToMany(ProcedureDocument::class, 'loan_submitted_documents', 'loan_id')->withPivot('reception_date', 'comment', 'is_valid');
    }
    //Lista requisitos de un prestamos
    public function documents_modality()
    {
        $submitted_documents =  "SELECT l.id, lsd.procedure_document_id, pr.number, pd.name FROM loans l
        JOIN loan_submitted_documents lsd  ON l.id = lsd.loan_id
        JOIN procedure_requirements pr ON pr.procedure_document_id = lsd.procedure_document_id
        JOIN procedure_documents pd ON pd.id = pr.procedure_document_id WHERE l.id = $this->id  AND l.procedure_modality_id = pr.procedure_modality_id ORDER BY pr.number  ASC";
        $submitted_documents = DB::select($submitted_documents);
        return $submitted_documents;
    }

    public function getSubmittedDocumentsListAttribute()
    {
        $requiredValidated = true;
        $optionalValidated = true;

        foreach ($this->submitted_documents as $document) {
            if ($this->modality->required_documents->contains($document) && !$document->pivot->is_valid) {
                $requiredValidated = false;
            }
            if ($this->modality->optional_documents->contains($document) && !$document->pivot->is_valid) {
                $optionalValidated = false;
            }
        }
        $allDocumentsValidated = $requiredValidated && $optionalValidated;

        $documents = [
            'required' => ($this->submitted_documents)->intersect($this->modality->required_documents),
            'optional' => ($this->submitted_documents)->intersect($this->modality->optional_documents),
            'validated' => $allDocumentsValidated
        ];

        return $documents;
    }

    public function guarantors()
    {
        //return $this->hasMany(LoanGuarantor::class);
        return $this->belongsToMany(Affiliate::class, 'loan_guarantors')->orderBy('id');
    }

    public function personal_references()
    {
        return $this->loan_persons()->withPivot('cosigner')->whereCosigner(false);
    }

    public function cosigners()
    {
        return $this->loan_persons()->withPivot('cosigner')->whereCosigner(true);
    }

    public function loan_persons()
    {
        return $this->belongsToMany(PersonalReference::class, 'loan_persons');
    }

    public function modality()
    {
        return $this->belongsTo(ProcedureModality::class, 'procedure_modality_id', 'id');
    }

    public function getDefaultedAttribute()
    {
        return LoanPayment::days_interest2($this)->penal > 0 ? true : false;
    }
    public function getdelay()
    {
        return LoanPayment::days_interest2($this);
    }
    public function getdelay_parcial()
    {
        return LoanPayment::days_interest2($this)->interest_accumulated > 0 ? true : false;
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class)->orderBy('quota_number', 'desc')->orderBy('created_at');
    }
    public function payments_pendings_confirmations()
    {
        $state_id = LoanPaymentState::whereName('Pendiente por confirmar')->first()->id;
        return $this->hasMany(LoanPayment::class)->where('state_id', $state_id)->orderBy('quota_number', 'desc')->orderBy('created_at');
    }
    public function payment_pending_confirmation() //pago de pendiente por confirmacion para refin
    {
        $state_id = LoanPaymentState::whereName('Pendiente por confirmar')->first()->id;
        return $this->hasMany(LoanPayment::class)->where('state_id', $state_id)->orderBy('quota_number', 'desc')->orderBy('created_at')->first();
    }
    public function paymentsKardex()
    {
        $id_pagado = LoanPaymentState::where('name', 'Pagado')->first();
        $id_pendiente = LoanPaymentState::where('name', 'Pendiente por confirmar')->first();
        return $this->hasMany(LoanPayment::class)->whereIn('state_id', [$id_pagado->id, $id_pendiente->id])->orderBy('quota_number', 'asc');
    }
    //relacion uno a muchos
    public function loan_contribution_adjusts()
    {
        return $this->hasMany(LoanContributionAdjust::class);
    }
    public function interest()
    {
        return $this->belongsTo(LoanInterest::class, 'interest_id', 'id');
    }
    public function data_loan()
    {
        return $this->hasOne(Sismu::class, 'loan_id', 'id');
    }

    public function getRecordsUserAttribute()
    {
        return $this->records()->first()->user();
    }

    public function observations()
    {
        return $this->morphMany(Observation::class, 'observable')->latest('updated_at');
    }
    //desembolso --> afiliado, esposa
    public function disbursable()
    {
        return $this->morphTo();
    }

    public function destiny()
    {
        return $this->belongsTo(LoanDestiny::class, 'destiny_id', 'id');
    }
    // add records
    public function records()
    {
        return $this->morphMany(Record::class, 'recordable')->latest('updated_at');
    }
    // Saldo capital
    public function getBalanceAttribute()
    {
        $balance = $this->amount_approved;
        $loan_states = LoanPaymentState::where('name', 'Pagado')->orWhere('name', 'Pendiente por confirmar')->get();
        if ($this->payments()->count() > 0) {
            $balance -= $this->payments()->where('state_id', $loan_states->first()->id)->sum('capital_payment');
            $balance -= $this->payments()->where('state_id', $loan_states->last()->id)->sum('capital_payment');
        }
        return Util::round($balance);

        /*$balance = DB::select('select balance_loan('.$this->id.')');
        return Util::round($balance[0]->balance_loan);*/
    }

    public function getLastPaymentAttribute()
    {
        return $this->payments()->latest()->first();
    }

    public function getLastPaymentValidatedAttribute()
    {
        $loan_states = LoanPaymentState::where('name', 'Pagado')->orWhere('name', 'Pendiente por confirmar')->get();
        $payment = $this->payments()->whereLoanId($this->id)->where('state_id', $loan_states->first()->id)->orWhere('state_id', $loan_states->last()->id)->whereLoanId($this->id)->latest()->first();
        return $payment;
    }

    public function last_payment_date($date_final)
    {
        $loan_states = LoanPaymentState::where('name', 'Pagado')->first();
        return $this->payments()->whereLoanId($this->id)->where('state_id', $loan_states->id)->Where('estimated_date', '<=', $date_final)->orderBy('estimated_date', 'asc')->limit(1)->first();
    }

    public function getObservedAttribute()
    {
        return ($this->observations()->count() > 0) ? true : false;
    }

    public static function get_percentage($dato)
    {
        if (count($dato) > 0) {
            return Util::round(1 / count($dato) * 100);
        }
    }

    public function last_quota()
    {
        $latest_quota = $this->last_payment;
        if ($latest_quota) {
            $payments = $this->payments()->whereQuotaNumber($latest_quota->quota_number)->get();
            $latest_quota = new LoanPayment();
            $latest_quota = $latest_quota->merge($payments);
        }
        return $latest_quota;
    }

    public function getLoanMonthTermAttribute()
    {
        return LoanModalityParameter::where('procedure_modality_id', $this->procedure_modality_id)->first()->loan_month_term;
    }

    public function getEstimatedQuotaAttribute()
    {
        $parameter = $this->loan_procedure->loan_global_parameter->numerator/$this->loan_procedure->loan_global_parameter->denominator;
        $loan_month_term = LoanModalityParameter::where('procedure_modality_id',$this->procedure_modality_id)->first()->loan_month_term;
        $monthly_interest = $this->interest->monthly_current_interest($parameter, $loan_month_term);
        unset($this->interest);
        return Util::round2($monthly_interest * $this->amount_approved / (1 - 1 / pow((1 + $monthly_interest), $this->loan_term)));
    }

    public function next_payment2($affiliate_id, $estimated_date, $paid_by, $procedure_modality_id, $estimated_quota, $liquidate = false)
    {
        $grace_period = $this->loan_procedure->loan_global_parameter->grace_period;
        // nuevos calculos
        $quota = new LoanPayment();
        $quota->transaction_date = Carbon::now()->format('Y-m-d H:i:s');
        $sw = false;
        $latest_quota = $this->last_payment_validated;
        $quota->estimated_date = $estimated_date;
        $quota->previous_balance = $this->balance;
        $quota->previous_payment_date = $latest_quota ? Carbon::parse($latest_quota->estimated_date)->endOfDay() : Carbon::parse($this->disbursement_date)->endOfDay();
        $quota->quota_number = $this->paymentsKardex->count() + 1;
        $date_ini = CarbonImmutable::parse($this->disbursement_date);
        $numerator = $this->loan_procedure->loan_global_parameter->numerator;
        $denominator = $this->loan_procedure->loan_global_parameter->denominator;
        $penal_days = 0;
        if ($date_ini->day <= $this->loan_procedure->loan_global_parameter->offset_interest_day) {
            $date_pay = $date_ini->endOfMonth()->endOfDay()->format('Y-m-d');
        } else {
            $date_pay = $date_ini->startOfMonth()->addMonth()->endOfMonth()->endOfDay()->format('Y-m-d');
        }
        $date_pay = Carbon::parse($date_pay)->endOfDay();
        $estimated_date = Carbon::parse($estimated_date)->endOfDay();
        if ($quota->quota_number == 1 && $estimated_date <= $date_pay) {
            $penal_days = 0;
            $current_days = (Carbon::parse($quota->previous_payment_date)->diffInDays(Carbon::parse($estimated_date)));
            $interest_generated = LoanPayment::interest_by_days($current_days, $this->interest->annual_interest, $this->balance, $denominator);
        } else {
            $current_days = (Carbon::parse($quota->previous_payment_date)->diffInDays(Carbon::parse($estimated_date)));
            $interest_generated = LoanPayment::interest_by_days($current_days, $this->interest->annual_interest, $this->balance, $denominator);
            if ($current_days > 31)
                $penal_days = (Carbon::parse($quota->previous_payment_date)->diffInDays(Carbon::parse($estimated_date)) - 31);
        }

        //dias y montos estimados
        $estimated_days = [
            'current' => $current_days,
            'current_generated' => $interest_generated,
            'interest_accumulated' => $latest_quota ? $latest_quota->interest_accumulated : 0,
            'penal' => $penal_days,
            'penal_generated' => LoanPayment::interest_by_days($penal_days, $this->interest->penal_interest, $this->balance, $denominator),
            'penal_accumulated' => $latest_quota ? $latest_quota->penal_accumulated : 0,
        ];
        $quota->estimated_days = $estimated_days;
        $quota->paid_days = $quota->estimated_days;
        $quota->balance = $this->balance;
        $quota->penal_remaining = $quota->paid_days['penal_accumulated'];
        $quota->penal_payment  = 0;
        $quota->interest_remaining = $quota->paid_days['interest_accumulated'];
        $quota->capital_payment = $total_interests = $quota->interest_payment = 0;
        $quota->penal_accumulated = $quota->interest_accumulated = 0;
        $total_interests = 0;
        $partial_amount = 0;
        $interest = $this->interest;
        $amount = $estimated_quota;

        // Calcular intereses

        // Interes acumulado penal

        if ($quota->penal_remaining > 0) {
            if ($amount >= $quota->penal_remaining) {
                $amount = $amount - $quota->penal_remaining;
                //$quota->penal_remaining = 0;
            } else {
                $quota->penal_remaining = $amount;
                $amount = 0;
            }
        } else {
            $quota->penal_remaining = 0;
        }
        $total_interests += $quota->penal_remaining;

        // Interes acumulado corriente

        if ($quota->interest_remaining > 0) {
            if ($amount >= $quota->interest_remaining) {
                $amount = $amount - $quota->interest_remaining;
                //$quota->interest_remaining = 0;
            } else {
                $quota->interest_remaining = $amount;
                $amount = 0;
            }
        } else {
            $quota->interest_remaining = 0;
        }
        $total_interests += $quota->interest_remaining;

        // Interés penal 
        
        if ($this->loan_payment_procedure->penal_payment == 1){
            if($quota->estimated_days['penal'] >= $grace_period)
                $quota->penal_payment = LoanPayment::interest_by_days($penal_days, $this->interest->penal_interest, $this->balance, $denominator);
        }else{
            $penal_payment = 0;
            return $quota->penal_payment = $this->get_penal_payment($estimated_date);
        }
        if ($quota->penal_payment >= 0) {
            if ($amount >= $quota->penal_payment) {
                $amount = $amount - $quota->penal_payment;
            } else {
                $quota->penal_accumulated = Util::round2(($quota->penal_payment - $amount));
                $quota->penal_payment = $amount;
                $amount = 0;
            }
        } else {
            $quota->penal_payment = 0;
        }
        $total_interests += $quota->penal_payment;

        // Interés corriente
        $quota->interest_payment = $interest_generated;
        if ($amount >= $quota->interest_payment) {
            $amount = $amount - $quota->interest_payment;
        } else {
            $quota->interest_accumulated = $quota->interests_remaining + ($quota->interest_payment - $amount);
            $quota->interest_payment = $amount;
            $amount = 0;
        }

        $total_interests += Util::round2($quota->interest_payment);

        // Calcular amortización de capital        
        if ($liquidate) {
            $quota->capital_payment = $quota->balance;
        } else {
            if ($amount >= $this->balance) {
                $quota->capital_payment = Util::round2($this->balance);
            } else
                $quota->capital_payment = Util::round2($amount);
        }
        //calculo de la ultima cuota, solo si fue regular en los pagos

        // Calcular monto total de la cuota

        if ($quota->balance == $quota->capital_payment) {
            $quota->next_balance = 0;
        } else {
            $quota->next_balance = Util::round2($this->balance - $quota->capital_payment);
        }
        $quota->estimated_quota = Util::round2($quota->capital_payment + $total_interests);
        $quota->next_balance = Util::round2($quota->balance - $quota->capital_payment);


        //calculo de los nuevos montos restantes

        $quota->penal_accumulated = Util::round2($quota->penal_accumulated + ($quota->estimated_days['penal_accumulated'] - $quota->penal_remaining));
        $quota->interest_accumulated = Util::round2($quota->interest_accumulated + ($quota->estimated_days['interest_accumulated'] - $quota->interest_remaining));

        //redondeos

        $quota->interest_remaining = Util::round2($quota->interest_remaining);
        $quota->penal_remaining = Util::round2($quota->penal_remaining);
        //$quota->excesive_payment = Util::round($total_amount - ($quota->estimated_quota));


        //validacion pago excesivo

        return $quota;
    }

    public function next_payment_season($affiliate_id, $estimated_date, $paid_by, $procedure_modality_id, $estimated_quota, $liquidate = false)
    {
        $latest_quota = $this->last_payment_validated;
        $quota = new LoanPayment();
        $quota->transaction_date = Carbon::now()->format('Y-m-d H:i:s');
        $quota->estimated_date = $estimated_date;
        $quota->previous_balance = $this->balance;
        $quota->previous_payment_date = $latest_quota ? Carbon::parse($latest_quota->estimated_date)->endOfDay() : Carbon::parse($this->disbursement_date)->endOfDay();;
        $quota->quota_number = $this->paymentsKardex->count() + 1;
        $period = $this->modality->loan_modality_parameter->loan_month_term;
        $denominator = $this->loan_procedure->loan_global_parameter->denominator;
        $grace_period = $this->loan_procedure->loan_global_parameter->grace_period;
        $extra_days = 0;
        $date = Carbon::parse($estimated_date)->endOfDay();
        // definicion de fechas iniciales y finales
        if($quota->quota_number == 1)
        {
            if(Carbon::parse($this->disbursement_date)->quarter == 1)// desembolso en el primer trimestre
            {
                $date_ini = Carbon::parse($this->disbursement_date)->startOfYear()->startOfDay();
                $date_fin = $date ? $date : Carbon::parse($date_ini)->addMonth($period)->subDay()->endOfDay();
                $days = $date_fin->diffInDays($this->disbursement_date);
            }
            elseif(Carbon::parse($this->disbursement_date)->quarter == 2)// desembolso en el segundo trimestre
            {
                $date_ini = Carbon::parse($this->disbursement_date)->startOfYear()->startOfDay()->addMonth($period);
                $date_fin = $date ? $date : Carbon::parse($date_ini)->addMonth($period)->endOfDay();
                $days = $date_ini->diffInDays($date_fin) + 1;
                $extra_days = $date_ini->diffInDays($this->disbursement_date);
            }
            elseif(Carbon::parse($this->disbursement_date)->quarter == 3)// desembolso en el tercer trimestre
            {
                $date_ini = Carbon::parse($this->disbursement_date)->startOfYear()->startOfDay()->addMonth($period);
                $date_fin = $date ? $date : Carbon::parse($date_ini)->addMonth($period)->subDay()->endOfDay();
                $days = $date_fin->diffInDays($this->disbursement_date);
            }
            elseif(Carbon::parse($this->disbursement_date)->quarter == 4)// desembolso en el cuarto trimestre
            {
                $date_ini = Carbon::parse($this->disbursement_date)->endOfYear()->endOfDay();
                $date_fin = $date ? $date : Carbon::parse($date_ini)->addMonth($period)->subDay()->endOfDay();
                $days = $date_ini->diffInDays($date_fin);
                $extra_days = $date_ini->diffInDays(Carbon::parse($this->disbursement_date)->endOfDay());
            }
        }
        else
        {
            $previous_payment_date = Carbon::parse($latest_quota->estimated_date)->endOfDay();
            $days = Carbon::parse($estimated_date)->endOfDay()->diffInDays($previous_payment_date);
        }
        $interest_generated = LoanPayment::interest_by_days($days + $extra_days, $this->interest->annual_interest, $this->balance, $denominator);
        $estimated_days = [
            'current' => $days + $extra_days,
            'current_generated' => $interest_generated,
            'interest_accumulated' => $latest_quota ? $latest_quota->interest_accumulated : 0,
            'penal' => 0,
            'penal_generated' => LoanPayment::interest_by_days(0, $this->interest->penal_interest, $this->balance, $denominator),
            'penal_accumulated' => $latest_quota ? $latest_quota->penal_accumulated : 0,
        ];
        $quota->estimated_days = $estimated_days;
        $quota->paid_days = $quota->estimated_days;
        $quota->balance = $this->balance;
        $quota->penal_remaining = $quota->paid_days['penal_accumulated'];
        $quota->penal_payment  = 0;
        $quota->interest_remaining = $quota->paid_days['interest_accumulated'];
        $quota->capital_payment = $total_interests = $quota->interest_payment = 0;
        $quota->penal_accumulated = $quota->interest_accumulated = 0;
        $total_interests = 0;
        $partial_amount = 0;
        $interest = $this->interest;
        $amount = $estimated_quota;
        ///
        if ($quota->penal_remaining > 0) {
            if ($amount >= $quota->penal_remaining) {
                $amount = $amount - $quota->penal_remaining;
                //$quota->penal_remaining = 0;
            } else {
                $quota->penal_remaining = $amount;
                $amount = 0;
            }
        } else {
            $quota->penal_remaining = 0;
        }
        $total_interests += $quota->penal_remaining;

        // Interes acumulado corriente

        if ($quota->interest_remaining > 0) {
            if ($amount >= $quota->interest_remaining) {
                $amount = $amount - $quota->interest_remaining;
                //$quota->interest_remaining = 0;
            } else {
                $quota->interest_remaining = $amount;
                $amount = 0;
            }
        } else {
            $quota->interest_remaining = 0;
        }
        $total_interests += $quota->interest_remaining;

        // Interés penal 
        $penal_payment = 0;
        $quota->penal_payment = $this->get_penal_payment($estimated_date);

        // Interés corriente
        $quota->interest_payment = $interest_generated;
        if ($amount >= $quota->interest_payment) {
            $amount = $amount - $quota->interest_payment;
        } else {
            $quota->interest_accumulated = $quota->interests_remaining + ($quota->interest_payment - $amount);
            $quota->interest_payment = $amount;
            $amount = 0;
        }

        $total_interests += Util::round2($quota->interest_payment);

        // Calcular amortización de capital        
        if ($liquidate) {
            $quota->capital_payment = $quota->balance;
        } else {
            if ($amount >= $this->balance) {
                $quota->capital_payment = Util::round2($this->balance);
            } else
                $quota->capital_payment = Util::round2($amount);
        }
        //calculo de la ultima cuota, solo si fue regular en los pagos

        // Calcular monto total de la cuota

        if ($quota->balance == $quota->capital_payment) {
            $quota->next_balance = 0;
        } else {
            $quota->next_balance = Util::round2($this->balance - $quota->capital_payment);
        }
        $quota->estimated_quota = Util::round2($quota->capital_payment + $total_interests);
        $quota->next_balance = Util::round2($quota->balance - $quota->capital_payment);


        //calculo de los nuevos montos restantes

        $quota->penal_accumulated = Util::round2($quota->penal_accumulated + ($quota->estimated_days['penal_accumulated'] - $quota->penal_remaining));
        $quota->interest_accumulated = Util::round2($quota->interest_accumulated + ($quota->estimated_days['interest_accumulated'] - $quota->interest_remaining));

        //redondeos

        $quota->interest_remaining = Util::round2($quota->interest_remaining);
        $quota->penal_remaining = Util::round2($quota->penal_remaining);
        //$quota->excesive_payment = Util::round($total_amount - ($quota->estimated_quota));


        //validacion pago excesivo

        return $quota;
    }

    public function next_payment($estimated_date = null, $amount = null, $liquidate = false)
    {
        $parameter = (LoanProcedure::where('id', $this->loan_procedure_id)->first()->loan_global_parameter->numerator)/(LoanProcedure::where('id', $this->loan_procedure_id)->first()->loan_global_parameter->denominator);
        do {
            if ($liquidate) {
                $amount = $this->amount_requested * $this->amount_requested;
            } else {
                if (!$amount) $amount = $this->estimated_quota;
            }
            $quota = new LoanPayment();
            $next_payment = LoanPayment::quota_date($this);
            if (!$estimated_date) {
                $quota->estimated_date = $next_payment->date;
            } else {
                $quota->estimated_date = Carbon::parse($estimated_date)->toDateString();
            }
            $quota->quota_number = $this->balance > 0 ? $next_payment->quota : null;
            $interest = $this->interest;
            $quota->estimated_days = LoanPayment::days_interest($this, $quota->estimated_date);
            $quota->paid_days = clone ($quota->estimated_days);
            $quota->balance = $this->balance;
            $quota->penal_payment = $quota->accumulated_payment = $quota->interest_payment = $quota->capital_payment = $total_interests = 0;
            // Calcular intereses
            // Interés penal
            do {
                $total_interests -= $quota->penal_payment;
                $quota->penal_payment = Util::round2($quota->balance * $interest->daily_penal_interest * $quota->paid_days->penal);
                $total_interests += $quota->penal_payment;
                if ($total_interests > $amount) {
                    $quota->paid_days->penal = intval($amount * $quota->paid_days->penal / $quota->penal_payment);
                    $quota->paid_days->accumulated = $quota->paid_days->current = 0;
                }
            } while ($total_interests > $amount);
            // Interés acumulado
            do {
                $total_interests -= $quota->accumulated_payment;
                $quota->accumulated_payment = Util::round2($quota->balance * $interest->daily_current_interest($parameter) * $quota->paid_days->accumulated);
                $total_interests += $quota->accumulated_payment;
                if ($total_interests > $amount) {
                    $quota->paid_days->accumulated = intval(($amount - $quota->penal_payment) * $quota->paid_days->accumulated / $quota->accumulated_payment);
                    $quota->paid_days->current = 0;
                }
            } while ($total_interests > $amount);
            // Interés corriente
            do {
                $total_interests -= $quota->interest_payment;
                $quota->interest_payment = Util::round2($quota->balance * $interest->daily_current_interest($parameter) * $quota->paid_days->current);
                $total_interests += $quota->interest_payment;
                if ($total_interests > $amount) {
                    $quota->paid_days->current = intval(($amount - $quota->penal_payment - $quota->accumulated_payment) * $quota->paid_days->current / $quota->interest_payment);
                }
            } while ($total_interests > $amount);
            // Calcular amortización de capital
            //if ($total_interests > 0) {
            if (($quota->balance + $total_interests) > $amount) {
                if ($quota->quota_number == 1 && $quota->estimated_days->accumulated < 31) {
                    $quota->capital_payment = Util::round2($amount + $quota->accumulated_payment - $total_interests);
                } else {
                    $quota->capital_payment = Util::round2($amount - $total_interests);
                }
            } else {
                $quota->capital_payment = $quota->balance;
            }
            //}
            // Calcular monto total de la cuota
            $quota->estimated_quota = Util::round2($quota->capital_payment + $total_interests);
            $quota->next_balance = Util::round2($quota->balance - $quota->capital_payment);

            if ($liquidate) {
                if ($quota->next_balance > 0) {
                    $amount *= $this->amount_requested;
                } else {
                    $liquidate = false;
                }
            }
        } while ($liquidate);
        return $quota;
    }

    //obtener modalidad teniendo el tipo y el afiliado
    public static function get_modality($modality, $affiliate, $type_sismu, $cpop_affiliate, $remake_loan)
    {
        $verify = false;
        $modality_name = $modality->name;
        if (strpos($modality->name, 'Refinanciamiento') === false) { //para restringir para no tener prestamos paralelos de la misma sub mod
            foreach ($affiliate->active_loans() as $loan) {
                if ($loan->modality->procedure_type->id == $modality->id)
                    $verify = true;
            }
        }

        if ($verify && !$remake_loan) abort(403, 'El affiliado tiene préstamos activos en la modalidad: ' . $modality_name);

        $modality = null;
        if ($affiliate->affiliate_state) {
            $affiliate_state = $affiliate->affiliate_state->name;
            $affiliate_state_type = $affiliate->affiliate_state->affiliate_state_type->name;
            switch ($modality_name) {
                case 'Préstamo Anticipo':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state == "Servicio" || $affiliate_state == "Comisión") {
                            $modality = ProcedureModality::whereShortened("ANT-ACT")->first(); //Anticipo activo
                        } else {
                            $modality = ProcedureModality::whereShortened("ANT-DIS")->first(); // Anicipo dismponibilidad
                        }
                    }
                    if ($affiliate_state_type == "Pasivo") {
                        if ($affiliate->pension_entity->type == 'SENASIR') {
                            $modality = ProcedureModality::whereShortened("ANT-SEN")->first(); //  Prestamo a anticipo afp
                        } elseif ($affiliate->pension_entity->type == 'AFPS') {
                            $modality = ProcedureModality::whereShortened("ANT-AFP")->first(); // Prestamo a anticipo senasir
                        } elseif ($affiliate->pension_entity->type == 'GESTORA') {
                            $modality = ProcedureModality::whereShortened("ANT-GES")->first(); // Prestamo a anticipo senasir
                        }
                    }
                    break;
                case 'Préstamo a Corto Plazo':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state == "Servicio" || $affiliate_state == "Comisión") {
                            $modality = ProcedureModality::whereShortened("COR-ACT")->first(); //corto plazo activo
                        } else {
                            $modality = ProcedureModality::whereShortened("COR-DIS")->first(); // corto plazo activo letra A, no le corresponde refinanciamiento segun Art 76 del reglamento
                        }
                    }
                    if ($affiliate_state_type == "Pasivo") {
                        if ($affiliate->pension_entity->type == 'SENASIR') {
                            $modality = ProcedureModality::whereShortened("COR-SEN")->first();
                        } elseif ($affiliate->pension_entity->type == 'AFPS') {
                            $modality = ProcedureModality::whereShortened("COR-AFP")->first();
                        } elseif ($affiliate->pension_entity->type == 'GESTORA') {
                            $modality = ProcedureModality::whereShortened("COR-GES")->first();
                        }
                    }
                    break;
                case 'Refinanciamiento Préstamo a Corto Plazo':
                    if ($affiliate_state_type == "Activo") {

                        if ($affiliate_state == "Servicio" || $affiliate_state == "Comisión") {
                            $modality = ProcedureModality::whereShortened("REF-COR-ACT")->first(); //Refinanciamiento corto plazo activo
                        }
                        if ($affiliate_state == "Disponibilidad") {
                            $modality = ProcedureModality::whereShortened("REF-COR-DIS")->first(); //Refinanciamiento corto plazo activo en Disponibilidad
                        }
                    } else {
                        if ($affiliate_state_type == "Pasivo") {
                            if ($affiliate->pension_entity->type == 'SENASIR') {
                                $modality = ProcedureModality::whereShortened("REF-COR-SEN")->first();
                            } elseif ($affiliate->pension_entity->type == 'AFPS') {
                                $modality = ProcedureModality::whereShortened("REF-COR-AFP")->first();
                            } elseif ($affiliate->pension_entity->type == 'GESTORA') {
                                $modality = ProcedureModality::whereShortened("REF-COR-GES")->first();
                            }
                        }
                    }
                    break;
                case 'Préstamo a Largo Plazo':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state == "Servicio") // Servicio
                        {
                            if ($cpop_affiliate) {
                                $modality = ProcedureModality::whereShortened("LAR-1G")->first();
                            } else {
                                $modality = ProcedureModality::whereShortened("LAR-ACT")->first();
                            }
                        }
                        if ($affiliate_state == "Comisión") // Comision
                        {
                            $modality = ProcedureModality::whereShortened("LAR-COM")->first();
                        }
                        if ($affiliate_state == "Disponibilidad") // disponibilidad letra A o C
                        {
                            $modality = ProcedureModality::whereShortened("LAR-DIS")->first();
                        }
                    }
                    if ($affiliate_state_type == "Pasivo") {
                        if ((!$cpop_affiliate)) {
                            if ($affiliate->pension_entity->type == 'SENASIR') {
                                $modality = ProcedureModality::whereShortened("LAR-SEN")->first();
                            } elseif ($affiliate->pension_entity->type == 'AFPS') {
                                $modality = ProcedureModality::whereShortened("LAR-AFP")->first();
                            } elseif ($affiliate->pension_entity->type == 'GESTORA') {
                                $modality = ProcedureModality::whereShortened("LAR-GES")->first();
                            }
                        }
                    }
                    break;
                case 'Refinanciamiento Préstamo a Largo Plazo':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state == "Servicio") //Prestamo en Servicio
                        {
                            if ($cpop_affiliate) {
                                $modality = ProcedureModality::whereShortened("REF-LAR-1G")->first(); //refi largo plazo activo  un solo garante
                            } else {
                                $modality = ProcedureModality::whereShortened("REF-LAR-ACT")->first(); //refi largo plazo activo
                            }
                        }
                    } else {
                        if ($affiliate_state_type == "Pasivo") {
                            if ((!$cpop_affiliate)) {
                                if ($affiliate->pension_entity->type == 'SENASIR') {
                                    $modality = ProcedureModality::whereShortened("REF-LAR-SEN")->first();
                                } elseif ($affiliate->pension_entity->type == 'AFPS') {
                                    $modality = ProcedureModality::whereShortened("REF-LAR-AFP")->first();
                                } elseif ($affiliate->pension_entity->type == 'GESTORA') {
                                    $modality = ProcedureModality::whereShortened("REF-LAR-GES")->first();
                                }
                            }
                        }
                    }
                    break;
                case 'Préstamo Hipotecario':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state_type !== "Comisión") {

                            if ($cpop_affiliate) {
                                $modality = ProcedureModality::whereShortened("HIP-ACT-CPOP")->first(); //hipotecario CPOP
                            } else {
                                $modality = ProcedureModality::whereShortened("HIP-ACT")->first(); //hipotecario Sector Activo
                            }
                        }
                    }
                    break;
                case 'Refinanciamiento Préstamo Hipotecario':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state_type !== "Comisión" && $affiliate_state !== "Disponibilidad") { //affiliados con estado en disponibilidad no realizaran refinanciamientos 
                            if ($cpop_affiliate) {
                                $modality = ProcedureModality::whereShortened("REF-HIP-ACT-CPOP")->first(); // Refinanciamiento hipotecario CPOP
                            } else {
                                $modality = ProcedureModality::whereShortened("REF-HIP-ACT")->first(); // Refinanciamiento hipotecario Sector Activo
                            }
                        }
                    }
                    break;
            }
        }
        if ($modality) {
            $modality->loan_modality_parameter = $modality->loan_modality_parameter;
            $modality->procedure_type;
            return response()->json($modality);
        } else {
            return response()->json();
        }
    }


    //verificar pagos manuales consecutivos
    public function verify_payment_consecutive()
    {
        $loan_global_parameter  = $loan_global_parameter = $this->loan_procedure->loan_global_parameter;
        $number_payment_consecutive = $loan_global_parameter->consecutive_manual_payment; //3
        $modality_id = ProcedureModality::whereShortened("EFECTIVO")->first()->id;

        $Pagado = LoanPaymentState::whereName('Pagado')->first()->id;

        $payments = $this->payments->where('procedure_modality_id', '=', $modality_id)->where('state_id', '=', $Pagado)->sortBy('estimated_date');

        $consecutive = 1;
        $verify = false;

        if (count($payments) >= $number_payment_consecutive) {
            foreach ($payments as $i => $payment) {
                // $j=$i+1;
                foreach ($payments as $j => $paymentd) {
                    $stimated_date = CarbonImmutable::parse($payments[$i]->estimated_date);
                    $stimated_date_compare = CarbonImmutable::parse($payments[$j++]->estimated_date);
                    //return $payments[$j++]->estimated_date;
                    if ($stimated_date->startOfMonth()->diffInMonths($stimated_date_compare->startOfMonth()) == $consecutive) {
                        $consecutive++;
                    } else {
                        $consecutive = 1;
                    }
                }
                if ($consecutive >= $number_payment_consecutive) {
                    $verify = true;
                    break;
                }
                $consecutive = 1;
            }
        } else {
            $verify = false;
        }
        return $verify;
    }
    public function get_sismu()
    {
        return Sismu::find($this->id);
    }
    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    //obtener mod 
    public static function get_modality_search($modality_name, $affiliate)
    {
        $modality = null;
        if ($affiliate->affiliate_state) {
            $affiliate_state = $affiliate->affiliate_state->name;
            $affiliate_state_type = $affiliate->affiliate_state->affiliate_state_type->name;
            switch ($modality_name) {
                case 'Préstamo Anticipo':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state == "Servicio" || $affiliate_state == "Comisión") {
                            $modality = ProcedureModality::whereShortened("ANT-ACT")->first(); //Anticipo activo
                        } else {
                            $modality = ProcedureModality::whereShortened("ANT-DIS")->first(); // Anicipo dismponibilidad
                        }
                    }
                    break;
                case 'Préstamo a Corto Plazo':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state == "Servicio" || $affiliate_state == "Comisión") {
                            $modality = ProcedureModality::whereShortened("COR-ACT")->first(); //corto plazo activo
                        } else {
                            $modality = ProcedureModality::whereShortened("COR-DIS")->first(); // corto plazo activo letra A, no le corresponde refinanciamiento segun Art 76 del reglamento
                        }
                    }
                    break;
                case 'Préstamo a Largo Plazo':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state !== "Disponibilidad") // disponibilidad letra A o C no puede acceder a prestamos a largo plazo
                        {
                            $modality = ProcedureModality::whereShortened("LAR-ACT")->first();
                        }
                    }
                    break;
                case 'Préstamo Hipotecario':
                    if ($affiliate_state_type == "Activo") {
                        if ($affiliate_state_type !== "Comisión") {
                            $modality = ProcedureModality::whereShortened("HIP-ACT")->first(); //hipotecario Sector Activo
                        }
                    }
                    break;
            }
        }
        if ($modality) {
            $modality->loan_modality_parameter;
            return $modality;
        } else {
            $modality = [];
            return $modality;
        }
    }
    //Saldo del padre a refinanciar 
    public function balance_parent_refi()
    {
        $balance_parent = 0;
        if ($this->data_loan) {
            $balance_parent = $this->data_loan->balance;
        } elseif ($this->parent_loan) {
            if ($this->parent_loan->state->name != "Liquidado") {
                if (LoanPayment::where('loan_id', $this->parent_loan->id)->where('categorie_id', 1)->orderBy('quota_number', 'desc')->first())
                    $balance_parent = LoanPayment::where('loan_id', $this->parent_loan->id)->where('categorie_id', 1)->orderBy('quota_number', 'desc')->first()->estimated_quota;
                else
                    $balance_parent = 0;
            } else {
                $balance_parent = $this->parent_loan->last_payment_validated->estimated_quota;
            }
        }
        return  $balance_parent;
    }
    //fecha de corte para refi
    public function date_cut_refinancing()
    {
        $date_cut_refinancing = null;
        if ($this->data_loan) {
            $date_cut_refinancing = $this->data_loan->date_cut_refinancing;
        } else {
            if ($this->parent_loan->state->name != 'Liquidado') {
                if ($this->parent_loan && $this->parent_loan->payment_pending_confirmation() != null) {
                    $date_cut_refinancing = $this->parent_loan->payment_pending_confirmation()->estimated_date;
                }
            } else
                $date_cut_refinancing = $this->parent_loan->last_payment_validated->estimated_date;
        }
        return  $date_cut_refinancing;
    }

    //Verifica si los pagos realizados fueron regulares y sin mora
    public function regular_payment()
    {
        $loan_payments = $this->payments;
        $quota_number = 1;
        $sw = false;
        foreach ($loan_payments as $payments) {
            if ($quota_number == 1 && $payments->estimated_quota >= $this->estimated_quota)
                $sw = true;
            else {
                if ($payments->estimated_quota == $this->estimated_quota)
                    $sw = true;
                else
                    break;
            }
            $quota_number++;
        }
        return $sw;
    }

    //verificacion de saldo con pagos
    public function verify_balance()
    {
        $payments = $this->payments;
        $loan_state = LoanPaymentState::where('name', 'Pagado')->first();
        $balance = $this->amount_approved;
        $sum = 0;
        foreach ($payments as $payment) {
            if ($payment->state_id == $loan_state->id)
                $sum += $payment->capital_payment;
        }
        return round($balance - $sum, 2);
    }
    //muestra boletas de afiliado
    public function ballot_affiliate()
    {
        $affiliate = $this->borrower->first();
        $contributions = $affiliate->contributionable_ids;
        $contributions_type = $affiliate->contributionable_type;
        $ballots_ids = json_decode($contributions);
        $ballots = collect();
        $adjusts = collect();
        $ballot_adjust = collect();
        $average_ballot_adjust = collect();
        $mount_adjust = 0;
        $sum_payable_liquid = 0;
        $sum_mount_adjust = 0;
        $sum_border_bonus = 0;
        $sum_position_bonus = 0;
        $sum_east_bonus = 0;
        $sum_public_security_bonus = 0;
        $sum_dignity_rent = 0;
        $count_records = 0;
        $contribution_type = null;
        if ($contributions_type == "contributions") {
            $contribution_type = "contributions";
            foreach ($ballots_ids as $is_ballot_id) {
                if (Contribution::find($is_ballot_id))
                    $ballots->push(Contribution::find($is_ballot_id));
                if (LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id', $this->id)->first()) {
                    $adjusts->push(LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id', $this->id)->first());
                }
            }
            $count_records = count($ballots);
            foreach ($ballots as $ballot) {
                foreach ($adjusts as $adjust) {
                    if ($ballot->id == $adjust->adjustable_id)
                        $mount_adjust = $adjust->amount;
                }
                $ballot_adjust->push([
                    'month_year' => $ballot->month_year,
                    'payable_liquid' => (float)$ballot->payable_liquid,
                    'mount_adjust' => (float)$mount_adjust,
                    'border_bonus' => (float)$ballot->border_bonus,
                    'position_bonus' => (float)$ballot->position_bonus,
                    'east_bonus' => (float)$ballot->east_bonus,
                    'public_security_bonus' => (float)$ballot->public_security_bonus,
                ]);
                $sum_payable_liquid = $sum_payable_liquid + $ballot->payable_liquid;
                $sum_mount_adjust = $sum_mount_adjust + $mount_adjust;
                $sum_border_bonus = $sum_border_bonus + $ballot->border_bonus;
                $sum_position_bonus = $sum_position_bonus + $ballot->position_bonus;
                $sum_east_bonus = $sum_east_bonus + $ballot->east_bonus;
                $sum_public_security_bonus = $sum_public_security_bonus + $ballot->public_security_bonus;
            }
            $average_ballot_adjust->push([
                'average_payable_liquid' => $sum_payable_liquid / $count_records,
                'average_mount_adjust' => $sum_mount_adjust / $count_records,
                'average_border_bonus' => $sum_border_bonus / $count_records,
                'average_position_bonus' => $sum_position_bonus / $count_records,
                'average_east_bonus' => $sum_east_bonus / $count_records,
                'average_public_security_bonus' => $sum_public_security_bonus / $count_records,
            ]);
        }
        if ($contributions_type == "aid_contributions") {
            $contribution_type = "aid_contributions";
            foreach ($ballots_ids as $is_ballot_id) {
                if (AidContribution::find($is_ballot_id))
                    $ballots->push(AidContribution::find($is_ballot_id));
                if (LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id', $this->id)->first())
                    $adjusts->push(LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id', $this->id)->first());
            }
            $count_records = count($ballots);
            foreach ($ballots as $ballot) {
                foreach ($adjusts as $adjust) {
                    if ($ballot->id == $adjust->adjustable_id)
                        $mount_adjust = $adjust->amount;
                }
                $ballot_adjust->push([
                    'month_year' => $ballot->month_year,
                    'payable_liquid' => (float)$ballot->rent,
                    'mount_adjust' => (float)$mount_adjust,
                    'dignity_rent' => (float)$ballot->dignity_rent,
                ]);
                $sum_payable_liquid = $sum_payable_liquid + $ballot->rent;
                $sum_mount_adjust = $sum_mount_adjust + $mount_adjust;
                $sum_dignity_rent = $sum_dignity_rent + $ballot->dignity_rent;
            }
            $average_ballot_adjust->push([
                'average_payable_liquid' => $sum_payable_liquid / $count_records,
                'average_mount_adjust' => $sum_mount_adjust / $count_records,
                'average_dignity_rent' => $sum_dignity_rent / $count_records,
            ]);
        }
        if ($contributions_type == "loan_contribution_adjusts") {
            $contribution_type = "loan_contribution_adjusts";
            $liquid_ids = LoanContributionAdjust::where('loan_id', $this->id)->where('type_adjust', "liquid")->get()->pluck('id');
            $adjust_ids = LoanContributionAdjust::where('loan_id', $this->id)->where('type_adjust', "adjust")->get()->pluck('id');
            foreach ($liquid_ids as $liquid_id) {
                $ballots->push(LoanContributionAdjust::find($liquid_id));
            }
            foreach ($adjust_ids as $adjust_id) {
                $adjusts->push(LoanContributionAdjust::find($adjust_id));
            }
            $count_records = count($ballots);
            foreach ($ballots as $ballot) {
                foreach ($adjusts as $adjust) {
                    if ($ballot->period_date == $adjust->period_date)
                        $mount_adjust = $adjust->amount;
                }
                $ballot_adjust->push([
                    'month_year' => $ballot->period_date,
                    'payable_liquid' => (float)$ballot->amount,
                    'mount_adjust' => (float)$mount_adjust,
                ]);
                $sum_payable_liquid = $sum_payable_liquid + $ballot->amount;
                $sum_mount_adjust = $sum_mount_adjust + $mount_adjust;
            }
            $average_ballot_adjust->push([
                'average_payable_liquid' => $sum_payable_liquid / $count_records,
                'average_mount_adjust' => $sum_mount_adjust / $count_records,
            ]);
        }
        $data = [
            'contribution_type' => $contribution_type,
            'average_ballot_adjust' => $average_ballot_adjust,
            'ballot_adjusts' => $ballot_adjust->sortBy('month_year')->values()->toArray(),
        ];
        return (object)$data;
    }

    public function getPlanAttribute()
    {
        $plan = [];
        $loan_global_parameter = $this->loan_procedure->loan_global_parameter;
        $balance = $this->amount_approved;
        $days_aux = 0;
        $interest_rest = 0;
        $estimated_quota = $this->estimated_quota;
        $numerator = $this->loan_procedure->loan_global_parameter->numerator;
        $denominator = $this->loan_procedure->loan_global_paramater->denominator;
        for ($i = 1; $i <= $this->loan_term; $i++) {
            if ($i == 1) {
                $date_ini = Carbon::parse($this->disbursement_date)->format('d-m-Y');
                if (Carbon::parse($date_ini)->format('d') <= $loan_global_parameter->offset_interest_day) {
                    $date_fin = Carbon::parse($date_ini)->endOfMonth();
                    $days = $date_fin->diffInDays($date_ini);
                    $interest = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $balance, $denominator);
                    $capital = $estimated_quota - $interest;
                } else {
                    $date_fin = Carbon::parse($date_ini)->startOfMonth()->addMonth()->endOfMonth();
                    $capital = ($estimated_quota - LoanPayment::interest_by_days($date_fin->day, $this->interest->annual_interest, $balance, $denominator));
                    $days = $date_fin->diffInDays($date_ini);
                    $interest = LoanPayment::interest_by_days($date_fin->day, $this->interest->annual_interest, $balance) + LoanPayment::interest_by_days(Carbon::parse($date_ini)->endOfMonth()->format('d') - Carbon::parse($date_ini)->format('d'), $this->interest->annual_interest, $balance, $denominator);
                }
                $payment = round(($capital + $interest), 2);
            } else {
                $date_fin = Carbon::parse($date_ini)->endOfMonth();
                $days = $date_fin->diffInDays($date_ini) + 1;
                $interest = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $balance);
                $capital = $estimated_quota - $interest;
                $payment = $estimated_quota;
            }
            $balance = ($balance - $capital);
            if ($i == 1) {
                array_push($plan, (object)[
                    'nro' => $i,
                    'date' => Carbon::parse($date_fin)->format('d-m-Y'),
                    'days' => $days + $days_aux,
                    'interest' => $interest + $interest_rest,
                    'capital' => $capital,
                    'payment' => $payment + $interest_rest,
                    'balance' => $balance,
                ]);
            } else {
                if ($i == $this->loan_term) {
                    array_push($plan, (object)[
                        'nro' => $i,
                        'date' => Carbon::parse($date_fin)->format('d-m-Y'),
                        'days' => $days,
                        'interest' => $interest,
                        'capital' => $capital + $balance,
                        'payment' => $payment + $balance,
                        'balance' => 0,
                    ]);
                } else {
                    array_push($plan, (object)[
                        'nro' => $i,
                        'date' => Carbon::parse($date_fin)->format('d-m-Y'),
                        'days' => $days,
                        'interest' => $interest,
                        'capital' => $capital,
                        'payment' => $payment,
                        'balance' => $balance,
                    ]);
                }
            }
            $date_ini = Carbon::parse($date_fin)->startOfMonth()->addMonth();
        }
        return $plan;
    }
    public function validate_loan_affiliate_edit($amount_approved, $loan_term)
    {
        $procedure_modality = ProcedureModality::findOrFail($this->procedure_modality_id);
        if ($amount_approved <= $procedure_modality->loan_modality_parameter->maximum_amount_modality && $amount_approved >= $procedure_modality->loan_modality_parameter->minimum_amount_modality) {
            if ($loan_term <= $procedure_modality->loan_modality_parameter->maximum_term_modality && $loan_term >= $procedure_modality->loan_modality_parameter->minimum_term_modality) {
                $quota_estimated = CalculatorController::quota_calculator($procedure_modality, $loan_term, $amount_approved);
                $new_indebtedness_calculated = Util::round2(($quota_estimated / $this->liquid_qualification_calculated) * 100);
                $validate = false;
                if ($new_indebtedness_calculated <= $procedure_modality->loan_modality_parameter->debt_index) {
                    if ((count($this->guarantors) > 0) || (count($this->borrower) > 1)) {
                        if ($new_indebtedness_calculated <= Util::round2($this->indebtedness_calculated_previous)) {
                            foreach ($this->borrower  as $lender) {
                                $quota_estimated_lender = $quota_estimated / count($this->borrower);
                                $new_indebtedness_lender = Util::round2($quota_estimated_lender / (float)$lender->liquid_qualification_calculated * 100);
                                if ($new_indebtedness_lender <= (float)$lender->indebtedness_calculated_previous) {
                                    $validate = true;
                                } else {
                                    $validate = false;
                                }
                            }
                            if (count($this->guarantors) > 0) {
                                foreach ($this->guarantors  as $guarantor) {
                                    $loan_guarantor = LoanGuarantor::where('loan_id', $guarantor->pivot->loan_id)->where('affiliate_id', $guarantor->pivot->affiliate_id)->first();
                                    $active_guarantees = $guarantor->active_guarantees();
                                    $sum_quota = 0;
                                    foreach ($active_guarantees as $res)
                                        $sum_quota += ($res->estimated_quota * $res->payment_percentage) / 100; // descuento en caso de tener garantias activas
                                    $active_guarantees_sismu = $guarantor->active_guarantees_sismu();
                                    foreach ($active_guarantees_sismu as $res)
                                        $sum_quota += $res->PresCuotaMensual / $res->quantity_guarantors; // descuento en caso de tener garantias activas del sismu*/
                                    $quota_estimated_guarantor = $quota_estimated / count($this->guarantors);
                                    $new_indebtedness_calculated_guarantor = Util::round2((($quota_estimated_guarantor + $sum_quota - $loan_guarantor->quota_treat) / $loan_guarantor->liquid_qualification_calculated) * 100);
                                    if ($new_indebtedness_calculated_guarantor <= (float)$loan_guarantor->indebtedness_calculated_previous) {
                                        $validate = true;
                                    } else {
                                        $validate = false;
                                    }
                                }
                            }
                            if ($validate) {
                                $validate = true;
                            } else {
                                $message['message'] = 'El índice de endeudamiento del titular o garante no debe ser superior a la evaluación realizada en la creación del tramite';
                            }
                            return $validate;
                        } else {
                            $message['message'] = 'El índice de endeudamiento no debe ser superior a ' . $this->indebtedness_calculated_previous . '%, evaluación realizada en la creación del tramite';
                        }
                    } else {
                        if (count($this->borrower) == 1) {
                            foreach ($this->borrower  as $lender) {
                                $validate = true;
                            }
                            return $validate;
                        }
                    }
                } else {
                    $message['message'] = 'El índice de endeudamiento no debe ser superior a ' . $procedure_modality->loan_modality_parameter->debt_index . '%';
                }
            } else {
                $message['message'] = 'No se pudo realizar la edición. El plazo en meses solicitado no corresponde a la modalidad ' . $procedure_modality->name;
            }
        } else {
            $message['message'] = 'No se pudo realizar la edición. El monto solicitado no corresponde a la modalidad ' . $procedure_modality->name;
        }
        return $message;
    }

    public function verify_regular_payments()
    {
        $date = Carbon::parse($this->disbursement_date)->format('Y-m-d');
        $regular = true;
        //nuevo procedimiento
        foreach ($this->paymentsKardex as $payment) {
            if ($payment->estimated_quota != $this->loan_plan->where('quota_number', $payment->quota_number)->first()->total_amount || $payment->estimated_date != $this->loan_plan->where('quota_number', $payment->quota_number)->first()->estimated_date) {
                $regular = false;
                break;
            }
        }
        return $regular;
    }

    public function get_amount_payment($loan_payment_date, $liquidate, $type)
    {
        $quota = 0;
        $penal_interest = 0;
        $suggested_amount = 0;
        $estimated_date = null;
        $numerator = $this->loan_procedure->loan_global_parameter->numerator;
        $denominator = $this->loan_procedure->loan_global_parameter->denominator;
        if ($liquidate) { // Opcion si es liquidación del prestamo
            $remaining = 0;
            if (!$this->last_payment_validated)
                $days = Carbon::parse(Carbon::parse($this->disbursement_date)->endOfDay())->diffInDays(Carbon::parse($loan_payment_date)->endOfDay());
            else {
                $days = Carbon::parse(Carbon::parse($this->last_payment_validated->estimated_date)->endOfDay())->diffInDays(Carbon::parse($loan_payment_date)->endOfDay());
                $remaining = $this->last_payment_validated->interest_accumulated + $this->last_payment_validated->penal_accumulated;
            }
            $interest_by_days = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $this->balance, $denominator);
            $penal_interest = $this->get_penal_payment($loan_payment_date);
            $suggested_amount = $this->balance + $interest_by_days + $penal_interest + $remaining;
        } 
        else 
        { // Cuota Regular
            if ($type == "T") 
            { // Cuota pagada por el titular
                if (!$this->last_payment_validated) 
                { //Primera cuota
                    if($this->modality->loan_modality_parameter->loan_month_term == 1)
                    {
                        $date_ini = CarbonImmutable::parse($this->disbursement_date);
                        if ($date_ini->day <= $this->loan_procedure->loan_global_parameter->offset_interest_day) {
                            $suggested_amount = $this->estimated_quota;
                        } else {
                            $date_pay = Carbon::parse($this->disbursement_date)->startOfMonth()->addMonth()->endOfMonth();
                            $loan_payment_date = Carbon::parse($loan_payment_date);
                            if ($loan_payment_date->lt($date_pay)) // less than
                            {
                                if($this->loan_term == 1)
                                {
                                    $days = Carbon::parse(Carbon::parse($this->disbursement_date)->endOfDay())->diffInDays(Carbon::parse($loan_payment_date)->endOfDay());
                                    $suggested_amount = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $this->balance, $denominator) + $this->balance;
                                }
                                else
                                {
                                    $extra_days = Carbon::parse(Carbon::parse($this->disbursement_date)->format('d-m-Y'))->diffInDays(Carbon::parse($this->disbursement_date)->endOfMonth()->format('d-m-Y'));
                                    $suggested_amount = LoanPayment::interest_by_days($extra_days, $this->interest->annual_interest, $this->balance, $denominator) + $this->estimated_quota;
                                }
                            } else {
                                if($this->loan_term == 1)
                                {
                                    $days = Carbon::parse(Carbon::parse($this->disbursement_date)->endOfDay())->diffInDays(Carbon::parse($loan_payment_date)->endOfDay());
                                    $suggested_amount = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $this->balance, $denominator) + $this->balance;  
                                }
                                else
                                    $suggested_amount = $this->estimated_quota;
                            }
                        }
                    }
                    else
                    {
                        $extra_days = 0;
                        if(Carbon::parse($this->disbursement_date)->quarter == 2) // caso a cobrar 2do complemento
                        {
                            $date_ini = Carbon::parse($this->disbursement_date)->endOfQuarter();
                            $extra_days = Carbon::parse($this->disbursement_date)->diffInDays(Carbon::parse($date_ini));
                        }
                        elseif(Carbon::parse($this->disbursement_date)->quarter == 4) //casos cobrados con 1er complemento del siguiente año
                        {
                            $date_ini = Carbon::parse($this->disbursement_date)->startOfYear()->addYear()->startOfDay();
                            $extra_days = Carbon::parse($this->disbursement_date)->diffInDays(Carbon::parse($date_ini));
                        }
                        $extra_interest = LoanPayment::interest_by_days($extra_days, $this->interest->annual_interest, $this->balance, $denominator);

                        if($this->loan_term == 1)
                        {
                            $days = Carbon::parse(Carbon::parse($this->disbursement_date)->endOfDay())->diffInDays(Carbon::parse($loan_payment_date)->endOfDay());
                            $suggested_amount = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $this->balance, $denominator) + $this->balance;  
                        }else
                            $suggested_amount = $extra_interest + $this->estimated_quota;
                    }
                }
                else 
                {// Otras cuotas
                    if ($this->verify_regular_payments() && ($this->paymentsKardex->count() + 1) == $this->loan_term && $this->payment_plan_compliance) {
                        $days = Carbon::parse($this->last_payment_validated->estimated_date)->diffInDays($loan_payment_date);
                        $interest_by_days = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $this->balance, $denominator);
                        $suggested_amount = $interest_by_days + $this->balance;
                    } else {
                        if ($this->balance > $this->estimated_quota)
                            $suggested_amount = $this->estimated_quota;
                        else {
                            $days = Carbon::parse($this->last_payment_validated->estimated_date)->diffInDays($loan_payment_date);
                            $interest_by_days = LoanPayment::interest_by_days($days, $this->interest->annual_interest, $this->balance, $denominator);
                            $suggested_amount = $this->balance + $interest_by_days;
                        }
                    }
                }
            } else { // cuota pagada por los garantes
                $suggested_amount = $this->BorrowerGuarantors->first()->quota_treat;
            }
        }
        return  round($suggested_amount, 2);
    }

    public function getBorrowerAttribute()
    {
        $data = collect([]);
        $borrowers = LoanBorrower::where('loan_id', $this->id)->get();
        foreach ($borrowers as $borrower) {
            $borrower_data = new LoanBorrower();
            $borrower_data = $borrower;
            $borrower_data->city_identity_card = $borrower->city_identity_card;
            $borrower_data->initials = $borrower->initials;
            $borrower_data->account_number = $borrower->loan->number_payment_type;
            $borrower_data->financial_entity = $this->financial_entity;
            $borrower_data->type = $borrower->type;
            $borrower_data->quota = $borrower->quota_treat;
            $borrower_data->percentage_quota = $borrower->payment_percentage;
            $borrower_data->state = $borrower->affiliate_state;
            $borrower_data->address = $borrower->address;
            $borrower_data->ballots = $borrower->ballots;
            $borrower_data->sigep_status = $borrower->affiliate()->sigep_status;
            $data->push($borrower_data);
        }
        return $data;
    }

    public function one_borrower()
    {
        return $this->hasOne(LoanBorrower::class);
    }

    public function getBorrowerGuarantorsAttribute()
    {
        $data = collect([]);
        foreach ($this->guarantors as $guarantor) {
            $titular_guarantor = LoanGuarantor::where('loan_id', $this->id)->where('affiliate_id', $guarantor->id)->first();
            $titular_guarantor->city_identity_card = $titular_guarantor->city_identity_card;
            $titular_guarantor->type_initials = "G-" . $titular_guarantor->initials;
            $titular_guarantor->ballots = $titular_guarantor->ballots();
            $titular_guarantor->cell_phone_number = $titular_guarantor->cell_phone_number;
            $titular_guarantor->account_number = $titular_guarantor->account_number;
            $titular_guarantor->financial_entity = $titular_guarantor->financial_entity;
            $titular_guarantor->type = $titular_guarantor->type;
            $titular_guarantor->quota = $titular_guarantor->quota_treat;
            $titular_guarantor->percentage_quota = $titular_guarantor->percentage_quota;
            $titular_guarantor->state = $titular_guarantor->affiliate_state;
            $titular_guarantor->address = $titular_guarantor->address;
            $titular_guarantor->active_guarantees = $titular_guarantor->active_guarantees();
            $data->push($titular_guarantor);
        }
        return $data;
        //return $this->hasMany(LoanGuarantor::class);
    }
    public function getGuarantors()
    {
        $loans_guarantors = DB::table('view_loan_guarantors')
            ->where('id_loan', $this->id)
            ->select('*')
            ->get();
        return $loans_guarantors;
    }

    public function getBorrowers()
    {
        $loans_borrowers = DB::table('view_loan_borrower')
            ->where('id_loan', $this->id)
            ->select('*')
            ->get();
        return $loans_borrowers;
    }
    //ultimo pago del kardex web y kardex de impresión
    public function payment_kardex_last()
    {
        return $this->paymentsKardex->sortByDesc('id')->first();
    }

    //actualizacion del estado del prestamo
    public function verify_state_loan()
    {
        if ($this->state->name == "Vigente") {
            if ($this->verify_balance() == 0) {
                $this->state_id = LoanState::whereName('Liquidado')->first()->id;
                $this->update();
            }
        }
        if ($this->state->name == "Liquidado") {
            if ($this->verify_balance() > 0) {
                $this->state_id = LoanState::whereName('Vigente')->first()->id;
                $this->update();
            }
        }
        return $this;
    }

    public function regular_payments_date($date)
    {
        $date = Carbon::parse($date)->endOfDay();
        $loan_payments = LoanPayment::where('loan_id', $this->id)->where('estimated_date', '<=', $date)->where('state_id', LoanPaymentState::whereName('Pagado')->first()->id)->orderBy('quota_number', 'asc')->get();
        $quota_number = 1;
        $sw = true;
        foreach ($loan_payments as $payments) {
            if ($payments->estimated_quota < LoanPlanPayment::where('loan_id', $this->id)->where('quota_number', $quota_number)->first()->total_amount) {
                $sw = false;
                break;
            }
            $quota_number++;
        }
        return $sw;
    }

    public function destroy_borrower()
    {
        return $this->borrower->first()->forceDelete();
    }

    public function destroy_guarantors()
    {
        foreach ($this->BorrowerGuarantors as $guarantor)
            $guarantor->forceDelete();
        if ($this->guarantors->count() == 0)
            return true;
        else
            return false;
    }

    public function destroy_guarantee_registers()
    {
        foreach ($this->loan_guarantee_registers as $guarantee_register)
            $guarantee_register->forceDelete();
    }

    public function loan_guarantee_registers()
    {
        return $this->hasMany(LoanGuaranteeRegister::class);
    }

    public function loan_tracking()
    {
        return $this->hasMany(LoanTracking::class)->orderBy("tracking_date");
    }

    public function default_alert()
    {
        $state_loan = false;
        $loan_procedure = LoanProcedure::where('is_enable', true)->first()->id;
        $loan_global_parameter = LoanGlobalParameter::where('loan_procedure_id', $loan_procedure)->first();
        if ($this->state_id == LoanState::whereName('Vigente')->first()->id) { // prestamo Vigente
            $new_current_date = Affiliate::find($this->affiliate_id)->default_alert_date_import();
            if ($this->last_payment_validated) {
                $date_ini = Carbon::parse($this->last_payment_validated->estimated_date)->startOfDay();
                $days = $date_ini->diffInDays($new_current_date);
            } else {
                $date_ini = Carbon::parse($this->disbursement_date)->startOfDay();
                $date_end = Carbon::parse($new_current_date)->endOfDay();
                if ($date_ini) {
                    if (Carbon::parse($date_ini)->format('d') <= $loan_global_parameter->offset_interest_day) {
                        $days = $date_end->diffInDays($date_ini);
                    } else {
                        $extra_days = Carbon::parse($this->disbursement_date)->endOfMonth()->endOfDay()->format('d') - $date_ini->format('d');
                        $days = Carbon::parse($this->disbursement_date)->diffInDays($date_end) - $extra_days;
                    }
                }
            }
            if ($days <= $loan_global_parameter->days_current_interest) {
                $state_loan = false;
            } else {
                $state_loan = true; // Prestamo en Mora
            }
        }
        return $state_loan;
    }

    public function getRetirementAttribute()
    {   
        $loan = Loan::find($this->id);
        $retirement = [];

        if ($loan) {
            $average = $loan->loanGuaranteeRetirementFund->retirementFundAverage->retirement_fund_average ?? null;
            $percentage = $loan->modality->loan_modality_parameter->coverage_percentage ?? null;

            if ($average !== null && $percentage !== null) {
                $retirement = [
                    'average' => $average,
                    'coverage' => $average * $percentage,
                    'percentage' => $percentage
                ];
            }
        }

        return $retirement;
    }

    public function loan_procedure()
    {
        return $this->hasOne(LoanProcedure::class, 'id', 'loan_procedure_id');
    }

    public function paid_by_guarantors()
    {
        if ($this->payments->where('paid_by', 'G')->where('validated', true)->where('state_id', LoanPaymentState::where('name', 'Pagado')->first()->id)->count() > 0)
            return true;
        else
            return false;
    }

    public function loanBorrowers()
    {
        return $this->hasMany(LoanBorrower::class, 'loan_id');
    }

    public function loanGuaranteeRetirementFund()
    {
        return $this->hasOne(LoanGuaranteeRetirementFund::class,'loan_id');
    }

    public function currentState()
    {
        return $this->belongsTo(WfState::class, 'wf_states_id');
    }

    public function get_min_amount_for_refinancing()
    {
        $pay_for_eval = $this->loan_term - 3;
        return $this->loan_plan->where('quota_number', $pay_for_eval)->first()->balance;
    }

    public function expiration_date()
    {
        if($this->disbursement_date)
            return Carbon::parse($this->disbursement_date)->startOfMonth()->addMonths($this->loan_term)->endOfMonth()->format('d-m-Y');
        else
            return null;
    }

    public function platform_user()
    {
        $id_platform = Role::where('module_id', 6)->whereDisplayName('Plataforma')->first()->id;
        return $this->records()->where('action', 'ilike', '%registró%')->whereRoleId($id_platform)->orderBy('created_at', 'desc')->first()->user ?? null;
    }

    public function qualification_user()
    {
        $id_qualification = Role::where('module_id', 6)->whereDisplayName('Calificación')->first()->id;
        return $this->records()->where('action', 'ilike', '%de Calificación%')->whereRoleId($id_qualification)->orderBy('created_at', 'desc')->first()->user ?? null;
    }

    public function plan_payment_balance_in_date($date)
    {
        $date = Carbon::parse($date)->endOfDay();
        return $this->loan_plan->where('estimated_date', '<=', $date)->sortByDesc('quota_number')->first()->balance ?? $this->amount_approved;
    }

    public function balance_for_reprogramming()
    {
        $payment = $this->last_payment;
        if($payment)
        {
            if (Str::of($payment->categorie->name)->lower()->contains(Str::of('repro')->lower()) && 
            Str::of($payment->modality->name)->lower()->contains(Str::of('repro')->lower()))
                return $payment->capital_payment;
        }
        return 0;
    }

    public function reprogrammed_active_process_loans()
    {
        $loan_states = LoanState::whereNotIn('name',['Anulado', 'Liquidado'])->get()->pluck('id');
        return Loan::where('parent_loan_id', $this->id)
                    ->whereIn('state_id', $loan_states)
                    ->where('parent_reason', 'REPROGRAMACIÓN')->get();
    }

    public function balance_parent_repro()
    {
        return $this->parent_loan_id ? $this->parent_loan->balance_for_reprogramming() : 0;
    }
    
    public function first_payment_date()
    {
        return Carbon::parse($this->loan_plan->sortBy('quota_number')->first()->estimated_date)->format('Y-m-d');
    } 

    public function verify_balance_in_date($date)
    {
        if($date > $this->first_payment_date())
        {
            $month_term = $this->modality->loan_modality_parameter->loan_month_term;
            if($month_term == 1)
            {
                $date_to_compare = Carbon::parse($date)->startOfDay()->startOfMonth()->subMonth()->endOfMonth()->endOfDay();
                $pay_to_compare = $this->loan_plan->where('estimated_date', '<=', Carbon::parse($date_to_compare)->format('Y-m-d'))->sortByDesc('quota_number')->first();
            }
            elseif($month_term == 6){
                if(Carbon::parse($date)->month <= $month_term)
                    $date_to_compare = Carbon::parse($date)->subYear()->endOfYear()->endOfDay();
                else
                    $date_to_compare = Carbon::parse($date)->startOfDay()->startOfYear()->addmonth($month_term - 1)->endOfMonth()->endOfDay();
                $pay_to_compare = $this->loan_plan->where('estimated_date', '<=', Carbon::parse($date_to_compare)->format('Y-m-d'))->sortByDesc('quota_number')->first();
            }
            if($this->balance > $pay_to_compare->balance)
                return true;
            else
                return false;
        }else
            return false;
    }

    /*public function payments_defaulted_by_quota($date)
    {
        $plan_payments = $this->loan_plan
            ->where('estimated_date', '<', Carbon::parse($date)->format('Y-m-d'))
            ->sortByDesc('quota_number');
        $payments_defaulted = [];
        $paid_amount = 0;
        $days = 0;
        $payment_balance = 0;
        $plan_payment_date = null;
        $balance_to_compare = $this->amount_approved;
        $diff_amount = 0;
        foreach($plan_payments as $plan_payment)
        {
            $plan_payment_date = Carbon::parse($plan_payment->estimated_date)->format('Y-m-d');
            $payments = $this->payments->whereBetween('estimated_date',[Carbon::parse($plan_payment->estimated_date)->startOfMonth()->startOfDay(), Carbon::parse($plan_payment->estimated_date)->endOfMonth()->endOfDay()])->sortByDesc('quota_number');
            if($payments->count() > 0)
            {
                $balance_to_compare = $payments->first()->previous_balance - $payments->first()->capital_payment;
                if($balance_to_compare > $plan_payment->balance){
                    $days = Carbon::parse($plan_payment->estimated_date)->diffInDays(Carbon::parse($date));
                    $capital_paid = $payments->sum('capital_payment');
                    $quota_number = $plan_payment->quota_number;
                    if($capital_paid < $plan_payment->capital){
                        $diff_amount = round($plan_payment->capital - $capital_paid,2);
                    $payments_defaulted[] = (object)[
                        'quota' => $quota_number,
                        'days' => $days,
                        'diff_amount' => round($diff_amount,2),
                    ];
                    }else{
                        continue;
                    }
                }else{
                    break;
                }
            }else{
                $payments_defaulted[] = (object)[
                    'quota' => $plan_payment->quota_number,
                    'days' => Carbon::parse($plan_payment->estimated_date)->diffInDays(Carbon::parse($date)),
                    'diff_amount' => round($plan_payment->capital,2),
                ];
            }
        }
        return $payments_defaulted;
    }*/

    public function payments_defaulted_by_quota($date)
    {
        $plan_payments = $this->loan_plan
            ->where('estimated_date', '<', Carbon::parse($date)->format('Y-m-d'))
            ->sortBy('quota_number');
        $payments_defaulted = [];
        $capital_paid = $this->capital_paid();
        $amount = 0;
        foreach($plan_payments as $plan_payment)
        {
            $amount = $capital_paid - $plan_payment->capital;
            if($amount < 0)
            {
                $diff_amount = $plan_payment->capital - $capital_paid;
                $days = Carbon::parse($plan_payment->estimated_date)->diffInDays(Carbon::parse($date));
                $payments_defaulted[] = (object)[
                    'quota' => $plan_payment->quota_number,
                    'days' => $days,
                    'diff_amount' => round($diff_amount,2),
                ];
                $capital_paid = 0;
            }else{
                $capital_paid -= $plan_payment->capital;
            }
        }
        return $payments_defaulted;
    }

    public function get_penal_payment($date)
    {
        return $payments_defaulted = $this->payments_defaulted_by_quota($date);
        $penal_payment = 0;
        $denominator = $this->loan_procedure->loan_global_parameter->denominator;
        if(count($payments_defaulted) > 0)
        {
            foreach($payments_defaulted as $payment_defaulted)
            {
                if($payment_defaulted->diff_amount  > 0)
                    $penal_payment += LoanPayment::penal_by_paid($this->interest->penal_interest, $payment_defaulted->days, $payment_defaulted->diff_amount, $denominator);
            }
        }
        return $penal_payment;
    }

    public function capital_paid()
    {
        return $this->payments->where('state_id', LoanPaymentState::where('name', 'Pagado')->first()->id)->sum('capital_payment');
    }
}
