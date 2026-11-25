<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id')->unique(); // BioTime department_id
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('department_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name')->collation('utf8mb4_bin')->unique();
            $table->timestamps();
        });

        Schema::create('department_manager', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('managers')->cascadeOnDelete();
            $table->primary(['manager_id', 'department_id']);
        });

        Schema::table('managers', function (Blueprint $table) {
            $table->tinyInteger('type')->default(0)->after('employee_id'); // 0: Amir, 1: İK
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('emp_code')->constrained()->nullOnDelete();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('employee_id');
            $table->index(['department_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'date']);
            $table->dropColumn('department_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('managers', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::dropIfExists('department_manager');
        Schema::dropIfExists('department_names');
        Schema::dropIfExists('departments');
    }
};
