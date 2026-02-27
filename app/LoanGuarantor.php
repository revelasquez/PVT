<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use Util;

class LoanGuarantor extends Model
{
    use Traits\EloquentGetTableNameTrait;
    use Traits\RelationshipsTrait;
    use SoftDeletes;

    protected $fillable = [
        'loan_id',
        'affiliate_id',
        'degree_id',
        'unit_id',
        'category_id',
        'type_affiliate',
        'unit_police_description',
        'affiliate_state_id',
        'identity_card',
        'city_identity_card_id',
        'city_birth_id',
        'registration',
        'last_name',
        'mothers_last_name',
        'first_name',
        'second_name',
        'surname_husband',
        'gender',
        'civil_status',
        'phone_number',
        'cell_phone_number',
        'address_id',
        'pension_entity_id',
        'payment_percentage',
        'payable_liquid_calculated',
        'bonus_calculated',
        'quota_previous',
        'quota_treat',
        'indebtedness_calculated',
        'indebtedness_calculated_previous',
        'liquid_qualification_calculated',
        'contributionable_ids',
        'contributionable_type',
        'type',
        'eval_quota'
      ];

  public function Affiliate()
  {
    $affiliate = $this->belongsTo(Affiliate::class);
    return $affiliate;  
  }

  public function Address()
  {
    return $this->hasOne(Address::class, 'id', 'address_id');
  }

  public function getFullNameAttribute()
  {
    return rtrim($this->first_name.' '.$this->second_name.' '.$this->last_name.' '.$this->mothers_last_name.' '.$this->surname_husband);
  }

  public function getIdentityCardExtAttribute()
  {
    $data = $this->identity_card;
    if ($this->city_identity_card && $this->city_identity_card != 'NINGUNO'){
        $data .= ' ' . $this->city_identity_card->first_shortened;
    } 
    return rtrim($data);
  }

  public function affiliate_state()
  {
    return $this->belongsTo(AffiliateState::class);
  }

