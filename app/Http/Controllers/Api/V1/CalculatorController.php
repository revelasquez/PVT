<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Affiliate;
use Util;
use Illuminate\Support\Str;
use App\ProcedureModality;
use App\Loan;
use App\Http\Requests\CalculatorForm;
use App\Http\Requests\SimulatorForm;
use App\Http\Requests\Guarantor_evaluateForm;
use App\LoanGlobalParameter;
use App\LoanProcedure;
use App\LoanGuarantor;


/** @group Calculadora
* Simulador de la calculadora
*/
class CalculatorController extends Controller
{
    /**
    * Liquido para calificación
    * @bodyParam liquid_calification[0].affiliate_id integer required ID del afiliado. Example: 9389
    * @bodyParam liquid_calification[0].sismu boolean En caso de tener un Préstamo Padre en el Sistema sismu. Example: true
    * @bodyParam liquid_calification[0].quota_sismu float En caso de tener un Préstamo Padre en el Sistema sismu,  se requiere cuota. Example: 500
    * @bodyParam liquid_calification[1].parent_loan_id integer ID de Préstamo Padre No-example.
    * @bodyParam liquid_calification[0].contributions[0].payable_liquid float required Líquido pagable. Example: 2000
    * @bodyParam liquid_calification[0].contributions[0].position_bonus float required Bono Cargo . Example: 50
    * @bodyParam liquid_calification[0].contributions[0].border_bonus float required Bono Frontera . Example: 0
    * @bodyParam liquid_calification[0].contributions[0].public_security_bonus float required Bono Seguridad Ciudadana . Example: 0
    * @bodyParam liquid_calification[0].contributions[0].east_bonus float Bono Oriente. Example: 950.6
    * @bodyParam liquid_calification[0].contributions[0].dignity_rent_bonus float required Bono Renta dignidad. Example: 200
    * @bodyParam liquid_calification[0].contributions[1].payable_liquid float Líquido pagable. Example: 2000
    * @bodyParam liquid_calification[0].contributions[1].position_bonus float Bono Cargo . Example: 50
    * @bodyParam liquid_calification[0].contributions[1].border_bonus float Bono Frontera . Example: 0
    * @bodyParam liquid_calification[0].contributions[1].public_security_bonus float Bono Seguridad Ciudadana . Example: 0
    * @bodyParam liquid_calification[0].contributions[1].east_bonus float Bono Oriente. Example: 950.6
    * @bodyParam liquid_calification[0].contributions[1].dignity_rent_bonus float required Bono Renta dignidad. Example: 200.6
    * @bodyParam liquid_calification[0].contributions[2].payable_liquid float Líquido pagable. Example: 2500
    * @bodyParam liquid_calification[0].contributions[2].position_bonus float Bono Cargo . Example: 0
    * @bodyParam liquid_calification[0].contributions[2].border_bonus float Bono Frontera . Example: 0
    * @bodyParam liquid_calification[0].contributions[2].public_security_bonus float Bono Seguridad Ciudadana . Example: 0
    * @bodyParam liquid_calification[0].contributions[2].east_bonus float Bono Oriente. Example: 950.6
    * @bodyParam liquid_calification[0].contributions[2].dignity_rent_bonus float required Bono Renta dignidad. Example: 300
    * @bodyParam liquid_calification[1].affiliate_id integer required ID del afiliado. Example: 47461
    * @bodyParam liquid_calification[1].parent_loan_id integer ID de Préstamo Padre. Example: 13
    * @bodyParam liquid_calification[1].sismu boolean En caso de tener un Préstamo Padre en el Sistema sismu. Example: false
    * @bodyParam liquid_calification[1].quota_sismu float En caso de tener un Préstamo Padre en el Sistema sismu,  se requiere cuota No-example.
    * @bodyParam liquid_calification[1].contributions[0].payable_liquid float required Líquido pagable. Example: 3000
    * @bodyParam liquid_calification[1].contributions[0].position_bonus float required Bono Cargo . Example: 0
    * @bodyParam liquid_calification[1].contributions[0].border_bonus float required Bono Frontera . Example: 0
    * @bodyParam liquid_calification[1].contributions[0].public_security_bonus float required Bono Seguridad Ciudadana . Example: 0
    * @bodyParam liquid_calification[1].contributions[0].east_bonus float required Bono Oriente. Example: 950.6
    * @bodyParam liquid_calification[1].contributions[0].dignity_rent_bonus float required Bono Renta dignidad. Example: 200.6
    * @authenticated
    * @responseFile responses/calculator/store.200.json
    */
    public function store(CalculatorForm $request)
    {
        $type = true;
        $modality = ProcedureModality::findOrFail($request->modality_id);
        $liquid_calification = $request->liquid_calification;
        $liquid_calificated = collect([]);
        foreach($liquid_calification as $liq){
            $affiliate = Affiliate::findOrFail($liq['affiliate_id']);
            $parent_quota = 0;
            if(array_key_exists('parent_loan_id', $liq)||array_key_exists('sismu', $liq)){
                if(array_key_exists('parent_loan_id', $liq) && $liq['parent_loan_id'] != null)
                {
                    $parent_loan = Loan::findOrFail($liq['parent_loan_id']);
                    if (!$parent_loan) abort(404);
                    $parent_lender = $parent_loan->borrower->first();
                    if(!$parent_lender) abort(403,'El afiliado no es titular del préstamo');
                    if($modality && !Str::contains($modality->name, 'Gestora'))
                        $parent_quota = $parent_loan->next_payment()->estimated_quota * $parent_lender->payment_percentage/100;
                }else{
                    if (array_key_exists('sismu', $liq)) {
                        if($liq['sismu']){
                            $parent_quota = $liq['quota_sismu'];
                        }
                    }
                }
            }
            $contributions = $liq['contributions'];
            $contributions = collect($contributions);
            $payable_liquid_average = $contributions->avg('payable_liquid');
            $position_bonus_average = $contributions->avg('position_bonus');
            $border_bonus_average = $contributions->avg('border_bonus');
            $public_security_bonus_average = $contributions->avg('public_security_bonus');
            $east_bonus_average = $contributions->avg('east_bonus');
            $dignity_rent_bonus_average = $contributions->avg('dignity_rent_bonus');

            $total_bonuses = $position_bonus_average+$border_bonus_average+$public_security_bonus_average+$east_bonus_average+$dignity_rent_bonus_average;
            $liquid_qualification_calculated = $this->liquid_qualification($type, $payable_liquid_average, $total_bonuses, $affiliate, $parent_quota);
            $livelihood_amount = false;
            if($liquid_qualification_calculated > 0) $livelihood_amount=true;
            $guarantees_sismu = $affiliate->active_guarantees_sismu();
            $guarantees_collect = collect([]);
            foreach($guarantees_sismu as $guarantees){
                $quota = $guarantees->PresCuotaMensual/$guarantees->quantity_guarantors;
                $guarantees_collect->push([
                    'id' => $guarantees->IdPrestamo,
                    'code' => $guarantees->PresNumero,
                    'quota' => $quota,
                    'state' => 'Vigente',
                    'origin' => 'sismu'
                ]);
            }
            $guarantees_sismu = $affiliate->process_guarantees_sismu();
            foreach($guarantees_sismu as $guarantees){
                $quota = $guarantees->PresCuotaMensual/$guarantees->quantity_guarantors;
                $guarantees_collect->push([
                    'id' => $guarantees->IdPrestamo,
                    'code' => $guarantees->PresNumero,
                    'loan_quota' => $guarantees->PresCuotaMensual,
                    'quota' => $quota,
                    'state' => 'En Proceso',
                    'origin' => 'sismu'
                ]);
            }
            $guarantees_pvt = $affiliate->active_guarantees();
            foreach($guarantees_pvt as $guarantees){
                $guarantees_collect->push([
                    'loan_id' => $guarantees->id,
                    'code' => $guarantees->code,
                    'loan_quota' => $guarantees->estimated_quota,
                    'quota' => ($guarantees->payment_percentage/100)*$guarantees->estimated_quota,
                    'state' => $guarantees->state->name,
                    'origin' => 'PVT'
                ]);
            }
            $liquid_calificated->push([
                'affiliate_id' => $affiliate->id,
                'payable_liquid_calculated' => Util::round2($payable_liquid_average),
                'bonus_calculated' => Util::round2($total_bonuses),
                'liquid_qualification_calculated' => Util::round2($liquid_qualification_calculated),
                'quota_previous' => Util::round2($parent_quota),
                'livelihood_amount' => $livelihood_amount,
                'guarantees' => $guarantees_collect
            ]);
        }
        return $liquid_calificated;
    }
    /**
    * Simulador
    * @bodyParam procedure_modality_id integer required ID de modalidad. Example: 41
    * @bodyParam amount_requested integer required monto solicitado. Example: 39000
    * @bodyParam months_term integer required plazo. Example: 30
    * @bodyParam liquid_qualification_calculated_lender float liquido para calificacion del titular en caso de evaluación como garantes. Example: 3500
    * @bodyParam guarantor boolean Afiliados evaluados como garantes. Example: true
    * @bodyParam liquid_calculated[0].affiliate_id integer required ID del afiliado. Example: 9389
    * @bodyParam liquid_calculated[0].liquid_qualification_calculated float required liquido para calificación calculada Example: 2200.5
    * @bodyParam liquid_calculated[1].affiliate_id integer required ID del afiliado. Example: 1
    * @bodyParam liquid_calculated[1].liquid_qualification_calculated float required liquido para calificación calculada Example: 2700.6
    * @authenticated
    * @responseFile responses/calculator/simulator.200.json
    */
    public function simulator(SimulatorForm $request)
    {
        $modality = ProcedureModality::findOrFail($request->procedure_modality_id);
        $amount_requested = $request->amount_requested;
        $liquid_calculated = collect($request->liquid_calculated);
        $calculated_data = collect([]);
        if($request->guarantor)
        {
            if(count($liquid_calculated) != $modality->loan_modality_parameter->guarantors)abort(403, 'La cantidad de garantes no corresponde a la modalidad');
            //calculo de totales para la cabecera
            $debt_index = $modality->loan_modality_parameter->debt_index;
            $debt_index_suggested = $debt_index;
            $liquid_qualification_calculated_lender = $request->liquid_qualification_calculated_lender;
            $months_term = $request->months_term;
            $quota_calculated_total = $this->quota_calculator($modality, $request->months_term, $amount_requested);
            $amount_maximum_suggested = $this->maximum_amount($modality,$request->months_term,$liquid_qualification_calculated_lender);
            if($amount_requested>$amount_maximum_suggested){
                $quota_calculated_total = $this->quota_calculator($modality, $request->months_term, $amount_maximum_suggested);
                $amount_requested = $amount_maximum_suggested;
            }
            $maximum_suggested_valid = false;
            if($modality->loan_modality_parameter->minimum_amount<=$amount_maximum_suggested && $amount_maximum_suggested<=$modality->loan_modality_parameter->maximum_amount_modality) $maximum_suggested_valid = true;
            $indebtedness_calculated_total=Util::round((($quota_calculated_total/$liquid_qualification_calculated_lender)*100));
            $evaluate = false;
            if ($indebtedness_calculated_total<=$debt_index) $evaluate=true;
            //calculo de garantes
            $quantity_guarantors = count($liquid_calculated);
            $quota_calculated = $quota_calculated_total/$quantity_guarantors;
            $c=1;$percentage = 0;
            foreach($liquid_calculated as $liquid){
                $affiliate = Affiliate::find($liquid['affiliate_id']);
            // descuento por garantias adicionado
                $active_guarantees = $affiliate->active_guarantees();$sum_quota = 0;
                foreach($active_guarantees as $res)
                {
                    $sum_quota += ($res->estimated_quota * $res->payment_percentage)/100; // descuento en caso de tener garantias activas
                }
                $active_guarantees_sismu = $affiliate->active_guarantees_sismu();
                foreach($active_guarantees_sismu as $res)
                    $sum_quota += $res->PresCuotaMensual / $res->quantity_guarantors; // descuento en caso de tener garantias activas del sismu
                if($quantity_guarantors && $request->liquid_qualification_calculated_lender >0)
                    $indebtedness_calculated = ($quota_calculated + $sum_quota)/$liquid['liquid_qualification_calculated']*100;
                if($quantity_guarantors%2==0){
                    $percentage_payment = intval($quota_calculated*100/$quota_calculated_total);
                }else{
                    if($c<$quantity_guarantors){
                        $percentage_payment = intval($quota_calculated*100/$quota_calculated_total);
                        $c++;$percentage = $percentage + $percentage_payment;
                    }else{
                        $percentage_payment = 100-$percentage;
                    }
                }
                $livelihood_amount = 0; $valuate_affiliate = true;
                $calculated_data->push([
                    'affiliate_id' => $liquid['affiliate_id'],
                    'quota_calculated' => Util::round2($quota_calculated),
                    'indebtedness_calculated' => Util::round2($indebtedness_calculated),
                    'payment_percentage' => $percentage_payment,
                    'liquid_qualification_calculated' => $liquid['liquid_qualification_calculated'],
                    'is_valid' => $valuate_affiliate
                ]);
            }
            foreach($calculated_data as $data)
            {
                if($data['is_valid'] == false)
                {
                    $evaluate = false;
                    break;
                }
            }
            $response = $this->header($quota_calculated_total,$indebtedness_calculated_total,$request->amount_requested,$months_term,$evaluate,$liquid_qualification_calculated_lender,$amount_maximum_suggested, $debt_index_suggested,$maximum_suggested_valid,$calculated_data);
        }
        else{
            $modality = ProcedureModality::findOrFail($request->procedure_modality_id);
            $allowedTypes = [
                'Préstamo Anticipo', 
                'Préstamo a Corto Plazo', 
                'Préstamo a Largo Plazo', 
                'Préstamo al Sector Activo con Garantía del Beneficio del Fondo de Retiro Policial Solidario', 
                'Préstamo Estacional para el Sector Pasivo de la Policía Boliviana'
            ];
            $liquid_repro = 0;
            if(in_array($modality->procedure_type->name, $allowedTypes)){
                if(count($liquid_calculated) > $modality->loan_modality_parameter->max_lenders)abort(403, 'La cantidad de titulares no corresponde a la modalidad');
                foreach($liquid_calculated as $liquid){
                    $quota_calculated = $this->quota_calculator($modality, $request->months_term, $amount_requested);
                    $liquid_repro = $quota_calculated;
                    $amount_maximum = $this->maximum_amount($modality,$request->months_term,$liquid['liquid_qualification_calculated']);
                    $debt_index_suggested = $modality->loan_modality_parameter->suggested_debt_index;
                    $affiliate_average_rf = 0;
                    // para prestamos con garantia delñ fondo de retiro
                    if(strpos($modality->procedure_type->name, 'Fondo de Retiro Policial Solidario'))
                    {
                        $affiliate = Affiliate::find($request->liquid_calculated[0]['affiliate_id']);
                        $affiliate_average_rf = intval($affiliate->retirement_fund_average()->retirement_fund_average * $modality->loan_modality_parameter->coverage_percentage);
                        if($amount_maximum > $affiliate_average_rf)
                            $amount_maximum = $affiliate_average_rf;
                    }
                    //
                    $amount_maximum_suggested = $this->maximum_amount_suggested($modality,$request->months_term,$liquid['liquid_qualification_calculated'], $affiliate_average_rf);
                    //
                    if($amount_requested > $amount_maximum){
                        $quota_calculated = $this->quota_calculator($modality, $request->months_term, $amount_maximum);
                        $amount_requested = $amount_maximum;
                    }
                    $maximum_suggested_valid = false;
                    if($modality->loan_modality_parameter->minimum_amount_modality<=$amount_maximum && $amount_maximum<=$modality->loan_modality_parameter->maximum_amount_modality) $maximum_suggested_valid = true;
                    $indebtedness_calculated = $quota_calculated/$liquid['liquid_qualification_calculated']*100;
                    $livelihood_amount = 0; $valuate = false;
                    $livelihood_amount = $liquid['liquid_qualification_calculated'] - $quota_calculated; // liquido para calificacion menos la cuota estimada debe ser menor igual al monto de subsistencia
                    if(($indebtedness_calculated) <= ($modality->loan_modality_parameter->decimal_index)*100) $valuate = true;  // validar Indice de endeudamiento y monto de subsistencia
                    $calculated_data->push([
                        'affiliate_id' => $liquid['affiliate_id'],
                        'quota_calculated' => strpos($modality->name, 'Reprogramación') !== false ? Util::round2($liquid_repro) : Util::round2($quota_calculated),
                        'indebtedness_calculated' => Util::round2($indebtedness_calculated),
                        'payment_percentage' => 100,
                        'liquid_qualification_calculated' => $liquid['liquid_qualification_calculated'],
                        'is_valid' =>$valuate // debe estar en el rango de indice de endeudamiento y dentro del monto de subsistencia
                    ]);
                }
                if(strpos($modality->name, 'Reprogramación') !== false){
                    $quota_calculated = $liquid_repro;
                    $indebtedness_calculated = 100;
                    $amount_maximum = $request->amount_requested;
                }
                $response = $this->header($quota_calculated,$indebtedness_calculated,$request->amount_requested,$request->months_term,$valuate,$liquid['liquid_qualification_calculated'],$amount_maximum, $amount_maximum_suggested, $debt_index_suggested,$maximum_suggested_valid,$calculated_data);

            }else{
                if(count($liquid_calculated)>$modality->loan_modality_parameter->max_lenders)abort(403, 'La cantidad de titulares no corresponde a la modalidad');
                $response = $this->loan_percent($request);
            }
        }
        return $response;
    }
    // funcion para sacar la cuota estimada con la calculadora---
    public static function quota_calculator($procedure_modality, $months_term, $amount_requested){
        $parameter = (LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->numerator)/(LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->denominator);
        $interest_rate = $procedure_modality->current_interest->monthly_current_interest($parameter, $procedure_modality->loan_modality_parameter->loan_month_term);
        return Util::round2(((($interest_rate)/(1-(1/pow((1+$interest_rate),$months_term))))*$amount_requested));
    }
    // liquido para calificacion
    // type true para lenders, false para guarantees
    private function liquid_qualification($type, $payable_liquid_average, $total_bonuses, $affiliate, $parent_quota=null){
        $sum_quota = 0;
        if($type){
            $sum_quota += LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->livelihood_amount;
        }
        $liquid_qualification_calculated = $payable_liquid_average - $total_bonuses - $sum_quota + $parent_quota;
        return $liquid_qualification_calculated;
    }
    // monto maximo---
    public static function maximum_amount($procedure_modality,$months_term,$liquid_qualification_calculated){
        $parameter = (LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->numerator)/(LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->denominator);
        $interest_rate = $procedure_modality->current_interest->monthly_current_interest($parameter, $procedure_modality->loan_modality_parameter->loan_month_term);
        $loan_interval = $procedure_modality->loan_modality_parameter;
        $debt_index = $procedure_modality->loan_modality_parameter->decimal_index;
        $maximum_qualified_amount = intval((1-(1/pow((1+$interest_rate),$months_term)))*($debt_index*$liquid_qualification_calculated)/$interest_rate);
        if ($maximum_qualified_amount > ($loan_interval->maximum_amount_modality)){
            $maximum_qualified_amount = $loan_interval->maximum_amount_modality;
        }else{
            $maximum_qualified_amount = $maximum_qualified_amount;
        }
        return $maximum_qualified_amount;
    }

