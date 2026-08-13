<?php

use Goldnead\Marketing\Support\DeliveryIdentity;

/**
 * The bypasses a per-recipient limit stands or falls on.
 *
 * A limit is only as good as its idea of "the same person". Key it on the
 * address as typed and an attacker types it differently — which is not a
 * theoretical objection, it is a two-character edit and the reason the obvious
 * fix (key on `email_normalized`, which only trims and lowercases) would have
 * been no fix at all.
 */
it('sees through the ways one mailbox can be written', function (string $variant): void {
    expect(DeliveryIdentity::keyFor($variant))
        ->toBe(DeliveryIdentity::keyFor('opfer@gmail.com'));
})->with([
    'as typed' => 'opfer@gmail.com',
    'case in the local part' => 'Opfer@gmail.com',
    'case in the domain' => 'opfer@GMAIL.com',
    'surrounding whitespace' => '  opfer@gmail.com  ',
    'a subaddress tag' => 'opfer+newsletter@gmail.com',
    'a second tag' => 'opfer+1@gmail.com',
    'an empty tag' => 'opfer+@gmail.com',
    'dots, which Gmail ignores' => 'o.p.f.e.r@gmail.com',
    'dots and a tag together' => 'o.p.f.e.r+spam@gmail.com',
    'the googlemail alias' => 'opfer@googlemail.com',
    'the alias with a tag' => 'opfer+x@googlemail.com',
    'a fully qualified domain' => 'opfer@gmail.com.',
    // Passes Laravel's `email` rule, is accepted by Symfony's Address, and is
    // unquoted by the receiving server — same inbox, and it used to be a free
    // second bucket for the price of two characters.
    'a quoted local part' => '"opfer"@gmail.com',
    'a quoted local part with escapes' => '"op\\fer"@gmail.com',
    // `"opfer"+x@…` is deliberately absent: a local part is a dot-atom OR a
    // quoted string, never both, so that form fails validation long before it
    // reaches this class. A test for it would assert about an address that
    // cannot arrive.
]);

it('strips subaddress tags on any domain, not only the ones it knows', function (): void {
    expect(DeliveryIdentity::keyFor('opfer+egal@firma.de'))
        ->toBe(DeliveryIdentity::keyFor('opfer@firma.de'));
});

/**
 * The deliberate limit of the dot rule. Dots are significant almost
 * everywhere; treating them as noise on every domain would merge people who
 * have nothing to do with each other, and a limit that silently swallows a
 * stranger's confirmation mail is its own kind of failure.
 */
it('keeps dots outside Gmail, where they address different people', function (): void {
    expect(DeliveryIdentity::keyFor('a.b@firma.de'))
        ->not->toBe(DeliveryIdentity::keyFor('ab@firma.de'));
});

it('keeps genuinely different mailboxes apart', function (string $a, string $b): void {
    expect(DeliveryIdentity::keyFor($a))->not->toBe(DeliveryIdentity::keyFor($b));
})->with([
    'different local parts' => ['eine@example.com', 'andere@example.com'],
    'different domains' => ['gleich@example.com', 'gleich@example.org'],
    'a tag is not a local part' => ['opfer+chef@example.com', 'chef@example.com'],
    'gmail is not example' => ['opfer@gmail.com', 'opfer@example.com'],
]);

/**
 * Fullwidth Latin renders identically to ASCII in most mail clients and, after
 * NFKC, is the same string. Skipped without ext-intl, where the class says
 * plainly that it cannot fold width — the case and tag rules still apply, so
 * the common bypasses stay shut either way.
 */
it('folds compatibility forms onto their plain equivalents', function (): void {
    expect(DeliveryIdentity::keyFor('ｏｐｆｅｒ@ｇｍａｉｌ.ｃｏｍ'))
        ->toBe(DeliveryIdentity::keyFor('opfer@gmail.com'));
})->skip(fn () => ! class_exists(Normalizer::class), 'ext-intl is not installed');

it('answers for degenerate input without throwing', function (): void {
    expect(DeliveryIdentity::keyFor(null))->toBe(DeliveryIdentity::keyFor(''))
        ->and(DeliveryIdentity::keyFor('kein-at-zeichen'))->toBeString()
        ->and(DeliveryIdentity::keyFor('@example.com'))->toBeString();
});
