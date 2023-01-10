<?php

use Illuminate\Database\Seeder;
use App\LoanModalityParameter;
use App\ProcedureModality;

class ProcedureModalityParameterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $procedure_modality_all = ProcedureModality::whereIn('procedure_type_id',[9,10,11,12,13])->get();
        $loan_modality_parameters=[
            [
                'procedure_modality_id'=>$procedure_modality_all->where('name','Anticipo Sector Activo')->first()->id,
                'debt_index' => 50,
                'quantity_ballots' => 1,
                'guarantors' => 0,
                 //'min_guarantor_category'=>0,
                 //'max_guarantor_category'=>0,
                'personal_reference'=>false,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 5000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 3,
                'print_contract_platform' =>true,
                'print_receipt_fund_rotary' => true,
                'print_form_qualification_platform' =>true,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Anticipo en Disponibilidad')->first()->id,
                'debt_index' => 50,//2
                'quantity_ballots' => 1,
                'guarantors' => 0,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>false,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 5000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 3,
                'print_contract_platform' =>true,
                'print_receipt_fund_rotary' => true,
                'print_form_qualification_platform' =>true,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Anticipo Sector Pasivo AFP')->first()->id,
                'debt_index' => 50,//3
                'quantity_ballots' => 1,
                'guarantors' => 1,
                 //'min_guarantor_category'=>0,
                 //'max_guarantor_category'=>0,
                'personal_reference'=>false,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 5000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 3,
                'print_contract_platform' =>true,
                'print_receipt_fund_rotary' => true,
                'print_form_qualification_platform' =>true,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Anticipo Sector Pasivo SENASIR')->first()->id,
                'debt_index' => 50,//4
                'quantity_ballots' => 1,
                'guarantors' => 0,
                 //'min_guarantor_category'=>0,
                 //'max_guarantor_category'=>0,
                'personal_reference'=>false,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 5000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 3,
                'print_contract_platform' =>true,
                'print_receipt_fund_rotary' => true,
                'print_form_qualification_platform' =>true,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Corto Plazo Sector Activo')->first()->id,
                'debt_index' => 30,//5
                'quantity_ballots' => 1,
                'guarantors' => 0,
                 //'min_guarantor_category'=>0,
                 //'max_guarantor_category'=>0,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 25000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 24,
                'print_contract_platform' => true,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Corto Plazo en Disponibilidad')->first()->id,
                'debt_index' => 30,//6
                'quantity_ballots' => 1,
                'guarantors' => 0,
                 //'min_guarantor_category'=>0,
                 //'max_guarantor_category'=>0,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 25000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 24,
                'print_contract_platform' => true,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Corto Plazo Sector Pasivo AFP')->first()->id,
                'debt_index' => 30,//7
                'quantity_ballots' => 1,
                'guarantors' => 0,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 25000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 12,
                'print_contract_platform' => true,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Corto Plazo Sector Pasivo SENASIR')->first()->id,
                'debt_index' => 30,//8
                'quantity_ballots' => 1,
                'guarantors' => 0,
                //'min_guarantor_category'=>0,
                //'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 25000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 12,
                'print_contract_platform' => true,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Refinanciamiento de Préstamo a Corto Plazo Sector Activo')->first()->id,
                'debt_index' => 50,//9
                'quantity_ballots' => 1,
                'guarantors' => 0,
                //'min_guarantor_category'=>0,
                //'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 25000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 24,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Refinanciamiento de Préstamo a Corto Plazo sector Pasivo AFP')->first()->id,
                'debt_index' => 50,//10
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 25000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 12,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Refinanciamiento de Préstamo a Corto Plazo Sector Pasivo SENASIR')->first()->id,
                'debt_index' => 50,//11
                'quantity_ballots' => 1,
                'guarantors' => 0,
                //'min_guarantor_category'=>0,
                 //'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 25000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 12,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Largo Plazo con Garantía Personal Sector Activo')->first()->id,
                'debt_index' => 40,//12
                'quantity_ballots' => 1,
                'guarantors' => 2,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 60,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Largo Plazo con Garantía Personal Sector Pasivo AFP')->first()->id,
                'debt_index' => 40,//13
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 24,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Largo Plazo con Garantía Personal Sector Pasivo SENASIR')->first()->id,
                'debt_index' => 40,//14
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 24,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Largo Plazo con un Solo Garante Sector Activo')->first()->id,
                'debt_index' => 50,//15
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 60,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Largo Plazo con Garantía Personal Servicio Activo Comisión')->first()->id,
                'debt_index' => 40,//16
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>35,
                'max_guarantor_category'=>85,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 60,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Largo Plazo con Garantía Personal Servicio en Disponibilidad')->first()->id,
                'debt_index' => 40,//17
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>35,
                'max_guarantor_category'=>85,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 60,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Refinanciamiento de Préstamo a Largo Plazo Sector Activo')->first()->id,
                'debt_index' => 50,//18
                'quantity_ballots' => 1,
                'guarantors' => 2,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 60,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Refinanciamiento de Préstamo a Largo Plazo Sector Pasivo AFP')->first()->id,
                'debt_index' => 50,//19
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 24,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Refinanciamiento de Préstamo a Largo Plazo Sector Pasivo SENASIR')->first()->id,
                'debt_index' => 50,//20
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 24,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ],[
                'procedure_modality_id'=>$procedure_modality_all->where('name','Refinanciamiento de Préstamo a Largo Plazo con un Solo Garante Sector Activo')->first()->id,
                'debt_index' => 50,//21
                'quantity_ballots' => 1,
                'guarantors' => 1,
                'min_guarantor_category'=>0,
                'max_guarantor_category'=>100,
                'personal_reference'=>true,
                'max_lenders' => 1,
                'minimum_amount_modality' => 1,
                'maximum_amount_modality' => 300000,
                'minimum_term_modality' => 1,
                'maximum_term_modality' => 60,
                'print_contract_platform' => false,
                'print_receipt_fund_rotary' => false,
                'print_form_qualification_platform' =>false,
                'loan_procedure_id' => 2,
            ]
          ];
        foreach ($loan_modality_parameters as $modality_paramet) {
          LoanModalityParameter::firstOrCreate($modality_paramet);
        }
    }
}
