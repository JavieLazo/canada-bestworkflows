{{--
    BestWorkflows public landing.

    A static marketing page, ported from the reference in the app's own stack:
    the real Vite Tailwind build (not the CDN), brand tokens declared in
    resources/css/app.css, and Lucide icons inlined as SVG so nothing is fetched
    from a third party. "Sign In" points at the actual /login; the demo form is
    a visual stub for now.
--}}
@php
    // Lucide icon paths, inlined. Rendered by the `icon` closure at a size.
    $paths = [
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'shield-alert' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'play-circle' => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
        'cpu' => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
        'check-check' => '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
        'file-check' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/>',
        'git-branch' => '<line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
        'wifi-off' => '<path d="M12 20h.01"/><path d="M8.5 16.429a5 5 0 0 1 7 0"/><path d="M5 12.859a10 10 0 0 1 5.17-2.69"/><path d="M19 12.859a10 10 0 0 0-2.007-1.523"/><path d="M2 8.82a15 15 0 0 1 4.177-2.643"/><path d="M22 8.82a15 15 0 0 0-11.288-3.764"/><path d="m2 2 20 20"/>',
        'qr-code' => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'bar-chart' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
    ];
    $icon = fn (string $name, string $class = 'w-6 h-6') => '<svg class="'.$class.'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BestWorkflows | Management Information System &amp; Compliance Engine</title>
    <meta name="description" content="Next-generation Management Information System built for statutory compliance, multi-branch workflow orchestration, and edge-resilient governance.">

    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/bestworkflows-icon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-brand-surface text-brand-slate font-body antialiased selection:bg-brand-amber selection:text-white">

    {{-- 1. Announcement bar --}}
    <div class="bg-brand-dark text-white border-b border-cyan-900/50 px-4 py-2 text-xs md:text-sm font-medium">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2 mx-auto md:mx-0">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-brand-amber text-slate-950 uppercase tracking-wider">Launch Briefing</span>
                <span><strong>BestWorkflows 1.0 is live:</strong> Bridging localized regulatory compliance with global operational standards.</span>
            </div>
            <a href="#demo" class="hidden md:inline-flex items-center gap-1 text-cyan-300 hover:text-white transition">
                Read the Whitepaper {!! $icon('arrow-right', 'w-3.5 h-3.5') !!}
            </a>
        </div>
    </div>

    {{-- 2. Header / navbar --}}
    <header class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between gap-3">
            <div class="flex min-w-0 shrink-0 items-center">
                <a href="/" class="group inline-flex items-center">
                    <x-brand-logo class="w-44 sm:w-56 transition-transform group-hover:scale-[1.02]" />
                </a>
            </div>

            <nav class="hidden xl:flex items-center gap-5 text-sm font-medium text-slate-600">
                <a href="#features" class="hover:text-brand-teal transition">Platform Pillars</a>
                <a href="#architecture" class="hover:text-brand-teal transition">Edge Resilience</a>
                <a href="#workflow" class="hover:text-brand-teal transition">Workflow Engine</a>
                <a href="#deployment" class="hover:text-brand-teal transition">Deployment Models</a>
            </nav>

            <div class="flex shrink-0 items-center gap-3">
                <a href="{{ route('login') }}" class="inline-flex items-center justify-center whitespace-nowrap px-3 sm:px-4 py-2.5 text-sm font-semibold text-brand-teal hover:text-brand-dark transition">
                    Sign In
                </a>
                <a href="#demo" class="hidden sm:inline-flex items-center justify-center whitespace-nowrap px-5 py-2.5 text-sm font-semibold rounded-lg bg-brand-amber hover:bg-brand-amberdark text-slate-950 transition shadow-sm font-display tracking-wide">
                    Request Pilot
                </a>
            </div>
        </div>
    </header>

    {{-- 3. Hero --}}
    <section class="relative overflow-hidden pt-12 pb-20 lg:pt-20 lg:pb-28 border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                <div class="lg:col-span-7 space-y-6">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100/70 border border-cyan-200 text-brand-teal text-xs font-semibold">
                        {!! $icon('shield-check', 'w-4 h-4') !!} Institutional Management Information System
                    </div>
                    <h1 class="font-display text-4xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 tracking-tight leading-[1.1]">
                        Mission-Critical Workflows. <br class="hidden sm:inline">
                        <span class="text-brand-teal">Complete Local Compliance.</span> <br class="hidden sm:inline">
                        Zero Friction.
                    </h1>
                    <p class="text-lg text-slate-600 max-w-2xl leading-relaxed">
                        BestWorkflows is an HR and operations Management Information System engineered for regional agencies, multi-branch enterprises, and regulated authorities. Unify field dockets, statutory verification, and executive decision pipelines on a tamper-evident foundation.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4 pt-2">
                        <a href="#demo" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-lg bg-brand-amber hover:bg-brand-amberdark text-slate-950 font-bold font-display transition shadow-md">
                            {!! $icon('play-circle', 'w-5 h-5') !!} Request Executive Demo
                        </a>
                        <a href="#architecture" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-lg bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-semibold transition">
                            <span class="text-brand-teal">{!! $icon('cpu', 'w-5 h-5') !!}</span> System Architecture
                        </a>
                    </div>

                    <div class="pt-6 border-t border-slate-200 grid grid-cols-3 gap-4 text-left">
                        <div>
                            <div class="font-display font-bold text-slate-900 text-lg">100%</div>
                            <div class="text-xs text-slate-500">Data Sovereignty &amp; Policy Bounds</div>
                        </div>
                        <div>
                            <div class="font-display font-bold text-slate-900 text-lg">Edge First</div>
                            <div class="text-xs text-slate-500">Offline Field Synchronization</div>
                        </div>
                        <div>
                            <div class="font-display font-bold text-slate-900 text-lg">&lt; 0.2s</div>
                            <div class="text-xs text-slate-500">Audit-Proof Signature Verification</div>
                        </div>
                    </div>
                </div>

                {{-- Simulated MIS interface card --}}
                <div class="lg:col-span-5" id="architecture">
                    <div class="relative rounded-2xl bg-white border border-slate-200 shadow-xl overflow-hidden shadow-[0_0_35px_-5px_rgba(245,158,11,0.22)]">
                        <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between border-b border-slate-800">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                <span class="text-xs font-mono text-slate-400 ml-2">sys-node.central.internal</span>
                            </div>
                            <span class="inline-flex items-center gap-1 text-[11px] font-mono text-emerald-400 bg-emerald-950/60 border border-emerald-800/80 px-2 py-0.5 rounded">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> ONLINE (EDGE ACTIVE)
                            </span>
                        </div>

                        <div class="p-6 space-y-5 bg-slate-50">
                            <div class="grid grid-cols-2 gap-3">
                                <div class="bg-white p-3.5 rounded-lg border border-slate-200">
                                    <span class="text-xs text-slate-500 font-medium">Compliance Readiness</span>
                                    <div class="flex items-baseline gap-2 mt-1">
                                        <span class="font-display font-extrabold text-2xl text-slate-900">99.8%</span>
                                        <span class="text-xs font-semibold text-emerald-600">STABLE</span>
                                    </div>
                                </div>
                                <div class="bg-white p-3.5 rounded-lg border border-slate-200">
                                    <span class="text-xs text-slate-500 font-medium">Active Dockets</span>
                                    <div class="flex items-baseline gap-2 mt-1">
                                        <span class="font-display font-extrabold text-2xl text-slate-900">1,482</span>
                                        <span class="text-xs font-semibold text-brand-teal">&uarr; 14 today</span>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 flex justify-between">
                                    <span>Routing Stream</span>
                                    <span class="font-mono text-brand-teal">SLA Enforced</span>
                                </div>

                                <div class="bg-white p-3 rounded-lg border-l-4 border-l-brand-teal border border-slate-200 shadow-sm flex items-center justify-between">
                                    <div>
                                        <div class="text-xs font-mono font-medium text-slate-400">DOC-2026-9041</div>
                                        <div class="text-sm font-semibold text-slate-800">Inter-Agency Clearance Review</div>
                                    </div>
                                    <span class="px-2 py-1 rounded text-[11px] font-bold bg-amber-50 text-brand-amberdark border border-amber-200">Step 2 / 3</span>
                                </div>

                                <div class="bg-white p-3 rounded-lg border-l-4 border-l-brand-amber border border-slate-200 shadow-sm flex items-center justify-between">
                                    <div>
                                        <div class="text-xs font-mono font-medium text-slate-400">DOC-2026-9042</div>
                                        <div class="text-sm font-semibold text-slate-800">Field Inspection Sign-off</div>
                                    </div>
                                    <span class="px-2 py-1 rounded text-[11px] font-bold bg-cyan-50 text-brand-teal border border-cyan-200">Edge Synced</span>
                                </div>
                            </div>

                            <div class="bg-slate-900 text-slate-300 p-3 rounded-lg text-xs font-mono flex items-center justify-between">
                                <span class="truncate text-slate-400">SHA-256: 7f8a9...b401e</span>
                                <span class="text-emerald-400 font-semibold flex items-center gap-1">
                                    {!! $icon('check-check', 'w-3.5 h-3.5') !!} Verifiable
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 4. Core features --}}
    <section id="features" class="py-20 bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-xs font-bold uppercase tracking-widest text-brand-amberdark mb-3">Enterprise Capabilities</h2>
                <p class="font-display text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">
                    Purpose-Built for High-Stakes Operations
                </p>
                <p class="mt-4 text-slate-600">
                    Most general software fails when exposed to regulatory scrutiny and disconnected fields. BestWorkflows combines statutory rigor with modern ease-of-use.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                @php
                    $features = [
                        ['file-check', 'bg-cyan-100 text-brand-teal', 'Statutory Policy Engine', 'Incorporate municipal codes, legal frameworks, and jurisdictional approval hierarchies directly into form routing. Prevent off-policy dockets from moving downstream.'],
                        ['git-branch', 'bg-amber-100 text-brand-amberdark', 'Multi-Tier Approval Chains', 'Configure visual routing pathways with dynamic conditions: financial ceilings, concurrent legal sign-offs, and automatic escalation when turnaround SLAs expire.'],
                        ['wifi-off', 'bg-emerald-100 text-emerald-700', 'Edge-First Field Ingestion', 'Inspectors and satellite offices continue operations without an active web connection. Encrypted queues sync automatically with conflict resolution when back online.'],
                        ['shield-alert', 'bg-indigo-100 text-indigo-700', 'Immutable Audit Telemetry', 'Full compliance readiness with write-once audit logs. Every user interaction, credential sign-off, and state transition is cryptographically serialized.'],
                        ['qr-code', 'bg-rose-100 text-rose-700', 'QR Tokenized Certifications', 'Generate tamper-proof physical and digital certifications embedded with secure public-verification tokens, preventing credential fabrication and manual forgery.'],
                        ['bar-chart', 'bg-teal-100 text-brand-teal', 'Executive Decision Cockpit', 'Real-time analytics for department leads and enterprise officers. Monitor bottlenecks, clearance times, budget balances, and operational backlog metrics at a glance.'],
                    ];
                @endphp
                @foreach ($features as [$ic, $swatch, $heading, $body])
                    <div class="bg-brand-surface p-8 rounded-xl border border-slate-200 hover:border-brand-teal transition">
                        <div class="w-12 h-12 rounded-lg {{ $swatch }} flex items-center justify-center mb-6">
                            {!! $icon($ic, 'w-6 h-6') !!}
                        </div>
                        <h3 class="font-display text-xl font-bold text-slate-900 mb-2">{{ $heading }}</h3>
                        <p class="text-sm text-slate-600 leading-relaxed">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- 5. Orchestration pipeline --}}
    <section id="workflow" class="py-20 bg-slate-900 text-white border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <span class="text-brand-amber font-mono text-xs uppercase tracking-widest font-semibold">Orchestration Blueprint</span>
                <h2 class="font-display text-3xl sm:text-4xl font-extrabold tracking-tight mt-2">
                    From Field Docket to Verifiable Record
                </h2>
                <p class="text-slate-400 mt-4 text-sm sm:text-base">
                    How BestWorkflows moves high-compliance requests through automated checkpoints without manual friction.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                @php
                    $steps = [
                        ['01. INGESTION', 'Input &amp; Verification', 'Portal form or offline mobile agent submission with cryptographic client signature.'],
                        ['02. COMPLIANCE CHECK', 'Automated Policy Gate', 'Engine parses municipal and regional rules to validate pre-requisites and documentation.'],
                        ['03. ROUTING &amp; SLA', 'Departmental Actions', 'Concurrent review across legal, treasury, and field inspection with auto-escalation timers.'],
                        ['04. IMMUTABLE OUTPUT', 'Certified Issuance', 'Tokenized public QR record generated, archive sealed, and audit ledger updated.'],
                    ];
                @endphp
                @foreach ($steps as [$tag, $heading, $body])
                    <div class="bg-slate-800/80 p-6 rounded-xl border border-slate-700">
                        <div class="text-brand-amber font-mono text-xs mb-2">{{ $tag }}</div>
                        <h4 class="text-lg font-bold mb-2">{!! $heading !!}</h4>
                        <p class="text-xs text-slate-400 leading-relaxed">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- 6. Deployment models --}}
    <section id="deployment" class="py-20 bg-brand-surface border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-xs font-bold uppercase tracking-widest text-brand-teal mb-3">Deployment Tiers</h2>
                <p class="font-display text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">
                    Engineered for Sovereign Scaling
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-stretch">
                {{-- Tier 1 --}}
                <div class="bg-white rounded-2xl p-8 border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Tier 1</span>
                        <h3 class="font-display text-2xl font-bold text-slate-900 mt-1">Regional Cloud</h3>
                        <p class="text-sm text-slate-600 mt-3">Ideal for single municipalities or growing multi-branch operations needing immediate cloud digitizing.</p>
                        <div class="my-6 border-t border-slate-100 pt-6 space-y-3 text-sm text-slate-700">
                            @foreach (['Up to 50 Concurrent Operators', 'Secure Sovereign Cloud Hosting', 'Built-in Compliance Rulesets', 'Standard Email &amp; Ticket Support'] as $line)
                                <div class="flex items-center gap-2"><span class="text-brand-teal">{!! $icon('check', 'w-4 h-4') !!}</span> {!! $line !!}</div>
                            @endforeach
                        </div>
                    </div>
                    <a href="#demo" class="w-full py-3 text-center rounded-lg border border-slate-300 font-semibold text-slate-800 hover:bg-slate-50 transition block">
                        Select Cloud Tier
                    </a>
                </div>

                {{-- Tier 2 (featured) --}}
                <div class="bg-white rounded-2xl p-8 border-2 border-brand-teal shadow-xl relative flex flex-col justify-between">
                    <div class="absolute -top-3.5 left-1/2 -translate-x-1/2 bg-brand-teal text-white text-[11px] font-bold uppercase tracking-wider px-3 py-1 rounded-full">
                        Recommended for Public Sector
                    </div>
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-brand-amberdark">Tier 2</span>
                        <h3 class="font-display text-2xl font-bold text-slate-900 mt-1">Hybrid Sovereign</h3>
                        <p class="text-sm text-slate-600 mt-3">Private cloud control with local on-premises edge nodes to guarantee offline continuity in isolated sectors.</p>
                        <div class="my-6 border-t border-slate-100 pt-6 space-y-3 text-sm text-slate-700">
                            @foreach (['Unlimited Operators &amp; Branch Nodes', 'Offline Edge Node Synchronization', 'Custom Regulatory Mapping', '24/7 Dedicated Priority Incident Support'] as $line)
                                <div class="flex items-center gap-2"><span class="text-brand-amberdark">{!! $icon('check', 'w-4 h-4') !!}</span> {!! $line !!}</div>
                            @endforeach
                        </div>
                    </div>
                    <a href="#demo" class="w-full py-3 text-center rounded-lg bg-brand-amber hover:bg-brand-amberdark font-bold text-slate-950 transition block font-display">
                        Request Architecture Review
                    </a>
                </div>

                {{-- Tier 3 --}}
                <div class="bg-white rounded-2xl p-8 border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Tier 3</span>
                        <h3 class="font-display text-2xl font-bold text-slate-900 mt-1">Air-Gapped Bare Metal</h3>
                        <p class="text-sm text-slate-600 mt-3">Tailored for defense, national registry systems, and critical infrastructure requiring zero external web connectivity.</p>
                        <div class="my-6 border-t border-slate-100 pt-6 space-y-3 text-sm text-slate-700">
                            @foreach (['100% Air-Gapped Local Cluster Deployment', 'Custom Hardware HSM Integration', 'Dedicated On-Site Technical Engineer', 'Source Code Escrow &amp; Custom Audits'] as $line)
                                <div class="flex items-center gap-2"><span class="text-brand-teal">{!! $icon('check', 'w-4 h-4') !!}</span> {!! $line !!}</div>
                            @endforeach
                        </div>
                    </div>
                    <a href="#demo" class="w-full py-3 text-center rounded-lg border border-slate-300 font-semibold text-slate-800 hover:bg-slate-50 transition block">
                        Contact Defense / Enterprise Team
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- 7. Consultation form (static stub) --}}
    <section id="demo" class="py-20 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-brand-teal text-white rounded-3xl p-8 sm:p-12 shadow-2xl relative overflow-hidden">
                <div class="relative z-10 max-w-xl">
                    <h2 class="font-display text-3xl sm:text-4xl font-extrabold tracking-tight leading-tight">
                        Schedule an Executive Systems Consultation
                    </h2>
                    <p class="text-cyan-100 mt-3 text-sm sm:text-base">
                        Discuss your statutory compliance, regional edge layout, and workflow migration needs with a BestWorkflows Enterprise Solutions Architect.
                    </p>

                    <form id="consultation-form" class="mt-8 space-y-4" onsubmit="return false;">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="fullName" class="block text-xs font-semibold text-cyan-200 uppercase mb-1">Full Name</label>
                                <input id="fullName" name="fullName" type="text" placeholder="Atty. / Dir. / Officer Name" class="w-full px-4 py-2.5 rounded-lg bg-cyan-950/70 border border-cyan-800 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-amber text-sm">
                            </div>
                            <div>
                                <label for="organization" class="block text-xs font-semibold text-cyan-200 uppercase mb-1">Official Agency / Enterprise</label>
                                <input id="organization" name="organization" type="text" placeholder="Organization Name" class="w-full px-4 py-2.5 rounded-lg bg-cyan-950/70 border border-cyan-800 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-amber text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="email" class="block text-xs font-semibold text-cyan-200 uppercase mb-1">Official Email</label>
                                <input id="email" name="email" type="email" placeholder="name@agency.gov / corp.com" class="w-full px-4 py-2.5 rounded-lg bg-cyan-950/70 border border-cyan-800 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-amber text-sm">
                            </div>
                            <div>
                                <label for="deploymentType" class="block text-xs font-semibold text-cyan-200 uppercase mb-1">Desired Deployment</label>
                                <x-kit.select id="deploymentType" name="deploymentType" aria-label="Desired Deployment" class="w-full rounded-lg bg-cyan-950 text-white [&_button[role=combobox]]:border-cyan-800 [&_button[role=combobox]]:focus:ring-brand-amber">
                                    <flux:select.option value="hybrid">Hybrid Sovereign (Recommended)</flux:select.option>
                                    <flux:select.option value="cloud">Regional Secure Cloud</flux:select.option>
                                    <flux:select.option value="bare-metal">Air-Gapped Bare Metal</flux:select.option>
                                </x-kit.select>
                            </div>
                        </div>

                        <button type="submit" class="w-full sm:w-auto px-8 py-3.5 bg-brand-amber hover:bg-brand-amberdark text-slate-950 font-bold font-display rounded-lg transition shadow-lg mt-2">
                            Submit Implementation Briefing
                        </button>
                        <p id="consultation-note" class="hidden text-sm text-cyan-100 pt-1">
                            Thanks — this is a preview. Briefing intake is not wired up yet.
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </section>

    {{-- 8. Footer --}}
    <footer class="bg-slate-950 text-slate-400 py-12 text-sm border-t border-slate-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-3">
                <div class="p-2 inline-flex items-center justify-center">
                    <x-brand-logo class="w-56" />
                </div>
            </div>

            <div class="flex items-center gap-6 text-xs text-slate-500">
                <a href="#" class="hover:text-slate-300 transition">Data Sovereignty Standard</a>
                <a href="#" class="hover:text-slate-300 transition">Audit Readiness Guide</a>
                <a href="#" class="hover:text-slate-300 transition">Security Certifications</a>
            </div>

            <div class="text-xs text-slate-600">
                &copy; {{ date('Y') }} BestWorkflows. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
        // Static stub: acknowledge the briefing form without a backend.
        document.getElementById('consultation-form')?.addEventListener('submit', function () {
            document.getElementById('consultation-note')?.classList.remove('hidden');
            this.reset();
            this.querySelector('[name="deploymentType"]')?.dispatchEvent(new Event('change', { bubbles: true }));
        });
    </script>
    @fluxScripts
</body>
</html>
