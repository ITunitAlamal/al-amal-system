<?php

return [
    'maintenance' => [
        'currency' => 'ILS',

        'financial_approval' => [
            'executive_director_limit_agorot' => 50000,

            'higher_authority' => 'board_chairman',

            // لا تُفعَّل الموافقات حتى تكتمل
            // الصلاحيات والتحقق من هوية المعتمد.
            'approval_execution_enabled' => false,

            'require_documented_cost' => true,
            'require_authorized_approver' => true,
        ],
    ],
];
