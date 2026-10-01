/**
 * The badge colour of a campaign status — one answer for the listing, the
 * editor and the report, which used to keep three copies of the same table.
 *
 * Waiting for an approval is amber: something an editor has to do. A series
 * template is sky: it never sends itself, it only produces campaigns.
 */
export function campaignStatusColor(status) {
    return {
        draft: 'default',
        scheduled: 'purple',
        sending: 'yellow',
        sent: 'green',
        series: 'sky',
        awaiting_approval: 'amber',
    }[status] || 'default';
}
