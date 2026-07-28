<?php

return [
    'title' => 'Mailing Lists',
    'name' => 'Name',
    'handle' => 'Handle',
    'description' => 'Description',
    'double_opt_in' => 'Double opt-in',
    'subscribed' => 'Subscribed',
    'pending' => 'Pending',
    'create' => 'Create list',
    'flashes' => [
        'created' => 'List created.',
        'updated' => 'List updated.',
        'deleted' => 'List deleted.',
        'handle_taken' => 'A list with this handle already exists.',
        'handle_taken_by_brand' => 'This handle already belongs to the brand ":brand". Handles are unique across brands, because the public subscribe form derives the brand from the list handle.',
    ],
];
