<?php

return [
    'frontend' => [
        'OQLF/ratelimit/LimitRequestRateForPage' => [
            'target' => 'OQLF\\ratelimit\\Middleware\\LimitRequestRateForPage',
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
