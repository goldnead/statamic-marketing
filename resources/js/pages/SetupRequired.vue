<script setup>
/**
 * What a CP listing shows when its database tables are not there yet.
 *
 * Everything readable arrives as a prop, already translated: the page has no
 * strings of its own, so the same file works in every addon of the suite.
 */
import { Head } from '@statamic/cms/inertia';
import { EmptyStateItem, EmptyStateMenu, Icon } from '@statamic/cms/ui';

defineProps({
    title: { type: String, required: true },
    heading: { type: String, required: true },
    description: { type: String, required: true },
    tables: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="title" />

    <div class="max-w-page mx-auto">
        <!-- Centered heading, not <Header>: the empty-state form, per ui-vocabulary.md §2.7 (a). -->
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <!-- `fieldtype-table`, not `database`: statamic/cms v6 ships no
                     `database.svg`, and <Icon> renders an unknown name as an
                     empty box without an error anywhere. -->
                <Icon name="fieldtype-table" class="size-5 text-gray-500" />{{ title }}
            </h1>
        </header>

        <EmptyStateMenu :heading="heading">
            <EmptyStateItem
                icon="fieldtype-table"
                :heading="tables.join(', ')"
                :description="description"
            />
        </EmptyStateMenu>
    </div>
</template>
