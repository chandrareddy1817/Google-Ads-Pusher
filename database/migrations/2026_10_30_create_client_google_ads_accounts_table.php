<?php
// database/migrations/xxxx_xx_xx_create_client_google_ads_accounts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_google_ads_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('refresh_token'); // Will be encrypted
            $table->string('customer_id')->nullable(); // The new Ad Account ID
            $table->string('manager_customer_id')->nullable(); // Your MCC ID
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_google_ads_accounts');
    }
};