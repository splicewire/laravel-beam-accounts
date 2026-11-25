<?php

/*
 * Enum display labels at beam's derived keys (localization ruling 01 §2; BeamSchemaText::enumCaseLabel):
 * `beam-accounts::enums.<Enum class, \ written />.cases.<CaseName>.label`. The English lines equal each case's declared
 * `label()`, so English output is unchanged; a host or a locale overrides a line to change it everywhere the
 * label renders (the team page's role options, the invitation mail, the accept-invitation page).
 */
return [
    'Splicewire/Beam/Accounts/Enums/Role' => [
        'cases' => [
            'Owner' => ['label' => 'Owner'],
            'Admin' => ['label' => 'Admin'],
            'Member' => ['label' => 'Member'],
        ],
    ],
];
