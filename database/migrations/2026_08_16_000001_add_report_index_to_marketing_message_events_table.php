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
 *
 * WHY `down()` TAKES THE FOREIGN KEY APART FIRST
 * ----------------------------------------------
 * InnoDB requires an index whose leading column is the referencing column, and
 * it keeps exactly one: adding `(message_id, type)` makes the index MySQL
 * created for `marketing_message_events_message_id_foreign` redundant, and
 * MySQL **drops it in the same statement**. Measured on MySQL 8.0.46, not
 * assumed — after `up()` the only index left on `message_id` is this one.
 *
 * A plain `dropIndex` in `down()` therefore takes the last index the foreign
 * key has, and InnoDB refuses:
 *
 *     SQLSTATE[HY000] 1553 Cannot drop index 'mme_message_id_type_index':
 *     needed in a foreign key constraint
 *
 * SQLite has neither the requirement nor the refusal, which is why the whole
 * SQLite matrix stayed green while every MySQL run died in the rollback that
 * `loadMigrationsFrom()` performs after each test.
 *
 * So the constraint is released, the index dropped, the constraint put back —
 * and MySQL recreates its own index for it, which is exactly the state before
 * `up()`. Leaving the index standing instead was the other candidate and is
 * worse: a `down()` that does not undo its `up()` is not a rollback.
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
            $table->dropForeign(['message_id']);
            $table->dropIndex('mme_message_id_type_index');
            $table->foreign('message_id')->references('id')->on('marketing_messages')->cascadeOnDelete();
        });
    }
};
