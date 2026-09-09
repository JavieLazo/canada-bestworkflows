{{--
    The BestWorkflows shell — the port of ProtectedShell + AppSidebar
    (copems/src/app/(protected)/protected-shell.tsx, components/AppSidebar.tsx).

    Sidebar left with the granted modules in MODULES order, identity block and
    sign-out in the footer; sticky header with the current module's label and
    the notification bell. Nav entries point at module paths by URL, not route
    name, so a module whose routes are not registered yet renders (and 404s on
    click) instead of fataling the layout.
--}}
@php
    $access = app(\App\Support\Access::class);
    $registry = app(\App\Support\ModuleRegistry::class);

    $navModules = $registry->navFor($access->modules);
    $holdsProfile = in_array(\App\Domain\Enums\ModuleCode::PROFILE, $access->modules, true);
    $profilePath = $registry->modulePath(\App\Domain\Enums\ModuleCode::PROFILE);

    $currentPath = '/'.trim(request()->path(), '/');
    $currentModuleCode = $registry->forPath($currentPath);
    $currentLabel = $currentModuleCode !== null ? $registry->moduleLabel($currentModuleCode) : null;

    $isCurrent = fn (string $path): bool => str_starts_with($currentPath, $path);
    $treeHoldsCurrent = function (array $module) use ($isCurrent): bool {
        foreach ($module['groups'] ?? [] as $group) {
            if (isset($group['path']) && $isCurrent($group['path'])) {
                return true;
            }
            foreach ($group['items'] ?? [] as $item) {
                if ($isCurrent($item['path'])) {
                    return true;
                }
            }
        }

        return false;
    };

    // The signed-in employee's portrait, when their record carries one —
    // only for the identity block in the sidebar footer.
    $photoUrl = null;
    if ($access->hasEmployeeLink()) {
        $photoPath = \App\Models\EmployeeProfile::query()->whereKey($access->employeeId)->value('photo_path');
        if (is_string($photoPath) && $photoPath !== '') {
            $photoUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($photoPath);
        }
    }

    // First and last word, not the first two: a second given name would
    // otherwise stand in for the surname (AppSidebar.tsx:63-70).
    $nameWords = array_values(array_filter(preg_split('/\s+/', trim($access->fullName)) ?: []));
    $initials = mb_strtoupper(
        mb_substr($nameWords[0] ?? '', 0, 1)
        .(count($nameWords) > 1 ? mb_substr($nameWords[count($nameWords) - 1], 0, 1) : '')
    );

    $itemClasses = 'flex w-full items-center gap-2 overflow-hidden rounded-md p-2 text-left text-sm text-sidebar-foreground outline-hidden transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:p-2';
    $itemActive = ' bg-sidebar-accent font-medium text-sidebar-accent-foreground';
    $subItemClasses = 'flex min-w-0 items-center gap-2 overflow-hidden rounded-md px-2 py-1.5 text-sm text-sidebar-foreground outline-hidden transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground';
    $hideCollapsed = 'in-data-flux-sidebar-collapsed-desktop:hidden';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-background text-foreground">
        <flux:sidebar sticky collapsible class="border-e border-sidebar-border bg-sidebar text-sidebar-foreground">
            <flux:sidebar.header>
                <div class="flex min-h-12 min-w-0 flex-1 items-center gap-2 overflow-hidden rounded-md p-2 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:p-0">
                    <div class="flex aspect-square size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white in-data-flux-sidebar-collapsed-desktop:size-8">
                        <img src="{{ asset('bestworkflows-logo.png') }}" alt="BestWorkflows logo" class="size-11 object-contain in-data-flux-sidebar-collapsed-desktop:size-8" />
                    </div>
                    {{-- The full company name has to read in one piece, so it wraps
                         rather than truncating — and disappears in icon mode. --}}
                    <div class="grid min-w-0 flex-1 leading-tight {{ $hideCollapsed }}">
                        <span class="text-sm font-bold">BestWorkflows</span>
                        <span class="text-xs text-sidebar-foreground/70">Employee Management System</span>
                    </div>
                </div>
            </flux:sidebar.header>

            <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto">
                {{-- A role granted nothing but My Profile would otherwise be left
                     with a "Modules" heading and nothing under it. --}}
                @if (count($navModules) > 0)
                    <div class="flex flex-col gap-1">
                        <div class="px-2 py-1 text-xs font-medium text-sidebar-foreground/70 {{ $hideCollapsed }}">Modules</div>

                        <nav class="flex flex-col gap-0.5" aria-label="Modules">
                            @foreach ($navModules as $module)
                                @if (isset($module['groups']))
                                    {{-- A module rendered as a tree: the module collapses, and
                                         each folder inside it collapses again. Both open by
                                         default when the current page lives inside. --}}
                                    @php($holdsCurrent = $treeHoldsCurrent($module))
                                    <div x-data="{ open: @js($holdsCurrent) }">
                                        <button
                                            type="button"
                                            x-on:click="open = !open"
                                            title="{{ $module['label'] }}"
                                            class="{{ $itemClasses }}{{ $holdsCurrent ? $itemActive : '' }}"
                                        >
                                            @include('layouts.app.module-icon', ['icon' => $module['icon'], 'class' => 'size-4 shrink-0'])
                                            <span class="min-w-0 flex-1 truncate {{ $hideCollapsed }}">{{ $module['label'] }}</span>
                                            <span class="ml-auto shrink-0 transition-transform {{ $hideCollapsed }}" :class="open && 'rotate-90'">
                                                @include('layouts.app.module-icon', ['icon' => 'chevron-right', 'class' => 'size-4'])
                                            </span>
                                        </button>

                                        <div x-show="open" x-cloak class="{{ $hideCollapsed }}">
                                            <ul class="mx-3.5 flex min-w-0 flex-col gap-1 border-s border-sidebar-border px-2.5 py-0.5">
                                                @foreach ($module['groups'] as $group)
                                                    @if (isset($group['path']))
                                                        {{-- A page of its own … --}}
                                                        <li>
                                                            <a href="{{ url($group['path']) }}" class="{{ $subItemClasses }}{{ $isCurrent($group['path']) ? $itemActive : '' }}">
                                                                <span class="truncate">{{ $group['label'] }}</span>
                                                            </a>
                                                        </li>
                                                    @else
                                                        {{-- … or a folder that opens to reveal its pages. --}}
                                                        @php($folderHoldsCurrent = collect($group['items'])->contains(fn ($item) => $isCurrent($item['path'])))
                                                        <li x-data="{ open: @js($folderHoldsCurrent) }">
                                                            <button
                                                                type="button"
                                                                x-on:click="open = !open"
                                                                class="{{ $subItemClasses }} w-full cursor-pointer"
                                                            >
                                                                <span class="truncate">{{ $group['label'] }}</span>
                                                                <span class="ml-auto shrink-0 transition-transform" :class="open && 'rotate-90'">
                                                                    @include('layouts.app.module-icon', ['icon' => 'chevron-right', 'class' => 'size-3'])
                                                                </span>
                                                            </button>

                                                            <ul x-show="open" x-cloak class="mx-3.5 flex min-w-0 flex-col gap-1 border-s border-sidebar-border px-2.5 py-0.5">
                                                                @foreach ($group['items'] as $item)
                                                                    <li>
                                                                        <a href="{{ url($item['path']) }}" class="{{ $subItemClasses }}{{ $isCurrent($item['path']) ? $itemActive : '' }}">
                                                                            <span class="truncate">{{ $item['label'] }}</span>
                                                                        </a>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </li>
                                                    @endif
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @else
                                    <a
                                        href="{{ url($module['path']) }}"
                                        title="{{ $module['label'] }}"
                                        class="{{ $itemClasses }}{{ $isCurrent($module['path']) ? $itemActive : '' }}"
                                        @if ($isCurrent($module['path'])) aria-current="page" @endif
                                    >
                                        @include('layouts.app.module-icon', ['icon' => $module['icon'], 'class' => 'size-4 shrink-0'])
                                        <span class="min-w-0 flex-1 truncate {{ $hideCollapsed }}">{{ $module['label'] }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </nav>
                    </div>
                @endif
            </div>

            <div class="flex flex-col gap-0.5">
                {{-- The identity block is the way into My Profile — but only when
                     the role actually holds it, since a grant can be revoked. --}}
                @if ($holdsProfile)
                    <a
                        href="{{ url($profilePath) }}"
                        title="My Profile"
                        class="{{ $itemClasses }}{{ $isCurrent($profilePath) ? $itemActive : '' }}"
                    >
                        @include('layouts.app.identity', ['photoUrl' => $photoUrl, 'initials' => $initials, 'fullName' => $access->fullName, 'roleName' => $access->roleName, 'hideCollapsed' => $hideCollapsed])
                    </a>
                @else
                    {{-- Without the grant there is nothing to open, so it stays a
                         plain block rather than a dead button in the tab order. --}}
                    <div class="{{ $itemClasses }} cursor-default hover:bg-transparent hover:text-sidebar-foreground">
                        @include('layouts.app.identity', ['photoUrl' => $photoUrl, 'initials' => $initials, 'fullName' => $access->fullName, 'roleName' => $access->roleName, 'hideCollapsed' => $hideCollapsed])
                    </div>
                @endif

                <form method="POST" action="{{ route('shell.logout') }}">
                    @csrf
                    <button type="submit" title="Sign out" class="{{ $itemClasses }} cursor-pointer" data-test="logout-button">
                        @include('layouts.app.module-icon', ['icon' => 'log-out', 'class' => 'size-4 shrink-0'])
                        <span class="min-w-0 flex-1 truncate {{ $hideCollapsed }}">Sign out</span>
                    </button>
                </form>
            </div>
        </flux:sidebar>

        <flux:header sticky class="!min-h-14 h-14 items-center gap-2 border-b border-border bg-card/95 !px-4 backdrop-blur print:hidden">
            {{-- SidebarTrigger (protected-shell.tsx:109): one control for both
                 viewports - Flux's flux-sidebar-toggle event collapses on
                 desktop and stashes on mobile. Panel icon per the reference. --}}
            <button
                type="button"
                x-data
                x-on:click="$dispatch('flux-sidebar-toggle')"
                class="-ml-1 inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                aria-label="Toggle sidebar"
            >
                <svg class="h-4.5 w-4.5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M7.5 3.75V16.25M3.4375 16.25H16.5625C17.08 16.25 17.5 15.83 17.5 15.3125V4.6875C17.5 4.17 17.08 3.75 16.5625 3.75H3.4375C2.92 3.75 2.5 4.17 2.5 4.6875V15.3125C2.5 15.83 2.92 16.25 3.4375 16.25Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
            </button>
            <div class="mr-2 h-4 w-px shrink-0 bg-border" role="separator" aria-orientation="vertical"></div>
            <span class="truncate text-sm font-semibold">
                {{ $currentLabel ?? 'BestWorkflows Employee Management System' }}
            </span>
            <div class="ml-auto flex items-center gap-1">
                {{--
                    Light/dark toggle. Rides Flux's appearance store ($flux.appearance,
                    persisted in localStorage and applied as the `.dark` class on <html>),
                    the same mechanism Settings -> Appearance drives - so the two controls
                    can never disagree. Toggles between explicit light and dark; the
                    three-way (incl. system) choice remains available in Settings.
                --}}
                <button
                    type="button"
                    x-data
                    x-on:click="$flux.appearance = ($flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)) ? 'light' : 'dark'"
                    class="inline-flex size-9 cursor-pointer items-center justify-center rounded-md text-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                    aria-label="Toggle light or dark mode"
                >
                    <flux:icon.sun x-show="$flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)" x-cloak class="h-4 w-4" variant="outline" />
                    <flux:icon.moon x-show="!($flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark))" x-cloak class="h-4 w-4" variant="outline" />
                </button>
                <livewire:shell.notification-bell />
            </div>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
