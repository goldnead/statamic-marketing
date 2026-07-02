/**
 * Marketing — Statamic 6 Control Panel entry point.
 *
 * Each page registered here corresponds to an `Inertia::render('marketing::...')`
 * call on the PHP side. The string identifier MUST match exactly.
 */

import Dashboard from './pages/Dashboard.vue';
import ListsIndex from './pages/Lists/Index.vue';
import ListsEdit from './pages/Lists/Edit.vue';
import ListsShow from './pages/Lists/Show.vue';
import CampaignsIndex from './pages/Campaigns/Index.vue';
import CampaignsEdit from './pages/Campaigns/Edit.vue';
import CampaignsShow from './pages/Campaigns/Show.vue';
import TemplatesIndex from './pages/Templates/Index.vue';
import TemplatesEdit from './pages/Templates/Edit.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('marketing::Dashboard', Dashboard);
    Statamic.$inertia.register('marketing::Lists/Index', ListsIndex);
    Statamic.$inertia.register('marketing::Lists/Edit', ListsEdit);
    Statamic.$inertia.register('marketing::Lists/Show', ListsShow);
    Statamic.$inertia.register('marketing::Campaigns/Index', CampaignsIndex);
    Statamic.$inertia.register('marketing::Campaigns/Edit', CampaignsEdit);
    Statamic.$inertia.register('marketing::Campaigns/Show', CampaignsShow);
    Statamic.$inertia.register('marketing::Templates/Index', TemplatesIndex);
    Statamic.$inertia.register('marketing::Templates/Edit', TemplatesEdit);
});
