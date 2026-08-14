<?php

namespace Goldnead\Marketing\Events;

use Goldnead\Marketing\Support\MachineOpen;

/**
 * The first open of this message that a person appears to have made.
 *
 * Separate from {@see MessageOpened}, which fires on the very first open of any
 * kind and stays fired. For a mailbox behind a scanner — Mimecast, Proofpoint,
 * Barracuda — that first open is practically always the machine's, and without
 * this event the record would say "preloaded" forever and never once say that
 * somebody read it. The facts were there; nothing announced them.
 *
 * Additive on purpose. `MessageOpened` keeps its exact meaning and its exact
 * timing, so nothing that listens to it starts firing twice.
 *
 * "Appears to have made" is the whole of the claim. See
 * {@see MachineOpen} for how far that can be known.
 */
class MessageOpenedByHuman extends MessageEventBase {}
