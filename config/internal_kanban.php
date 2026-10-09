<?php

/*
 | Internal project Kanban: a temporary tool for the PingoFin team, not part of the client product.
 | Access uses the existing platform master authorization, independent of organization roles.
 */
return [
    'enabled' => filter_var(env('INTERNAL_KANBAN_ENABLED', false), FILTER_VALIDATE_BOOL),
];
