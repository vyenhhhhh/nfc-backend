<?php

// OJT rules (from the OJT coordinator). Keep these the same as SCHEDULE / PROGRAM_HOURS
// in the React app (components/DashKit.jsx).
return [
    // Required OJT hours per program
    'program_hours' => [
        'BSIT' => 486,
        'BSIS' => 486,
        'BSCS' => 320,
    ],
    'default_hours' => 486,   // when an intern has no program set

    // Official hours (24-hour clock). No grace period: a tap after 8:00 is late.
    // Arriving early is allowed, but hours only count from 8:00 (and from 1:00 for the PM half).
    // 12:00–1:00 is lunch and is never counted.
    'schedule' => [
        'am_in'  => 8,
        'am_out' => 12,
        'pm_in'  => 13,
        'pm_out' => 17,
    ],

    // Most hours the official hours can give in a day. Overtime the coordinator approves is added on top.
    'daily_cap_hours' => 8,

    // Time after 5:00 PM becomes an overtime request for the coordinator
    'overtime_min_minutes' => 1,   // no minimum: any minute after 5:00 PM goes to the coordinator

    // A second tap within this many minutes is treated as an accidental duplicate
    'tap_cooldown_minutes' => 5,

    // One-time card codes against copied cards (app/Services/CardSecurity.php). SECURE_CARDS in .env:
    //   off    = UID only, like before
    //   on     = phone (Web NFC) and ACR122U kiosks check the code and write a new one on every tap;
    //            kiosks with the old USB reader still work with the UID only (while you switch readers)
    //   strict = every tap needs a good code (old USB-reader kiosks are refused)
    'secure_cards' => env('SECURE_CARDS', 'on'),

    // A kiosk that loses the internet keeps the taps and sends them when it is back online.
    // Taps older than this are refused (the coordinator adds them by hand).
    'offline_tap_max_hours' => 72,

    // Quarterly report: a placement whose name has one of these words counts as "within the university"
    // (the coordinator can still change it per office in the report)
    'university_keywords' => ['CSU', 'Caraga State University'],

    // Intern evaluation by the HTE supervisor (rated 1-5). Change the items to match the
    // official evaluation form; "key" must stay the same once evaluations are saved.
    // ── Internship grade (CCIS "Internship Grading Criteria", OJT/Practicum IS 197 · IT 197 · CS 197) ──
    // Final grade (%) = HTE supervisor's appraisal (60%) + SIP coordinator's evaluation (40%).
    // Every item is rated 1-5; an item is worth  rating ÷ 5 × its weight.
    // "key" must stay the same once grades are saved. The weights inside each part add up to 100 / 40.
    'grading' => [
        'scale' => [5 => 'Excellent', 4 => 'Good', 3 => 'Satisfactory', 2 => 'Needs improvement', 1 => 'Poor'],

        // Part 1 · HTE supervisor appraisal = the official form F-OSIP-PAR-004 rev.2 ("Student-Trainee's
        // Performance Appraisal Report"), Part I: 20 items rated 1-5, TOTAL POINTS out of 100.
        // It counts for 60% of the final grade. "weight" = the most points a category can get (items × 5).
        'supervisor_share' => 60,
        'supervisor' => [
            ['key' => 'attendance', 'label' => 'Attendance & Punctuality', 'weight' => 15, 'co' => 'CO3', 'items' => [
                ['key' => 'att_on_time',    'label' => 'Reports on time'],
                ['key' => 'att_regular',    'label' => 'Reports regularly'],
                ['key' => 'att_permission', 'label' => 'Requests permission before being absent'],
            ]],
            ['key' => 'performance', 'label' => 'Performance', 'weight' => 40, 'co' => 'CO1', 'items' => [
                ['key' => 'perf_knows',      'label' => 'Knows his/her work well'],
                ['key' => 'perf_on_time',    'label' => 'Completes assignments on time'],
                ['key' => 'perf_speed',      'label' => 'Works with speed and accuracy'],
                ['key' => 'perf_quality',    'label' => 'Ensures quality of work'],
                ['key' => 'perf_output',     'label' => 'Produces much output with less time'],
                ['key' => 'perf_resource',   'label' => 'Displays resourcefulness'],
                ['key' => 'perf_less_sup',   'label' => 'Requires less supervision'],
                ['key' => 'perf_initiative', 'label' => 'Has initiative'],
            ]],
            ['key' => 'attitude', 'label' => 'General Attitude', 'weight' => 45, 'co' => 'CO2, CO3', 'items' => [
                ['key' => 'att_interest',     'label' => 'Shows interest in his/her work'],
                ['key' => 'att_suggestions',  'label' => 'Accepts suggestions'],
                ['key' => 'att_cooperative',  'label' => 'Cooperates well with everybody'],
                ['key' => 'att_honest',       'label' => 'Exhibits honesty and dependability'],
                ['key' => 'att_instructions', 'label' => 'Follows instructions'],
                ['key' => 'att_safety',       'label' => 'Observes safety rules and regulations'],
                ['key' => 'att_responsible',  'label' => 'Accepts responsibility'],
                ['key' => 'att_respectful',   'label' => 'Respects superiors'],
                ['key' => 'att_friendly',     'label' => 'Shows friendliness and pleasant attitude'],
            ]],
        ],

        // Part 2 · SIP coordinator / OJT focal evaluation (40% of the final grade). Weights add up to 40.
        'coordinator' => [
            ['key' => 'narrative', 'label' => 'Narrative / Technical Internship Report', 'weight' => 25, 'co' => 'CO1, CO3', 'items' => [
                ['key' => 'nr_format',     'label' => 'Organization and Format', 'weight' => 3, 'co' => 'CO3',
                 'levels' => [5 => 'Exceptionally well-organized; follows the prescribed format completely', 4 => 'Well-organized with minor formatting issues', 3 => 'Adequately organized', 2 => 'Poor organization / formatting', 1 => 'Disorganized / does not follow the format']],
                ['key' => 'nr_complete',   'label' => 'Completeness of Content', 'weight' => 5, 'co' => 'CO1',
                 'levels' => [5 => 'All required sections thoroughly discussed', 4 => 'Most sections complete with minor omissions', 3 => 'Required sections present but lacking detail', 2 => 'Several missing sections', 1 => 'Incomplete report']],
                ['key' => 'nr_technical',  'label' => 'Technical Depth and Relevance', 'weight' => 8, 'co' => 'CO1',
                 'levels' => [5 => 'Strong technical analysis and reflection on tasks / projects', 4 => 'Good technical discussion', 3 => 'Adequate discussion of tasks', 2 => 'Limited technical explanation', 1 => 'Minimal / no technical substance']],
                ['key' => 'nr_reflection', 'label' => 'Reflection and Learning Insights', 'weight' => 5, 'co' => 'CO3',
                 'levels' => [5 => 'Highly insightful reflection on learning and development', 4 => 'Good reflection', 3 => 'Adequate reflection', 2 => 'Superficial reflection', 1 => 'No meaningful reflection']],
                ['key' => 'nr_writing',    'label' => 'Grammar, Mechanics, and Professional Writing', 'weight' => 4, 'co' => 'CO3',
                 'levels' => [5 => 'Error-free, professional quality writing', 4 => 'Minor errors', 3 => 'Understandable with some errors', 2 => 'Frequent errors', 1 => 'Poor grammar impedes understanding']],
            ]],
            ['key' => 'timely', 'label' => 'Timely Submission of Requirements', 'weight' => 10, 'co' => 'CO1, CO3', 'items' => [
                ['key' => 'timely', 'label' => 'Timely Submission of Requirements', 'weight' => 10],
            ]],
            ['key' => 'compliance', 'label' => 'Compliance / Monitoring Requirements', 'weight' => 5, 'co' => 'CO2, CO3', 'items' => [
                ['key' => 'compliance', 'label' => 'Compliance / Monitoring Requirements', 'weight' => 5],
            ]],
        ],

        // Rating scale on F-OSIP-PAR-004 page 2: final grade (%) → numerical grade and adjectival rating.
        // The form skips 83-84; 2.25 is used for it (confirm with the OJT coordinator).
        // The final % is rounded to a whole number before it is looked up (89.5 → 90 → 1.50).
        'equivalents' => [
            ['min' => 98, 'grade' => '1.00', 'rating' => 'Excellent'],
            ['min' => 94, 'grade' => '1.25', 'rating' => 'Superior'],
            ['min' => 90, 'grade' => '1.50', 'rating' => 'Very Good'],
            ['min' => 88, 'grade' => '1.75', 'rating' => 'Meritorious'],
            ['min' => 85, 'grade' => '2.00', 'rating' => 'Very Satisfactory'],
            ['min' => 83, 'grade' => '2.25', 'rating' => 'Satisfactory'],
            ['min' => 80, 'grade' => '2.50', 'rating' => 'Satisfactory'],
            ['min' => 78, 'grade' => '2.75', 'rating' => 'Fairly Satisfactory'],
            ['min' => 75, 'grade' => '3.00', 'rating' => 'Passing'],
            ['min' => 70, 'grade' => '4.00', 'rating' => 'Conditional Failure'],
            ['min' => 0,  'grade' => '5.00', 'rating' => 'Failure'],
        ],

        // "Late" on the attendance summary shown beside the appraisal: a time in after this.
        'late_after' => '08:00',
    ],

    'evaluation_criteria' => [
        ['key' => 'attendance',    'label' => 'Attendance and punctuality',       'hint' => 'Comes on time, rarely absent, tells the office ahead'],
        ['key' => 'quality',       'label' => 'Quality of work',                  'hint' => 'Accurate, neat and complete work'],
        ['key' => 'quantity',      'label' => 'Quantity of work',                 'hint' => 'Finishes the expected amount of work on time'],
        ['key' => 'knowledge',     'label' => 'Job knowledge and skills',         'hint' => 'Applies what was learned in school; learns new tools quickly'],
        ['key' => 'initiative',    'label' => 'Initiative and resourcefulness',   'hint' => 'Starts tasks without being told; finds solutions'],
        ['key' => 'dependability', 'label' => 'Dependability',                    'hint' => 'Can be trusted with tasks and follows through'],
        ['key' => 'teamwork',      'label' => 'Cooperation and teamwork',         'hint' => 'Works well with co-workers and supervisors'],
        ['key' => 'communication', 'label' => 'Communication skills',             'hint' => 'Clear in speaking and writing; asks when unsure'],
        ['key' => 'attitude',      'label' => 'Attitude and professionalism',     'hint' => 'Respectful, follows office rules, proper grooming'],
        ['key' => 'ethics',        'label' => 'Work ethics and confidentiality',  'hint' => 'Honest; keeps office information private'],
    ],
    'evaluation_scale' => [
        5 => 'Outstanding', 4 => 'Very satisfactory', 3 => 'Satisfactory', 2 => 'Fair', 1 => 'Needs improvement',
    ],

    // Secret shared by the kiosk and the server. /tap refuses any device without it.
    // Set KIOSK_KEY in the Laravel .env and VITE_KIOSK_KEY (same value) in the React .env.
    'kiosk_key' => env('KIOSK_KEY', ''),

    // ── When the kiosk accepts a CHECK-IN (temporary, until the OJT coordinator confirms) ──
    // Check-outs are always allowed, so an intern who is already checked in can still tap out.
    'tap_window'     => ['from' => 6, 'to' => 21],   // check-ins only from 6:00 AM to 9:00 PM
    'block_weekends' => true,                        // no check-ins on Saturdays and Sundays
    'block_holidays' => true,                        // no check-ins on holidays / non-working days in the Calendar

    // The internship starts only when ALL pre-internship requirements are approved by the coordinator.
    // Until then the kiosk and online attendance refuse the intern, and the During-internship
    // requirements stay locked. Keep this list the same as PRE_REQUIREMENTS in DashKit.jsx.
    'require_pre_requirements' => true,
    'pre_requirements' => [
        'capstone_summary', 'letter_of_intent', 'endorsement_letter', 'moa', 'internship_contract',
        'internship_plan', 'medical_certificate', 'personal_history', 'parents_consent', 'pledge',
        'acceptance_form', 'insurance', 'orientation',
    ],

    // Online attendance: any intern may log a day they worked from home (their HTE supervisor confirms it,
    // then the OJT coordinator approves it). A WFH arrangement with the HTE's letter is only for interns who
    // work from home for the whole semester. Set to true to allow online days for WFH interns only.
    'online_requires_wfh' => false,
];