<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Support\Database\JsonAggregate;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    /**
     * Liste des portefeuilles avec compteurs et apercu des premiers biens, en
     * une seule requête SQL.
     *
     * L'apercu passait par `with(['properties' => fn ($q) => $q->take(3)])`,
     * qui déclenche une seconde requête. Une sous-requête agrégée en JSON
     * ramène la même chose dans la requête des lignes. La limite reste posée
     * *dans* la sous-requête, portefeuille par portefeuille : c'est ce qui la
     * rend bornée quel que soit le nombre de biens.
     */
    public function index(Request $request)
    {
        $preview = JsonAggregate::arrayOf(
            [
                'id' => 'apercu.id',
                'title' => 'apercu.title',
                'city' => 'apercu.city',
                'is_rented' => 'apercu.is_rented',
            ],
            'from (select p.id, p.title, p.city, p.is_rented
                     from properties p
                    where p.portfolio_id = portfolios.id
                    order by p.id
                    limit 3) as apercu'
        );

        $query = auth()->user()->portfolios()
            ->withCount('properties')
            ->withCount(['properties as occupied_properties_count' => fn ($query) => $query->where('is_rented', true)])
            ->withCount('documents')
            ->selectRaw($preview.' as properties_preview')
            ->filtered($request);

        return $this->paginate($query, $request);
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
