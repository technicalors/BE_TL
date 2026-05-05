<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKpiHoanThanhKeHoachTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('kpi_hoan_thanh_ke_hoach', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('ngay')->unique();
            $table->decimal('ti_le', 5, 2)->default(0);
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
        Schema::dropIfExists('kpi_hoan_thanh_ke_hoach');
    }
}
