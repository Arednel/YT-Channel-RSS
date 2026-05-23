<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class OptionsController
{
    public function index(): View
    {
        return view('Options');
    }
}
