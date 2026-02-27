<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\V1\CalculatorController;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use DB;
use App\Affiliate;
use App\City;
use App\User;
use App\Loan;
use App\Tag;
use App\LoanState;
use App\LoanPaymentState;
use App\RecordType;
use App\Workflow;
use App\WfSequence;
use App\ProcedureDocument;
use App\ProcedureModality;
use App\PaymentType;
use App\Role;
use App\RoleSequence;
use App\LoanPayment;
use App\Voucher;
use App\Sismu;
use App\Record;
use App\ProcedureType;
use App\Module;
use App\Contribution;
use App\AidContribution;
use App\LoanContributionAdjust;
use App\LoanGuaranteeRegister;
use App\LoanGlobalParameter;
use App\MovementConcept;
use App\MovementFundRotatory;
use App\Http\Requests\LoansForm;
use App\Http\Requests\LoanForm;
use App\Http\Requests\LoanPaymentForm;
use App\Http\Requests\ObservationForm;
use App\Http\Requests\DisbursementForm;
use App\Events\LoanFlowEvent;
use Carbon;
use App\Helpers\Util;
use App\Http\Controllers\Api\V1\LoanPaymentController;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ArchivoPrimarioExport;
use App\Exports\FileWithMultipleSheetsReport;
use App\Exports\FileWithMultipleSheetsDefaulted;
use App\LoanPlanPayment;
use App\LoanBorrower;
use App\LoanGuarantor;
use App\LoanProcedure;
use App\Jobs\ProcessNotificationSMS;
use App\LoanGuaranteeRetirementFund;
use App\Observation;
use App\WfState;
use App\ObservationForModule;
use App\LoanModalityParameter;

/** @group Préstamos
* Datos de los trámites de préstamos y sus relaciones
*/
class LoanController extends Controller
{
    public static function append_data(Loan $loan)
    {
        $loan->indebtedness_calculated = $loan->indebtedness_calculated;
        $loan->liquid_qualification_calculated = $loan->liquid_qualification_calculated;
        $loan->balance = $loan->balance;
        $loan->balance_for_reprogramming = $loan->balance_for_reprogramming();
        $loan->estimated_quota = $loan->estimated_quota;
        $loan->defaulted = $loan->defaulted;
        $loan->observed = $loan->observed;
        $loan->last_payment_validated = $loan->last_payment_validated;
        $loan->personal_references = $loan->personal_references;
        $loan->cosigners = $loan->cosigners;
        $loan->data_loan = $loan->data_loan;
        $loan->user = $loan->user;
        $loan->city = $loan->city;
        $loan->observations = $loan->observations->last();
        $loan->procedure_modality = $loan->modality;
        $loan->modality=$loan->modality->procedure_type;
        $loan->tags = $loan->tags;
        $loan->affiliate = $loan->affiliate;
        $loan->default_alert_state = $loan->default_alert();
        if($loan->borrower->first()->type == 'spouses')
            $loan->affiliate->spouse = $loan->affiliate->spouse;
        if($loan->parent_loan){
            $loan->parent_loan->balance = $loan->parent_loan->balance;
            $loan->parent_loan->estimated_quota = $loan->parent_loan->estimated_quota;
            $loan->parent_loan->balance_for_reprogramming = $loan->parent_loan->balance_for_reprogramming();
        }
        $loan->intereses=$loan->interest;
        if($loan->parent_reason=='REFINANCIAMIENTO'){
            $loan->balance_parent_loan_refinancing = $loan->balance_parent_refi();
            $loan->date_cut_refinancing=$loan->date_cut_refinancing();
        }else{
            $loan->balance_parent_loan_refinancing = null;
            $loan->date_cut_refinancing=null;
        }
        $loan->payment_type;
        $loan->state;
        $loan->borrower = $loan->borrower;
        $loan->borrowerguarantors = $loan->borrowerguarantors;
        $loan->paid_by_guarantors = $loan->paid_by_guarantors();
        $loan->refinancing = false;
        $loan->reprogramming = false;
        $loan_parameters = LoanModalityParameter::where('procedure_modality_id', $loan->procedure_modality_id)->where('loan_procedure_id', $loan->loan_procedure_id)->first();
        if($loan_parameters->modality_refinancing_id <> null) $loan->refinancing = true;
        if($loan_parameters->modality_reprogramming_id <> null) $loan->reprogramming = true;
        return $loan;
    }

    public static function append_data_index(Loan $loan){
        $loan->borrower = $loan->borrower;
        $loan->modality = $loan->modality->procedure_type;
        $loan->estimated_quota = $loan->estimated_quota;
        return $loan;
    }
    /**
    * Lista de Préstamos
    * Devuelve el listado con los datos paginados
    * @queryParam role_id Ver préstamos del rol, si es 0 se muestra la lista completa. Example: 73
    * @queryParam affiliate_id Ver préstamos del afiliado. Example: 529
    * @queryParam trashed Booleano para obtener solo eliminados. Example: 1
    * @queryParam validated Booleano para filtrar trámites válidados. Example: 1
    * @queryParam procedure_type_id ID para filtrar trámites por tipo de trámite. Example: 9
    * @queryParam search Parámetro de búsqueda. Example: 2000
    * @queryParam sortBy Vector de ordenamiento. Example: []
    * @queryParam sortDesc Vector de orden descendente(true) o ascendente(false). Example: [true]
    * @queryParam per_page Número de datos por página. Example: 8
    * @queryParam page Número de página. Example: 1
    * @authenticated
    * @responseFile responses/loan/index.200.json
    */
    public function index(Request $request)
    {
        $filters = [];
        $relations = [];
        if (!$request->has('role_id')) {
            if (Auth::user()->can('show-all-loan')) {
                $request->role_id = 0;
            } else {
                $role = Auth::user()->roles()->whereHas('module', function($query) {
                    return $query->whereName('prestamos');
                })->orderBy('name')->first();
                if ($role) {
                    $request->role_id = $role->id;
                } else {
                    abort(403);
                }
            }
        } else {
            $request->role_id = (integer)$request->role_id;
            if (($request->role_id == 0 && !Auth::user()->can('show-all-loan')) || ($request->role_id != 0 && !Auth::user()->roles->pluck('id')->contains($request->role_id))) {
                abort(403);
            }
        }
        if ($request->role_id != 0) {
            if(!Auth::user()->can('show-all-loan')){
                if($request->has('trashed') && !Auth::user()->can('show-deleted-loan')) abort(403);
            }
            $wf_states_id = Role::find($request->role_id)->wf_states_id;
            $filters = [
                'wf_states_id' => $wf_states_id
            ];
        }
        if ($request->has('validated')) $filters['validated'] = $request->boolean('validated');
        if ($request->has('workflow_id')) {
            $relations['modality'] = [
                'workflow_id' => $request->workflow_id
            ];
        }
        if ($request->has('affiliate_id')) {
            $relations['lenders'] = [
                'affiliate_id' => $request->affiliate_id
            ];
        }
        if ($request->has('user_id')) {
            $filters['user_id'] = $request->user_id;
        }
        else{
            if($request->validated){
                $filters['validated'] = $request->validated;
                $filters['user_id'] = null;
            }
        }
        $data = Util::search_sort(new Loan(), $request, $filters, $relations);
        $data->getCollection()->transform(function ($loan) {
            return self::append_data_index($loan, true);
        });
        return $data;
    }

    /** Mis Prestamos
    * Devuelve los prestamos que fueron derivados al usuario
    * @queryParam user_id required id del usuario. Example:70
    * @queryParam per_page ver cantidad de prestamos. Example:1
    * @queryParam page Numero de pagina. Example:1
    * @authenticated
    * @responseFile responses/loan/my_loans.200.json
    */
    public function my_loans(Request $request){
        if(!$request->per_page){
            $request->per_page = 0;
        }
        $loans = Loan::whereUser_idAndValidated($request->user_id, false)->paginate($request->per_page);
        $loans->getCollection()->transform(function ($loan) {
            return self::append_data($loan, true);
        });
        return $loans;
    }

