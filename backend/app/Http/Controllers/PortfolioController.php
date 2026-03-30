<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function index()
    {
        return Portfolio::all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required'],
            'user_id' => ['required', 'exists:users'],
            'description' => ['nullable'],
        ]);

        return Portfolio::create($data);
    }

    public function show(Portfolio $portfolio)
    {
        return $portfolio;
    }

    public function update(Request $request, Portfolio $portfolio)
    {
        $data = $request->validate([
            'name' => ['required'],
            'user_id' => ['required', 'exists:users'],
            'description' => ['nullable'],
        ]);

        $portfolio->update($data);

        return $portfolio;
    }

    public function destroy(Portfolio $portfolio)
    {
        $portfolio->delete();

        return response()->json();
    }
}
