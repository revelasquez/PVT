<?php

use Illuminate\Database\Seeder;

class ProcedureDocumentLoanGuarantors extends Seeder
{
    public function run()
    {
        try {
            DB::beginTransaction();

            $un_garante = DB::table('procedure_documents')->insertGetId([
                'name' => 'Última boleta de pago del garante cargada en la herramienta informática PVT',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $garante_uno = DB::table('procedure_documents')->insertGetId([
                'name' => 'Última boleta de pago del garante uno cargada en la herramienta informática PVT',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $garante_dos = DB::table('procedure_documents')->insertGetId([
                'name' => 'Última boleta de pago del garante dos cargado en la herramienta informática PVT',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $modalities = DB::table('loan_modality_parameters')->where('loan_procedure_id', 3)->where('guarantors', '>', 0)->get();
            foreach ($modalities as $modality) {
                if ($modality->guarantors == 1) {
                    DB::table('procedure_requirements')->insert([
                        'procedure_modality_id' => $modality->procedure_modality_id,
                        'procedure_document_id' => $un_garante,
                        'number' => 4,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } else if ($modality->guarantors == 2) {
                    DB::table('procedure_requirements')->insert([
                        'procedure_modality_id' => $modality->procedure_modality_id,
                        'procedure_document_id' => $garante_uno,
                        'number' => 4,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    DB::table('procedure_requirements')->insert([
                        'procedure_modality_id' => $modality->procedure_modality_id,
                        'procedure_document_id' => $garante_dos,
                        'number' => 6,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}