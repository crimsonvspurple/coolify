<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('slack_notification_settings', 'deployment_commit_details_slack_notifications')) {
            Schema::table('slack_notification_settings', function (Blueprint $table) {
                $table->boolean('deployment_commit_details_slack_notifications')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('slack_notification_settings', 'deployment_commit_details_slack_notifications')) {
            Schema::table('slack_notification_settings', function (Blueprint $table) {
                $table->dropColumn('deployment_commit_details_slack_notifications');
            });
        }
    }
};
