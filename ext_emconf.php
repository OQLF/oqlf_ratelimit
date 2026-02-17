<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'OQLF Rate limiter',
    'description' => 'a Typo3 middleware to limit legitimate and illegitimate bots from hammering on computationally expensive website resources',
    'category' => 'services',
    'author' => 'Rémi Payette',
    'author_email' => 'rpayette@oqlf.gouv.qc.ca',
    'author_company' => 'OQLF',
    'state' => 'stable',
    'version' => '1.0.3',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
    ],
];
