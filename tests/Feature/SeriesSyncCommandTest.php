<?php

/**
 * Der Serien-Befehl, wenn das Termine-Addon fehlt: kein Absturz, kein Lauf,
 * ein Hinweis — und ein Erfolg-Exitcode, weil nichts zu tun nichts Fehler ist.
 *
 * Die Termine-Tabellen werden hier bewusst nicht migriert; die Klassen des
 * Siblings sind über composer da, die Meldung kommt also aus dem zweiten Arm
 * von `SeriesSync::available()` (installiert, aber nicht migriert).
 */
it('meldet dem Serien-Befehl das fehlende Termine-Addon, statt zu laufen', function (): void {
    $this->artisan('marketing:series-sync')
        ->expectsOutput(__('marketing::series.not_installed'))
        ->assertSuccessful();
});
