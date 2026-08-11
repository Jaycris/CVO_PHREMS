<?php

return [

    'cash_advance' => [

        /*
         * The most an employee may ask for in a single request. This caps the
         * employee's side only — HR, the accountant and the CEO/COO can amend a
         * request to any figure, since they are the ones authorising the money.
         *
         * Raising the company limit is an .env change today. It moves to an
         * HR-editable payroll settings screen in M4.
         */
        'max_request_amount' => (float) env('CASH_ADVANCE_MAX_REQUEST', 3000),

    ],

];
