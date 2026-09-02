<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequences: a mail series with a trigger, read and edited as a list.
 *
 * Two tables and both are thin on purpose. A sequence is a *view plus a
 * generator*: what runs is the automation it writes into
 * `goldnead/statamic-automations` (`automation_id`), and the only queue in the
 * house stays `automation_scheduled_jobs`. Nothing here is a second scheduler.
 *
 * `handle` is unique across brands, like every other marketing handle: the
 * automation the sequence writes is named after it.
 *
 * `brand_id` is nullable in the schema and stamped by HasBrand on create, the
 * shape every other marketing table has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            // The automations trigger handle this series starts on, e.g.
            // `payments.paid`, `funnels.completed`, `leadhub.lead_tag_added`.
            $table->string('trigger');
            $table->json('trigger_config')->nullable();
            // Where consent comes from. A template carries no list of its own,
            // and marketing.send_email refuses a template send without one.
            $table->string('list_handle')->nullable()->index();
            $table->boolean('enabled')->default(false);
            // The automation this sequence manages. Null until automations is
            // installed and the sequence has been saved once with it present.
            $table->unsignedBigInteger('automation_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('marketing_sequences')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // An `et_templates` slug.
            $table->string('template');
            // Null means "the subject the template carries".
            $table->string('subject_override')->nullable();
            // The gap before this mail, measured from the previous step (or
            // the trigger for the first one). 0 = straight away.
            $table->unsignedInteger('delay_amount')->default(0);
            $table->string('delay_unit', 16)->default('days');
            $table->timestamps();

            $table->index(['sequence_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_sequence_steps');
        Schema::dropIfExists('marketing_sequences');
    }
};
