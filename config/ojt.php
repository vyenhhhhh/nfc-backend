<?php

/*
 | Required OJT hours per program.
 | EXAMPLE VALUES — change them to the real numbers.
 | Keep them the same as PROGRAM_HOURS in the React file components/DashKit.jsx.
 | After editing, run:  php artisan config:clear
 */
return [
    'default_hours' => 486,   // used when an intern has no program set
    'program_hours' => [
        'BSIT' => 486,
        'BSIS' => 400,
        'BSCS' => 300,
    ],
];