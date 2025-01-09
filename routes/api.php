<?php

Route::group([
    'middleware' => 'api',
    'prefix' => 'v1',
], function () {
    // Rutas abiertas
    Route::get('config', 'Api\V1\ConfigController');
    Route::apiResource('auth', 'Api\V1\AuthController')->only('store');
    Route::patch('edit_loan/{loan}/qualification', 'Api\V1\LoanController@edit_amounts_loan_term');
    Route::apiResource('affiliate', 'Api\V1\AffiliateController')->only('show');//TODO biometrico VERIFICAR RUTA ABIERTA 
    Route::apiResource('record', 'Api\V1\RecordController')->only('index');//TODO biometrico VERIFICAR RUTA ABIERTA 
    Route::get('affiliate/{affiliate}/fingerprint', 'Api\V1\AffiliateController@fingerprint_saved');//TODO biometrico VERIFICAR RUTA ABIERTA 
    Route::get('affiliate/{affiliate}/deletefingerprint', 'Api\V1\AffiliateController@fingerprint_delete');//b
    // INDEFINIDO (TODO)
    Route::get('document/{affiliate_id}', 'Api\V1\ScannedDocumentController@create_document');
    Route::get('generate_plans', 'Api\V1\LoanController@generate_plans');
    //ruta para saber si un afiliado cuenta con prestamos que hayan sido pagados por sus garantes
    Route::get('loans_paid_by_guarantors/{affiliate}', 'Api\V1\AffiliateController@loans_paid_by_guarantors');
    // Autenticado con token
    Route::group([
        'middleware' => 'auth'
    ], function () {
        Route::apiResource('user', 'Api\V1\UserController');//->only('index', 'show', 'update');
        if (!env("LDAP_AUTHENTICATION")) Route::apiResource('user', 'Api\V1\UserController')->only('update');
        Route::get('user/{user}/role', 'Api\V1\UserController@get_roles');
        Route::get('user_role/permission', 'Api\V1\UserController@role_permision');
        Route::apiResource('auth', 'Api\V1\AuthController')->only('index');
        Route::patch('auth', 'Api\V1\AuthController@refresh');
        Route::delete('auth', 'Api\V1\AuthController@logout');
        Route::get('procedure_modality/{procedure_modality}/requirement', 'Api\V1\ProcedureModalityController@get_requirements');
        Route::post('calculator', 'Api\V1\CalculatorController@calculator');//se debe eliminar una ves arreglado front
        Route::apiResource('liquid_calificated', 'Api\V1\CalculatorController')->only('store');
        Route::post('simulator','Api\V1\CalculatorController@simulator');
        Route::apiResource('role', 'Api\V1\RoleController')->only('index', 'show');
        Route::apiResource('permission', 'Api\V1\PermissionController')->only('index');
        Route::apiResource('loan_global_parameter', 'Api\V1\LoanGlobalParameterController')->only('index', 'show', 'store', 'update', 'destroy');
        Route::get('last_loan_global_parameter', 'Api\V1\LoanGlobalParameterController@get_last_global_parameter');
        Route::apiResource('loan_destiny', 'Api\V1\LoanDestinyController')->only('index', 'show', 'store', 'update', 'destroy');
        Route::get('affiliate_show/{affiliate}', 'Api\V1\AffiliateController@affiliate_show');//TODO mostrar datos del afiliado para prestaos, ruta adaptadda para que no afecte al biometrico
        Route::apiResource('affiliate_state', 'Api\V1\AffiliateStateController')->only('index');
        Route::apiResource('city', 'Api\V1\CityController')->only('index', 'show');
        Route::apiResource('pension_entity', 'Api\V1\PensionEntityController')->only('index', 'show');
        Route::apiResource('degree', 'Api\V1\DegreeController')->only('index', 'show');
        Route::apiResource('category', 'Api\V1\CategoryController')->only('index', 'show');
        Route::apiResource('unit', 'Api\V1\UnitController')->only('index', 'show');
        Route::apiResource('procedure_type', 'Api\V1\ProcedureTypeController')->only('index', 'show');
        Route::apiResource('kinship', 'Api\V1\KinshipController')->only('index','show');
        Route::get('procedure_type/{procedure_type}/modality', 'Api\V1\ProcedureTypeController@get_modality');
        Route::get('procedure_type/{procedure_type}/flow', 'Api\V1\ProcedureTypeController@get_flow');
        Route::post('procedure_type/modality/loan', 'Api\V1\ProcedureTypeController@get_modality_loan');//Mostrar Todas las modalidades de Préstamos Según Reglamento
        Route::get('affiliate_loan_modality/{affiliate}/{procedure_type}','Api\V1\AffiliateController@get_sub_modality_affiliate');//Mostrar las sub modalidades a las que el afiliado puede acceder
        Route::get('procedure_modality_parameters/{procedure_modality}', 'Api\V1\ProcedureModalityController@get_loan_modality_parameter_remake'); //obtiene los parametros para rehacer tramite
        Route::get('validate_affiliate_modality/{affiliate}/{procedure_modality}','Api\V1\AffiliateController@validate_affiliate_modality');//Mostrar las sub modalidades a las que el afiliado puede acceder
        Route::apiResource('payment_type', 'Api\V1\PaymentTypeController')->only('index', 'show');
        Route::apiResource('procedure_modality', 'Api\V1\ProcedureModalityController')->only('index', 'show');
        Route::get('procedure_modality/{procedure_modality}/loan_modality_parameter', 'Api\V1\ProcedureModalityController@get_loan_modality_parameter');
        Route::apiResource('module', 'Api\V1\ModuleController')->only('index', 'show');
        Route::get('module/{module}/role', 'Api\V1\ModuleController@get_roles');
        Route::get('module/{module}/procedure_type', 'Api\V1\ModuleController@get_procedure_types');
        Route::get('module/{module}/observation_type', 'Api\V1\ModuleController@get_observation_types');
        Route::get('module/{module}/observation_type_affiliate/{affiliate}', 'Api\V1\AffiliateObservationController@get_observation_types_affiliate');
        Route::get('module/{module}/modality_loan', 'Api\V1\ModuleController@get_modality_types');
        Route::get('module/{module}/amortization_loan', 'Api\V1\ModuleController@get_amortization_types');
        Route::patch('loans', 'Api\V1\LoanController@bulk_update_role');
        Route::patch('loan_payments', 'Api\V1\LoanPaymentController@bulk_update_role');
        Route::get('record_payment', 'Api\V1\RecordController@record_loan_payment');
        Route::apiResource('statistic', 'Api\V1\StatisticController')->only('index', 'show');
        Route::apiResource('voucher_type', 'Api\V1\VoucherTypeController')->only('index', 'show');
        Route::apiResource('financial_entity', 'Api\V1\FinancialEntityController')->only('index', 'show');
        Route::post('evaluate_garantor', 'Api\V1\CalculatorController@evaluate_guarantor');
        Route::post('evaluate_garantor2', 'Api\V1\CalculatorController@evaluate_guarantor2');
        Route::get('affiliate_record', 'Api\V1\AffiliateController@affiliate_record');
        Route::post('affiliate_guarantor', 'Api\V1\AffiliateController@test_guarantor');
        //evaluacion de garantes
        Route::post('existence', 'Api\V1\AffiliateController@existence');
        Route::post('validate_guarantor', 'Api\V1\AffiliateController@validate_guarantor');
        //Categorias de tipo "USUARIO"
        Route::get('get_categorie_user', 'Api\V1\LoanPaymentCategorieController@get_categorie_user');
        //Evaluacion de prestamos afiliado
        Route::post('search_loan','Api\V1\AffiliateController@search_loan');
        //contribuciones afiliado
        Route::apiResource('aid_contribution', 'Api\V1\AidContributionController')->only('index', 'show', 'store', 'update', 'destroy');
        Route::post('aid_contribution/updateOrCreate', 'Api\V1\AidContributionController@updateOrCreate');
        Route::apiResource('contributions_affiliate', 'Api\V1\ContributionController')->only('index', 'show', 'store', 'update', 'destroy');
        Route::get('affiliate/{affiliate}/contributions_affiliate', 'Api\V1\ContributionController@get_all_contribution_affiliate');
        Route::get('contribution/{contribution}/print/contribution','Api\V1\ContributionController@print_contribution');
        //Conceptos de movimientos
        Route::apiResource('movement_concept', 'Api\V1\MovementConceptController')->only('index', 'show', 'store', 'update', 'destroy');
        //REPORTS
        Route::post('send_contract', 'Api\V1\SMSController@send_sms_for_contract');
            //loanReport
        Route::get('loan_tracking', 'Api\V1\LoanReportController@loan_tracking');//seguimiento de prestamos
        Route::get('list_loan_generate', 'Api\V1\LoanReportController@list_loan_generate');
        Route::get('report_loan_vigent', 'Api\V1\LoanReportController@report_loan_vigent');
        Route::get('report_loan_state_cartera', 'Api\V1\LoanReportController@report_loan_state_cartera');
        Route::get('report_loans_mora', 'Api\V1\LoanReportController@report_loans_mora_v2');
        Route::get('report_loans_income', 'Api\V1\LoanReportController@report_loans_income');
        //Route::get('report_loans_mora_v2', 'Api\V1\LoanReportController@report_loans_mora_v2');
        Route::get('loan_information', 'Api\V1\LoanReportController@loan_information');//reporte de nuevos prestamos desembolsados
        Route::get('loan_defaulted_guarantor', 'Api\V1\LoanReportController@loan_defaulted_guarantor');//reporte de nuevos prestamos desembolsados
        Route::get('loan_pvt_sismu_report', 'Api\V1\LoanReportController@loan_pvt_sismu_report');//reporte de prestamos PVT y sismu simultaneos
        Route::get('request_state_report', 'Api\V1\LoanReportController@request_state_report');
        Route::get('loan_application_status', 'Api\V1\LoanReportController@loan_application_status');
        Route::get('loans_days_amortization', 'Api\V1\LoanReportController@loans_days_amortization');// reporte dias transcurridos desde ultima amortización
        Route::get('processed_loan_report', 'Api\V1\LoanReportController@processed_loan_report');// Reporte de tramites procesados
        Route::get('report_loans_pay_partial', 'Api\V1\LoanReportController@report_loans_pay_partial');
        //loanPaymentReport
        Route::get('list_loan_payments_generate', 'Api\V1\LoanPaymentReportController@list_loan_payments_generate');
        Route::get('report_amortization_discount_months', 'Api\V1\LoanPaymentReportController@report_amortization_discount_months');
        //Route::get('report_amortization_cash_deposit', 'Api\V1\LoanPaymentReportController@report_amortization_cash_deposit');
        Route::get('report_amortization_cash_deposit', 'Api\V1\LoanPaymentReportController@report_amortization_cash_deposit_discount_type');
        Route::get('report_amortization_ajust', 'Api\V1\LoanPaymentReportController@report_amortization_ajust');
        Route::get('report_amortization_pending_confirmation', 'Api\V1\LoanPaymentReportController@report_amortization_pending_confirmation');
        Route::get('report_amortization_fondo_complement', 'Api\V1\LoanPaymentReportController@report_amortization_fondo_complement');
        Route::get('treasury_report', 'Api\V1\LoanPaymentReportController@treasury_report');
            //ImportationReport
        Route::get('report_amortization_importation_payments', 'Api\V1\ImportationReportController@report_amortization_importation_payments');
        Route::get('report_request_institution', 'Api\V1\ImportationReportController@report_request_institution');
            //movementFundRotatory_Report
        Route::get('disbursements_fund_rotatory_outputs_report', 'Api\V1\MovementFundRotatoryController@disbursements_fund_rotatory_outputs_report'); //report de desembolsos anticipo 
        //IMPORTACION
        Route::get('agruped_payments', 'Api\V1\ImportationController@agruped_payments');
        Route::get('importation_payments_senasir', 'Api\V1\ImportationController@importation_payment_senasir');//senasir pagos
        Route::get('upload_fail_validated_group', 'Api\V1\ImportationController@upload_fail_validated_group');
        Route::get('copy_payments', 'Api\V1\ImportationController@copy_payments');
        Route::get('create_payments_command', 'Api\V1\ImportationController@create_payments_command');
        Route::get('rollback_copy_groups_payments', 'Api\V1\ImportationController@rollback_copy_groups_payments');
        //PERIODOS DE IMPORTACION
        Route::get('get_list_month', 'Api\V1\LoanPaymentPeriodController@get_list_month');//listado de meses por gestion
        Route::get('get_list_year', 'Api\V1\LoanPaymentPeriodController@get_list_year');//listado de meses por gestion
        Route::apiResource('periods', 'Api\V1\LoanPaymentPeriodController')->only('index', 'show', 'store', 'update', 'destroy');//cambiar a cobranzas
        //Route::post('loan/update_loan_affiliates', 'Api\V1\LoanController@update_loan_affiliates');
        Route::post('committee_session/{loan}', 'Api\V1\LoanController@committee_session');
        Route::get('record_affiliate_history', 'Api\V1\RecordController@record_affiliate_history');
        Route::Post('loan_sismu', 'Api\V1\SismuController@getLoanSismu');
        Route::Post('update_balance_sismu', 'Api\V1\SismuController@update_balance');
        /*Seguimiento de mora de prestamo*/
        Route::group([
            'middleware' => 'permission:print-delay-tracking'
        ], function () {
            Route::get('loan/{loan}/print/delay_tracking', 'Api\V1\LoanTrackingController@print_delay_tracking');
            Route::get('loan/{loan}/print/download_delay_tracking', 'Api\V1\LoanTrackingController@download_delay_tracking');
        });

        Route::group([
            'middleware' => ['permission:create-delay-tracking', 'permission:show-delay-tracking', 'permission:update-delay-tracking', 'permission:delete-delay-tracking']
        ], function() {
            Route::apiResource('loan_tracking_delay', 'Api\V1\LoanTrackingController');
            Route::get('get_loan_trackings_types', 'Api\V1\LoanTrackingController@get_loan_trackings_types');
        });

        Route::group([
            'middleware' => ['permission:print-loan-certification']
        ], function () {
            Route::get('loan/{loan}/print/loan_certification','Api\V1\LoanCertificationController@print_warranty_discount_certification');
        });

        //Movimientos de fondo Rotatorio
        Route::group([
            'middleware' => 'permission:closing-movement-fund-rotatory'
        ], function () {
            Route::post('closing_movements', 'Api\V1\MovementFundRotatoryController@closing_movements');
        });
        Route::group([
            'middleware' => 'permission:show-movement-fund-rotatory'
        ], function () {
            Route::get('list_movements_fund_rotatory', 'Api\V1\MovementFundRotatoryController@list_movements_fund_rotatory');
            Route::apiResource('movements', 'Api\V1\MovementFundRotatoryController')->only('index');
            Route::apiResource('movements', 'Api\V1\MovementFundRotatoryController')->only('show');
        });
        Route::group([
            'middleware' => 'permission:delete-movement-fund-rotatory'
        ], function () {
            Route::delete('delete_movement/{movement}', 'Api\V1\MovementFundRotatoryController@delete_movement');
        });
        Route::group([
            'middleware' => 'permission:update-movement-fund-rotatory'
        ], function () {
            Route::apiResource('movements', 'Api\V1\MovementFundRotatoryController')->only('update');
        });

        Route::group([
            'middleware' => 'permission:print-disbursement-receipt'
        ], function () {
            Route::get('print_fund_rotary_output/{loan_id}', 'Api\V1\MovementFundRotatoryController@print_fund_rotary');
        });
        Route::group([
            'middleware' => 'permission:create-entry-fund-rotatory'
        ], function () {
            Route::post('movement_fund_rotatory_entry/store_input', 'Api\V1\MovementFundRotatoryController@store_input');
        });
        // Afiliados
        Route::group([
            'middleware' => 'permission:show-affiliate'
        ], function () {
            Route::apiResource('affiliate', 'Api\V1\AffiliateController')->only('index');
            Route::apiResource('spouse', 'Api\V1\SpouseController')->only('index', 'show');
            Route::get('affiliate/{affiliate}/state', 'Api\V1\AffiliateController@get_state');
            Route::get('affiliate/{affiliate}/spouse', 'Api\V1\AffiliateController@get_spouse');
            Route::get('affiliate/{affiliate}/address', 'Api\V1\AffiliateController@get_addresses');
            Route::get('affiliate/{affiliate}/contribution', 'Api\V1\AffiliateController@get_contributions');
            Route::get('affiliate/{affiliate}/fingerprint_picture', 'Api\V1\AffiliateController@get_fingerprint_images');
            Route::get('affiliate/{affiliate}/profile_picture', 'Api\V1\AffiliateController@get_profile_images');
            Route::get('affiliate/{affiliate}/observation','Api\V1\AffiliateObservationController@index')->middleware('permission:show-observation-affiliate');
            Route::post('affiliate/{affiliate}/observation','Api\V1\AffiliateObservationController@store')->middleware('permission:create-observation-affiliate');
            Route::patch('affiliate/{affiliate}/observation','Api\V1\AffiliateObservationController@update')->middleware('permission:update-observation-affiliate');
            Route::delete('affiliate/{affiliate}/observation','Api\V1\AffiliateObservationController@destroy')->middleware('permission:delete-observation-affiliate');
            Route::post('affiliate_spouse_guarantor', 'Api\V1\AffiliateController@test_spouse_guarantor');
            Route::get('affiliate_existence','Api\V1\AffiliateController@get_existence');
            Route::get('affiliate/{affiliate}/maximum_loans','Api\V1\AffiliateController@evaluate_maximum_loans');
            Route::post('affiliate_loans_guarantees', 'Api\V1\AffiliateController@loans_guarantees');
            Route::get('affiliate/{affiliate}/verify_affiliate_spouse','Api\V1\AffiliateController@verify_affiliate_spouse');
            Route::get('affiliate/get_retirement_fund_average','Api\V1\AffiliateController@get_retirement_fund_average');

        });
        Route::group([
            'middleware' => 'permission:create-affiliate'
        ], function () {
            Route::apiResource('affiliate', 'Api\V1\AffiliateController')->only('store');
        });
        Route::group([
            'middleware' => 'permission:update-affiliate-primary|update-affiliate-secondary'
        ], function () {
            Route::apiResource('affiliate', 'Api\V1\AffiliateController')->only('update');
            Route::apiResource('spouse', 'Api\V1\SpouseController')->only('update');
        });
        Route::group([
            'middleware' => 'permission:update-affiliate-secondary'
        ], function () {
            Route::apiResource('spouse', 'Api\V1\SpouseController')->only('store');
            Route::patch('affiliate/{affiliate}/fingerprint', 'Api\V1\AffiliateController@update_fingerprint');
            Route::patch('affiliate/{affiliate}/profile_picture', 'Api\V1\AffiliateController@picture_save');
            Route::patch('affiliate/{affiliate}/address', 'Api\V1\AffiliateController@update_addresses');
            Route::apiResource('personal_reference', 'Api\V1\PersonalReferenceController')->only('index', 'store', 'show', 'destroy', 'update');
        });
        Route::group([
            'middleware' => 'permission:delete-affiliate'
        ], function () {
            Route::apiResource('affiliate', 'Api\V1\AffiliateController')->only('destroy');
            Route::apiResource('spouse', 'Api\V1\SpouseController')->only('destroy');
        });

        // Préstamo
        Route::group([
            'middleware' => 'permission:show-loan|show-all-loan'
        ], function () {
            Route::apiResource('loan', 'Api\V1\LoanController')->only('index');
            Route::apiResource('loan', 'Api\V1\LoanController')->only('show');
            Route::post('loan_advance/{loan}', 'Api\V1\LoanController@destroy_advance');
            Route::get('loan/{loan}/disbursable', 'Api\V1\LoanController@get_disbursable');
            Route::get('affiliate/{affiliate}/loan','Api\V1\AffiliateController@get_loans');
            Route::get('loan/{loan}/document','Api\V1\LoanController@get_documents');
            Route::get('loan/{loan}/note','Api\V1\LoanController@get_notes');
            Route::get('loan/{loan}/flow','Api\V1\LoanController@get_flow');
            Route::get('loan/{loan}/print/plan','Api\V1\LoanController@print_plan');
            Route::post('regenerate_plan/{loan}', 'Api\V1\LoanController@regenerate_plan');
            Route::apiResource('note','Api\V1\NoteController')->only('show');
            Route::get('procedure_type/{procedure_type}/loan_destiny', 'Api\V1\ProcedureTypeController@get_loan_destinies');
            Route::get('loan/{loan}/observation','Api\V1\LoanController@get_observations');
            Route::post('loan/{loan}/observation','Api\V1\LoanController@set_observation');
            Route::patch('loan/{loan}/observation','Api\V1\LoanController@update_observation');
            Route::delete('loan/{loan}/observation','Api\V1\LoanController@unset_observation');
            Route::get('loan/{loan}/print/form', 'Api\V1\LoanController@print_form');
            Route::get('loan/{loan}/print/advance_form', 'Api\V1\LoanController@print_advance_form');
            Route::get('loan/{loan}/print/contract', 'Api\V1\LoanController@print_contract');
            Route::get('loan/{loan}/print/kardex','Api\V1\LoanController@print_kardex');      
            Route::get('loan/{loan}/print/qualification', 'Api\V1\LoanController@print_qualification');
            Route::apiResource('loan_contribution_adjust', 'Api\V1\LoanContributionAdjustController')->only('index','show','store', 'update', 'destroy');
            Route::post('loan_contribution_adjust/updateOrCreate', 'Api\V1\LoanContributionAdjustController@updateOrCreate');
            Route::post('loan_guarantee_register/updateOrCreateLoanGuaranteeRegister', 'Api\V1\LoanGuaranteeRegisterController@updateOrCreateLoanGuaranteeRegister');
            //Route::get('loan/{loan}/loan_affiliates', 'Api\V1\LoanController@get_loan_affiliates');
            Route::apiResource('loan_property', 'Api\V1\LoanPropertyController')->only('index', 'store', 'show', 'destroy', 'update');
            Route::post('loan/{loan}/validate_re_loan', 'Api\V1\LoanController@validate_re_loan');
            Route::post('loan/{affiliate_id}/validate_affiliate', 'Api\V1\LoanController@validate_affiliate');
            //Route::get('calculate_percentage', 'Api\V1\LoanController@calculate_percentage');
            Route::get('my_loans', 'Api\V1\LoanController@my_loans');
            Route::post('procedure_brother', 'Api\V1\LoanController@procedure_brother');
            Route::post('release_loan/{loan}', 'Api\V1\LoanController@release_loan');
        });
        Route::group([
            'middleware' => 'permission:create-loan'
        ], function () {
            Route::apiResource('loan', 'Api\V1\LoanController')->only('store');
            Route::get('loan/{loan}/print/documents', 'Api\V1\LoanController@print_documents');
            Route::post('affiliate/{affiliate}/loan_modality', 'Api\V1\AffiliateController@get_loan_modality');
        });
        Route::group([
            'middleware' => 'permission:update-loan'
        ], function () {
            Route::apiResource('loan', 'Api\V1\LoanController')->only('update');
            Route::patch('loan/{loan}/document/{document}', 'Api\V1\LoanController@update_document');
            Route::patch('loan/{loan}/documents', 'Api\V1\LoanController@update_documents');
            Route::patch('loan/{loan}/sismu', 'Api\V1\LoanController@update_sismu');
            Route::post('switch_guarantor_lender', 'Api\V1\LoanController@switch_guarantor_lender');
            Route::post('update_number_payment_type', 'Api\V1\LoanController@update_number_payment_type');
            Route::post('authorize_refinancing', 'Api\V1\LoanController@authorize_refinancing');
        });
        Route::group([
            'middleware' => 'permission:delete-loan'
        ], function () {
            Route::apiResource('loan', 'Api\V1\LoanController')->only('destroy');
        });
        Route::group([
            'middleware' => 'permission:update-refinancing-balance'
        ], function () {
            Route::patch('loan/{loan}/update_refinancing_balance','Api\V1\LoanController@update_balance_refinancing');
        });
        // payments
        Route::group([
            'middleware' => 'permission:show-payment-loan|show-all-payment-loan'

        ], function () {
            Route::get('loan/{loan}/payment','Api\V1\LoanController@get_payments');
            Route::get('loan_payment/{loan_payment}/print/loan_payment','Api\V1\LoanPaymentController@print_loan_payment');
            Route::apiResource('loan_payment', 'Api\V1\LoanPaymentController')->only('index', 'show');
            Route::get('loan_payment/{loan_payment}/state', 'Api\V1\LoanPaymentController@get_state');
            Route::patch('loan_payment/{id}/reactivate','Api\V1\LoanPaymentController@reactivate');
            Route::get('loan_payment/{loan_payment}/flow','Api\V1\LoanPaymentController@get_flow');
            Route::get('kardex_loan_payment','Api\V1\LoanPaymentController@indexKardex');
            Route::get('history_loan_payment','Api\V1\LoanPaymentController@payment_history');
            Route::post('payments_per_period','Api\V1\LoanPaymentController@payments_per_period');
            Route::post('command_senasir_save_payment', 'Api\V1\LoanPaymentController@download');
        });
        Route::group([
            'middleware' => 'permission:create-payment-loan'
        ], function () {
            Route::get('get_duplicity_account', 'Api\V1\LoanPaymentController@get_duplicity_account');
            Route::get('get_amount_payment', 'Api\V1\LoanController@get_amount_payment');
            Route::patch('loan/{loan}/payment','Api\V1\LoanController@get_next_payment');
            Route::post('loan/{loan}/payment','Api\V1\LoanController@set_payment');
            Route::post('loan_payment/importation_command_senasir', 'Api\V1\LoanPaymentController@importation_command_senasir');//importacion de pagos
            Route::post('loan_payment/importation_pending_command_senasir', 'Api\V1\LoanPaymentController@importation_pending_command_senasir');//importacion de pendientes de pagos
            Route::post('loan_payment/upload_file_payment', 'Api\V1\ImportationController@upload_file_payment'); 
            Route::post('loan_payment/import_progress_bar', 'Api\V1\ImportationController@import_progress_bar');
        });
        Route::group([
            'middleware' => 'permission:update-payment-loan'
        ], function () {
            Route::apiResource('loan_payment', 'Api\V1\LoanPaymentController')->only('update');
        });
        Route::group([
            'middleware' => 'permission:delete-payment-loan'
        ], function () {
            Route::apiResource('loan_payment', 'Api\V1\LoanPaymentController')->only('destroy');
            Route::patch('bulk_destroy', 'Api\V1\LoanPaymentController@bulk_destroy');
            Route::delete('delete_last_payment/{loan_payment}/payment', 'Api\V1\LoanPaymentController@delete_last_record_payment');  
        });
        //Registro de pago por tesoreria
        Route::group([
            'middleware' => 'permission:show-payment'
        ], function () {
            Route::apiResource('voucher', 'Api\V1\VoucherController')->only('index', 'show');
            Route::get('voucher/{voucher}/print/voucher','Api\V1\VoucherController@print_voucher');
            Route::get('loan_payment/{loan_payment}/voucher', 'Api\V1\LoanPaymentController@get_voucher');
        });
        Route::group([
            'middleware' => 'permission:create-payment'
        ], function () {
            Route::post('loan_payment/{loan_payment}/voucher','Api\V1\LoanPaymentController@set_voucher');
        });
        Route::group([
            'middleware' => 'permission:update-payment'
        ], function () {
            Route::apiResource('voucher','Api\V1\VoucherController')->only('update');
        });
        Route::group([
            'middleware' => 'permission:delete-payment'
        ], function () {
            Route::apiResource('voucher', 'Api\V1\VoucherController')->only('destroy');
            Route::patch('voucher/{voucher_id}/delete','Api\V1\VoucherController@delete_voucher_payment');              
        });
        // Voucher Tesoreria
        Route::group([
            'middleware' => 'permission:show-list-voucher'
        ], function () {
            Route::get('index_voucher', 'Api\V1\VoucherController@index_voucher');
            });

        // Dirección
        Route::group([
            'middleware' => 'permission:create-address'
        ], function () {
            Route::apiResource('address', 'Api\V1\AddressController')->only('store');
        });
        Route::group([
            'middleware' => 'permission:update-address'
        ], function () {
            Route::apiResource('address', 'Api\V1\AddressController')->only('update');
        });
        Route::group([
            'middleware' => 'permission:delete-address'
        ], function () {
            Route::apiResource('address', 'Api\V1\AddressController')->only('destroy');
        });

        // Notas
        Route::group([
            'middleware' => 'permission:update-note'
        ], function () {
            Route::apiResource('note', 'Api\V1\NoteController')->only('update');
        });
        Route::group([
            'middleware' => 'permission:delete-note'
        ], function () {
            Route::apiResource('note', 'Api\V1\NoteController')->only('destroy');
        });

        // Ajustes
        Route::group([
            'middleware' => 'permission:update-setting'
        ], function () {
            Route::patch('procedure_type/{procedure_type}/flow', 'Api\V1\ProcedureTypeController@set_flow');
            Route::patch('procedure_type/{procedure_type}/loan_destiny', 'Api\V1\ProcedureTypeController@set_loan_destinies');
        });

        // Administrador
        Route::group([
            'middleware' => 'permission:show-role'
        ], function () {
            Route::get('user/{user}/permission', 'Api\V1\UserController@get_permissions');
            Route::get('role/{role}/permission', 'Api\V1\RoleController@get_permissions');
        });
        Route::group([
            'middleware' => 'permission:update-role'
        ], function () {
            Route::patch('user/{user}/role', 'Api\V1\UserController@set_roles');
            Route::patch('role/{role}/permission', 'Api\V1\RoleController@set_permissions');
        });
        Route::group([
            'middleware' => 'role:TE-admin'
        ], function () {
            // Ldap
            Route::apiResource('user', 'Api\V1\UserController')->only('store', 'destroy');;
            if (env("LDAP_AUTHENTICATION")) {
                Route::get('user/ldap/unregistered', 'Api\V1\UserController@unregistered_users');
                Route::get('user/ldap/sync', 'Api\V1\UserController@synchronize_users');
            }
        });
    });
});