    /**
    * Nuevo préstamo
    * Inserta nuevo préstamo
    * @bodyParam procedure_modality_id integer required ID de modalidad. Example: 46
    * @bodyParam amount_requested integer required monto solicitado. Example: 26000
    * @bodyParam city_id integer required ID de la ciudad. Example: 4
    * @bodyParam loan_term integer required plazo. Example: 40
    * @bodyParam refinancing_balance numeric  Monto saldo de refinanciamiento. Example: 1052.26
    * @bodyParam guarantor_amortizing boolean true si es de amortizacion garante. Example: false
    * @bodyParam payment_type_id integer required Tipo de desembolso. Example: 1
    * @bodyParam financial_entity_id integer ID de entidad financiera. Example: 1
    * @bodyParam number_payment_type integer Número de cuenta o Número de cheque para el de desembolso. Example: 10000541214
    * @bodyParam liquid_qualification_calculated numeric required Total de bono calculado. Example: 2000
    * @bodyParam indebtedness_calculated numeric required Indice de endeudamiento. Example: 52.26
    * @bodyParam parent_loan_id integer ID de Préstamo Padre. Example: 1
    * @bodyParam parent_reason enum (REFINANCIAMIENTO, REPROGRAMACIÓN) Tipo de trámite hijo. Example: REFINANCIAMIENTO
    * @bodyParam destiny_id integer required ID destino de Préstamo. Example: 2
    * @bodyParam documents array required Lista de IDs de Documentos solicitados. Example: [294,283,296,305,306,307,308,309,310,311,312,313,284,44,274]
    * @bodyParam notes array Lista de notas aclaratorias. Example: [Informe de baja policial, Carta de solicitud]
    * @bodyParam personal_references array Lista de IDs de personas de referencia del préstamo. Example: [1]
    * @bodyParam cosigners array Lista de IDs de codeudores no afiliados a la muserpol. Example: [2,3]
    * @bodyParam user_id integer ID del usuario. Example: 1.
    * @bodyParam remake_loan_id integer ID del prestamo que se esta rehaciendo. Example: 1
    * @bodyParam delivery_contract_date string Fecha de entrega del contrato al afiliado. Example: 2021-04-05
    * @bodyParam return_contract_date string Fecha de devolución del contrato del afiliado. Example: 2021-04-07
    * @bodyParam contract_signature_date string Fecha de devolución del contrato del afiliado. Example: 2021-04-07
    * @bodyParam lenders array required Lista de afiliados Titular(es) del préstamo.
    * @bodyParam lenders[0].affiliate_id integer required ID del afiliado. Example: 47461
    * @bodyParam lenders[0].payment_percentage numeric required porcentage de pago del afiliado. Example: 50.6
    * @bodyParam lenders[0].payable_liquid_calculated numeric required ID del afiliado. Example: 2000
    * @bodyParam lenders[0].bonus_calculated integer required ID del afiliado. Example: 300
    * @bodyParam lenders[0].quota_previous numeric required ID del afiliado. Example: 514.6
    * @bodyParam lenders[0].quota_treat numeric required cuota del afiliado. Example: 514.6
    * @bodyParam lenders[0].indebtedness_calculated numeric required ID del afiliado. Example: 34
    * @bodyParam lenders[0].liquid_qualification_calculated numeric required ID del afiliado. Example: 2000
    * @bodyParam lenders[0].contributionable_ids array required  Ids de las contribuciones asocidas al prestamo por afiliado. Example: [1,2,3]
    * @bodyParam lenders[0].contributionable_type enum required  Nombre de la tabla de contribuciones . Example: contributions
    * @bodyParam lenders[0].loan_contributions_adjust_ids array required Ids de los ajustes de la(s) contribución(s). Example: [1,2]
    * @bodyParam lenders[1].affiliate_id integer required ID del afiliado. Example: 22773
    * @bodyParam lenders[1].payment_percentage numeric required porcentage de pago del afiliado. Example: 50.6
    * @bodyParam lenders[1].payable_liquid_calculated numeric required ID del afiliado. Example: 2000
    * @bodyParam lenders[1].bonus_calculated integer required ID del afiliado. Example: 300
    * @bodyParam lenders[1].quota_previous numeric required ID del afiliado. Example: 514.6
    * @bodyParam lenders[1].quota_treat numeric required cuota del afiliado. Example: 514.6
    * @bodyParam lenders[1].indebtedness_calculated numeric required ID del afiliado. Example: 34
    * @bodyParam lenders[1].liquid_qualification_calculated numeric required ID del afiliado. Example: 2000
    * @bodyParam lenders[1].contributionable_ids array required Ids de las contribuciones asocidas al prestamo por afiliado. Example: [1,2,3]
    * @bodyParam lenders[1].contributionable_type enum required Nombre de la tabla de contribuciones . Example: contributions
    * @bodyParam lenders[1].loan_contributions_adjust_ids array required Ids de los ajustes de la(s) contribución(s). Example: [3]
    * @bodyParam guarantors array Lista de afiliados Garante(es) del préstamo.
    * @bodyParam guarantors[0].affiliate_id integer required ID del afiliado. Example: 51925
    * @bodyParam guarantors[0].payment_percentage numeric required porcentage de pago del afiliado. Example: 50.6
    * @bodyParam guarantors[0].payable_liquid_calculated numeric required ID del afiliado. Example: 2000
    * @bodyParam guarantors[0].bonus_calculated integer required ID del afiliado. Example: 300
    * @bodyParam guarantors[0].indebtedness_calculated numeric required ID del afiliado. Example: 34
    * @bodyParam guarantors[0].quota_treat numeric required cuota del afiliado garante. Example: 514.6
    * @bodyParam guarantors[0].liquid_qualification_calculated numeric required ID del afiliado. Example: 2000
    * @bodyParam guarantors[0].contributionable_ids array required Ids de las contribuciones asocidas al prestamo por afiliado. Example: [1,2,3]
    * @bodyParam guarantors[0].contributionable_type enum required  Nombre de la tabla de contribuciones. Example: contributions
    * @bodyParam guarantors[0].loan_contributions_adjust_ids array required  Ids de los ajustes de la(s) contribución(s). Example: []
    * @bodyParam guarantors[0].loan_contribution_guarantee_register_ids array ID del registro de la cuota de sus garantias garante. Example:[4,5,6]
    * @bodyParam data_loan array Datos Sismu.
    * @bodyParam data_loan[0].code string required Codigo del prestamo en el Sismu. Example: PRESTAMO123
    * @bodyParam data_loan[0].amount_approved numeric required Monto aprovado del prestamo del Sismu. Example: 5000.50
    * @bodyParam data_loan[0].loan_term integer required Plazo del prestamo del Sismu. Example: 25
    * @bodyParam data_loan[0].balance numeric required saldo del prestamo del Sismu. Example: 10000.50
    * @bodyParam data_loan[0].estimated_quota numeric required cuota del prestamo del Sismu. Example: 1000.50
    * @bodyParam data_loan[0].date_cut_refinancing date Fecha de corte de refinanciamineto Example: 2021-04-07
    * @bodyParam data_loan[0].disbursement_date datetime Fecha y hora de  desembolso del prestamo a refinanciar Example: 2021-04-23 21:28:24
    * @authenticated
    * @responseFile responses/loan/store.200.json
    */
    public function store(LoanForm $request)
    {
        DB::beginTransaction();

    try {
        if($request->parent_reason == 'REPROGRAMACIÓN')
        {
            if(!$request->parent_loan_id) abort(403, 'el prestamo no cuenta con un préstamo padre');
            $loan_parent = Loan::find($request->parent_loan_id);
            if($loan_parent->balance_for_reprogramming() == 0 || $loan_parent->balance_for_reprogramming() != $request->amount_requested)
                abort(403, 'El saldo del préstamo padre es diferente al monto solicitado');
            if($loan_parent->reprogrammed_active_process_loans()->count()>0 && !$request->has('remake_loan_id') && $request->remake_loan_id != $loan_parent->id)
                abort(403, 'El préstamo padre ya tiene un préstamo de reprogramación en proceso');
        }
        $roles = Auth::user()->roles()->whereHas('module', function($query) {
            return $query->whereName('prestamos');
        })->pluck('id');
        $procedure_modality = ProcedureModality::findOrFail($request->procedure_modality_id);
        if (!$request->wf_states_id) abort(403, 'Debe crear un flujo de trabajo');
        if(str_contains($procedure_modality->shortened,'EST-PAS-CON') && !Affiliate::find($request->lenders[0]['affiliate_id'])->spouses->count()>0)
            abort(403, 'El afiliado no tiene esposa registrada para esta modalidad');
        // Guardar préstamo
        if(count(Affiliate::find($request->lenders[0]['affiliate_id'])->process_loans) >= LoanGlobalParameter::first()->max_loans_process && $request->remake_loan_id == null) abort(403, 'El afiliado ya tiene un préstamo en proceso');
        $saved = $this->save_loan($request);
        // Relacionar afiliados y garantes
        $loan = $saved->loan;
        $request = $saved->request;
        // Relacionar documentos requeridos y opcionales
        $date = Carbon::now()->toISOString();
        $documents = [];
        foreach ($request->documents as $document_id) {
            if ($loan->submitted_documents()->whereId($document_id)->doesntExist()) {
                $documents[$document_id] = [
                    'reception_date' => $date
                ];
            }
        }
        $loan->submitted_documents()->syncWithoutDetaching($documents);
        // Relacionar notas
        if ($request->has('notes')) {
            foreach ($request->notes as $message) {
                $loan->notes()->create([
                    'message' => $message,
                    'date' => Carbon::now()
                ]);
            }
        }
        //rehacer préstamo
        if($request->has('remake_loan_id')&& $request->remake_loan_id != null){
            $remake_loan = Loan::find($request->remake_loan_id);
            $this->destroyAll($remake_loan);
            $this->happenRecordLoan($remake_loan,$loan->id);
            Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'rehízo préstamo: '));
        }
        else{//ini aqui
            if($loan->parent_reason == 'REPROGRAMACIÓN' && $loan->parent_loan)
            {
                Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'registró el préstamo de REPROGRAMACIÓN '));
            }else{
                Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'registró el préstamo'));
            }
        }//fin aqui

        //Etiqueta Sismu
        $user = User::whereUsername('admin')->first();
        $sismu_tag = Tag::whereSlug('sismu')->first();
        if(empty($loan->parent_loan_id)){
            if($loan->parent_reason == 'REFINANCIAMIENTO' || $loan->parent_reason == 'REPROGRAMACIÓN'){
                $loan ->tags()->detach($sismu_tag);
                $loan ->tags()->attach([$sismu_tag->id => [
                    'user_id' => $user->id,
                    'date' => Carbon::now()
                ]]);
                Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'etiquetado: Préstamo proveniente del Sismu'));
            }
        }
        // Generar PDFs
        $information_loan= $this->get_information_loan($loan);
        $file_name = implode('_', ['solicitud', 'prestamo', $loan->code]) . '.pdf';
        if(Auth::user()->can('print-contract-loan')){
            $print_docs = [];
            //impresion de la hoja de tramite
            array_push($print_docs, $this->print_process_form(new Request([]), $loan, false));
            //impresión de la ficha de registros de garantias
            if($loan->guarantors->count()>0)
            {
                array_push($print_docs, $this->print_warranty_registration_form(new Request([]), $loan, false));
                array_push($print_docs, '');
            }
            array_push($print_docs, $this->print_form(new Request([]), $loan, false));
            if($loan->modality->loan_modality_parameter->print_contract_platform)
            {
                array_push($print_docs, '');
                $contract = $this->print_contract(new Request([]), $loan, false);
                for($i=0; $i < 3; $i++){
                    array_push($print_docs, $contract);
                }
            }
            if($loan->modality->loan_modality_parameter->print_form_qualification_platform)
                array_push($print_docs, $this->print_qualification(new Request([]), $loan, false));
            $loan->attachment = Util::pdf_to_base64($print_docs, $file_name,$information_loan, 'legal', $request->copies ?? 1);
        }else{
            $loan->attachment = Util::pdf_to_base64([
                $this->print_form(new Request([]), $loan, false),
            ], $file_name,$information_loan, 'legal',$request->copies ?? 1);
        }

        DB::commit();
        return $loan;
    } catch (\Exception $e) {
        DB::rollback();
        throw $e;
    }

    }

    /**
    * Detalle de Préstamo
    * Devuelve el detalle de un préstamo mediante su ID
    * @urlParam loan required ID de préstamo. Example: 4
    * @authenticated
    * @responseFile responses/loan/show.200.json
    */
    public function show(Loan $loan)
    {
        if (Auth::user()->can('show-all-loan') || Auth::user()->can('show-loan') || Auth::user()->can('show-payment-loan') || Auth::user()->roles()->whereHas('module', function($query) {
            return $query->whereName('prestamos');
        })->pluck('id')->contains($loan->role_id)) {
            $loan = self::append_data($loan);
            $loan->borrower = $loan->borrower;
            $loan->affiliate->type_initials = "T-".$loan->affiliate->initials;
            foreach($loan->borrower as $borrower)
            {
                $borrower->type_initials = "T-".$borrower->initials;
            }
            foreach($loan->guarantors as $guarantor){
                $guarantor->type_initials = "G-".$guarantor->initials;
            }
            $loan->retirement=$loan->retirement;
            return $loan;
        } else {
            abort(403);
        }
    }
    
    /**
    * Actualizar préstamo
    * Actualizar datos principales de préstamo
    * @urlParam loan required ID del préstamo. Example: 1
    * @bodyParam date_signal boolean true si no se envia fecha  y false da señal de que se enviara fecha en el campo disbursement_dateExample: true
    * @bodyParam procedure_modality_id integer ID de modalidad. Example: 41
    * @bodyParam amount_requested integer monto solicitado. Example: 2000
    * @bodyParam city_id integer ID de la ciudad. Example: 6
    * @bodyParam loan_term integer plazo. Example: 2
    * @bodyParam refinancing_balance numeric  Monto saldo de refinanciamiento. Example: 1052.26
    * @bodyParam guarantor_amortizing boolean true si es de amortizacion garante. Example: false
    * @bodyParam payment_type_id integer Tipo de desembolso. Example: 1
    * @bodyParam liquid_qualification_calculated numeric Total de bono calculado. Example: 2000
    * @bodyParam indebtedness_calculated numeric Indice de endeudamiento. Example: 52.26
    * @bodyParam disbursement_date date Fecha de desembolso. Example: 2020-02-01
    * @bodyParam num_accounting_voucher string numero de comprobante contable.Example: 107
    * @bodyParam parent_loan_id integer ID de Préstamo Padre. Example: 1
    * @bodyParam parent_reason enum (REFINANCIAMIENTO, REPROGRAMACIÓN) Tipo de trámite hijo. Example: REFINANCIAMIENTO
    * @bodyParam financial_entity_id integer ID de entidad financiera. Example: 1
    * @bodyParam number_payment_type integer Número de cuenta o Número de cheque para el de desembolso. Example: 10000541214
    * @bodyParam destiny_id integer ID destino de Préstamo. Example: 1
    * @bodyParam role_id integer Rol al cual derivar o devolver. Example: 81
    * @bodyParam validated boolean Estado validación del préstamo. Example: true
    * @bodyParam personal_references array Lista de personas de referencia del préstamo. Example: [1]
    * @bodyParam cosigners array Lista de codeudores no afiliados a la muserpol. Example: [2,3]
    * @bodyParam user_id integer ID del usuario. Example: 1.
    * @bodyParam remake_loan_id integer ID del prestamo que se esta rehaciendo. Example: 1
    * @bodyParam delivery_contract_date string Fecha de entrega del contrato al afiliado. Example: 2021-04-05
    * @bodyParam return_contract_date string Fecha de devolución del contrato del afiliado. Example: 2021-04-07
    * @bodyParam lenders array Lista de afiliados Titular(es) del préstamo.
    * @bodyParam lenders[0].affiliate_id integer ID del afiliado.Example: 47461
    * @bodyParam lenders[0].payment_percentage numeric porcentage de pago del afiliado. Example: 50.6
    * @bodyParam lenders[0].payable_liquid_calculated numeric ID del afiliado. Example: 2000
    * @bodyParam lenders[0].bonus_calculated integer ID del afiliado. Example: 300
    * @bodyParam lenders[0].quota_previous numeric ID del afiliado. Example: 514.6
    * @bodyParam lenders[0].quota_treat numeric required cuota del afiliado. Example: 514.6
    * @bodyParam lenders[0].indebtedness_calculated numeric ID del afiliado. Example: 34
    * @bodyParam lenders[0].liquid_qualification_calculated numeric ID del afiliado. Example: 2000
    * @bodyParam lenders[0].contributionable_ids array  Ids de las contribuciones asocidas al prestamo por afiliado. Example: [1,2,3]
    * @bodyParam lenders[0].contributionable_type enum Nombre de la tabla de contribuciones . Example: contributions
    * @bodyParam lenders[0].loan_contributions_adjust_ids array Ids de los ajustes de la(s) contribución(s). Example: [1,2]
    * @bodyParam lenders[0].quota_treat cuota del titular. Example: 2315.86
    * @bodyParam lenders[1].affiliate_id integer ID del afiliado. Example: 22773
    * @bodyParam lenders[1].payment_percentage numeric porcentage de pago del afiliado. Example: 50.6
    * @bodyParam lenders[1].payable_liquid_calculated numeric ID del afiliado. Example: 2000
    * @bodyParam lenders[1].bonus_calculated integer ID del afiliado. Example: 300
    * @bodyParam lenders[1].quota_previous numeric ID del afiliado. Example: 514.6
    * @bodyParam lenders[1].quota_treat numeric required cuota del afiliado. Example: 514.6
    * @bodyParam lenders[1].indebtedness_calculated numeric ID del afiliado. Example: 34
    * @bodyParam lenders[1].liquid_qualification_calculated numeric ID del afiliado. Example: 2000
    * @bodyParam lenders[1].contributionable_ids array  Ids de las contribuciones asocidas al prestamo por afiliado. Example: [1,2,3]
    * @bodyParam lenders[1].contributionable_type enum Nombre de la tabla de contribuciones . Example: contributions
    * @bodyParam lenders[1].loan_contributions_adjust_ids array Ids de los ajustes de la(s) contribución(s). Example: [3]
    * @bodyParam lenders[1].quota_treat cuota del titular. Example: 2315.86
    * @bodyParam guarantors array Lista de afiliados Garante(es) del préstamo.
    * @bodyParam guarantors[0].affiliate_id integer ID del afiliado. Example: 51925
    * @bodyParam guarantors[0].payment_percentage numeric porcentage de pago del afiliado. Example: 50.6
    * @bodyParam guarantors[0].payable_liquid_calculated numeric ID del afiliado. Example: 2000
    * @bodyParam guarantors[0].bonus_calculated integer ID del afiliado. Example: 300
    * @bodyParam guarantors[0].quota_treat numeric  cuota del afiliado garante. Example: 514.6
    * @bodyParam guarantors[0].indebtedness_calculated numeric ID del afiliado. Example: 34
    * @bodyParam guarantors[0].liquid_qualification_calculated numeric ID del afiliado. Example: 2000
    * @bodyParam guarantors[0].contributionable_ids array  Ids de las contribuciones asocidas al prestamo por afiliado. Example: [1,2,3]
    * @bodyParam guarantors[0].contributionable_type enum Nombre de la tabla de contribuciones . Example: contributions
    * @bodyParam guarantors[0].loan_contributions_adjust_ids array Ids de los ajustes de la(s) contribución(s). Example: []
    * @bodyParam guarantors[0].loan_contribution_guarantee_register_ids array ID del registro de la cuota de sus garantias garante. Example:[4,5,6]
    * @bodyParam guarantors[0].quota_treat cuota del garante. Example: 2315.86
    * @authenticated
    * @responseFile responses/loan/update.200.json
    */
    public function update(LoanForm $request, Loan $loan)
    {
        DB::beginTransaction();
        try {
        if (!$this->can_user_loan_action($loan, $request->current_role_id)) abort(409, "El tramite no esta disponible para su rol");
        $request['validate'] = false;
         if($request->date_signal == true || ($request->date_signal == false && $request->has('disbursement_date') && $request->disbursement_date != NULL)){
            $state_id = LoanState::whereName('Vigente')->first()->id;
            $request['state_id'] = $state_id;
        //si es refinanciamiento o reprogramacion colocar la etiqueta correspondiente al padre del préstamo
            if($loan->parent_loan_id != null){
                $user = User::whereUsername('admin')->first();
                $refinancing_tag = Tag::whereSlug('refinanciamiento')->first();
                $reprogramming_tag = Tag::whereSlug('reprogramacion')->first();
                $parent_loan  = Loan::find($loan->parent_loan_id);
                if($loan->parent_reason == 'REFINANCIAMIENTO'){
                        $parent_loan ->tags()->detach($refinancing_tag);
                        $parent_loan ->tags()->attach([$refinancing_tag->id => [
                            'user_id' => $user->id,
                            'date' => Carbon::now()
                        ]]);
                    Util::save_record($parent_loan, 'datos-de-un-tramite', Util::concat_action($parent_loan,'etiquetado: Préstamo refinanciado'));
                }
                if($loan->parent_reason == 'REPROGRAMACIÓN'){
                        $parent_loan ->tags()->detach($reprogramming_tag);
                        $parent_loan ->tags()->attach([$reprogramming_tag->id => [
                            'user_id' => $user->id,
                            'date' => Carbon::now()
                        ]]);
                    Util::save_record($parent_loan, 'datos-de-un-tramite', Util::concat_action($parent_loan,'etiquetado: Préstamo reprogramado'));
                }
            }
        }
        $authorized_disbursement = false;
        $moviment_concept_disbursement_id = MovementConcept::whereIsValid(true)->whereType("EGRESO")->whereShortened("DES-ANT-EG")->first()->id;
        if(Auth::user()->can('disbursement-loan')) {
            if($request->date_signal == true){
                if($loan->modality->procedure_type->name == "Préstamo Anticipo"){
                    $fund_rotatory = MovementFundRotatory::orderBy('id')->get()->last();
                    if(isset($fund_rotatory)){
                        $fund_rotatory_output = MovementFundRotatory::whereLoanId($loan->id)->first();
                        if(!isset($fund_rotatory_output)){
                            if($fund_rotatory->balance >= $loan->amount_approved){
                                MovementFundRotatory::register_advance_fund($loan->id,$request->current_role_id,$moviment_concept_disbursement_id);
                                $authorized_disbursement = true;   
                            }else{ 
                                return abort(409, "Para poder realizar el desembolso el saldo existente en el fondo rotatorio debe ser mayor o igual a ".$loan->amount_approved);
                            }
                        }else{
                            abort(409, "No se puede realizar el desembolso por que el prestamo ya tiene registros relacionados con el fondo rotatorio");
                        }     
                    }else {
                        return abort(409, "Debe realizar registro del fondo rotatorio para poder realizar el desembolso");
                    } 
                }else{
                    $authorized_disbursement = true;
                } 
                if($authorized_disbursement){
                    //Validaciones para la reprogramación
                    if($loan->parent_reason == 'REPROGRAMACIÓN')
                    {
                        if(!$loan->parent_loan_id) abort(403, 'el prestamo no cuenta con un préstamo padre');
                        $loan_parent = Loan::find($loan->parent_loan_id);
                        if($loan_parent->reprogrammed_active_process_loans()->count()>0 && $loan_parent->reprogrammed_active_process_loans()->first()->id != $loan->id)
                            abort(403, 'El préstamo padre ya tiene un préstamo de reprogramación en proceso');
                        if($loan->parent_loan->last_payment_validated->state->name != 'Pagado')
                            abort(403, 'El préstamo padre aun tiene pagos pendientes por validar');
                        if($loan->parent_loan->state->name == 'Vigente')
                            abort(403, 'El préstamo padre aun se encuentra vigente');
                        if(Carbon::parse($loan_parent->last_payment_validated->estimated_date)->startOfDay() < Carbon::now()->startOfDay())
                            abort(403, 'El préstamo padre tiene el último pago validado con fecha pasada, no se puede generar el plan de pagos reprogramado');
                        if($loan->amount_approved != $loan_parent->balance_for_reprogramming())
                            abort(403, 'El saldo del préstamo padre es diferente al monto aprobado del préstamo de reprogramación');
                    }

                    $loan['disbursement_date'] = Carbon::now();
                    $state_id = LoanState::whereName('Vigente')->first()->id;
                    $loan['state_id'] = $state_id;
                    $loan->save();
                    $this->get_plan_payments($loan, $loan['disbursement_date']);
                    $loan_id = $loan->id;
                    $cell_phone_number = $loan->affiliate->cell_phone_number;
					if(!is_null($cell_phone_number) && $cell_phone_number !== '') {
                        $cell_phone_number = explode(",",Util::remove_special_char($cell_phone_number))[0];//primer numero
                        if($loan->city_id === 4) {
                            $message = "MUSERPOL%0aLE INFORMA QUE SU PRESTAMO FUE ABONADO A SU CUENTA, FAVOR RECOGER SU CONTRATO Y PLAN DE PAGOS PASADO LOS 5 DIAS HABILES POR EL AREA LEGAL.";
                        } else {
                            $message = "MUSERPOL%0aLE INFORMA QUE SU PRESTAMO FUE ABONADO A SU CUENTA, FAVOR RECOGER SU CONTRATO Y PLAN DE PAGOS PASADO LOS 10 DIAS HABILES POR LA REGIONAL.";
                        }
                        $notification_type = 4; // Tipo de notificación: 4 (Desembolso de préstamo)
                        ProcessNotificationSMS::dispatch($cell_phone_number, $message, $loan_id, Auth::user()->id, $notification_type);
                    }
                }
            }else{
                if($request->date_signal == false){
                    if($request->has('disbursement_date') && $request->disbursement_date != NULL){
                        if(Auth::user()->can('change-disbursement-date')) {
                            if($loan->modality->procedure_type->name == "Préstamo Anticipo"){
                                $fund_rotatory = MovementFundRotatory::orderBy('id')->get()->last();
                                if(isset($fund_rotatory)){
                                    $fund_rotatory_output = MovementFundRotatory::whereLoanId($loan->id)->first();
                                    if(!isset($fund_rotatory_output)){
                                        if($fund_rotatory->balance >= $loan->amount_approved){  
                                            MovementFundRotatory::register_advance_fund($loan->id,$loan->role_id,$moviment_concept_disbursement_id);
                                            $authorized_disbursement = true;   
                                        }else{ 
                                            abort(409, "Para poder realizar el desembolso el saldo existente en el fondo rotatorio debe ser mayor o igual a ".$loan->amount_approved);
                                        } 
                                    }else{
                                        abort(409, "No se puede realizar la edición por que ya se encuentra un registro en el fondo rotatorio");
                                    }
                                }else {
                                    abort(409, "Debe realizar registro del fondo rotatorio para poder realizar el desembolso");
                                }                       
                            }else{
                                $authorized_disbursement = true;
                            } 
                            if($authorized_disbursement){
                                $loan['disbursement_date'] = $request->disbursement_date;
                                $state_id = LoanState::whereName('Vigente')->first()->id;
                                $loan['state_id'] = $state_id;
                                $loan->save();
                                $this->get_plan_payments($loan, $loan['disbursement_date']);
                                $loan_id = $loan->id;
                                $cell_phone_number = $loan->affiliate->cell_phone_number;
                                if(!is_null($cell_phone_number) && $cell_phone_number !== '') {
                                    $cell_phone_number = explode(",",Util::remove_special_char($cell_phone_number))[0];//primer numero
                                    $message = "AL HABERSE EFECTIVIZADO SU DESEMBOLSO, SE NOTIFICA PARA QUE SE APERSONE POR OFICINAS DE MUSERPOL A OBJETO DEL RECOJO DE SU CONTRATO Y PLAN DE PAGOS DE SU PRÉSTAMO.";
                                    $notification_type = 4; // Tipo de notificación: 4 (Desembolso de préstamo)
                                    ProcessNotificationSMS::dispatch($cell_phone_number, $message, $loan_id, Auth::user()->id, $notification_type);
                                }
                            }
                        }else abort(409, "El usuario no tiene los permisos necesarios para realizar el registro");
                    }
                }
            }
        }
   /* else{
        abort(409, "El usuario no tiene los permisos para realizar el desembolso");
    }*/
    $saved = $this->save_loan($request, $loan);
    DB::commit();
    return $saved->loan;
    } catch (\Exception $e) {
        DB::rollback();
        throw $e;
    } 
    }

    /**
    * Anular préstamo
    * @urlParam loan required ID del préstamo. Example: 1
    * @authenticated
    * @responseFile responses/loan/destroy.200.json
    */
    public function destroy(Loan $loan, request $request)
    {
        if (!$this->can_user_loan_action($loan, $request->current_role_id)) abort(409, "El tramite no esta disponible para su rol");
        $state = LoanState::whereName('Anulado')->first();
        $loan->state()->associate($state);
        $loan->save();
        $loan->delete();
        if($loan->data_loan)
        $loan->data_loan->delete();
        return $loan;
    }

    /**
    * Anular préstamo anticipo
    * @urlParam loan required ID del préstamo. Example: 1
    * @authenticated
    * @responseFile responses/loan/destroy.200.json
    */
    public function destroy_advance(Loan $loan, request $request)
    {
        try {
            DB::beginTransaction();
            if (!$this->can_user_loan_action($loan, $request->data['current_role_id'])  && $loan->modality->procedure_type->second_name != 'Anticipo') 
                abort(409, "El tramite no esta disponible para su rol o no es un prestamo de tipo anticipo");
            $state = LoanState::whereName('Anulado')->first();
            $loan->state()->associate($state);
            $loan->save();
            $loan->delete();
            if($loan->data_loan)
            $loan->data_loan->delete();
            DB::commit();
            return $loan;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    private function save_loan(Request $request, $loan = null)
    {
        $loan_copy = $loan;
        /** Verificando información de los titulares y garantes */
        if($request->lenders && $request->guarantors){
            $lenders_guarantors = array_merge($request->lenders, $request->guarantors);
            foreach ($lenders_guarantors as $lender_guarantor) {
                $information_affiliate = Affiliate::findOrFail($lender_guarantor['affiliate_id']);
                $is_valid_information = Affiliate::verify_information($information_affiliate);
                if(!$is_valid_information) abort(409, 'Debe actualizar los datos personales de titulares y garantes');
            }
        }
        /** fin validacion */
        if (Auth::user()->can(['update-loan', 'create-loan']) && ($request->has('lenders') || $request->has('guarantors'))) {
            $request->lenders = collect($request->has('lenders') ? $request->lenders : [])->unique();
            $request->guarantors = collect($request->has('guarantors') ? $request->guarantors : [])->unique();
            $a = 0;
            foreach ($request->lenders as $lender) {
                $affiliates[$a] = $lender['affiliate_id'];
                $a++;
            }
            if (!$request->has('affiliate_id')) {
                $disbursable_id = $request->lenders[0]['affiliate_id'];
            } else {
                if (!in_array($request->affiliate_id, $affiliates)) abort(404);
                $disbursable_id = $request->affiliate_id;
            }
            $disbursable = Affiliate::findOrFail($disbursable_id);
        }
        if ($loan) {
            $exceptions = ['code', 'role_id'];
            if ($request->has('validated')) {
                if (!Auth::user()->roles()->pluck('id')->contains($loan->role_id)) {
                    array_push($exceptions, 'validated');
                }
            }
            if (Auth::user()->can('update-loan')) {
                $loan->fill(array_merge($request->except($exceptions)));
            }
            if (in_array('validated', $exceptions)){
                if($loan->parent_reason == 'REPROGRAMACIÓN' && $loan->currentState->name == 'Confirmación legal' && $loan->disbursement_date == null)
                    return abort(403, 'No se puede validar el préstamo de reprogramación sin generar el nuevo plan de pagos');
                $loan->validated = $request->validated;
            }
            if ($request->has('role_id')) {
                if ($request->role_id != $loan->role_id) {
                    $loan->role()->associate(Role::find($request->role_id));
                    $loan->validated = false;
                    event(new LoanFlowEvent([$loan]));
                }
            }
        } else {
            if($request->has('remake_loan_id') && $request->remake_loan_id != null)
            {
                $loan = new Loan(array_merge($request->all(), ['affiliate_id' => $disbursable->id,'amount_approved' => $request->amount_requested]));
            }
            else
            {
                if($request->parent_reason && $request->parent_reason == 'REPROGRAMACIÓN' && $request->parent_loan_id)
                {
                    $parent_loan = Loan::find($request->parent_loan_id);
                    $loan = new Loan(array_merge($request->all(), ['affiliate_id' => $parent_loan->affiliate_id,'amount_approved' => $request->amount_requested]));
                    $code = "R-".$parent_loan->code;
                    $loan->refinancing_balance = $parent_loan->balance_for_reprogramming();
                }
                else
                {
                    $correlative = Util::Correlative('loan');
                    $code = implode(['PTMO', str_pad($correlative, 6, '0', STR_PAD_LEFT), '-', Carbon::now()->year]);
                    $loan = new Loan(array_merge($request->all(), ['affiliate_id' => $disbursable->id,'amount_approved' => $request->amount_requested]));
                }
                $loan->code = $code;
            }
            $loan_procedure = LoanProcedure::where('is_enable', true)->first()->id;
            $loan->loan_procedure_id = $loan_procedure;
        }
        //rehacer obtener cod
        if($request->has('remake_loan_id')&& $request->remake_loan_id != null)
        {
            $remake_loan = Loan::find($request->remake_loan_id);
            $loan->code = $remake_loan->code;
            $loan->uuid = $remake_loan->uuid;
            $options=[$remake_loan->id];
            $remake_loan = Loan::withoutEvents(function() use($options){
                $remake_loan = Loan::find($options[0]);
                $remake_loan->code = "PTMO-xxxx";
                $remake_loan->uuid = (string) Str::uuid();
                $remake_loan->save();
                return $remake_loan;
            });
        }if($request->has('indebtedness_calculated')){
            $loan->indebtedness_calculated_previous = $request->indebtedness_calculated;
        }
        if($loan_copy){
            $loan->update();
        }else{
            //ini aqui
            $option = $loan;
            $loan_a = Loan::withoutEvents(function () use ($option) {
            $option->save();
            return $option;
            });
            $loan=$loan_a;
            //fin aqui
        }

        if($request->has('data_loan') && $request->parent_loan_id == null && $request->parent_reason != null && !$request->has('id')){
            $data_loan = $request->data_loan[0];
            $loan->data_loan()->create($data_loan);
        }
        if($request->loan!=null && $request->has('data_loan')){
            $data_loan = $request->data_loan[0];
            $loan->data_loan()->update($data_loan);
        }

        if (Auth::user()->can(['update-loan', 'create-loan']) && ($request->has('lenders') || $request->has('guarantors'))) {
            $affiliates = []; $a = 0; $previous = 0; $indebtedness = 0;
            foreach ($request->lenders as $affiliate) {
                if($request->parent_loan_id)
                {
                    $quota_previous = $affiliate['quota_previous'];
                }else{
                    $quota_previous = $previous;
                }
                if (array_key_exists('indebtedness_calculated', $affiliate)) {
                    $indebtedness = $affiliate['indebtedness_calculated'];
                }else{
                    $indebtedness = 0;
                }
                $affiliate_lender = Affiliate::findOrFail($affiliate['affiliate_id']);
                $loan_borrower = new LoanBorrower([
                    'loan_id' => $loan->id,
                    'degree_id' => $affiliate_lender->degree_id,
                    'unit_id' => $affiliate_lender->unit_id,
                    'category_id' => $affiliate_lender->category_id,
                    'type_affiliate' => $affiliate_lender->type_affiliate,
                    'unit_police_description' => $affiliate_lender->unit_police_description,
                    'affiliate_state_id' => $affiliate_lender->affiliate_state_id,
                    'identity_card' => $affiliate_lender->dead ? $affiliate_lender->spouse->identity_card : $affiliate_lender->identity_card,
                    'city_identity_card_id' => $affiliate_lender->dead ? $affiliate_lender->spouse->city_identity_card_id : $affiliate_lender->city_identity_card_id,
                    'city_birth_id' => $affiliate_lender->dead ? $affiliate_lender->spouse->city_birth_id : $affiliate_lender->city_birth_id,
                    'registration' => $affiliate_lender->dead ? $affiliate_lender->spouse->registration : $affiliate_lender->registration,
                    'last_name' => $affiliate_lender->dead ? $affiliate_lender->spouse->last_name : $affiliate_lender->last_name,
                    'mothers_last_name' => $affiliate_lender->dead ? $affiliate_lender->spouse->mothers_last_name : $affiliate_lender->mothers_last_name,
                    'first_name' => $affiliate_lender->dead ? $affiliate_lender->spouse->first_name : $affiliate_lender->first_name,
                    'second_name' => $affiliate_lender->dead ? $affiliate_lender->spouse->second_name : $affiliate_lender->second_name,
                    'surname_husband' => $affiliate_lender->dead ? $affiliate_lender->spouse->surname_husband : $affiliate_lender->surname_husband,
                    'gender' => $affiliate_lender->dead ? ($affiliate_lender->gender = 'M' ? 'F' : 'M') : $affiliate_lender->gender,
                    'civil_status' => $affiliate_lender->dead ? $affiliate_lender->spouse->civil_status : $affiliate_lender->civil_status,
                    'phone_number' => $affiliate_lender->phone_number,
                    'cell_phone_number' => $affiliate_lender->cell_phone_number,
                    'address_id' => $affiliate_lender->address->id,
                    'pension_entity_id' => $affiliate_lender->pension_entity_id,
                    'payment_percentage' => $affiliate['payment_percentage'],
                    'payable_liquid_calculated' => $affiliate['payable_liquid_calculated'],
                    'bonus_calculated' => $affiliate['bonus_calculated'],
                    'quota_previous' => $quota_previous,
                    'quota_treat' => $affiliate['quota_treat'],
                    'indebtedness_calculated' => $indebtedness,
                    'indebtedness_calculated_previous' => $indebtedness,
                    'liquid_qualification_calculated' => $affiliate['liquid_qualification_calculated'],
                    'contributionable_type' => $affiliate['contributionable_type'],
                    'contributionable_ids' => json_encode($affiliate['contributionable_ids']),
                    'type' => $affiliate_lender->dead ? 'spouses':'affiliates',
                    'availability_info' => $affiliate_lender->availability_info,
                ]);
                $loan_borrower->save();
                if(array_key_exists('loan_contributions_adjust_ids', $affiliate)){
                    $idsajust=$affiliate['loan_contributions_adjust_ids'];
                }else{
                    $idsajust=[];
                }
                foreach ($idsajust as $adjustid){
                    $ajuste=LoanContributionAdjust::find($adjustid);
                    $ajuste->loan_id=$loan->id;
                    $ajuste->update();
                }
                $a++;
            }
            if($request->guarantors){
                foreach ($request->guarantors as $affiliate) {
                    $affiliate_guarantor = Affiliate::findOrFail($affiliate['affiliate_id']);
                    $loan_guarantor = new LoanGuarantor([
                        'loan_id' => $loan->id,
                        'affiliate_id' => $affiliate_guarantor->id,
                        'degree_id' => $affiliate_guarantor->degree_id,
                        'unit_id' => $affiliate_guarantor->unit_id,
                        'category_id' => $affiliate_guarantor->category_id,
                        'type_affiliate' => $affiliate_guarantor->type_affiliate,
                        'unit_police_description' => $affiliate_guarantor->unit_police_description,
                        'affiliate_state_id' => $affiliate_guarantor->affiliate_state_id,
                        'identity_card' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->identity_card : $affiliate_guarantor->identity_card,
                        'city_identity_card_id' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->city_identity_card_id : $affiliate_guarantor->city_identity_card_id,
                        'city_birth_id' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->city_birth_id : $affiliate_guarantor->city_birth_id,
                        'registration' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->registration : $affiliate_guarantor->registration,
                        'last_name' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->last_name : $affiliate_guarantor->last_name,
                        'mothers_last_name' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->mothers_last_name : $affiliate_guarantor->mothers_last_name,
                        'first_name' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->first_name : $affiliate_guarantor->first_name,
                        'second_name' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->second_name : $affiliate_guarantor->second_name,
                        'surname_husband' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->surname_husband : $affiliate_guarantor->surname_husband,
                        'gender' => $affiliate_guarantor->dead ? ($affiliate_guarantor->gender = 'M' ? 'F' : 'M') : $affiliate_guarantor->gender,
                        'civil_status' => $affiliate_guarantor->dead ? $affiliate_guarantor->spouse->civil_status : $affiliate_guarantor->civil_status,
                        'phone_number' => $affiliate_guarantor->phone_number,
                        'cell_phone_number' => $affiliate_guarantor->cell_phone_number,
                        'address_id' => $affiliate_guarantor->address->id,
                        'pension_entity_id' => $affiliate_guarantor->pension_entity_id,
                        'payment_percentage' => $affiliate['payment_percentage'],
                        'payable_liquid_calculated' => $affiliate['payable_liquid_calculated'],
                        'bonus_calculated' => $affiliate['bonus_calculated'],
                        'quota_previous' => $affiliate['quota_treat'],
                        'quota_treat' => $affiliate['quota_treat'],
                        'indebtedness_calculated' => $affiliate['indebtedness_calculated'],
                        'indebtedness_calculated_previous' => $affiliate['indebtedness_calculated'],
                        'liquid_qualification_calculated' => $affiliate['liquid_qualification_calculated'],
                        'contributionable_type' => $affiliate['contributionable_type'],
                        'contributionable_ids' => json_encode($affiliate['contributionable_ids']),
                        'type' => $affiliate_guarantor->dead ? 'spouses':'affiliates',
                        'eval_quota' => $affiliate['eval_quota'],
                    ]);
                    $loan_guarantor->save();
                    if(array_key_exists('loan_contributions_adjust_ids', $affiliate)){
                        $idsajust=$affiliate['loan_contributions_adjust_ids'];
                    }else{
                        $idsajust=[];
                    }
                    foreach ($idsajust as $adjustid){
                        $ajuste=LoanContributionAdjust::find($adjustid);
                        $ajuste->loan_id=$loan->id;
                        $ajuste->update();
                    }
                    if(array_key_exists('loan_guarantee_register_ids', $affiliate)){
                        $loan_guarantee_register_ids = $affiliate['loan_guarantee_register_ids'];
                    }else{
                        $loan_guarantee_register_ids = [];
                    }
                    foreach ($loan_guarantee_register_ids as $loan_guarantee_register_id){
                        $loan_guarantee_register = LoanGuaranteeRegister::find($loan_guarantee_register_id);
                        $loan_guarantee_register->loan_id=$loan->id;
                        $loan_guarantee_register->update();
                    }
                    $a++;
                }
            }
        }
        if (Auth::user()->can(['update-loan', 'create-loan']) && ($request->has('personal_references') || $request->has('cosigners'))) {
            $persons = [];
            if($request->personal_references){
                foreach ($request->personal_references as $personal_reference) {
                    $persons[$personal_reference] = [
                        'cosigner' => false
                    ];
                }
            }
            if($request->cosigners){
                foreach ($request->cosigners as $cosigner) {
                    $persons[$cosigner] = [
                        'cosigner' => true
                    ];
                }
            }
            if (count($persons) > 0) $loan->loan_persons()->sync($persons);
        }
        /** Guardar datos garantía si es préstamo por fondo de retiro*/
        if (str_contains($loan->modality->procedure_type->name, "Préstamo al Sector Activo con Garantía del Beneficio del Fondo de Retiro Policial Solidario")) {
            $retirement_fund = $loan->borrower->first()->retirement_fund_average();
            $data = [
                "loan_id" => $loan->id,
                "retirement_fund_average_id" => $retirement_fund->id,
            ];
            if (!empty($data['retirement_fund_average_id']))
                LoanGuaranteeRetirementFund::create($data);
        } 
        return (object)[
            'request' => $request,
            'loan' => $loan
        ];  
    }

    /**
    * Actualización de documentos
    * Actualiza los datos para cada documento presentado
    * @urlParam loan required ID del préstamo. Example: 8
    * @urlParam document required ID de préstamo. Example: 40
    * @bodyParam is_valid boolean required Validez del documento. Example: true
    * @bodyParam comment string Comentario para añadir a la presentación. Example: Documento actualizado a la gestión actual
    * @authenticated
    * @responseFile responses/loan/update_document.200.json
    */
    public function update_document(Request $request, Loan $loan, ProcedureDocument $document)
    {
        $request->validate([
            'is_valid' => 'required|boolean',
            'comment' => 'string|nullable|min:1'
        ]);
        $loan->submitted_documents()->updateExistingPivot($document->id, $request->all());
        return $loan->submitted_documents;
    }

    public function update_documents(Request $request, Loan $loan)
    {
        $request->validate([
            'documents.*.procedure_document_id' => 'required|exists:procedure_documents,id',
            'documents.*.is_valid' => 'required|boolean',
            'documents.*.comment' => 'nullable|string|min:1'
        ]);
        foreach($request->documents as $document)
        {
            $loan->submitted_documents()->updateExistingPivot($document['procedure_document_id'], ['is_valid' => $document['is_valid'], 'comment' => $document['comment']]);
        }
        $updated_documents = $loan->submitted_documents()->get();
        return $updated_documents;
    }

    /**
    * Lista de documentos entregados
    * Obtiene la lista de los documentos presentados para el trámite
    * @urlParam loan required ID del préstamo. Example: 8
    * @authenticated
    * @responseFile responses/loan/get_documents.200.json
    */
    public function get_documents(Loan $loan)
    {
        return $loan->submitted_documents_list;
    }

    /**
    * Actualización de sismu
    * Actualiza los datos del sismu
    * @urlParam loan required ID del préstamo. Example: 3
    * @bodyParam data_loan array Datos Sismu.
    * @bodyParam data_loan[0].code string  Codigo del prestamo en el Sismu. Example: PRESTAMO123
    * @bodyParam data_loan[0].amount_approved numeric Monto aprovado del prestamo del Sismu. Example: 5000.50
    * @bodyParam data_loan[0].loan_term integer Plazo del prestamo del Sismu. Example: 25
    * @bodyParam data_loan[0].balance numeric saldo del prestamo del Sismu. Example: 10000.50
    * @bodyParam data_loan[0].estimated_quota numeric cuota del prestamo del Sismu. Example: 1000.50
    * @bodyParam data_loan[0].date_cut_refinancing date Fecha de corte de refinanciamineto Example: 2021-04-07
    * @bodyParam data_loan[0].disbursement_date datetime Fecha y hora de  desembolso del prestamo a refinanciar sismu Example: 2021-04-23 21:28:24
    * @authenticated
    * @responseFile responses/loan/update_sismu.200.json
    */
    public function update_sismu(Request $request, Loan $loan)
    {
        if($request->has('data_loan')){
            $data_loan = $request->data_loan[0];
            $data_loan = [
                'balance' => $data_loan['balance'],
                'date_cut_refinancing' => $data_loan['date_cut_refinancing'],
            ];
            $loan->data_loan()->update($data_loan);
        }
        return $loan->data_loan;
    }

    /**
    * Desembolso Afiliado
    * Devuelve los datos del o la cónyugue en caso de que hubiera fallecido a quien se hace el desembolso del préstamo
    * @urlParam loan required ID del préstamo. Example: 2
    * @authenticated
    * @responseFile responses/loan/get_disbursable.200.json
    */
    public function get_disbursable(Loan $loan)
    {
        return $loan->disbursable;
    }

    public static function verify_spouse_disbursable(Affiliate $affiliate)
    {
        $object = (object)[
            'disbursable_type' => 'affiliates',
            'disbursable_id' => $affiliate->id,
            'disbursable' => $affiliate
        ];
        if ($object->disbursable->dead) {
            $spouse = $object->disbursable->spouse;
            if ($spouse) {
                $object = (object)[
                    'disbursable_type' => 'spouses',
                    'disbursable_id' => $spouse->id,
                    'disbursable' => $spouse
                ];
            } else {
                abort(409, 'Debe actualizar la información de cónyugue para afiliados fallecidos');
            }
        }
        $needed_keys = ['city_birth', 'city_identity_card', 'city_identity_card', 'address'];
        foreach ($needed_keys as $key) {
            if (!$object->disbursable[$key]) abort(409, 'Debe actualizar los datos personales del titular y garantes');
        }
        return $object;
    }

    public function switch_states()
    {
        $user = User::whereUsername('admin')->first();
        $amortizing_tag = Tag::whereSlug('amortizando')->first();
        $defaulted_tag = Tag::whereSlug('mora')->first();
        $defaulted_loans = 0;
        $amortizing_loans = 0;

        // Switch amortizing loans to defaulted
        $loans = Loan::whereHas('state', function($query) {
            $query->whereName('Vigente');
        })->whereHas('tags', function($q) {
            $q->whereSlug('amortizando');
        })->get();
        foreach ($loans as $loan) {
            if ($loan->defaulted) {
                $loan->tags()->detach($amortizing_tag);
                $loan->tags()->attach([$defaulted_tag->id => [
                    'user_id' => $user->id,
                    'date' => Carbon::now()
                ]]);
                $defaulted_loans++;
                foreach ($loan->lenders as $lender) {
                    $lender->records()->create([
                        'user_id' => $user->id,
                        'record_type_id' => RecordType::whereName('etiquetas')->first()->id,
                        'action' => 'etiquetó en mora'
                    ]);
                }
            }
        }

        // Switch defaulted loans to amortizing
        $loans = Loan::whereHas('state', function($query) {
            $query->whereName('Vigente');
        })->whereHas('tags', function($q) {
            $q->whereSlug('mora');
        })->get();
        foreach ($loans as $loan) {
            if (!$loan->defaulted) {
                $loan->tags()->detach($defaulted_tag);
                $loan->tags()->attach([$amortizing_tag->id => [
                    'user_id' => $user->id,
                    'date' => Carbon::now()
                ]]);
                $amortizing_loans++;
            }
        }

        return response()->json([
            'defaulted' => $defaulted_loans,
            'amortizing' => $amortizing_loans
        ]);
    }

    /**
    * Impresión de Contrato
    * Devuelve un pdf del contrato acorde a un ID de préstamo
    * @urlParam loan required ID del préstamo. Example: 6
    * @queryParam copies Número de copias del documento. Example: 2
    * @authenticated
    * @responseFile responses/loan/print_contract.200.json
    */
    public function print_contract(Request $request, Loan $loan, $standalone = true)
    {
        $procedure_modality = $loan->modality;
        $parent_loan = "";
        $file_title = implode('_', ['CONTRATO', $procedure_modality->shortened, $loan->code,Carbon::now()->format('m/d')]);
        if($loan->parent_loan_id) $parent_loan = Loan::findOrFail($loan->parent_loan_id);
        $lenders = [];
        $guarantors = $loan->borrowerguarantors;
        $spouses = $loan->affiliate->spouses;
        $lenders = $loan->borrower;
        $employees = [
            ['position' => 'Director General Ejecutivo','name'=>'CNL. MSc. CAD. LUCIO ENRIQUE RENÉ JIMÉNEZ VARGAS','identity_card'=>'3475563'],
            ['position' => 'Director de Asuntos Administrativos','name'=>'LIC. FRANZ LAZO CHAVEZ','identity_card'=>'3367169 LP']
        ];
        $data = [
            'header' => [
                'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
                'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
                'table' => []
            ],
            'employees' => $employees,
            'title' => $procedure_modality->name,
            'loan' => $loan,
            'lenders' => collect($lenders),
            'guarantors' => collect($guarantors),
            'spouses' => collect($spouses),
            'parent_loan' => $parent_loan,
            'file_title' => $file_title,
        ];
        if($loan->parent_reason == 'REPROGRAMACIÓN' && !$loan->parent_loan->contract_signature_date)
                abort(409, 'El préstamo original no tiene fecha de firma de contrato, no se puede generar el contrato de reprogramación.');
        if ($loan->parent_reason === 'REPROGRAMACIÓN' && optional($loan->parent_loan)->guarantors) {
            $data['parent_loan_guarantors'] = $loan->parent_loan->guarantors;
        }
        $file_name = implode('_', ['contrato', $procedure_modality->shortened, $loan->code]) . '.pdf';

        if($loan->parent_reason == 'REPROGRAMACIÓN')
            $modality_type = 'Reprogramación';
        else
            $modality_type = $procedure_modality->procedure_type->name;
        
        switch($modality_type){
            case 'Préstamo Anticipo':
                $view_type = 'advance';
                break;
            case 'Préstamo a Corto Plazo':
                $view_type = 'short';
                break;
            case 'Refinanciamiento Préstamo a Corto Plazo':
                $view_type = 'short';
                break;
            case 'Préstamo a Largo Plazo':
                $view_type = 'long';
                break;
            case 'Refinanciamiento Préstamo a Largo Plazo':
                $view_type = 'long';
                break;
            case 'Préstamo al Sector Activo con Garantía del Beneficio del Fondo de Retiro Policial Solidario':
                $view_type = 'retirement_fund';
                break;
            case 'Préstamo Estacional para el Sector Pasivo de la Policía Boliviana':
                $view_type = 'seasonal';
                break;
            case 'Reprogramación':
                $view_type = 'reprogramming';
                break;
        }
        $information_loan= $this->get_information_loan($loan);
        $view = view()->make('loan.contracts.' . $view_type)->with($data)->render();
        if ($standalone) return Util::pdf_to_base64contract([$view], $file_name,$information_loan,$loan,'legal', $request->copies ?? 1);
        return $view;
    }

    public function get_information_loan(Loan $loan)
    {           
        $module_id= $loan->modality->procedure_type->module_id;
        $file_name =$module_id.'/'.$loan->uuid;
        return $file_name;
    }

    /**
    * Impresión de Formulario de solicitud
    * Devuelve el pdf del Formulario de solicitud acorde a un ID de préstamo
    * @urlParam loan required ID del préstamo. Example: 1
    * @queryParam copies Número de copias del documento. Example: 2
    * @authenticated
    * @responseFile responses/loan/print_form.200.json
    */

    // funcion para agregar uuid a todos los registros    
    public static function add_uuid(){
        $loans=Loan::withTrashed()->get();
        //no toma en cuenta los deleted at
       foreach ($loans as $loan) {
            $loan->uuid=(string) Str::uuid();
            $loan->save();
       }
    }
    public function print_form(Request $request, Loan $loan, $standalone = true)
    {
        $lenders = [];
        $is_dead = false;
        $is_spouse = false;
        $file_title = implode('_', ['FORM','SOLICITUD','PRESTAMO', $loan->code,Carbon::now()->format('m/d')]);
        //foreach ($loan->lenders as $lender) {
        $loan->borrower->first();
            array_push($lenders, $loan->borrower->first());
            if($loan->borrower->first()->type == 'spouses') $is_dead = true;
        //}
        $persons = collect([]);
        $loans = collect([]);
        foreach ($lenders as $lender) 
        {
            $persons->push([
                'id' => $lender->id,
                'full_name' => implode(' ', [$lender->title && $lender->type=="affiliates" ? $lender->title : '', $lender->full_name]),
                'identity_card' => $lender->identity_card,
                'position' => 'SOLICITANTE',
            ]);
        }
        // préstamos estacionales con cónyuge
        if($loan->modality->shortened == "EST-PAS-CON"){
            $persons->push([
                'id' => $loan->affiliate->spouse->id,
                'full_name' => implode(' ', [$loan->affiliate->spouse->full_name ?? '']),
                'identity_card' => $loan->affiliate->spouse->identity_card,
                'position' => 'CÓNYUGE ANUENTE',
            ]);
        }
        // garantes          
        $guarantors = [];
        foreach ($loan->borrowerguarantors as $guarantor) {
            $guarantor_loan = $guarantor;
            array_push($guarantors, $guarantor_loan);
            $persons->push([
                'id' => $guarantor_loan->id,
                'full_name' => implode('', [$guarantor_loan->title && $guarantor_loan->type=="affiliates" ? $guarantor_loan->title :'', $guarantor_loan->full_name]),
                'identity_card' => $guarantor_loan->identity_card,
                'position' => 'GARANTE'
            ]);
        }
        $data = [
            'header' => [
                'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
                'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
                'table' => [
                    ['Tipo', $loan->modality->procedure_type->second_name],
                    ['Modalidad', $loan->modality->shortened],
                    ['Usuario', Auth::user()->username]
                ]
            ],
            'title' => 'SOLICITUD DE ' . ($loan->parent_loan  ? $loan->parent_reason : 'PRÉSTAMO'),
            'loan' => $loan,
            'lenders' => collect($lenders),
            'signers' => $persons,
            'guarantors'=> collect($guarantors),
            'is_dead'=> $is_dead,
            'is_spouse'=> $is_spouse,
            'file_title' => $file_title
        ];
        $information_loan= $this->get_information_loan($loan);
        $file_name = implode('_', ['solicitud', 'prestamo', $loan->code]) . '.pdf';
        $view = view()->make('loan.forms.request_form')->with($data)->render();
        if ($standalone) return Util::pdf_to_base64([$view], $file_name,$information_loan, 'legal', $request->copies ?? 1);
        return $view;
        }
        
        /**
       * Impresión de Formulario de solicitud prestamos anticipos
       * Devuelve el pdf del Formulario de solicitud acorde a un ID de préstamo
       * @urlParam loan required ID del préstamo. Example: 1
       * @queryParam copies Número de copias del documento. Example: 2
       * @authenticated
       * @responseFile responses/loan/print_form_advance.200.json
       */
       public function print_advance_form(Request $request, Loan $loan, $standalone = true){
        $lenders = [];
        $persons = collect([]);
        $file_title = implode('_', ['FORM','SOLICITUD','PRESTAMO', $loan->code,Carbon::now()->format('m/d')]);
        $lenders = $loan->borrower;
        $guarantors = $loan->guarantors;
        foreach($lenders as $lender){
            $lender =$lender->disbursable;
            $persons->push([
                'id' => $lender->id,
                'full_name' => implode(' ', [$lender->title ? $lender->title : '', $lender->full_name]),
                'identity_card' => $lender->identity_card,
                'position' => 'SOLICITANTE',
            ]);
        }
        $data = [
            'header' => [
                'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
                'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
                'table' => [
                    ['Tipo', $loan->modality->procedure_type->second_name],
                    ['Modalidad', $loan->modality->shortened],
                    ['Usuario', Auth::user()->username]
                ]
            ],
            'loan' => $loan,
            'title' => 'SOLICITUD Y APROBACIÓN DE PRÉSTAMO',
            'signers' => $persons,
            'lenders' => collect($lenders),
            'file_title' => $file_title
        ];
        $information_loan = $this->get_information_loan($loan);
        $file_name = implode('_', ['solicitud', 'prestamo','anticipo', $loan->code]) . '.pdf';
        $view = view()->make('loan.forms.request_advance_form')->with($data)->render();
        if ($standalone) return Util::pdf_to_base64([$view], $file_name,$information_loan, 'legal', $request->copies ?? 1);
        return $view;
    }

    /**
    * Impresión del plan de pagos
    * Devuelve un pdf del plan de pagos acorde a un ID de préstamo
    * @urlParam loan required ID del préstamo. Example: 6
    * @queryParam copies Número de copias del documento. Example: 2
    * @authenticated
    * @responseFile responses/loan/print_plan.200.json
    */
    public function print_plan(Request $request, Loan $loan, $standalone = true)
    {  
        if($loan->disbursement_date){
            $procedure_modality = $loan->modality;
            $file_title = implode('_', ['PLAN','DE','PAGOS', $procedure_modality->shortened, $loan->code,Carbon::now()->format('m/d')]);
            $is_dead = false;
            if($loan->borrower->first()->type == 'spouses')
                $is_dead = true;
            $data = [
                'header' => [
                    'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
                    'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
                    'table' => [
                        ['Tipo', $loan->modality->procedure_type->second_name],
                        ['Modalidad', $loan->modality->shortened],
                        ['Fecha', Carbon::now()->format('d/m/Y')],
                        ['Hora', Carbon::now()->format('H:i')],
                        ['Usuario', Auth::user()->username],
                    ]
                ],
                'title' => 'PLAN DE PAGOS',
                'loan' => $loan,
                'lender' => $is_dead ? $loan->affiliate->spouse : $loan->affiliate,
                'is_dead'=> $is_dead,
                'file_title'=>$file_title
            ];
            $information_loan= $this->get_information_loan($loan);
            $file_name = implode('_', ['plan', $procedure_modality->shortened, $loan->code]) . '.pdf';
            $view = view()->make('loan.payments.payment_plan')->with($data)->render();
            if ($standalone) return Util::pdf_to_base64([$view], $file_name, $information_loan, 'legal', $request->copies ?? 1);
            return $view;
        }else{
            return "Prestamo no desembolsado";
        }
    }

    /**
    * Impresión formulario de Calificación
    * Devuelve el pdf del Formulario de Calificación acorde a un ID de préstamo
    * Devuelve datos relacionadas con el préstamo
    * @urlParam loan required ID del préstamo Example: 1
    * @queryParam copies Número de copias del documento. Example: 2
    * @authenticated
    * @responseFile responses/loan/print_qualification.200.json
    */
  
    public function print_qualification(Request $request, Loan $loan, $standalone = true){
        $procedure_modality = $loan->modality;
        $parent_reason=$loan->parent_reason;
        $parent_loan_id=$loan->parent_loan_id;
        $estimated=LoanPayment::where('loan_id',$parent_loan_id)->get();
        $estimated=$estimated->last(); 
        $loan_type_title=" "; 
        $file_title =implode('_', ['FORM','CALIFICACION', $procedure_modality->shortened, $loan->code,Carbon::now()->format('m/d')]);    
        if($parent_loan_id == null && !$parent_reason == null){
            $loan_type_title = $loan->parent_reason== "REFINANCIAMIENTO" ? "SISMU"." ".$loan->parent_reason:"SISMU REFINANCIAMIENTO";
        }
        elseif(!$parent_loan_id == null && !$parent_reason == null){
            $loan_type_title = $loan->parent_reason == "REFINANCIAMIENTO" ? "REFINANCIAMIENTO":"REPROGRAMACIÓN";
        }
        $lenders = [];
        $lenders = $loan->borrower;
        $guarantors = $loan->borrowerguarantors;
        $hight_amount = false;
        if($loan->modality->loan_modality_parameter->max_approved_amount != null && $loan->amount_requested >= $loan->modality->loan_modality_parameter->max_approved_amount)
            $hight_amount = true;
        $data = [
           'header' => [
               'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
               'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
               'table' => [
                   ['Tipo', $loan->modality->procedure_type->second_name],
                   ['Modalidad', $loan->modality->shortened],
                   ['Usuario', Auth::user()->username]
               ]
           ],
           'loan' => $loan,
           'lenders' => $loan->borrower,
           'guarantors' => collect($guarantors),
           'Loan_type_title' => $loan_type_title, 
           'estimated' => $estimated,
           'file_title' => $file_title,
           'high_amount' => $hight_amount
       ];
       $information_loan= $this->get_information_loan($loan);
       $file_name =implode('_', ['calificación', $procedure_modality->shortened, $loan->code]) . '.pdf'; 
       $view = view()->make('loan.forms.qualification_form')->with($data)->render();
       $portrait = true;//impresion horizontal
       $print_date = false;//modo retrato e impresion de la fecha en el formulario de calificación
       if ($standalone) return  Util::pdf_to_base64([$view], $file_name, $information_loan, 'legal', $request->copies ?? 1, $portrait, $print_date);  
       return $view; 
   }

    /**
    * Lista de Notas aclaratorias
    * Devuelve la lista de notas relacionadas con el préstamo
    * @urlParam loan required ID del préstamo. Example: 2
    * @authenticated
    * @responseFile responses/loan/get_notes.200.json
    */
    public function get_notes(Loan $loan)
    {
        return $loan->notes;
    }

    /**
    * Flujo de trabajo
    * Devuelve la lista de roles anteriores para devolver o posteriores para derivar el trámite
    * @urlParam loan required ID del préstamo. Example: 2
    * @authenticated
    * @responseFile responses/loan/get_flow.200.json
    */
    public function get_flow(Loan $loan)
    {
        $currentWfState = $loan->currentState;
        $workflow = $loan->modality->workflow;
        $previousStates = [];
        $previousStates = $this->get_prev_states($currentWfState->id, $workflow->id);

        $nextStates = WfSequence::where('workflow_id', $workflow->id)
            ->where('wf_state_current_id', $currentWfState->id)
                ->pluck('wf_state_next_id')
                ->toArray();

        // Obtener los usuarios de los estados anteriores
        $previousUsers = [];
        foreach ($previousStates as $prev) {
            $state = WfState::find($prev);
            $user = Record::where('role_id', $state->role_id)
                ->where('record_type_id', 3)
                ->where('recordable_id', $loan->id)
                ->first();

            $previousUsers[] = $user ? $user->user_id : '';
        }

        // Retornar los datos estructurados
        return response()->json([
            "current" => $loan->wf_states_id,
            "previous" => $previousStates,
            "previous_user" => $previousUsers,
            "next" => $nextStates,
            "next_user" => [] // Se puede implementar si es necesario
        ]);
    }

    public function get_prev_states($current, $workflow_id)
    {
        $seen   = [];
        $prevs  = [];

        while (true) {
            if (isset($seen[$current])) break;
            $seen[$current] = true;
            $prev = WfSequence::where('workflow_id', $workflow_id)
                ->where('wf_state_next_id', $current)
                ->value('wf_state_current_id');
            if (!$prev) break;
            $prev = (int) $prev;
            $prevs[] = $prev;
            $current = $prev;
        }
        return $prevs;
    }

    /** @group Cobranzas
    * Cálculo de siguiente pago
    * Devuelve el número de cuota, días calculados, días de interés que alcanza a pagar con la cuota, días restantes por pagar, montos de interés, capital y saldo a capital.
    * @urlParam loan required ID del préstamo. Example: 41426
    * @bodyParam affiliate_id integer required id del afiliado. Example: 2020-04-15
    * @bodyParam estimated_date date required Fecha para el cálculo del interés. Example: 2020-04-15
    * @bodyParam paid_by enum required Pago realizado por Titular(T) o Garante(G). Example: T
    * @bodyParam procedure_modality integer required id de la modalidad. Example: 54
    * @bodyParam estimated_quota float Monto para el cálculo. Example: 650
    * @bodyParam liquidate boolean liquidacion del prestamo true cuota introducida false
    * @authenticated
    * @responseFile responses/loan/get_next_payment.200.json}
    */
    public function get_next_payment(LoanPaymentForm $request, Loan $loan)
    {
        if (strpos($loan->modality->name, 'Estacional') !== false)
            return $loan->next_payment_season($request->input('affiliate_id'),$request->input('estimated_date', null), $request->input('paid_by'), $request->input('procedure_modality_id'), $request->input('estimated_quota', null), $request->input('liquidate', false));
        else
            return $loan->next_payment2($request->input('affiliate_id'),$request->input('estimated_date', null), $request->input('paid_by'), $request->input('procedure_modality_id'), $request->input('estimated_quota', null), $request->input('liquidate', false));
    }

    /** @group Cobranzas
    * Nuevo Registro de pago
    * Inserta una cuota de acuerdo a un monto y fecha estimados.
    * @urlParam loan required ID del préstamo. Example: 2
	* @bodyParam estimated_date date Fecha para el cálculo del interés. Example: 2020-04-30
	* @bodyParam estimated_quota float Monto para el cálculo de los días de interés pagados. Example: 600
    * @bodyParam description string Texto de descripción. Example: Penalizacion regularizada
    * @bodyParam voucher string Comprobante de pago GAR-ABV o D-10/20 o CONT-123. Example: CONT-123
    * @bodyParam affiliate_id integer required ID del afiliado. Example: 57950
    * @bodyParam paid_by enum required Pago realizado por Titular(T) o Garante(G). Example: T
    * @bodyParam procedure_modality_id integer required ID de la modalidad de amortización. Example: 53
    * @bodyParam user_id integer required ID del usuario. Example: 95
    * @bodyParam categorie_id integer required ID de la categoria del cobro. Example: 95
    * @bodyParam liquidate boolean liquidacion del prestamo true cuota introducida false
    * @authenticated
    * @responseFile responses/loan/set_payment.200.json
    */
    public function set_payment(LoanPaymentForm $request, Loan $loan)
    {
        if($loan->balance!=0){
            if (strpos($loan->modality->name, 'Estacional') !== false)
                $payment = $loan->next_payment_season($request->input('affiliate_id'), $request->input('estimated_date', null), $request->input('paid_by'), $request->input('procedure_modality_id'), $request->input('estimated_quota', null), $request->input('liquidate', false));
            else
                $payment = $loan->next_payment2($request->input('affiliate_id'), $request->input('estimated_date', null), $request->input('paid_by'), $request->input('procedure_modality_id'), $request->input('estimated_quota', null), $request->input('liquidate', false));
            $payment->description = $request->input('description', null);
            if(ProcedureModality::where('id', $request->procedure_modality_id)->first()->name == 'Efectivo')
                $payment->state_id = LoanPaymentState::whereName('Pendiente de Pago')->first()->id;
            elseif(ProcedureModality::where('id', $request->procedure_modality_id)->first()->name == 'Deposito Bancario')
            {
                $payment->state_id = LoanPaymentState::whereName('Pagado')->first()->id;
                $payment->validated = true;
            }
            else
                $payment->state_id = LoanPaymentState::whereName('Pendiente por confirmar')->first()->id;
            $payment->wf_states_id = Role::find($request->role_id)->wf_states_id;
            if($request->has('procedure_modality_id')){
                $modality = ProcedureModality::findOrFail($request->procedure_modality_id)->procedure_type;
                if($modality->name == "Amortización en Efectivo" || $modality->name == "Amortización cor Deposito en Cuenta") $payment->validated = true;
            }
            $payment->procedure_modality_id = $request->input('procedure_modality_id');
            $payment->voucher = $request->input('voucher', null);
            $affiliate_id=$request->input('affiliate_id');
            if($request->input('paid_by') == 'T'){
                $affiliate = LoanBorrower::where('loan_id',$loan->id)->first();
                $payment->affiliate_id = $affiliate->affiliate()->id;
            }
            else
            {
                $affiliate = LoanGuarantor::where('affiliate_id', $affiliate_id)->where('loan_id', $loan->id)->first();
                $payment->affiliate_id = $affiliate->affiliate_id;
            }
            $affiliate_state = $affiliate->affiliate_state->affiliate_state_type->name;
            $payment->state_affiliate = strtoupper($affiliate_state);
            $payment->initial_affiliate = $affiliate->initials;

            $payment->categorie_id = $request->input('categorie_id');
            $payment->loan_payment_date = Carbon::now();

            $payment->paid_by = $request->input('paid_by');
            if($request->has('user_id')){
                $payment->user_id = $request->user_id;
            }else{
                $payment->user_id = auth()->id();
            }

            //obtencion de codigo de pago
            $correlative = 0;
            $correlative = Util::correlative('payment');
            $payment->code = implode(['PAY', str_pad($correlative, 6, '0', STR_PAD_LEFT), '-', Carbon::now()->year]);
            //fin obtencion de codigo;

            $loan_payment = $loan->payments()->create($payment->toArray());
            $loan = Loan::find($loan->id);
            $loan->verify_state_loan();
            //generar PDF
            $information_loan= $this->get_information_loan($loan);
            $file_name = implode('_', ['pagos', $loan->modality->shortened, $loan->code]) . '.pdf';
            $loanpayment = new LoanPaymentController;
            $payment->attachment = Util::pdf_to_base64([
                $loanpayment->print_loan_payment(new Request([]), $loan_payment->id, false)
            ], $file_name,$information_loan, 'legal', $request->copies ?? 1);
            return $payment;
        }else{
            abort(403, 'El préstamo ya fue liquidado');
        }
    }

    /** @group Cobranzas
    * Lista de pagos
    * Devuelve el listado de los pagos ordenados por cuota de manera descendente
    * @urlParam loan required ID del préstamo. Example: 2
    * @queryParam trashed Booleano para obtener solo pagos eliminadas. Example: 1
    * @authenticated
    * @responseFile responses/loan/get_payments.200.json
    */
    public function get_payments(Request $request, Loan $loan)
    {
        $query = $loan->payments();
        if ($request->boolean('trashed')) $query = $query->onlyTrashed();
        return $query->get();
    }

    /** @group Observaciones de Préstamos
    * Lista de observaciones
    * Devuelve el listado de observaciones del trámite
    * @urlParam loan required ID del préstamo. Example: 2
    * @queryParam trashed Booleano para obtener solo observaciones eliminadas. Example: 1
    * @authenticated
    * @responseFile responses/loan/get_observations.200.json
    */
    public function get_observations(Request $request, Loan $loan)
    {
        $query = $loan->observations();
        if ($request->boolean('trashed')) $query = $query->onlyTrashed();
        return $query->get();
    }

    /** @group Observaciones de Préstamos
    * Nueva observación
    * Inserta una nueva observación asociada al trámite
    * @urlParam loan required ID del préstamo. Example: 2
    * @bodyParam observation_type_id integer required ID de tipo de observación. Example: 2
    * @bodyParam message string required Mensaje adjunto a la observación. Example: Subsanable en una semana
    * @authenticated
    * @responseFile responses/loan/set_observation.200.json
    */
    public function set_observation(ObservationForm $request, Loan $loan)
    {
        $observation = $loan->observations()->make([
            'message' => $request->message ?? null,
            'observation_type_id' => $request->observation_type_id,
            'date' => Carbon::now()
        ]);
        $observation->user()->associate(Auth::user());
        $observation->save();
        return $observation;
    }

    /** @group Observaciones de Préstamos
    * Actualizar observación
    * Actualiza los datos de una observación asociada al trámite
    * @urlParam loan required ID del préstamo. Example: 2
    * @bodyParam original.user_id integer required ID de usuario que creó la observación. Example: 123
    * @bodyParam original.observation_type_id integer required ID de tipo de observación original. Example: 2
    * @bodyParam original.message string required Mensaje de la observación original. Example: Subsanable en una semana
    * @bodyParam original.date date required Fecha de la observación original. Example: 2020-04-14 21:16:52
    * @bodyParam original.enabled boolean required Estado de la observación original. Example: false
    * @bodyParam update.enabled boolean Estado de la observación a actualizar. Example: true
    * @authenticated
    * @responseFile responses/loan/update_observation.200.json
    */
    public function update_observation(ObservationForm $request, Loan $loan)
    {
        $observation = $loan->observations();
        foreach (collect($request->original)->only('user_id', 'observation_type_id', 'message', 'date', 'enabled')->put('observable_id', $loan->id)->put('observable_type', 'loans') as $key => $value) {
            $observation = $observation->where($key, $value);
        }
        if ($observation->count() === 1) {
            $obs = $observation->first();
            if (isset($request->update['enabled'])) {
                if ($request->update['enabled']) {
                    $message = 'subsanó observación: ';
                } else {
                    $message = 'observó: ';
                }
            } else {
                $message = 'modificó observación: ';
            }
            Util::save_record($obs, 'observaciones', $message . $obs->message, $obs->observable);
            $observation->update(collect($request->update)->only('observation_type_id', 'message', 'enabled')->toArray());
        }
        return $loan->observations;
    }

    /** @group Observaciones de Préstamos
    * Eliminar observación
    * Elimina una observación del trámite siempre y cuando no haya sido modificada
    * @urlParam loan required ID del préstamo. Example: 2
    * @bodyParam user_id integer required ID de usuario que creó la observación. Example: 123
    * @bodyParam observation_type_id integer required ID de tipo de observación. Example: 2
    * @bodyParam message string required Mensaje de la observación. Example: Subsanable en una semana
    * @bodyParam date required Fecha de la observación. Example: 2020-04-14 21:16:52
    * @bodyParam enabled boolean required Estado de la observación. Example: false
    * @authenticated
    * @responseFile responses/loan/unset_observation.200.json
    */
    public function unset_observation(ObservationForm $request, Loan $loan)
    {
        $request->request->add(['observable_type' => 'loans', 'observable_id' => $loan->id]);
        $observation = $loan->observations();
        foreach ($request->except('created_at','updated_at','deleted_at') as $key => $value) {
            $observation = $observation->where($key, $value);
        }
        $observation = $observation->whereColumn('created_at','updated_at');
        if ($observation->count() == 1) {
            $observation->delete();
            return $loan->observations;
        } else {
            abort(404, 'La observación fue modificada, no se puede eliminar');
        }
    }

    /**
    * Derivar en lote
    * Deriva o devuelve trámites en un lote mediante sus IDs
    * @bodyParam ids array required Lista de IDs de los trámites a derivar. Example: [1,2,3]
    * @bodyParam role_id integer required ID del rol al cual derivar o devolver. Example: 82
    * @authenticated
    * @responseFile responses/loan/bulk_update_role.200.json
    */
    public function bulk_update_state(LoansForm $request)
    {
        $expectedStateId = Role::find($request->current_role_id)->wf_states->id;
        $loans_pre = Loan::whereIn('id', $request->ids)->get();
        $this->validate($request, [
            'ids' => [
                'array',
                function ($attribute, $value, $fail) use ($loans_pre, $expectedStateId) {
                    $states = $loans_pre->pluck('wf_states_id')->unique();
                    if ($states->count() > 1 || $states->first() !== $expectedStateId) {
                        $fail('El trámite no se encuentra en su rol.');
                    }
                },
            ],
        ]);
        if(!$request->user_id) 
            $user_id = null;
        else
            $user_id = $request->user_id;
        $sequence = null;
        $from_role = $request->current_role_id;
        $to_state = WfState::find($request->next_state_id);
        if ($loans_pre->pluck('wf_states_id')->unique()->count() > 1 || 
            $loans_pre->pluck('wf_states_id')->unique()->first() !== $expectedStateId)
            abort(403, 'El trámite no se encuentra en su rol');
        $from_state = WfState::find($expectedStateId);
        if (count(array_unique($loans_pre->pluck('wf_states_id')->toArray()))) 
            $from_state = WfState::find($loans_pre->first()->wf_states_id);
        if ($from_state)
            $flow_message = $this->flow_message($loans_pre->first()->modality->workflow->id, $from_state, $to_state);
        $loans_pre->map(function ($item, $key) use ($from_state, $to_state, $flow_message) {
            if (!$from_state) {
                $item['from_state_id'] = $item['state_id'];
                $from_state = Role::find($item['role_id'])->wf_state;
                $flow_message = $this->flow_message($item->modality->workflow->id, $from_role, $to_role);
            }
            $item['state_id'] = $from_state->id;
            $item['validated'] = false;

            Util::save_record($item, $flow_message['type'], $flow_message['message']);
        });
        $loans = Loan::whereIn('id', $request->ids)->where('wf_states_id', '!=', $to_state->id)->update([
            'wf_states_id' => $request->next_state_id, 
            'validated' => false, 
            'user_id' => $user_id
        ]);
        $loans_pre->transform(function ($loan) {
            return self::append_data($loan, false);
        });
        event(new LoanFlowEvent($loans_pre));
        // PDF template
        $data = [
            'type' => 'loan',
            'header' => [
                'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
                'unity' => 'Área de ' . $from_state->name,
                'table' => [
                    ['Fecha', Carbon::now()->isoFormat('L')],
                    ['Hora', Carbon::now()->format('H:i')],
                    ['Usuario', Auth::user()->username]
                ]
            ],
            'title' => ($flow_message['type'] == 'derivacion' ? 'DERIVACIÓN' : 'DEVOLUCIÓN') . ' DE TRÁMITES',
            'procedures' => $loans_pre,
            'states' => [
                'from' => $from_state,
                'to' => $to_state
            ]
        ];
        $information_derivation='Fecha: '.Str::slug(Carbon::now()->isoFormat('LLL'), ' ').'  enviado a  '.$from_state->name;
        $file_name = implode('_', ['derivacion', 'prestamos', Str::slug(Carbon::now()->isoFormat('LLL'), '_')]) . '.pdf';
        $view = view()->make('flow.bulk_flow_procedures')->with($data)->render();
        return response()->json([
            'attachment' => Util::pdf_to_base64([$view], $file_name,$information_derivation, 'letter', $request->copies ?? 1, false),
            'derived' => $loans_pre
        ]);
    }

    private function flow_message($workflow, $from_state, $to_state)
    {
        $sequences = WfSequence::where('workflow_id', $workflow)
            ->where('wf_state_current_id', $from_state->id)
            ->get();
        $next_states = $sequences->pluck('wf_state_next_id')->toArray();
        if (in_array($to_state->id, $next_states)) {
            $message = 'derivó';
            $type = 'derivacion';
        } else {
            $message = 'devolvió';
            $type = 'devolucion';
        }

        $message .= ' de ' . $from_state->name . ' a ' . $to_state->name;

        return [
            'message' => $message,
            'type' => $type
        ];
    }

    /** @group Cobranzas
    * Impresión del Kardex de Pagos
    * Devuelve un pdf del Kardex de pagos acorde a un ID de préstamo
    * @urlParam loan required ID del préstamo. Example: 1
    * @queryParam folded boolean tipo de kardex, desplegado o no desplegado. Example: true
    * @queryParam copies Número de copias del documento. Example: 2
    * @authenticated
    * @responseFile responses/loan/print_kardex.200.json
    */

    public function print_kardex(Request $request, Loan $loan, $standalone = true)
    {
        if($loan->disbursement_date){
            $procedure_modality = $loan->modality;
        $is_dead = false;
        if($loan->borrower->first()->type == 'spouses')
            $is_dead = true;
          $file_title = implode('_', ['KARDEX', $procedure_modality->shortened, $loan->code,Carbon::now()->format('m/d')]);
            $data = [
                'header' => [
                    'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
                    'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
                    'table' => [
                        ['Tipo', $loan->modality->procedure_type->second_name],
                        ['Modalidad', $loan->modality->shortened],
                        ['Fecha', Carbon::now()->format('d/m/Y')],
                        ['Hora', Carbon::now()->format('H:i')],
                        ['Usuario', Auth::user()->username]
                    ]
                ],
                'title' => 'KARDEX DE PAGOS',
                'loan' => $loan,
                'lender' => $is_dead ? $loan->affiliate->spouse : $loan->affiliate,
                'file_title' => $file_title,
                'is_dead' => $is_dead
            ];
            $information_loan= $this->get_information_loan($loan);
            $file_name = implode('_', ['kardex', $procedure_modality->shortened, $loan->code]) . '.pdf';
            if($request->folded == "true")
                $view = view()->make('loan.payments.payment_kardex')->with($data)->render();
            else
                $view = view()->make('loan.payments.payment_kardex_unfolded')->with($data)->render();
            if ($standalone) return Util::pdf_to_base64([$view], $file_name, $information_loan, 'letter', $request->copies ?? 1, false);
            return $view;
        }else{
            return "prestamo no desembolsado";
        }
    }

    /**
    * Evaluacion de prestamo para refinanciamiento
    * Devuelve un array con los estados de las validaciones
    * @urlParam loan required id de prestamo a evaluar. Example: 28
    * @bodyParam type_procedure boolean required si es true la evaluacion evalua refinanciamiento caso contrario evalua reprogramacion
    * @authenticated
    * @responseFile responses/loan/loan_evaluate.200.json
    */
    public function validate_re_loan(Request $request, Loan $loan){
        $loan_payments = $loan->payments->sortBy('quota_number');
        $capital_paid = 0;
        $message = array();
        if($request->type_procedure == true){
            foreach($loan_payments as $payment){
                $capital_paid = $capital_paid + $payment->capital_payment;
            }
            $percentage_paid = round(($capital_paid/$loan->amount_approved)*100,2);
            if($percentage_paid<25){
                $message['percentage'] = false;
            }
            else {
                $message['percentage'] = true;
            }
        }
        if($loan->loan_term > 4 && $loan->balance >= $loan->get_min_amount_for_refinancing()){
            $message['paids'] = true;
        }
        else{
            $message['paids'] = false;
        }

        if (!$loan->defaulted){
            $message['defaulted'] = true;
        }
        else{
            if($loan->authorize_refinancing)
                $message['defaulted'] = true;
            else
                $message['defaulted'] = false;
        }
        //pagos consecutivo
        if ($loan->verify_payment_consecutive()){
            $message['manual_payments'] = true;
        }
        else{
            $message['manual_payments'] = false;
        }
        return $message;
    }

    /**
    * Evaluacion de Afiliado
    * Devuelve mensaje de error 403
    * @urlParam affiliate_id required id del afiliado a evaluar. Example: 52540
    * @bodyParam affiliate_id integer required la evaluacion del afiliado en caso de que este aprobado devuelve true caso contrario devuelve error 403
    * @authenticated
    * @responseFile responses/loan/affiliate_evaluate.200.json
    */
    public function validate_affiliate($affiliate_id, request $request)
    {
        $message['validate'] = false;
        $affiliate = Affiliate::findOrFail($affiliate_id);
        $module_id = Module::where('name', 'prestamos')->first()->id;
        $observations = $affiliate->observations->pluck('observation_type_id')->toArray();
        $observations_for_denied = ObservationForModule::where('module_id', $module_id)->pluck('observation_type_id')->toArray();
        $loan_global_parameter = LoanProcedure::where('is_enable', true)->first()->loan_global_parameter;
        $loan_disbursement = count($affiliate->disbursement_loans);
        if($request->refinancing)
            $loan_disbursement = $loan_disbursement - 1;
        $loan_process = count($affiliate->process_loans);
        if(collect($observations)->intersect($observations_for_denied)->isEmpty())
        {
            if ($affiliate->affiliate_state){
                if($affiliate->affiliate_state->affiliate_state_type->name != "Baja" && $affiliate->affiliate_state->affiliate_state_type->name != ""){
                        if((!$affiliate->dead) || ($affiliate->dead && (($affiliate->spouse ? ($affiliate->spouse->dead ? false: true) : false) == true))){
                            if($affiliate->civil_status != null){
                                    if($affiliate->birth_date != null && $affiliate->city_birth_id != null){
                                        if($affiliate->affiliate_state->affiliate_state_type->name != 'Pasivo'){
                                            if($affiliate->unit && $affiliate->unit->breakdown->name != 'Item Cero' || $affiliate->affiliate_state->name != "Comisión")
                                            {
                                                if($loan_process < $loan_global_parameter->max_loans_process ){
                                                    if($loan_disbursement < $loan_global_parameter->max_loans_active){
                                                        $message['validate'] = true;
                                                    }else
                                                        $message['validate'] ='El afiliado no puede tener más de ' .$loan_global_parameter->max_loans_active. ' préstamos desembolsados. Actualemnte ya tiene '. $loan_disbursement .' préstamos desembolsados.'; 
                                                }else
                                                    $message['validate'] = 'El afiliado no puede tener más de '.$loan_global_parameter->max_loans_process.' trámite en proceso. Actualmente ya tiene '.$loan_process.' préstamos en proceso.';
                                            }else
                                                $message['validate'] = 'El afiliado no puede acceder a un prestamos por no tener registrado su unidad o encontrarse en comision Item 0';
                                        }elseif($affiliate->pension_entity_id ==  null){
                                                $message['validate'] = 'El afiliado no tiene registrado su ente Gestor.';
                                                }else{
                                                    if($loan_process < $loan_global_parameter->max_loans_process ){
                                                        if($loan_disbursement < $loan_global_parameter->max_loans_active){
                                                            $message['validate'] = true;
                                                            }else
                                                        $message['validate'] ='El afiliado no puede tener más de ' .$loan_global_parameter->max_loans_active. ' préstamos desembolsados. Actualemnte ya tiene '. $loan_disbursement .' préstamos desembolsados.';
                                                    }else
                                                        $message['validate'] = 'El afiliado no puede tener más de '.$loan_global_parameter->max_loans_process.' trámite en proceso. Actualmente ya tiene '.$loan_process.' préstamos en proceso.';
                                                }
                                    }else
                                        $message['validate'] = 'El afiliado no tiene registrado su fecha de nacimiento ó ciudad de nacimiento.';
                            }
                            else
                            $message['validate'] = 'El afiliado no tiene registrado su estado civil.';
                    }
                    else
                        $message['validate'] = 'El afiliado no puede acceder a un préstamo por estar fallecido ó no tener registrado a un(a) conyugue.';
                }
            else
                $message['validate'] = 'El afiliado no puede acceder a un préstamo por estar dado de baja ó no tener registrado su estado.';
            }
            else
                $message['validate'] = 'El afiliado no puede acceder a un préstamo por estar dado de baja ó no tener registrado su estado.';
        }
        else 
            $message['validate'] = 'El afiliado esta observado por lo cual no puede acceder a un prestamo';
        return $message;
    }
    //Destruir todo el préstamo
    public function destroyAll(Loan $loan)
    {
        /*DB::beginTransaction();
        try{*/
            if($loan->payments){
                    if($loan->data_loan) $loan->data_loan->forceDelete();

                    if($loan->loan_contribution_adjusts) $loan->loan_contribution_adjusts()->forceDelete();

                    if($loan->loan_persons) $loan->loan_persons()->detach();
                    
                    if($loan->submitted_documents) $loan->submitted_documents()->detach();
                    
                    if($loan->tags) $loan->tags()->detach();
                    //if($loan->lenders) $loan->lenders()->detach();
                    if($loan->borrower) $loan->destroy_borrower();
                    if($loan->guarantors) $loan->destroy_guarantors();
                    if($loan->observations) $loan->observations()->forceDelete();
                    $options=[$loan->id];
                    $loan = Loan::withoutEvents(function() use($options){
                        $loan = Loan::findOrFail($options[0])->forceDelete();
                        return $loan;
                    }
                );
                //DB::commit();
            }else{
                abort(403, 'No se puede reahacer el préstamo existen registros de cobros');
            } 
            return $loan;
        /*} catch (\Exception $e) {
            DB::rollback();
            return $e;
        }*/
    }

    //actualizar el record de todo el prestamo anterior al actual
    public function happenRecordLoan(Loan $loan,$id_new_loan)
    {
      $records_remake_loan=$loan->records;
        foreach($records_remake_loan as $record_remake_loan ){ 
            $record_remake_loan->recordable_id = $id_new_loan; 
            $record_remake_loan->update();            
        }
        return $id_new_loan;

    }
    
    //limpiar datos sin relacion
    public function clear_data_base(){

    }
    /**
    * Obtener la submodalidad de refinanciamiento o reprogramacion segun submodalidad hermano/referencia
    * Obtiene segun la submodalidad mandada su refinanciamiento o reprogramacion y tambien validad si lo tiene o no // ejemplo corto plazo sector activo
    * @bodyParam procedure_modality_id integer required id del préstamo de refencia de la que se require su refinanciamiento o reprogramación Example: 36
    * @bodyParam type string required tipo de entrada "refinancing" ó "reprogramming" para diferenciar entre refinanciamiento o reprogramación Example: refinancing
    * @authenticated
    */
    public function procedure_ref_rep(Request $request)
    {
        $request->validate([
            'loan_id'=>'required|integer|exists:loans,id',
            'type'=>'required|string|in:REF,REP',
           ]);
        $type = $request->type;
        if($request->type == 'REF')
            $modality = Loan::find($request->loan_id)->modality->loan_modality_parameter->refinancing_modality;
        elseif($request->type == 'REP')
            $modality = Loan::find($request->loan_id)->modality->loan_modality_parameter->reprogramming_modality;
        if(!$modality)
            abort(403, 'No se permite para esta modalidad');
        $modality->loan_modality_parameter = $modality->loan_modality_parameter;
        $modality->procedure_type = $modality->procedure_type;
        $modalities_and_parameters[] = $modality;
        return $modalities_and_parameters;
    }

    public function get_balance_sismu($ci){
        $query = "select Prestamos.PresNumero, Prestamos.PresSaldoAct, Prestamos.PresEstPtmo
        from Prestamos
        join Padron on Padron.IdPadron = Prestamos.IdPadron
        where Padron.PadCedulaIdentidad = '$ci'
        and Prestamos.PresEstPtmo = 'V'";
        $prestamos = DB::connection('sqlsrv')->select($query);
        return $prestamos;
    }

    /** 
   * Editar Monto y Plazo del Prestamo
   * Edita monto y plazo del prestam
   * @urlParam loan required ID del Prestamo. Example: 5
   * @bodyParam amount_approved numeric required Monto aprovado del prestamo. Example: 2000
   * @bodyParam loan_term integer required Plazo del prestamo. Example: 25
   * @authenticated
   * @responseFile responses/loan/edit_amounts_loan_term.200.json
   */

  //revisar para la nueva inclusion de inclusion y exclusion de garantias---------------------------------------------------------------------------
   public function edit_amounts_loan_term(Request $request, Loan $loan){
    return "ok";
    /*$request->validate([
        'amount_approved' => 'required|numeric',
        'loan_term' => 'required|integer'
    ]);
    DB::beginTransaction();
    if (!$this->can_user_loan_action($loan)) abort(409, "El tramite no esta disponible para su rol");
    $validate = $loan->validate_loan_affiliate_edit($request->amount_approved,$request->loan_term);
    $message = false;
    if($validate == 1){
    if($loan->state->name != "Vigente"){
    try {
            $procedure_modality = ProcedureModality::findOrFail($loan->procedure_modality_id);
            $quota_estimated = CalculatorController::quota_calculator($procedure_modality,$request->loan_term,$request->amount_approved);
            $new_indebtedness_calculated = ($quota_estimated/$loan->liquid_qualification_calculated)*100;                  
            $loan->amount_approved = $request->amount_approved;
            $loan->loan_term = $request->loan_term;
            $loan->indebtedness_calculated = Util::round2($new_indebtedness_calculated);
            $loan->save(); 
            $lenders_update = [];
            foreach ($loan->borrower  as $lender) {
                $loan_borrower = LoanBorrower::where('loan_id',$lender->loan_id)->first();
                $quota_estimated_lender = ($quota_estimated/100)*$loan_borrower->payment_percentage;
                $loan_borrower->quota_treat = Util::round2($quota_estimated_lender);
                $loan_borrower->indebtedness_calculated = Util::round2($quota_estimated_lender/(float)$lender->liquid_qualification_calculated * 100);
                $loan_borrower->save();
            }
            $guarantor_update = [];
            if(count($loan->guarantors)>0){  
                foreach ($loan->guarantors  as $guarantor) {
                    $loan_guarantor = LoanGuarantor::where('loan_id',);
                    $affiliate=Affiliate::find($guarantor->pivot->affiliate_id);
                    $active_guarantees = $affiliate->active_guarantees();$sum_quota = 0;
                    foreach($active_guarantees as $res)
                        $sum_quota += ($res->estimated_quota * $res->pivot->payment_percentage)/100; // descuento en caso de tener garantias activas se incluye esta la que se esta editando
                        $active_guarantees_sismu = $affiliate->active_guarantees_sismu();
                    foreach($active_guarantees_sismu as $res)
                        $sum_quota += $res->PresCuotaMensual / $res->quantity_guarantors; // descuento en caso de tener garantias activas del sismu
                        $quota_estimated_guarantor = Util::round2(($quota_estimated/100)*$guarantor->pivot->payment_percentage);          
                        $new_indebtedness_calculated_guarantor = Util::round2($sum_quota/$guarantor->pivot->liquid_qualification_calculated * 100); 
                        $affiliate_id_guarantor = $guarantor->pivot->affiliate_id;
                        /*$guarantor_update = "update loan_affiliates set quota_treat = $quota_estimated_guarantor, indebtedness_calculated = $new_indebtedness_calculated_guarantor 
                                             where affiliate_id = $affiliate_id_guarantor and loan_id = $loan_id";
                        $update_loan_affiliate_guarantor = DB::select($guarantor_update);
                } 
            }
        DB::commit();
        return self::append_data($loan, true); 
    } catch (\Exception $e) {
        DB::rollback();
        //throw $e;
        return ['message' => $e->getMessage()];
        }
    }
    else{
        $message['message']= "El monto y plazo en meses no puede ser editado por que el prestamo ya se encuentra desembolsado";
        return $message;
        }
    }
    return $validate;*/
}
    /** 
   * Actualizar monto de desembolso por refinanciamiento del prestamo
   * Actualizar monto de desembolso por refinanciamiento del prestamo caso del PVT y Sismu con el permiso update-refinancing-balance
   * @urlParam loan required ID del Prestamo. Example: 8
   * @authenticated
   * @responseFile responses/loan/update_refinancing_balance.200.json
   */
    public function update_balance_refinancing(Loan $loan, request $request){
        if (!$this->can_user_loan_action($loan, $request->current_role_id)) abort(409, "El tramite no esta disponible para su rol");
        $balance_parent = 0;
        if($loan->data_loan){
            $balance_parent=$loan->balance_parent_refi();
            $loan->refinancing_balance=$loan->amount_approved - $balance_parent;
            $loan->update();
        }else{
            if($loan->parent_loan){
                $balance_parent = $loan->balance_parent_refi();
                $loan->refinancing_balance=$loan->amount_approved - $balance_parent;
                $loan->update();
            }else
                    abort(409, 'No es un préstamo de tipo refinanciamiento! ');
        }
            $loan_res = collect([
                'balance_parent_loan_refinancing' => $balance_parent
            ])->merge($loan);

            return $loan_res;
    }

   //funcion para el cambio automatico de cobro garante titular
   public function switch_loans_guarantors()
   {
        $loans = Loan::where('state_id', LoanState::where('name', 'Vigente')->first()->id)->get();
        $c = 0;
        foreach($loans as $loan)
        {
            if($loan->guarantors->count() > 0)
            {
                if($loan->last_payment_validated != null)
                {
                    if(Carbon::parse($loan->last_payment_validated->estimated_date)->diffInDays(Carbon::now()->format('Y-m-d')) > 60){
                        $option = $loan;
                        $loan = Loan::withoutEvents(function () use ($option) {
                            $loan = Loan::whereId($option->id)->first();
                            $loan->guarantor_amortizing =  true;
                            $loan->update();
                        });
                        $c++;
                    }
                }
                else{
                    if(Carbon::parse($loan->disbursement_date)->diffInDays(Carbon::now()->format('Y-m-d')) > 60){
                        $option = $loan;
                        $loan = Loan::withoutEvents(function () use ($option){
                            $loan = Loan::whereId($option->id)->first();
                            $loan->guarantor_amortizing =  true;
                            $loan->update();
                        });
                        $c++;
                    }
                }
            }
        }
    return response()->json([
        'defaulted' => $c,
    ]);
   }

   //cronjob para actualizacion de prestamos
   public static function verify_loans()
   {
       $loans = Loan::where('state_id', LoanState::whereName('Vigente')->first()->id)->get();
       $c = 0;
       foreach( $loans as $loan ){
           if($loan->verify_balance() == 0)
           {
                $option = $loan;
                $loan = Loan::withoutEvents(function () use ($option){
                    $loan = Loan::whereId($option->id)->first();
                    $loan->state_id = LoanState::whereName('Liquidado')->first()->id;
                    $loan->update();
                });
                $c++;
           }
       }
       $loans = Loan::where('state_id', LoanState::whereName('Liquidado')->first()->id)->get();
       foreach( $loans as $loan ){
           if($loan->verify_balance() > 0)
           {
                $option = $loan;
                $loan = Loan::withoutEvents(function () use ($option){
                    $loan = Loan::whereId($option->id)->first();
                    $loan->state_id = LoanState::whereName('Vigente')->first()->id;
                    $loan->update();
                });
                $c++;
           }
       }
       return $c;
   }

    /**
    * Cobro de garante a titular
    * devuelve el afiliado al que se cambio
    * @bodyParam loan integer required ID del préstamo. Example: 6
    * @bodyParam rol_id integer required id del rol que cambia el tipo de cobro. Example: 2
    * @authenticated
    * @responseFile responses/loan/switch_guarantor_lender.200.json
    */
   public function switch_guarantor_lender(request $request)
   {
        $request->validate([
        'loan_id'=>'required|integer|exists:loans,id',
        'role_id'=>'required|integer|exists:roles,id',
       ]);
       $message = [];
       if(Loan::whereId($request->loan_id)->first() != null && Role::whereId($request->role_id)->first() != null){
            $option = Loan::whereId($request->loan_id)->first();
            $loan = Loan::withoutEvents(function () use ($option, $request){
                $loan = Loan::whereId($option->id)->first();
                if($loan->guarantor_amortizing == true){
                    $loan->guarantor_amortizing = false;
                    $loan->update();
                    Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'cambio cobro de garante a titular: '.$loan->code));
                    $message = ['message' => 'Cambio de cobro de garante a titular exitoso'];
                }else{
                    $loan->guarantor_amortizing = true;
                    $loan->update();
                    Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'cambio cobro de titular a garantes: '.$loan->code));
                    $message = ['message' => 'Cambio de cobro de titular a garante exitoso'];
                }
            });
        }
        else{
            $message['validate'] = 'prestamo y / o rol inexistente';
        }
        return $message;
   }

    /**
    * Autorizacion de refinanciamiento
    * Devuelve el Prestamo al que se autorizo el refinanciamiento
    * @bodyParam loan integer required ID del préstamo. Example: 6
    * @bodyParam rol_id integer required id del rol que cambia el tipo de cobro. Example: 2
    * @authenticated
    * @responseFile responses/loan/authorize_refinancing.200.json
    */
    public function authorize_refinancing(request $request)
    {
         $request->validate([
         'loan_id'=>'required|integer|exists:loans,id',
         'role_id'=>'required|integer|exists:roles,id',
        ]);
        $message = [];
        if(Loan::find($request->loan_id) != null){
             $option = Loan::find($request->loan_id);
             $loan = Loan::withoutEvents(function () use ($option, $request){
                 $loan = Loan::find($option->id);
                 if(!$loan->authorize_refinancing){
                     $loan->authorize_refinancing = true;
                     $loan->update();
                     Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'autorizo el refinanciamiento al prestamo: '.$loan->code));
                     $message = ['message' => 'Autorizacion de refinanciamiento exitoso'];
                 }else{
                    if(Loan::where('parent_loan_id', $loan->id)->where('parent_reason', 'REFINANCIAMIENTO')->count() == 0){
                        $loan->authorize_refinancing = false;
                        $loan->update();
                        Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'quito la autorización de refinanciamiento al prestamo: '.$loan->code));
                        $message = ['message' => 'Revocacion de autorización exitoso'];
                    }
                    else{
                        $message = ['message' => 'No se puede quitar la autorizacion por que el prestamo ya se encuentra refinanciado'];
                    }
                 }
             });
         }
         else{
             $message['validate'] = 'prestamo y / o rol inexistente';
         }
         return response()->json($message);
    }

   /**
    * Obtener el monto a pagar
    * devuelve el monto a pagar del titular o garante del prestamo
    * @queryParam loan integer required ID del préstamo. Example: 6
    * @queryParam loan_payment_date date required fecha calculada del pago. Example: 31-07-2021
    * @queryParam liquidate boolean required liquidacion del prestamo. Example: true
    * @queryParam type string required tipo del afiliado que ira a pagar. Example: T
    * @authenticated
    * @responseFile responses/loan/payment_amount.200.json
    */
   public function get_amount_payment(request $request){
       $request->validate([
        'loan'=>'required|integer|exists:loans,id',
        'loan_payment_date'=>'required|date_format:d-m-Y',
        'liquidate'=>'required|boolean',
        'type'=>'required|string|in:T,G',
       ]);
       $loan = Loan::whereId($request->loan)->first();

       return response()->json([
        'suggested_amount' => $loan->get_amount_payment($request->loan_payment_date, $request->liquidate, $request->type)
       ]);
    }

    
    // almacenamiento del plan de pagos

    public function get_plan_payments(Loan $loan)
    {
        DB::beginTransaction();
        try{
            $message = [];
            if($loan->loan_plan->count() > 0){
                LoanPlanPayment::where('loan_id', $loan->id)->delete();
            }
                $plan = [];
                $loan_global_parameter = $loan->loan_procedure->loan_global_parameter;
                $balance = $loan->amount_approved;
                $days_aux = 0;
                $interest_rest = 0;
                $estimated_quota = $loan->estimated_quota;
                $month_term = $loan->modality->loan_modality_parameter->minimum_term_modality * $loan->modality->loan_modality_parameter->loan_month_term;
                for($i = 1 ;$i<= $loan->loan_term; $i++){
                    if($i == 1){
                        if(strstr($loan->modality->shortened, 'EST-PAS'))
                        {
                            if(Carbon::parse($loan->disbursement_date)->quarter == 1)
                            {
                                $date_fin = Carbon::parse($loan->disbursement_date)->startOfYear()->addmonth($month_term)->subDay()->endOfDay();
                                $days = $date_fin->diffInDays(Carbon::parse($loan->disbursement_date)->endOfDay());
                            }
                            elseif(Carbon::parse($loan->disbursement_date)->quarter == 2)
                            {
                                $date_fin = Carbon::parse($loan->disbursement_date)->endOfYear()->endOfDay();
                                $date_ini = clone($date_fin);
                                $date_ini = $date_ini->startOfYear()->addMonth($month_term)->startOfDay();
                                $days = $date_ini->diffInDays($date_fin) + 1;
                            }
                            elseif(Carbon::parse($loan->disbursement_date)->quarter == 3)
                            {
                                $date_fin = Carbon::parse($loan->disbursement_date)->startOfYear()->addmonth($month_term * 2)->subDay()->endOfDay();
                                $days = $date_fin->diffInDays(Carbon::parse($loan->disbursement_date)->endOfDay());
                            }
                            elseif(Carbon::parse($loan->disbursement_date)->quarter == 4)
                            {
                                $date_fin = Carbon::parse($loan->disbursement_date)->endOfYear()->startOfMonth()->addMonth($month_term)->endOfMonth()->endOfDay();
                                $date_ini = clone($date_fin);
                                $date_ini = $date_ini->startOfYear()->startOfDay();
                                $days = $date_ini->diffInDays($date_fin) + 1;
                            }
                            if(Carbon::parse($loan->disbursement_date)->quarter == 2 || Carbon::parse($loan->disbursement_date)->quarter == 4)
                                $extra_days = Carbon::parse($loan->disbursement_date)->endOfDay()->diffInDays($date_ini);
                            else
                                $extra_days = 0;
                            $extra_interest = LoanPayment::interest_by_days($extra_days, $loan->interest->annual_interest, $balance, $loan->loan_procedure->loan_global_parameter->denominator);
                            $interest = LoanPayment::interest_by_days($days + $extra_days, $loan->interest->annual_interest, $balance, $loan->loan_procedure->loan_global_parameter->denominator);
                            $days = $days + $extra_days;
                            if($loan->loan_term == 1)
                                $payment = $loan->balance + $interest;
                            else
                                $payment = $loan->estimated_quota + $extra_interest;
                            $capital = $payment - $interest;
                        }
                        else
                        {
                            $date_ini = Carbon::parse($loan->disbursement_date)->format('d-m-Y');
                            if(Carbon::parse($date_ini)->format('d') <= $loan_global_parameter->offset_interest_day){
                                $date_fin = Carbon::parse($date_ini)->endOfMonth();
                                $days = $date_fin->diffInDays($date_ini);
                                $interest = LoanPayment::interest_by_days($days, $loan->interest->annual_interest, $balance, $loan->loan_procedure->loan_global_parameter->denominator);
                                if($loan->loan_term == 1)
                                {
                                    $capital = $balance;
                                    $payment = $capital + $interest;
                                }
                                else
                                {
                                    $capital = $estimated_quota - $interest;
                                    $payment = $capital + $interest;
                                }
                            }
                            else{
                                $date_ini = Carbon::parse($loan->disbursement_date)->startOfDay()->format('d');
                                $date_pay = Carbon::parse($loan->disbursement_date)->endOfMonth()->endOfDay()->format('d');
                                $extra_days = $date_pay - $date_ini;
                                $extra_interest = LoanPayment::interest_by_days($extra_days, $loan->interest->annual_interest, $balance, $loan->loan_procedure->loan_global_parameter->denominator);
                                $payment = $loan->estimated_quota + $extra_interest;
                                $date_fin = Carbon::parse($loan->disbursement_date)->startOfMonth()->addMonth()->endOfMonth()->endOfDay();
                                $days = Carbon::parse($loan->disbursement_date)->diffInDays($date_fin);
                                $interest = LoanPayment::interest_by_days($days, $loan->interest->annual_interest, $balance, $loan->loan_procedure->loan_global_parameter->denominator, $loan->loan_procedure->loan_global_parameter->denominator);
                                if($loan->loan_term == 1){
                                    $capital = $balance;
                                    $payment = $capital + $interest;
                                }
                                $capital = $payment - $interest;
                            }
                        }
                    }
                    else{
                        if(strstr($loan->modality->shortened, 'EST-PAS'))
                        {
                            $date_fin = Carbon::parse($date_ini)->addMonth($month_term - 1)->endOfMonth()->endOfDay();
                            $days = $date_fin->diffInDays($date_ini) + 1;
                            $interest = LoanPayment::interest_by_days($days, $loan->interest->annual_interest, $balance, $loan->loan_procedure->loan_global_parameter->denominator);
                            $capital = $estimated_quota - $interest;
                            $payment = $estimated_quota;
                        }
                        else
                        {
                            $date_fin = Carbon::parse($date_ini)->endOfMonth();
                            $days = $date_fin->diffInDays($date_ini)+1;
                            $interest = LoanPayment::interest_by_days($days, $loan->interest->annual_interest, $balance, $loan->loan_procedure->loan_global_parameter->denominator);
                            $capital = $estimated_quota - $interest;
                            $payment = $estimated_quota;
                        }
                    }
                    $balance = $balance - $capital;
                    if($i == 1){
                        $loan_payment = new LoanPlanPayment;
                        $loan_payment->loan_id = $loan->id;
                        $loan_payment->user_id = Auth::user()->id;
                        $loan_payment->disbursement_date = $loan->disbursement_date;
                        $loan_payment->quota_number = $i;
                        $loan_payment->estimated_date = Carbon::parse($date_fin)->endOfDay();
                        $loan_payment->days = $days + $days_aux;
                        $loan_payment->capital = $capital;
                        $loan_payment->interest = $interest;
                        $loan_payment->total_amount = $payment;
                        $loan_payment->balance = $balance;
                        $loan_payment->save();
                    }
                    else{
                        if($i == $loan->loan_term){
                            $loan_payment = new LoanPlanPayment;
                            $loan_payment->loan_id = $loan->id;
                            $loan_payment->user_id = Auth::user()->id;
                            $loan_payment->disbursement_date = $loan->disbursement_date;
                            $loan_payment->quota_number = $i;
                            $loan_payment->estimated_date = Carbon::parse($date_fin)->endOfDay();
                            $loan_payment->days = $days;
                            $loan_payment->capital = $capital + $balance;
                            $loan_payment->interest = $interest;
                            $loan_payment->total_amount = $balance + $payment;
                            $loan_payment->balance = 0;
                            $loan_payment->save();
                        }
                        else{
                            $loan_payment = new LoanPlanPayment;
                            $loan_payment->loan_id = $loan->id;
                            $loan_payment->user_id = Auth::user()->id;
                            $loan_payment->disbursement_date = $loan->disbursement_date;
                            $loan_payment->quota_number = $i;
                            $loan_payment->estimated_date = Carbon::parse($date_fin)->endOfDay();
                            $loan_payment->days = $days;
                            $loan_payment->capital = $capital;
                            $loan_payment->interest = $interest;
                            $loan_payment->total_amount = $payment;
                            $loan_payment->balance = $balance;
                            $loan_payment->save();
                        }
                    }
                    $date_ini = Carbon::parse($date_fin)->startOfMonth()->addMonth();
                }
                DB::commit();
                return true;
        }
        catch (\Exception $e){
            DB::rollback();
            return $e;
        }
    }

  /**
    * Actualizar cuenta bancaria del prestamo
    * Actualiza los datos de la cuenta bancaria en el prestamo y el afiliado
    * @bodyParam loan_id integer required ID del préstamo. Example: 2
    * @bodyParam number_payment_type integer required numero de cuenta bancaria. Example: 123456
    * @bodyParam financial_entity_id integer  id de la entidad bancaria. Example: 5
    * @authenticated
    * @responseFile responses/loan/update_loan_number_payment_type.200.json
    */   
    public function update_number_payment_type(request $request)
    {
        DB::beginTransaction();
        try{
            $request->validate([
            'loan_id'=>'required|integer|exists:loans,id',
            'number_payment_type'=>'required|integer',
            'financial_entity_id'=>'required|integer|exists:financial_entities,id',
            ]);
            $loan = Loan::find($request->loan_id);
            if (!$this->can_user_loan_action($loan, $request->current_role_id)) abort(409, "El tramite no esta disponible para su rol");
                $loan->number_payment_type = $request->number_payment_type;
                $loan->financial_entity_id = $request->financial_entity_id;
            $loan->save();
            $affiliate = $loan->affiliate;
            $affiliate->account_number = $request->number_payment_type;
            $affiliate->financial_entity_id = $request->financial_entity_id;
            $affiliate->update();
            DB::commit();
            return $loan;
        }catch (\Exception $e){
            DB::rollback();
            return response()->json(['error'=>'No se pudo actualizar cuenta'],409);
        }
    }

    //verifica si el usuario puede realizar acciones sobre el prestamo con su rol
    public function can_user_loan_action(Loan $loan, $role_id) {
        $wf_states_roles = $loan->currentState->roles->pluck('id')->toArray();
        return in_array($role_id, $wf_states_roles);
    }

    //
    public function release_loan(request $request, Loan $loan)
    {
        $message = "Ocurrio un error";
        $status = false;
        if(Auth::user()->can('release-loan-user'))
        {
            if($loan->state_id == LoanState::where('name', 'En Proceso')->first()->id)
            {
                $loan->validated = false;
                $loan->user_id = null;
                $loan->save();
                $message = "Se quito la validación del tramite";
                $status = true;
            }
            else
            {
                $message = "no se puede quitar la validación de un prestamo en estado " . $loan->state->name;
                $status = false;
            }
        }
        else
        {
            $message = "no tiene los permisos necesarios";
            $state = false;
        }
        return [
            'message' => $message,
            'type' => $status
        ];   
    }

    public function regenerate_plan(Loan $loan)
    {
        try{
            DB::beginTransaction();
            $this->get_plan_payments($loan);
            Util::save_record($loan, 'datos-de-un-tramite', Util::concat_action($loan,'regenero plan de pagos'));
            DB::commit();
            return $loan->loan_plan;
        }catch (\Exception $e){
            DB::rollback();
            return response()->json(['error'=>'No se pudo regenerar el plan de pagos'],409);
        }
    }

    public function get_value_no_debt_certification($affiliate_id)
    {
        $count_loans = Loan::where('affiliate_id', $affiliate_id)->where('state_id', 3)->count();
        
        if($count_loans == 0)
        {
            $date_first_loan_pvt = Carbon::parse(Loan::min('created_at'))->toDateString();

            $date_first_contribution = Affiliate::join('contributions', 'contributions.affiliate_id', '=', 'affiliates.id')
                ->where('affiliates.id', $affiliate_id)
                ->orderBy('contributions.month_year', 'asc')
                ->value('contributions.month_year');

            $date_entry = Affiliate::find($affiliate_id)->date_entry;

            if($date_entry >= $date_first_loan_pvt && $date_first_contribution >= $date_entry)
            {
                $value = 'NO ADEUDO';
            }else{
                $value = 'REVISAR BASE DE DATOS';
            }
        }else{
            $value = 'REGISTRA DEUDAS';
        }
        return $value;
    }

    public function no_debt_certification(Request $request, Affiliate $affiliate, $standalone = true)
    {
        $file_title = implode('_', ['CERT','NO','ADEUDO', $affiliate->id, Carbon::now()->format('m/d')]);
       
        $data = [
            'header' => [
                'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
                'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
                'table' => [
                    ['Fecha', Carbon::now()->format('d/m/Y')],
                    ['Hora', Carbon::now()->format('H:i')],
                    ['Usuario', Auth::user()->username]
                ]
            ],
            'institution' => 'Mutual de Servicios al Policía "MUSERPOL"',
            'title' => 'CERTIFICADO DE NO ADEUDO',
            'affiliate' => $affiliate,
            'id_affiliate' => $affiliate->id,
            'user' => Auth::user(),
            'code' => $request->code,
            'value' =>  $this->get_value_no_debt_certification($affiliate->id),
            'file_title' => $file_title
        ];
       
        $file_name = implode('_', ['no_debt_certification', $affiliate->id]) . '.pdf';
        $view = view()->make('loan.certification.no_debt_certification')->with($data)->render();
        if ($standalone) return Util::pdf_to_base64([$view], $file_name, $affiliate,'letter', $request->copies ?? 1);
        return $view;
    }

    public function print_process_form(Request $request, Loan $loan, $standalone = true){
        $procedure_modality = $loan->modality;
        $file_title =implode('_', ['FORM','TRAMITE', $procedure_modality->shortened, $loan->code,Carbon::now()->format('m/d')]);    
        $data = [
           'header' => [
               'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
               'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
               'table' => [
                   ['Tipo', $loan->modality->procedure_type->second_name],
                   ['Modalidad', $loan->modality->shortened],
                   ['Usuario', Auth::user()->username]
               ]
           ],
           'loan' => $loan,
           'file_title' => $file_title,
       ];
       $information_loan= $this->get_information_loan($loan);
       $file_name = implode('_', ['hoja_de_tramite', $procedure_modality->shortened, $loan->code]) . '.pdf'; 
       $view = view()->make('loan.forms.process_form')->with($data)->render();
       $portrait = true;//impresion horizontal
       $print_date = false;//modo retrato e impresion de la fecha en el formulario de calificación
       if ($standalone) return  Util::pdf_to_base64([$view], $file_name, $information_loan, 'legal', $request->copies ?? 1, $portrait, $print_date);  
       return $view; 
   }

   public function print_warranty_registration_form(Request $request, Loan $loan, $standalone = true){
    if($loan->guarantors->count() == 0)
        abort(409, 'El préstamo no cuenta con garantes registrados');
        $procedure_modality = $loan->modality;
        $file_title =implode('_', ['FORM','TRAMITE', $procedure_modality->shortened, $loan->code,Carbon::now()->format('m/d')]);
        $data_loan_guarantor = collect();
        $titular_guarantors = LoanGuarantor::where('loan_id', $loan->id)->orderby('affiliate_id')->get();
        foreach($titular_guarantors as $titular_guarantor)
        {
            if($titular_guarantor->type != 'affiliates'){
                $titular_guarantor->state_pasive = 'PASIVO';
                $titular_guarantor->category_pasive = $titular_guarantor->pension_entity->name;
            }
            $guarantees = LoanGuaranteeRegister::where('loan_id', $loan->id)
                            ->where('affiliate_id', $titular_guarantor->affiliate_id)
                            ->get();
            $guarantor_loans = collect();
            foreach($guarantees as $guarantee)
            {
                if($guarantee->guarantable_type == 'loans')
                    $loan_guarantee = Loan::find($guarantee->guarantable_id);
                else{
                    $affiliate = Affiliate::find($titular_guarantor->affiliate_id);
                    $loan_guarantee = $affiliate->get_loan_sismu($guarantee->guarantable_id)[0];
                    switch($loan_guarantee->PresEstPtmo)
                    {
                        case 'V':
                            $loan_guarantee->PresEstPtmo = 'Vigente';
                            break;
                        case 'A':
                            $loan_guarantee->PresEstPtmo = 'Aperturado';
                            break;
                        case 'E':
                            $loan_guarantee->PresEstPtmo = 'Pendiente';
                            break;
                        case 'N':
                            $loan_guarantee->PresEstPtmo = 'Anulado';
                            break;
                        case 'C':
                            $loan_guarantee->PresEstPtmo = 'Condonado';
                            break;
                        case 'X':
                            $loan_guarantee->PresEstPtmo = 'Cancelado';
                            break;
                        default:
                            $loan_guarantee->PresEstPtmo = 'Desconocido';
                            break;
                    }
                }

                $guarantor_loans->push((object)[
                    'code' => $guarantee->guarantable_type == 'loans' ? $loan_guarantee->code : $loan_guarantee->PresNumero,
                    'lender_full_name' => $guarantee->guarantable_type == 'loans' ? $loan_guarantee->borrower->first()->full_name : $loan_guarantee->full_name,
                    'lender_identity_card' => $guarantee->guarantable_type == 'loans' ? $loan_guarantee->borrower->first()->identity_card : $loan_guarantee->PadCedulaIdentidad,
                    'lender_registration' => $guarantee->guarantable_type == 'loans' ? $loan_guarantee->borrower->first()->registration : $loan_guarantee->PadMatriculaTit,
                    'modality' => $guarantee->guarantable_type == 'loans' ? $loan_guarantee->modality->shortened : $loan_guarantee->PrdDsc,
                    'request_date' => $guarantee->guarantable_type == 'loans' ? Carbon::parse($loan_guarantee->created_at)->format('d/m/Y') : Carbon::parse($loan_guarantee->PresFechaPrestamo)->format('d/m/Y'),
                    'disbursement_date' => $guarantee->guarantable_type == 'loans' ? Carbon::parse($loan_guarantee->disbursement_date)->format('d/m/Y') : Carbon::parse($loan_guarantee->PresFechaDesembolso)->format('d/m/Y'),
                    'amount_approved' => number_format($guarantee->guarantable_type == 'loans' ? $loan_guarantee->amount_approved : $loan_guarantee->PresMntDesembolso, 2, '.', ','),
                    'term' => $guarantee->guarantable_type == 'loans' ? $loan_guarantee->loan_term : $loan_guarantee->PresMeses,
                    'estimated_quota' => number_format($guarantee->guarantable_type == 'loans' ? $loan_guarantee->estimated_quota : $loan_guarantee->PresCuotaMensual, 2, '.', ','),
                    'balance' => number_format($guarantee->guarantable_type == 'loans' ? $loan_guarantee->verify_balance() : $loan_guarantee->PresSaldoAct, 2, '.', ','),
                    'type' => ($guarantee->guarantable_type == 'loans') ? 'PVT' : 'SISMU',
                    'state' => $guarantee->guarantable_type == 'loans' ? $loan_guarantee->state->name : $loan_guarantee->PresEstPtmo,
                ]);
            }
            $data_loan_guarantor->push((object)[
                'full_name' => $titular_guarantor->full_name,
                'identity_card' => $titular_guarantor->identity_card,
                'category' => $titular_guarantor->type == 'affiliates' ? $titular_guarantor->category->name : $titular_guarantor->category_pasive,
                'state' => $titular_guarantor->type == 'affiliates' ? $titular_guarantor->affiliate_state->name : $titular_guarantor->state_pasive,
                'guarantor_loans' => $guarantor_loans,
            ]);
        }
        $data = [
            'header' => [
               'direction' => 'DIRECCIÓN DE ESTRATEGIAS SOCIALES E INVERSIONES',
               'unity' => 'UNIDAD DE INVERSIÓN EN PRÉSTAMOS',
               'table' => [
                   ['Tipo', $loan->modality->procedure_type->second_name],
                   ['Modalidad', $loan->modality->shortened],
                   ['Usuario', Auth::user()->username]
               ]
            ],
            'loan' => $loan,
            'file_title' => $file_title,
            'data_loan_guarantors' => $data_loan_guarantor,
       ];
       $data['data_loan_guarantors'] = $data_loan_guarantor;
       $information_loan= $this->get_information_loan($loan);
       $file_name = implode('_', ['hoja_de_tramite', $procedure_modality->shortened, $loan->code]) . '.pdf'; 
       $view = view()->make('loan.forms.warranty_registration_form')->with($data)->render();
       $portrait = true;//impresion horizontal
       $print_date = false;//modo retrato e impresion de la fecha en el formulario de calificación
       if ($standalone) return  Util::pdf_to_base64([$view], $file_name, $information_loan, 'legal', $request->copies ?? 1, $portrait, $print_date);  
       return $view; 
   }

    public function generate_plans()
    {
        try{
            $loans = Loan::whereNotNull('disbursement_date')->get();
            $c=0;
            foreach($loans as $loan)
            {
                if($loan->loan_plan->count() == 0)
                {
                    $this->get_plan_payments($loan, $loan->disbursement_date);
                    $c++;
                }
            }
            return $c;
        }catch(\Exception $e){
            return $e;
        }
    }

    public function validate_reprogramming(Loan $loan)
    {
        $message = [];
        $status = true;
        $modality_reprogramming = null;
        $affiliate = true;
        if($loan->one_borrower->type == 'spouses')
            $affiliate = false;
        if($affiliate && $loan->affiliate->dead){
            $status = false;
            $message = 'El afiliado se encuentra fallecido, no se puede reprogramar';    
        }elseif(!$affiliate && $loan->affiliate->spouses->first()->dead){
            $status = false;
            $message = 'El prestatario se encuentra fallecido, no se puede reprogramar';    
        }
        elseif($loan->defaulted)
        {
            $status = false;
            $message = 'El prestamo tiene intereses adeudados, no se puede reprogramar';
        }elseif(!$loan->modality->loan_modality_parameter->reprogramming_modality)
        {
            $status = false;
            $message = 'Esta modalidad de prestamo no se puede reprogramar';
        }elseif(($loan->amount_approved - $loan->balance) <= $loan->get_min_amount_for_refinancing())
        {
            $status = false;
            $message = 'El monto aprobado del prestamo es menor al monto minimo para reprogramar';
            
        }elseif($loan->last_payment->penal_payment > 0 || $loan->last_payment->interest_payment > 0 || $loan->last_payment->capital_payment != $loan->verify_balance())
        {
            $status = false;
            $message = 'verificar el pendiente de reprogramación';
        }
        elseif($loan->reprogrammed_active_process_loans()->count() > 0)
        {
            $status = false;
            $message = 'El prestamo tiene una tramite en proceso';
        }
        elseif(!$loan->contract_signature_date){
            $status = false;
            $message = 'El prestamo no cuenta con una fecha de firma de contrato, no se puede reprogramar';
        }
        else{
            $modality_reprogramming = $loan->modality->loan_modality_parameter->reprogramming_modality;
            $modality_reprogramming->loan_modality_parameter = $modality_reprogramming->loan_modality_parameter;
            $message = 'El prestamo se puede reprogramar';
        }
        return response()->json([
            'status' => $status,
            'message' => $message,
            'modality_reprogramming' => $modality_reprogramming
        ], 200);
    }

    public function get_info_reprogramming(Loan $loan)
    {
        $message = '';
        $status = false;
        if($loan->parent_reason == 'REPROGRAMACIÓN' && $loan->parent_loan->last_payment_validated->state->name != 'Pagado' && $loan->currentState->name == 'Cobranzas Corte')
        {
            $message = "La reprogramación aun cuenta con pagos pendientes por validar";
            $status = true;
        }elseif ($loan->parent_reason == 'REPROGRAMACIÓN' && $loan->currentState->name == 'Confirmación legal' && $loan->state_id == 1) {
            $message = "Aun no se genero el nuevo plan de pagos de la reprogramación";
            $status = true;
        }
        return response()->json([
            'message' => $message,
            'status' => $status
        ], 200);
    }
}
