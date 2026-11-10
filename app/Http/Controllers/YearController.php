<?php

namespace App\Http\Controllers;

use App\Models\VoucherYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class YearController extends Controller
{
    public function create(): View
    {
        return view('years.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2200', 'unique:years,year']]);
        VoucherYear::create($data);

        return redirect()->route('vouchers.index', ['year' => $data['year']])->with('status', $data['year'].' folder created.');
    }
}
