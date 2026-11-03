<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Where uploaded files go (profile photos, MOVs/requirements, WFH letters).
    |   UPLOADS_DISK=public    -> this laptop only (storage/app/public)
    |   UPLOADS_DISK=supabase  -> Supabase Storage, shared by every laptop
    */
    'uploads' => env('UPLOADS_DISK', 'public'),

    /*
    | Where PRIVATE uploads go (requirement files, signed evaluation forms, school-event proofs,
    | staff signatures). They are never reachable by a plain link: the server hands out a link
    | that works for a few minutes, only to the people who may see the file (FileController).
    |   PRIVATE_UPLOADS_DISK=local             -> this computer only (storage/app/private)
    |   PRIVATE_UPLOADS_DISK=supabase_private  -> a PRIVATE Supabase bucket (SUPABASE_PRIVATE_BUCKET)
    */
    'private_uploads' => env('PRIVATE_UPLOADS_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // Supabase Storage (S3-compatible). Values come from
        // Supabase -> Project Settings -> Storage -> S3 Connection.
        'supabase' => [
            'driver' => 's3',
            'key' => env('SUPABASE_S3_KEY'),
            'secret' => env('SUPABASE_S3_SECRET'),
            'region' => env('SUPABASE_S3_REGION', 'ap-southeast-1'),
            'bucket' => env('SUPABASE_BUCKET', 'mytrack'),
            'endpoint' => env('SUPABASE_S3_ENDPOINT'),
            'url' => env('SUPABASE_PUBLIC_URL'),
            'use_path_style_endpoint' => true,
            'throw' => true,   // show the real error instead of silently saving nothing
        ],

        // the PRIVATE Supabase bucket (create it in Supabase -> Storage with "Public bucket" OFF).
        // Same S3 keys as above; no public url on purpose: files are opened with short-lived links only.
        'supabase_private' => [
            'driver' => 's3',
            'key' => env('SUPABASE_S3_KEY'),
            'secret' => env('SUPABASE_S3_SECRET'),
            'region' => env('SUPABASE_S3_REGION', 'ap-southeast-1'),
            'bucket' => env('SUPABASE_PRIVATE_BUCKET', 'mytrack-private'),
            'endpoint' => env('SUPABASE_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];