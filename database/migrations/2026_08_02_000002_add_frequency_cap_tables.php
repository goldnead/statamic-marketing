<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frequency caps: the classification, the log the cap counts, and the two
 * columns a deferred message needs.
 *
 * Nothing here changes behaviour on its own. `marketing.frequency_cap.enabled`
 * is false out of the box, so a `composer update` installs the schema and sends
 * exactly as before — an addon that started holding a running install's mail
 * back because a package moved would be the worse failure by a distance.
 *
 * `mail_class` defaults to `marketing` on every existing campaign, which is
 * what they are. A digest or a reminder is opted *out* of the cap by naming it,
 * never by omission — see MailClass.
 *
 * The log is its own table rather than a column on `marketing_messages`,
 * because the cap counts what reaches a person and `marketing_messages` only
 * knows about this addon's campaigns. A sibling addon sending a reminder writes
 * here too, through the FrequencyCap contract, without owning a campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            // Narrow: it holds one of four fixed words. Wide enough for a fifth
            // if the contract ever grows one, and it is half of no index, so
            // the width costs nothing but honesty about the column's range.
            $table->string('mail_class', 20)->default('marketing')->after('status');
        });

        Schema::table('marketing_messages', function (Blueprint $table) {
            // How often the cap has already pushed this message back. Bounded
            // by config; when it runs out the message is discarded and logged
            // rather than deferred forever, because a message that is retried
            // indefinitely is a queue that never drains and a campaign that
            // never finishes.
            $table->unsignedSmallInteger('cap_deferrals')->default(0)->after('status');

            // When the next attempt is due. Not the queue's business — the
            // queue has the delay — but the control panel's: without it,
            // "pending" is the same word for a message waiting its turn and a
            // message that has been held back three times.
            $table->timestamp('cap_deferred_until')->nullable()->after('cap_deferrals');
        });

        Schema::create('marketing_mail_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            $table->string('email_normalized');
            $table->string('mail_class', 20);
            $table->string('reference')->nullable();
            $table->timestamp('sent_at');

            // The one access path the cap has: this brand, this address, this
            // class, inside this window. Leading with brand and address makes
            // it usable for the control panel's per-contact history as well,
            // which is the same prefix without the class.
            $table->index(
                ['brand_id', 'email_normalized', 'mail_class', 'sent_at'],
                'marketing_mail_log_cap_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_mail_log');

        Schema::table('marketing_messages', function (Blueprint $table) {
            $table->dropColumn(['cap_deferrals', 'cap_deferred_until']);
        });

        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn('mail_class');
        });
    }
};
