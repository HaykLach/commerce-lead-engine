<?php

return [
    // First matching rule wins. Patterns inspect only the part before @.
    'purposes' => [
        'legal_privacy' => ['label' => 'Legal / privacy', 'pattern' => '/(?:^|[._+\-])(privacy|legal|dpo|gdpr|datenschutz)(?:$|[._+\-])/i', 'blocked' => true],
        'no_reply' => ['label' => 'No reply', 'pattern' => '/(?:^|[._+\-])(no[._\-]?reply|do[._\-]?not[._\-]?reply)(?:$|[._+\-])/i', 'blocked' => true],
        'recruitment' => ['label' => 'Recruitment', 'pattern' => '/(?:^|[._+\-])(jobs|careers|hr)(?:$|[._+\-])/i', 'blocked' => true],
        'customer_support' => ['label' => 'Customer support', 'pattern' => '/(?:^|[._+\-])(support|orders|returns)(?:$|[._+\-])/i'],
        'partnerships_sales' => ['label' => 'Partnerships / sales', 'pattern' => '/(?:^|[._+\-])(partners|partnerships|sales)(?:$|[._+\-])/i', 'automatic' => true],
        'business' => ['label' => 'General business', 'pattern' => '/(?:^|[._+\-])(info|hello|contact|office)(?:$|[._+\-])/i', 'automatic' => true],
        'unknown' => ['label' => 'Unknown'],
    ],
    'import' => ['max_pages' => 100, 'max_emails_per_page' => 50],
];
