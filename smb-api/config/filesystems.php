<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Default Media Storage Disk (§110-114 revisi prompt — LOCAL STORAGE MODE)
    |--------------------------------------------------------------------------
    |
    | Device media (foto kamera, dst) disimpan lewat abstraksi ini, BUKAN hardcode ke
    | disk 'spaces' — lihat MediaStorageService. Default 'smb_media' (local disk, di
    | perangkat/server milik pemilik produk sendiri — bukan cloud pihak ketiga). Ganti
    | ke 'spaces' kapan pun via .env TANPA ubah kode (StorageService abstraction, §110).
    |
    */

    'default_media_disk' => env('SMB_MEDIA_DISK', 'smb_media'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // §110-114: storage LOKAL di perangkat/server milik pemilik produk — disk default
        // untuk device_media (bukan Spaces). SMB_STORAGE_PATH dikonfigurasi via .env, TIDAK
        // di-hardcode ke drive tertentu (§111 — jangan hardcode "D:\SMB\storage" di kode).
        // 'serve' SENGAJA false — §113: tidak boleh diakses langsung lewat URL publik, hanya
        // lewat endpoint signed URL (MediaStorageService::readUrl(), DeviceMediaTransferController).
        'smb_media' => [
            'driver' => 'local',
            'root' => env('SMB_STORAGE_PATH', storage_path('app/smb-media')),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // §28: DigitalOcean Spaces — S3-compatible, driver 's3' dengan endpoint custom.
        // BELUM dikonfigurasi dengan credential asli (DO_SPACES_* kosong di .env) — kode
        // di sini SIAP dipakai begitu Anda isi credential Spaces, tidak perlu ubah apa pun
        // selain .env (§66: tidak ada implementasi palsu, hanya konfigurasi yang menunggu
        // credential produksi).
        'spaces' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'region' => env('DO_SPACES_REGION', 'sgp1'),
            'bucket' => env('DO_SPACES_BUCKET', 'smb-media'),
            'endpoint' => env('DO_SPACES_ENDPOINT'),
            'url' => env('DO_SPACES_ENDPOINT') ? env('DO_SPACES_ENDPOINT').'/'.env('DO_SPACES_BUCKET', 'smb-media') : null,
            'use_path_style_endpoint' => false,
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