    public static function maximum_amount_suggested($procedure_modality,$months_term,$liquid_qualification_calculated, $coverage_amount){
        $parameter = (LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->numerator)/(LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->denominator);
        $interest_rate = $procedure_modality->current_interest->monthly_current_interest($parameter, $procedure_modality->loan_modality_parameter->loan_month_term);
        $loan_interval = $procedure_modality->loan_modality_parameter;
        $suggested_debt_index = $procedure_modality->loan_modality_parameter->decimal_index_suggested;
        $maximum_qualified_amount = intval((1-(1/pow((1+$interest_rate),$months_term)))*($suggested_debt_index*$liquid_qualification_calculated)/$interest_rate);
        if($maximum_qualified_amount > $loan_interval->maximum_amount_modality && $coverage_amount == 0)
            $maximum_qualified_amount = $loan_interval->maximum_amount_modality;
        elseif($coverage_amount > 0 && $maximum_qualified_amount > $coverage_amount)
            $maximum_qualified_amount = $coverage_amount;
        else
            $maximum_qualified_amount = $maximum_qualified_amount;
        return $maximum_qualified_amount;
    }

    //division porcentual de las cuotas de los codeudores
    private function loan_percent(request $request){
        $coverage_amount = 0;
        $loan_global_parameter = LoanProcedure::where('is_enable', true)->first()->loan_global_parameter;
        $procedure_modality = ProcedureModality::findOrFail($request->procedure_modality_id);
        $month_term = $procedure_modality->loan_modality_parameter->loan_month_term;
        $parameter = (LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->numerator)/(LoanProcedure::where('is_enable', true)->first()->loan_global_parameter->denominator);
        $debt_index = $procedure_modality->loan_modality_parameter->debt_index;
        $debt_index_suggested = $procedure_modality->loan_modality_parameter->debt_index_suggested;
        $lc = $request->liquid_calculated;
        $ms = $request->amount_requested;
        $plm = $request->months_term;
        $ticm = $procedure_modality->current_interest->monthly_current_interest($parameter, $month_term);
        $ce = Util::round($this->quota_calculator($procedure_modality, $plm, $ms));
        $liquid_qualification_calculated = 0;
        foreach($lc as $obj){
        $liquid_qualification_calculated = $liquid_qualification_calculated + $obj["liquid_qualification_calculated"];
        }
        /** m */
        $amount_maximum = $this->maximum_amount($procedure_modality,$plm,$liquid_qualification_calculated);
        $amount_maximum_suggested = $this->maximum_amount_suggested($procedure_modality,$plm,$liquid_qualification_calculated, $coverage_amount);
        $debt_index_suggested = $procedure_modality->loan_modality_parameter->suggested_debt_index;
        if($ms>$amount_maximum){
            $ce = $this->quota_calculator($procedure_modality, $plm, $amount_maximum);
            $ms = $amount_maximum;
        }
        $maximum_suggested_valid = false;
        if($procedure_modality->loan_modality_parameter->minimum_amount <= $ms && $ms <= $procedure_modality->loan_modality_parameter->maximum_amount_modality)
            $maximum_suggested_valid = true;
        /** end m */
        $ie = ($ce/$liquid_qualification_calculated)*100;
        if($ie<=$debt_index){
            $evaluate = true;
        }else{
            $evaluate = false;
        }

        $cosigners=array();
        $top_debt_index = $liquid_qualification_calculated * ($debt_index / 100);
        $percentage_change = (1 - ($ce / $top_debt_index));
        foreach($lc as $liquid_calculated){
            $estimated_quota = (($liquid_calculated["liquid_qualification_calculated"]*$debt_index /100) * $percentage_change);
            $estimated_quota = Util::round2((($liquid_calculated["liquid_qualification_calculated"]*$debt_index /100))) - $estimated_quota;
            $livelihood_amount = 0;
            $valuate_affiliate = false;
            $livelihood_amount = $liquid_calculated['liquid_qualification_calculated'] - $estimated_quota; // liquido para calificacion menos la cuota estimada debe ser menor igual al monto de subsistencia
            if($livelihood_amount>$loan_global_parameter->livelihood_amount)
                $valuate_affiliate = true;  // validar Indice de endeudamiento y monto de subsistencia

            /** end m */
            $cosigner=array(
                'affiliate_id' => $liquid_calculated["affiliate_id"],
                "quota_calculated_estimated" => Util::round2($estimated_quota),
                'payment_percentage'=> Util::round2($estimated_quota / $ce * 100),
                'liquid_qualification_calculated' => $liquid_calculated["liquid_qualification_calculated"],
                'indebtnes_calculated' => Util::round2($estimated_quota/$liquid_calculated['liquid_qualification_calculated']*100),
                'is_valid' => $valuate_affiliate // validar si supera al monto de subsistencia
            );
            array_push($cosigners,$cosigner);
        }
        foreach($cosigners as $cos)
        {
            if($cos['is_valid'] == false)
            {
                $evaluate = false;
                break;
            }
        }
        $response = $this->header($ce,$ie,$ms,$plm,$evaluate,$liquid_qualification_calculated, $amount_maximum, $amount_maximum_suggested, $debt_index_suggested,$maximum_suggested_valid,$cosigners);
        return $response;
    }

