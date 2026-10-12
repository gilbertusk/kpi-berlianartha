@php
    use App\Models\User;
    use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
    use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
    use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
    use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;

    // Links are shown only when the server-side policy allows the page;
    // every page re-checks the same policy on load and on each action.
    $sections = array_filter([
        ['route' => 'administration.organization', 'label' => __('Struktur organisasi'), 'allowed' => auth()->user()->can('viewAny', OrganizationUnit::class)],
        ['route' => 'administration.employees', 'label' => __('Pegawai & penugasan'), 'allowed' => auth()->user()->can('viewAny', Employee::class)],
        ['route' => 'administration.accounts', 'label' => __('Akun'), 'allowed' => auth()->user()->can('viewAny', User::class)],
        ['route' => 'administration.roles', 'label' => __('Role & scope'), 'allowed' => auth()->user()->can('viewAny', RoleAssignment::class)],
        ['route' => 'administration.delegations', 'label' => __('Delegasi'), 'allowed' => auth()->user()->can('viewAny', Delegation::class)],
        ['route' => 'administration.access-review', 'label' => __('Access review'), 'allowed' => auth()->user()->can('access-review.view')],
    ], fn (array $section): bool => $section['allowed']);
@endphp

<nav aria-label="{{ __('Navigasi administrasi') }}" class="border-b border-zinc-200 dark:border-zinc-700">
    <ul class="-mb-px flex flex-wrap gap-x-4 text-sm">
        @foreach ($sections as $section)
            <li>
                <a
                    href="{{ route($section['route']) }}"
                    wire:navigate
                    @class([
                        'inline-block border-b-2 px-1 py-2 font-medium',
                        'border-brand-700 text-brand-800 dark:border-brand-300 dark:text-brand-200' => request()->routeIs($section['route'].'*'),
                        'border-transparent text-zinc-700 hover:border-zinc-400 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => ! request()->routeIs($section['route'].'*'),
                    ])
                    @if (request()->routeIs($section['route'].'*')) aria-current="page" @endif
                >{{ $section['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
