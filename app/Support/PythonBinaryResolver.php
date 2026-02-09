<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class PythonBinaryResolver
{
    public static function resolveVenv(): ?string
    {
        $venvCandidates = [
            base_path('python/venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
        ];

        foreach ($venvCandidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function resolveVenvOrFail(): string
    {
        $venvPython = self::resolveVenv();
        if ($venvPython === null) {
            throw new \RuntimeException(
                'Python virtual environment interpreter not found. Expected python/venv/Scripts/python.exe or python/venv/bin/python.'
            );
        }

        return $venvPython;
    }

    public static function resolve(): string
    {
        return self::resolveVenv() ?? 'python';
    }
}
