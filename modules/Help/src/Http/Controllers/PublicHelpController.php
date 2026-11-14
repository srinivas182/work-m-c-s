<?php

declare(strict_types=1);

namespace Wurk\Help\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Wurk\Help\Models\HelpCategory;
use Wurk\Help\Support\HelpCentre;

/** help.wurk.co.za (Sprint 16.1a): server-rendered, public, searchable. */
final class PublicHelpController
{
    public function __construct(private HelpCentre $help) {}

    public function home(): View
    {
        return $this->page('help::home', ['categories' => $this->help->categories()]);
    }

    public function category(string $slug): View
    {
        $c = HelpCategory::query()->where('slug', $slug)->firstOrFail();

        return $this->page('help::category', ['category' => $c, 'articles' => $this->help->inCategory($c->id)]);
    }

    public function article(Request $request, string $slug): View
    {
        $a = $this->help->published($slug) ?? abort(404);
        $this->help->viewed($a);

        return $this->page('help::article', ['article' => $a, 'html' => $this->help->render($a->body), 'video' => $this->help->url($a->video_path), 'poster' => $this->help->url($a->poster_path),
            'category' => HelpCategory::query()->find($a->category_id), 'thanked' => (bool) $request->session()->get('help.thanked.'.$a->id)]);
    }

    public function search(Request $request): View
    {
        $q = (string) $request->query('q', '');

        return $this->page('help::search', ['q' => $q, 'results' => $this->help->search($q)]);
    }

    public function feedback(Request $request, string $slug): RedirectResponse
    {
        $a = $this->help->published($slug) ?? abort(404);
        $key = 'help.thanked.'.$a->id;
        if (! $request->session()->has($key)) { // once per visit
            $this->help->feedback($a, $request->boolean('helpful'));
            $request->session()->put($key, true);
        }

        return redirect()->to('/a/'.$a->slug.'#feedback');
    }

    /** @param array<string, mixed> $data */
    private function page(string $name, array $data): View
    {
        /** @var view-string $name */
        return view($name, $data);
    }
}