  public function ballots()
  {        
      $contributions = $this->contributionable_ids;
      $contributions_type = $this->contributionable_type;
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
      if($contributions_type == "contributions")
      { 
        $contribution_type = "contributions";
        foreach($ballots_ids as $is_ballot_id)
        {
          if(Contribution::find($is_ballot_id))
            $ballots->push(Contribution::find($is_ballot_id));
            if(LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id',$this->loan_id)->first())
            $adjusts->push(LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id',$this->loan_id)->first());
        }
        $count_records = count($ballots);               
        foreach($ballots as $ballot)
        {
          foreach($adjusts as $adjust)
          {
            if($ballot->id == $adjust->adjustable_id)
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
                                'average_payable_liquid' => $sum_payable_liquid/$count_records,
                                'average_mount_adjust' => $sum_mount_adjust/$count_records,
                                'average_border_bonus' => $sum_border_bonus/$count_records,
                                'average_position_bonus' => $sum_position_bonus/$count_records,
                                'average_east_bonus' => $sum_east_bonus/$count_records,
                                'average_public_security_bonus' => $sum_public_security_bonus/$count_records,                       
                            ]);
      }
      if($contributions_type == "aid_contributions")
      {
        $contribution_type = "aid_contributions";
        foreach($ballots_ids as $is_ballot_id)
        {
          if(AidContribution::find($is_ballot_id))
            $ballots->push(AidContribution::find($is_ballot_id));
          if(LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id',$this->id)->first())
            $adjusts->push(LoanContributionAdjust::where('adjustable_id', $is_ballot_id)->where('loan_id',$this->id)->first());
        }
        $count_records = count($ballots);                
        foreach($ballots as $ballot)
        {
          foreach($adjusts as $adjust)
          {
            if($ballot->id == $adjust->adjustable_id)
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
                        'average_payable_liquid' => $sum_payable_liquid/$count_records,
                        'average_mount_adjust' => $sum_mount_adjust/$count_records,
                        'average_dignity_rent' => $sum_dignity_rent/$count_records,
                    ]);                     
      }
      if($contributions_type == "loan_contribution_adjusts")
      {
        $contribution_type = "loan_contribution_adjusts";
        $liquid_ids= LoanContributionAdjust::where('loan_id',$this->id)->where('type_adjust',"liquid")->get()->pluck('id');
        $adjust_ids= LoanContributionAdjust::where('loan_id',$this->id)->where('type_adjust',"adjust")->get()->pluck('id');
        foreach($liquid_ids as $liquid_id)
        {
          $ballots->push(LoanContributionAdjust::find($liquid_id));
        }
        foreach($adjust_ids as $adjust_id)
        {
          $adjusts->push( LoanContributionAdjust::find($adjust_id));
        } 
        $count_records = count($ballots);      
        foreach($ballots as $ballot)
        {
          foreach($adjusts as $adjust)
          {
            if($ballot->period_date == $adjust->period_date)
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
                        'average_payable_liquid' => $sum_payable_liquid/$count_records,
                        'average_mount_adjust' => $sum_mount_adjust/$count_records,
                    ]);         
      }       
      $data = [
            'contribution_type' =>$contribution_type,
            'average_ballot_adjust'=> $average_ballot_adjust,
            'ballot_adjusts'=> $ballot_adjust->sortBy('month_year')->values()->toArray(),
        ];
    return (object)$data;
  }

  public function city_birth()
  {
    return $this->belongsTo(City::class, 'city_birth_id', 'id');
  }

  public function city_identity_card()
  {
    return $this->belongsTo(City::class,'city_identity_card_id', 'id');
  }

  public function getCivilStatusGenderAttribute()
  {
    return Util::get_civil_status($this->civil_status, $this->gender);
  }

  public function getInitialsAttribute(){
    return 
        mb_substr($this->first_name, 0, 1, 'UTF-8') .
        mb_substr($this->second_name, 0, 1, 'UTF-8') .
        mb_substr($this->last_name, 0, 1, 'UTF-8') .
        mb_substr($this->mothers_last_name, 0, 1, 'UTF-8') .
        mb_substr($this->surname_husband, 0, 1, 'UTF-8');
  }

  public function loan()
  {
    return $this->belongsTo(Loan::class, 'loan_id', 'id');
  }

  public function active_guarantees()
  {
    // garantias activas en general
    $loan_guarantees_pvt = $this->affiliate->active_guarantees();
    $loan_guarantees_sismu = $this->affiliate->active_guarantees_sismu();
    $data_loan = collect();
    $loan_mixed = [];
    //garantias con las que fue evaluado en el prestamo
    /*$query = "SELECT * from loan_guarantee_registers where loan_id = '$this->loan_id' and affiliate_id = $this->affiliate_id";
    $loan_guarantee_registers = DB::select($query);*/
    $loan_guarantee_registers = LoanGuaranteeRegister::where('loan_id',$this->loan_id)->where('affiliate_id',$this->affiliate_id)->get();
    $loan_guarantees = collect();
    foreach($loan_guarantee_registers as $loan_guarantee_register)
    {
      if($loan_guarantee_register->database_name == "SISMU")
      {
        $count = "SELECT count(*) as cant from  Prestamos p where p.IdPrestamo = $loan_guarantee_register->guarantable_id";
          $loan_sismu = DB::connection('sqlsrv')->select($count);
          if($loan_sismu[0]->cant > 0)
          {
            $query = "SELECT trim(p2.PadNombres) as nombres, trim(p2.PadPaterno) as paterno, trim(p2.PadMaterno) as materno, trim(p2.PadApellidoCasada) as apecasada, ep.PresEstDsc as state
            from Prestamos p
            join Padron p2 on p.IdPadron = p2.IdPadron
            join EstadoPrestamo ep on ep.PresEstPtmo = p.PresEstPtmo
            where p.IdPrestamo = $loan_guarantee_register->guarantable_id";
            $loan_sismu = DB::connection('sqlsrv')->select($query);
            foreach($loan_sismu as $sismu)
            {
              $name = $sismu->nombres.' '.$sismu->paterno.' '.$sismu->materno.' '.$sismu->apecasada;
              $state = $sismu->state;
            }
            $loan = [
              'id' => $loan_guarantee_register->guarantable_id,
              'code' => $loan_guarantee_register->loan_code_guarantee,
              'name' => $name,
              'type' => $loan_guarantee_register->database_name,
              'state' => $state,
              'evaluate' => true
            ];
            $loan_guarantees->push($loan);
          }
      }
      elseif($loan_guarantee_register->database_name == "PVT")
      {
        if(Loan::find($loan_guarantee_register->guarantable_id))
          if(Loan::where('code', $loan_guarantee_register->loan_code_guarantee)->first())
          {
            $guarantee = Loan::where('code', $loan_guarantee_register->loan_code_guarantee)->first();
            $name = $guarantee->borrower->first()->full_name;
            $state = $guarantee->state->name;
            $loan = [
              'id' => $loan_guarantee_register->guarantable_id,
              'code' => $loan_guarantee_register->loan_code_guarantee,
              'name' => $name,
              'type' => $loan_guarantee_register->database_name,
              'state' => $state,
              'evaluate' => true
            ];
            $loan_guarantees->push($loan);
          }
      }
    }
    foreach($loan_guarantees_pvt as $guarantee_pvt)
    {
      if($this->loan_id != $guarantee_pvt->id)
      {
        $loan = [
          'id' => $guarantee_pvt->id,
          'code' => $guarantee_pvt->code,
          'name' => $guarantee_pvt->borrower->first()->full_name,
          'type' => "PVT",
          'state' => $guarantee_pvt->state->name,
          'evaluate' => false
        ];
        $sw = false;
        foreach($loan_guarantees as $loan_guarantee)
        {
          if($loan_guarantee['id'] == $loan['id'])
          {
            $sw = true;
          }
        }
        if(!$sw)
        {
          $loan_guarantees->push($loan);
        }
      }
    }
    foreach($loan_guarantees_sismu as $guarantee_sismu)
    {
      $loan = [
        'id' => intval($guarantee_sismu->IdPrestamo),
        'code' => $guarantee_sismu->PresNumero,
        'name' => $guarantee_sismu->PadNombres." ".$guarantee_sismu->PadPaterno." ".$guarantee_sismu->PadMaterno,
        'type' => "SISMU",
        'state' => "VIGENTE",
        'evaluate' => false
      ];
      $sw = false;
      foreach($loan_guarantees as $loan_guarantee)
      {
        if($loan_guarantee['id'] == $loan['id'])
        {
          $sw = true;
        }
      }
      if(!$sw)
      {
        $loan_guarantees->push($loan);
      }
    }
    return $loan_guarantees;
  }
  public function getTitleAttribute()
    {
      $data = "";
      if ($this->degree) $data = $this->degree->shortened;;
      return $data;
    }

    public function degree()
    {
        return $this->belongsTo(Degree::class);
    }
    public function getFullUnitAttribute()
    {
        $data = "";
        if ($this->unit) $data .= ' ' . $this->unit->district.' - '.$this->unit->name.' ('.$this->unit->shortened.')';
        return $data;
    }

    public function unit()
    {
      return $this->belongsTo(Unit::class, 'unit_id', 'id');
    }
    public function getCategoryAttribute()
    {
      $category = null;
      if($this->category_id){
        $category =  Category::whereId($this->category_id)->first();
      }
      return $category;
    }

    public function pension_entity()
    {
      return $this->belongsTo(PensionEntity::class);
    }
}