    //colocado de la cabecera al array
    private function header($ce,$ie,$ms,$plm,$evaluate,$liquid_qualification_calculated, $amount_maximum,$amount_maximum_suggested, $debt_index_suggested,$maximum_suggested_valid,$cosigners){
        $response=array(
            "quota_calculated_estimated_total"=>Util::round2($ce),
            "indebtedness_calculated_total"=>Util::round2($ie),
            "amount_requested"=>$ms,
            "months_term"=>$plm,
            "is_valid"=>$evaluate,
            'liquid_qualification_calculated_total' => $liquid_qualification_calculated,
            'amount_maximum' => $amount_maximum,
            'amount_maximum_suggested' => $amount_maximum_suggested,
            'debt_index_suggested' => $debt_index_suggested,
            'maximum_suggested_valid' => $maximum_suggested_valid,
            "affiliates"=>$cosigners
        );
        return $response;
    }

    /**
    * Evaluacion individual de garantes
    * @bodyParam procedure_modality_id integer required ID de modalidad. Example: 34
    * @bodyParam affiliate_id integer required ID del afiliado. Example: 1
    * @bodyParam quota_calculated_total_lender cuota calculada del titular. Example: 900
    * @bodyParam contributions[0].payable_liquid integer required Líquido pagable. Example: 2000
    * @bodyParam contributions[0].position_bonus integer required Bono Cargo . Example: 0.00
    * @bodyParam contributions[0].border_bonus integer required Bono Frontera . Example: 0.00
    * @bodyParam contributions[0].public_security_bonus integer required Bono Seguridad Ciudadana . Example: 0.00
    * @bodyParam contributions[0].east_bonus integer required Bono Oriente. Example: 0.00
    * @authenticated
    * @responseFile responses/calculator/evaluate_guarantor.200.json
    */
    public function evaluate_guarantor(Guarantor_evaluateForm $request){
        $procedure_modality = ProcedureModality::findOrFail($request->procedure_modality_id);
        $livelihood_amount = true;
        $quantity_guarantors = $procedure_modality->loan_modality_parameter->guarantors;
        if($quantity_guarantors > 0){$type = false;
            $debt_index = 50; // solo para garantias se evalua al 50%
            $affiliate_id = $request->affiliate_id;
            $affiliate = Affiliate::findOrFail($request->affiliate_id);
            $contributions = collect($request->contributions);
            $payable_liquid_average = $contributions->avg('payable_liquid');
            $quota_calculated = $request->quota_calculated_total_lender/$quantity_guarantors;
            $contribution_first = $contributions->first();
            $total_bonuses = $contribution_first['position_bonus']+$contribution_first['border_bonus']+$contribution_first['public_security_bonus']+$contribution_first['east_bonus'];
            $liquid_qualification_calculated = $this->liquid_qualification($type, $payable_liquid_average, $total_bonuses, $affiliate);
            $active_guarantees = $affiliate->active_guarantees();$sum_quota = 0;
            foreach($active_guarantees as $res)
            {
                if($request->remake_evaluation && $res->id != $request->remake_loan_id)
                    $sum_quota += ($res->estimated_quota * $res->payment_percentage)/100; // descuento en caso de tener garantias activas
            }
            $active_guarantees_sismu = $affiliate->active_guarantees_sismu();
            foreach($active_guarantees_sismu as $res)
                $sum_quota += $res->PresCuotaMensual / $res->quantity_guarantors; // descuento en caso de tener garantias activas del sismu
            $liquid_rest = Util::round(($liquid_qualification_calculated * 0.5) - ($quota_calculated + $sum_quota));
            $indebtedness_calculated = ($quota_calculated + $sum_quota)/$liquid_qualification_calculated * 100;
            $evaluate = true;
            $response = array(
                "affiliate_id" => $affiliate_id,
                "payable_liquid_calculated" => Util::round2($payable_liquid_average),
                "bonus_calculated" => Util::round2($total_bonuses),
                "liquid_qualification_calculated" => Util::round2($liquid_qualification_calculated),
                "indebtnes_calculated" => Util::round2($indebtedness_calculated),
                "is_valid" => $evaluate,
                "livelihood_amount" => $livelihood_amount,
                "liquid_rest" => $liquid_rest
            );
            return $response;
        }
        else{
            return abort(403, 'no corresponde a esta modalidad');
        }
    }


