<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Where uploaded files go (profile photos, MOVs/requirements, WFH letters).
    |   UPLOADS_DISK=public    -> this laptop only (storage/app/public)
    |   UPLOADS_DISK=supabase  -> Supabase Storage, shared by every laptop
    */
    'uploads' => env('UPLOADS_DISK', 'public'),

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

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];