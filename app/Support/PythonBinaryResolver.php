<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class PythonBinaryResolver
{
    public static function resolve(): string
    {
        $candidates = [
            base_path('python/venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
            'python3',
            'python',
        ];

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, base_path('python/venv')) && File::exists($candidate)) {
                return $candidate;
            }
        }

        return 'python';
    }
}
