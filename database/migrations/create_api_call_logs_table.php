<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('api-call-logger.connection');
    }

    public function up(): void
    {
        $table = config('api-call-logger.table', 'api_call_logs');

        if (Schema::connection($this->getConnection())->hasTable($table)) {
            return;
        }

        Schema::connection($this->getConnection())->create($table, function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10)->default('outbound');   // outbound | inbound
            $table->string('provider', 60);                          // vtpass, gobeller, paystack, ...
            $table->string('context', 120)->nullable();              // human operation name: 'buy-airtime'
            $table->string('method', 10);
            $table->string('url', 2048);                             // full URL without query string
            $table->string('host')->nullable();
            $table->string('path', 1024)->nullable();
            // Payload columns are JSON; with encryption on they hold an encrypted JSON string.
            $table->json('query')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable(); // null = connection failure
            $table->boolean('success')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();
            $table->text('error')->nullable();
            $table->string('tenant')->nullable();                    // institution / organisation code
            $table->string('user_id', 64)->nullable();
            $table->nullableMorphs('loggable');                      // related local record (transaction, order, ...)
            $table->string('correlation_id', 64)->nullable();        // groups every call of one request/job (uuid or trace id)
            $table->string('idempotency_key')->nullable();           // Idempotency-Key header sent to the provider
            $table->unsignedSmallInteger('attempt')->default(1);     // Http::retry() attempt number
            $table->unsignedBigInteger('retry_of_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['success', 'created_at']);
            $table->index(['context', 'created_at']);
            $table->index('tenant');
            $table->index('correlation_id');
            $table->index('idempotency_key');
            $table->index('retry_of_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(config('api-call-logger.table', 'api_call_logs'));
    }
};
