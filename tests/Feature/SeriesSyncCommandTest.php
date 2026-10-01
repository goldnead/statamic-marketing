<?php

use Goldnead\Marketing\Series\SeriesSync;

/**
 * Der Serien-Befehl, wenn das Termine-Addon fehlt: kein Absturz, kein Lauf,
 * ein Hinweis — und ein Erfolg-Exitcode, weil nichts zu tun nichts Fehler ist.
 *
 * Die Suite migriert die Termine-Tabellen für jeden Test (welcher Test in
 * einem Prozess zuerst läuft, darf nicht entscheiden, ob es sie gibt). Hier
 * wird `SeriesSync::available()` deshalb auf false festgelegt: die Antwort,
 * die ein installiertes, aber nicht migriertes Sibling bekommt.
 */
it('meldet dem Serien-Befehl das fehlende Termine-Addon, statt zu laufen', function (): void {
    (new ReflectionProperty(SeriesSync::class, 'available'))->setValue(null, false);

    $this->artisan('marketing:series-sync')
        ->expectsOutput(__('marketing::series.not_installed'))
        ->assertSuccessful();
});
