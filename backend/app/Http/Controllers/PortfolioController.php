<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function index()
    {
        return auth()->user()->portfolios()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required'],
            'description' => ['nullable'],
        ]);

        $data['user_id'] = auth()->id();

        return Portfolio::create($data);
    }

    public function show(Portfolio $portfolio)
    {
        $this->authorize('view', $portfolio);

        return $portfolio;
    }

    public function update(Request $request, Portfolio $portfolio)
    {
        $this->authorize('update', $portfolio);

        $data = $request->validate([
            'name' => ['required'],
            'description' => ['nullable'],
        ]);

        $portfolio->update($data);

        return $portfolio;
    }

    public function destroy(Portfolio $portfolio)
    {
        $this->authorize('delete', $portfolio);

        $portfolio->delete();

        return response()->json();
    }
}
