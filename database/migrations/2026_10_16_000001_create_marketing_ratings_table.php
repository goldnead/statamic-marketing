<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Ein-Klick-Bewertung einer Ausgabe: Gut, In Ordnung, Schlecht, dazu
 * optional ein Satz, warum.
 *
 * Eine Zeile je Zustellung, nicht je Klick. `message_id` ist deshalb eindeutig:
 * wer zweimal klickt, ueberschreibt seine Wahl, und die Begruendung bleibt an
 * derselben Stimme haengen. Die Zustellung traegt schon die Kampagne und die
 * Adresse, mehr braucht die Auswertung nicht.
 *
 * Bewusst nur das: Kampagne, Zustellung, Wahl, Text, Zeit. Keine IP, kein
 * User-Agent. Wird die Zustellung geloescht, geht die Stimme mit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            $table->foreignId('message_id')->unique()->constrained('marketing_messages')->cascadeOnDelete();
            $table->string('campaign_handle')->index();
            $table->string('choice', 16);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_ratings');
    }
};
