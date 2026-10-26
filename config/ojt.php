<?php

// OJT rules (from the OJT coordinator). Keep these the same as SCHEDULE / PROGRAM_HOURS
// in the React app (components/DashKit.jsx).
return [
    // Required OJT hours per program
    'program_hours' => [
        'BSIT' => 460,
        'BSIS' => 460,
        'BSCS' => 320,
    ],
    'default_hours' => 460,   // when an intern has no program set

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