    /**
    * Nueva Evaluacion individual de garantes
    * @bodyParam procedure_modality_id integer required ID de modalidad. Example: 34
    * @bodyParam affiliate_id integer required ID del afiliado. Example: 1
    * @bodyParam quota_calculated_total_lender cuota calculada del titular. Example: 900
    * @bodyParam contributions[0].payable_liquid integer required Líquido pagable. Example: 2000
    * @bodyParam contributions[0].position_bonus integer required Bono Cargo . Example: 0.00
    * @bodyParam contributions[0].border_bonus integer required Bono Frontera . Example: 0.00
    * @bodyParam contributions[0].public_security_bonus integer required Bono Seguridad Ciudadana . Example: 0.00
    * @bodyParam contributions[0].east_bonus integer required Bono Oriente. Example: 0.00
    * @bodyParam guarantees[0].id integer id del prestamo. Example: 9
    * @bodyParam guarantees[0].code string codigo del prestamo del prestamo. Example: PTMO000009-2021
    * @bodyParam guarantees[0].lender string titular del prestamo. Example: IVAN
    * @bodyParam guarantees[0].quota float required quota del prestamo como garante del prestamo. Example: 1314.2
    * @bodyParam guarantees[0].quota_loan float required cuota total del prestamo. Example: 2628.39
    * @bodyParam guarantees[0].state string required estado del prestamo. Example: VIGENTE
    * @bodyParam guarantees[0].type string required tipo del tramite PVT, SISMU del prestamo. Example: VIGENTE
    * @authenticated
    * @responseFile responses/calculator/evaluate_guarantor.200.json
    */
    public function evaluate_guarantor2(Guarantor_evaluateForm $request){
        $quantity_guarantors = ProcedureModality::find($request->procedure_modality_id)->loan_modality_parameter->guarantors;
        if($quantity_guarantors > 0){
            $type = false;
            $procedure_modality = ProcedureModality::find($request->procedure_modality_id)->loan_modality_parameter;
            $debt_index = $procedure_modality->guarantor_debt_index;
            $eval_percentage = $procedure_modality->eval_percentage;
            $affiliate = Affiliate::findOrFail($request->affiliate_id);
            $contributions = collect($request->contributions);
            $payable_liquid_average = $contributions->avg('payable_liquid');
            $quota_calculated = $request->quota_calculated_total_lender / $quantity_guarantors;
            $contribution_first = $contributions->first();
            $total_bonuses = $contribution_first['position_bonus']+$contribution_first['border_bonus']+$contribution_first['public_security_bonus']+$contribution_first['east_bonus']+$contribution_first['dignity_rent'];
            $liquid_qualification_calculated = $this->liquid_qualification($type, $payable_liquid_average, $total_bonuses, $affiliate);
            $total_guarantees = 0;
            $eval_quota = $request->quota_calculated_total_lender * $eval_percentage;
            $personal_debt_index = $eval_quota / $liquid_qualification_calculated;
            $total_debt_index = 0;
            $quota_eval_guarantees = 0;
            //
            foreach($request->guarantees as $guarantees)
            {
                $quota_eval_guarantees += $guarantees['eval_quota'];
            }
            if($liquid_qualification_calculated > 0)
            {
                $total_debt_index = Util::round2((($eval_quota + $quota_eval_guarantees)/$liquid_qualification_calculated) * 100);
                if($total_debt_index <= $debt_index)
                    $liquid_rest = $liquid_qualification_calculated - $eval_quota - $quota_eval_guarantees;
                else
                    $liquid_rest = 0;
            }else{
                $indebtedness_calculated = 100;
                $liquid_rest = 0;
            }
            $response = array(
                "affiliate_id" => $affiliate->id,
                "payable_liquid_calculated" => Util::round2($payable_liquid_average),
                "bonus_calculated" => Util::round2($total_bonuses),
                "liquid_qualification_calculated" => Util::round2($liquid_qualification_calculated),
                "indebtnes_calculated" => Util::round($total_debt_index),
                "quota_calculated" => Util::round2($quota_calculated),
                'payment_percentage' => Util::round2(100/$quantity_guarantors),
                "is_valid" => $total_debt_index > $debt_index ? false : true,
                "liquid_rest" => $liquid_rest < 0 ? 0 : Util::round2($liquid_rest),
                "eval_quota" => Util::round2($eval_quota)
            );
            return $response;
        }
        else{
            return abort(403, 'no corresponde a esta modalidad');
        }
    }
}