<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warranty: a service's warranty in months (blank = none stated), the
     * backjob types the owner defines and which ones a service covers, and
     * the backjobs the team logs. A booking item keeps the months it was
     * booked with, so a later edit never changes old coverage.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable();
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable();
        });

        Schema::create('warranty_backjob_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_backjob_type', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backjob_type_id')->constrained('warranty_backjob_types')->cascadeOnDelete();
            $table->primary(['service_id', 'backjob_type_id']);
        });

        Schema::create('backjobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('backjob_type_id')->nullable()->constrained('warranty_backjob_types')->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('noted_on');
            $table->text('note')->nullable();
            // open or done
            $table->string('status', 10)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backjobs');
        Schema::dropIfExists('service_backjob_type');
        Schema::dropIfExists('warranty_backjob_types');

        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });
    }
};
