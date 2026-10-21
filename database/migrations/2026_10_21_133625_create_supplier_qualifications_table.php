<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ingredient_id');
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            $table->string('supplier_reference')->default('');
            $table->string('status', 24);
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'ingredient_id', 'supplier_id', 'supplier_reference'], 'supplier_qualifications_identity_unique');
            $table->index(['workspace_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_qualifications');
    }
};
