<?php

namespace Goldnead\Marketing\Exceptions;

use RuntimeException;

/**
 * Die Bausteine, die die Form geschickt hat, liessen sich nicht verarbeiten.
 *
 * Der Fall, der das noetig gemacht hat, ist kein Angriff und kein Fehler des
 * Benutzers: jemand waehlt ein Bild, jemand anderes loescht es aus dem
 * Asset-Container, und beim naechsten Speichern wirft der Asset-Feldtyp
 * `Asset [x] not found` mitten aus `process()` heraus. Ungefangen ist das eine
 * 500er-Seite — beim Speichern **und** bei jedem Tastendruck in der Vorschau,
 * die dieselbe Kette faehrt.
 *
 * Also wird es hier eingepackt und an den beiden Stellen behandelt, an denen
 * es einen Ort hat: als Fehler am Feld `blocks`, wenn gespeichert wird, und
 * als Fehlertext neben der Vorschau, wenn getippt wird. Ein Layout geht dabei
 * nie verloren — abgelehnt wird der Schreibvorgang, nicht der Inhalt des
 * Editors.
 */
class BlockValuesRejected extends RuntimeException {}
