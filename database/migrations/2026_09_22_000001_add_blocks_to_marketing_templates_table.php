<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Layout kann ab hier auch ein Baukasten sein.
 *
 * Zwei Spalten, kein zweiter Ausgang. `type` sagt, womit das Layout
 * geschrieben wurde, `blocks` haelt die Bloecke — und beim Speichern werden
 * die Bloecke zu Mail-HTML uebersetzt und landen in derselben `html`-Spalte,
 * die es schon immer gab. Renderer, Versand, Snapshot und Archiv lesen weiter
 * genau einen HTML-String und erfahren nie, dass es Bloecke gibt.
 *
 * Bestandszeilen bekommen `html` und bleiben Zeichen fuer Zeichen unveraendert:
 * ein von Hand geschriebenes Layout wird nicht konvertiert, weil es keine
 * Rueckuebersetzung gibt und jede Konvertierung genau einmal schiefgehen muss.
 *
 * DIE FALLE, und sie ist der Grund fuer die Spaltenkommentare: fuer ein
 * Block-Layout ist `html` ein **abgeleiteter** Wert. Wer die Spalte von Hand
 * aendert, verliert die Aenderung beim naechsten Speichern, weil der
 * Uebersetzer sie aus `blocks` neu schreibt. Wer das HTML behalten will, legt
 * ein Layout vom Typ `html` an.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Je Spalte einzeln geprüft und nicht einmal für beide. Auf SQLite
        // sind das zwei `alter table`-Anweisungen: bricht der Lauf zwischen
        // ihnen ab, steht `type` und `blocks` fehlt, und ein Wächter, der nur
        // die erste Spalte kennt, überspringt den Rest für immer.
        Schema::table('marketing_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_templates', 'type')) {
                $table->string('type', 20)
                    ->default('html')
                    ->after('name')
                    ->comment('html = handgeschriebenes Mail-HTML, blocks = Baukasten. Nach dem Anlegen fest.');
            }

            // longText und nicht json: die Spalte wird nie gefiltert oder
            // sortiert, nur ganz gelesen und ganz geschrieben, und longText
            // verhaelt sich auf SQLite und MySQL 8 identisch. Eine
            // JSON-Spalte kaufte hier nur Regeln ein, die niemand braucht.
            if (! Schema::hasColumn('marketing_templates', 'blocks')) {
                $table->longText('blocks')
                    ->nullable()
                    ->after('html')
                    ->comment('Nur bei type=blocks. Quelle der Wahrheit — html wird daraus erzeugt.');
            }
        });
    }

    public function down(): void
    {
        $spalten = array_values(array_filter(
            ['type', 'blocks'],
            fn (string $spalte) => Schema::hasColumn('marketing_templates', $spalte),
        ));

        if ($spalten === []) {
            return;
        }

        Schema::table('marketing_templates', function (Blueprint $table) use ($spalten) {
            $table->dropColumn($spalten);
        });
    }
};
