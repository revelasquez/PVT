<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePersonalReferenceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('personal_references', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_identity_card_id')->nullable();
            $table->foreign('city_identity_card_id')->references('id')->on('cities');
            $table->foreign('city_birth_id')->references('id')->on('cities');
            $table->unsignedBigInteger('city_birth_id')->nullable();
            $table->string('identity_card')->nullable();
            $table->string('last_name')->nullable();
            $table->string('mothers_last_name')->nullable();
            $table->string('first_name');
            $table->string('second_name')->nullable();
            $table->string('surname_husband')->nullable();
            $table->enum('civil_status', ['C','D','S','V'])->nullable();// estado civil
            $table->enum('gender', ['F','M'])->nullable();
            $table->string('phone_number')->nullable();
            $table->string('cell_phone_number')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('personal_references');
    }
}
