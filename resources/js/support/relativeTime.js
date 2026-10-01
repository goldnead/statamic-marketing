/**
 * "in 12 Tagen", "in 3 Stunden", "vor 2 Tagen" — how far a moment is from
 * now, in the language of the Control Panel.
 *
 * Days from a day out, hours below that, minutes below an hour. Rounded, not
 * floored: thirteen and a half days reads as "in 14 Tagen", which is what a
 * calendar would say.
 */
export function relativeTime(value, now = new Date()) {
    if (! value) return '';

    const target = new Date(value);
    if (Number.isNaN(target.getTime())) return '';

    const seconds = (target.getTime() - now.getTime()) / 1000;
    const abs = Math.abs(seconds);

    let unit = 'minute';
    let amount = seconds / 60;

    if (abs >= 86400) {
        unit = 'day';
        amount = seconds / 86400;
    } else if (abs >= 3600) {
        unit = 'hour';
        amount = seconds / 3600;
    }

    try {
        const locale = document.documentElement.lang || undefined;
        return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(Math.round(amount), unit);
    } catch (e) {
        return '';
    }
}
