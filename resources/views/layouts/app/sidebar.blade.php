{{--
    The COPEMS shell — the port of ProtectedShell + AppSidebar
    (copems/src/app/(protected)/protected-shell.tsx, components/AppSidebar.tsx).

    Sidebar left with the granted modules in MODULES order (Community first),
    identity block and sign-out (behind a confirm) in the footer; sticky
    header with the current module's label and the notification bell. Nav
    entries point at module paths by URL, not route name, so a module whose
    routes are not registered yet renders (and 404s on click) instead of
    fataling the layout.
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

    // The ONE link a tree lights: of every group or item path the current page
    // lives under (the path itself, or below it), the LONGEST. Payroll's
    // "/payroll" is a prefix of its "/payroll/thirteenth-month", so a plain
    // prefix test would light both; Records' paths are no prefix of one
    // another, so for them this comes down to the plain prefix test.
    $activeTreePath = function (array $module) use ($currentPath): ?string {
        $best = null;
        foreach ($module['groups'] ?? [] as $group) {
            $paths = isset($group['path']) ? [$group['path']] : array_column($group['items'] ?? [], 'path');
            foreach ($paths as $path) {
                $under = $currentPath === $path || str_starts_with($currentPath, rtrim($path, '/').'/');
                if ($under && ($best === null || strlen($path) > strlen($best))) {
                    $best = $path;
                }
            }
        }

        return $best;
    };
    $treeHoldsCurrent = fn (array $module): bool => $activeTreePath($module) !== null;

    // The signed-in employee's portrait, when their record carries one —
    // only for the identity block in the sidebar footer.
    $photoUrl = null;
    if ($access->hasEmployeeLink()) {
        $photoPath = app(\App\Modules\Workforce\Contracts\OperationalDirectory::class)->find($access, $access->ownEmployeeId())?->photo_path;
        if (is_string($photoPath) && $photoPath !== '') {
            $photoUrl = \App\Platform\Files\PortraitUrl::for($photoPath);
        }
    }

    // First and last word, not the first two: a second given name would
    // otherwise stand in for the surname (AppSidebar.tsx:63-70).
    $nameWords = array_values(array_filter(preg_split('/\s+/', trim($access->fullName)) ?: []));
    $initials = mb_strtoupper(
        mb_substr($nameWords[0] ?? '', 0, 1)
        .(count($nameWords) > 1 ? mb_substr($nameWords[count($nameWords) - 1], 0, 1) : '')
    );

    $itemClasses = 'flex w-full items-center gap-2 overflow-hidden rounded-md p-2 text-left text-sm text-sidebar-foreground outline-hidden transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground sidebar-rail:justify-center sidebar-rail:p-2';
    $itemActive = ' bg-sidebar-accent font-medium text-sidebar-accent-foreground';
    $subItemClasses = 'flex min-w-0 items-center gap-2 overflow-hidden rounded-md px-2 py-1.5 text-sm text-sidebar-foreground outline-hidden transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground';
    $hideCollapsed = 'sidebar-rail:hidden';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{-- `hris-shell` makes the body Flux's grid BEFORE `<flux:main>` is parsed.
         Without it the top bar drops under the sidebar while a page is still
         arriving — see the utility in app.css and ShellLayoutTest. --}}
    <body class="hris-shell min-h-screen bg-background text-foreground">
        {{-- Collapsed, the sidebar is a rail that opens over the page under the
             pointer (`hris-sidebar` and the `sidebar-rail` variant, app.css).
             Flux expands a collapsed sidebar FOR GOOD on a click on its own
             padding. Under a pointer that sidebar is already open, so the
             click could only be one that missed a link, and it would move the
             whole page: the capture listener ends it before Flux's hears it.
             A screen without hover keeps Flux's click, its only way to open
             the rail from the rail. --}}
        <flux:sidebar
            sticky
            collapsible
            class="hris-sidebar border-e border-sidebar-border bg-sidebar text-sidebar-foreground"
            x-on:click.self.capture="$el.hasAttribute('data-flux-sidebar-collapsed-desktop') && window.matchMedia('(hover: hover)').matches && $event.stopImmediatePropagation()"
        >
            <flux:sidebar.header>
                <div class="flex min-h-12 min-w-0 flex-1 items-center gap-2 overflow-hidden rounded-md p-2 sidebar-rail:justify-center sidebar-rail:p-0">
                    <div class="flex aspect-square size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white sidebar-rail:size-8">
                        <img src="{{ asset('bestworkflows-icon-32.png') }}" alt="BestWorkflows logo" class="size-11 object-contain sidebar-rail:size-8" />
                    </div>
                    {{-- The full company name has to read in one piece, so it wraps
                         rather than truncating — and disappears in icon mode. --}}
                    <div class="grid min-w-0 flex-1 leading-tight {{ $hideCollapsed }}">
                        <span class="text-sm font-bold">BestWorkflows</span>
                        <span class="text-xs text-sidebar-foreground/70">Management Information System</span>
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
                                    @php($activePath = $activeTreePath($module))
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
                                                            <a href="{{ url($group['path']) }}" class="{{ $subItemClasses }}{{ $group['path'] === $activePath ? $itemActive : '' }}" @if ($group['path'] === $activePath) aria-current="page" @endif>
                                                                <span class="truncate">{{ $group['label'] }}</span>
                                                            </a>
                                                        </li>
                                                    @else
                                                        {{-- … or a folder that opens to reveal its pages. --}}
                                                        @php($folderHoldsCurrent = collect($group['items'])->contains(fn ($item) => $item['path'] === $activePath))
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
                                                                        <a href="{{ url($item['path']) }}" class="{{ $subItemClasses }}{{ $item['path'] === $activePath ? $itemActive : '' }}" @if ($item['path'] === $activePath) aria-current="page" @endif>
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
                                        @if ($module['code'] === \App\Domain\Enums\ModuleCode::CHAT)
                                            <livewire:chat.unread-badge />
                                        @endif
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

                {{-- Sign out asks first. The footer button only OPENS the confirm;
                     the POST to `shell.logout` (App\Livewire\Actions\Logout) sits
                     inside the dialog, so a stray click on the collapsed icon
                     cannot end the session. One wrapper holds the trigger and
                     the dialog, so the footer spaces them as one item. The
                     dialog is named from its heading's id, set from x-init,
                     because Flux forwards no aria attributes to the <dialog>
                     itself. --}}
                <div>
                    <flux:modal.trigger name="confirm-sign-out">
                        <button type="button" title="Sign out" class="{{ $itemClasses }} cursor-pointer" data-test="logout-button">
                            @include('layouts.app.module-icon', ['icon' => 'log-out', 'class' => 'size-4 shrink-0'])
                            <span class="min-w-0 flex-1 truncate {{ $hideCollapsed }}">Sign out</span>
                        </button>
                    </flux:modal.trigger>

                    <flux:modal
                        name="confirm-sign-out"
                        class="w-full max-w-md"
                        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'confirm-sign-out-heading')"
                    >
                        <form method="POST" action="{{ route('shell.logout') }}" class="space-y-4">
                            @csrf
                            <div class="space-y-2">
                                <flux:heading size="lg" id="confirm-sign-out-heading">Sign out?</flux:heading>
                                <flux:text>You will need to sign in again to continue.</flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="outline">Cancel</flux:button>
                                </flux:modal.close>
                                <flux:button type="submit" variant="primary" data-test="confirm-logout-button">Sign out</flux:button>
                            </div>
                        </form>
                    </flux:modal>
                </div>
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
                {{ $currentLabel ?? 'BestWorkflows Management Information System' }}
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
