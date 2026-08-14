<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index the campaign report asks for, and only that one.
 *
 * Every read the report makes on this table has the same shape: the messages of
 * one campaign, narrowed to one kind of event — "who clicked", "who opened",
 * "who unsubscribed". Until now the table offered `message_id` (the foreign
 * key) and `type` (standalone) separately, so a campaign with fifty thousand
 * recipients and half a million events made the engine choose one of them and
 * then filter the rest by hand.
 *
 * `(message_id, type)` is that pair in the order the queries use it: the
 * message set comes first (it is the sub-select the whole report hangs off, see
 * CampaignStats::metrics()), the type second.
 *
 * Three indexes were considered and NOT added:
 *
 *  - `(message_id, type, machine)`. The human/machine split reads open events
 *    for the fifty messages on one page. After `(message_id, type)` the rows
 *    left per message are the handful of times that one mail was opened, and a
 *    third column to filter a handful of rows buys nothing while costing a
 *    write on every pixel fetch.
 *  - `(type, machine)`. No query in the addon asks for a kind of event across
 *    all campaigns; every one of them is already inside a campaign.
 *  - `(campaign_handle, status)` on `marketing_messages`, which is literally
 *    what the delivery tab filters on. Both columns are `varchar(255)`, so
 *    under utf8mb4 the key would be 2040 bytes — over half of InnoDB's 3072
 *    and refused by tests/Unit/IndexKeyLengthTest.php, which asserts headroom
 *    on purpose. `campaign_handle` alone already narrows to one campaign, and
 *    a status filter inside one campaign is a scan of rows that are on the
 *    page anyway.
 *
 * The standalone `type` index stays. A composite cannot serve a query that
 * does not name its leading column, so dropping it would trade one problem for
 * another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_message_events', function (Blueprint $table) {
            $table->index(['message_id', 'type'], 'mme_message_id_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_message_events', function (Blueprint $table) {
            $table->dropIndex('mme_message_id_type_index');
        });
    }
};
