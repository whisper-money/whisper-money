<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Inline script to detect system dark mode preference and apply it immediately --}}
    <script>
        (function() {
            const appearance = @json($appearance ?? 'system');

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    <style>
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
        }
    </style>

    <title>{{ __('Connect to :app', ['app' => config('app.name', 'Whisper Money')]) }}</title>

    <link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon/favicon.svg" />
    <link rel="shortcut icon" href="/favicon/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />
    <link rel="manifest" href="/favicon/site.webmanifest" />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-background text-foreground">
@php
    $redirectHost = parse_url($request->get('redirect_uri', ''), PHP_URL_HOST);
@endphp
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Card Container -->
        <div class="rounded-lg border bg-card text-card-foreground shadow-sm">
            <!-- Header -->
            <div class="flex flex-col space-y-1.5 p-6">
                <div class="flex items-center justify-center mb-4">
                    <!-- Shield Icon -->
                    <svg class="h-12 w-12 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>

                <h3 class="text-2xl font-semibold leading-none tracking-tight text-center">
                    {{ __('Connect :client to :app', ['client' => $client->name, 'app' => config('app.name', 'Whisper Money')]) }}
                </h3>

                <p class="text-sm text-muted-foreground text-center">
                    {{ __(':client is asking to read and make changes to your finance data.', ['client' => $client->name]) }}
                </p>
            </div>

            <!-- Content -->
            <div class="p-6 pt-0 space-y-4">
                <!-- User Info -->
                <div class="rounded-lg border p-4 bg-muted/50">
                    <p class="text-sm text-muted-foreground mb-2">{{ __('Signed in as') }}</p>
                    <p class="font-medium">{{ $user->email }}</p>
                    @if($redirectHost)
                        <p class="text-sm text-muted-foreground mt-3 mb-1">{{ __('Sends you back to') }}</p>
                        <p class="font-medium break-all">{{ $redirectHost }}</p>
                    @endif
                </div>

                <!-- What the connection can and cannot do -->
                <div class="space-y-2">
                    <p class="text-sm font-medium">{{ __('This connection can:') }}</p>

                    <ul class="space-y-2">
                        <li class="flex items-start gap-2">
                            <div class="rounded-full bg-primary/10 p-1 mt-0.5">
                                <div class="h-1.5 w-1.5 rounded-full bg-primary"></div>
                            </div>
                            <span class="text-sm text-muted-foreground">
                                {{ __('Read and analyse your transactions, balances, categories and spending.') }}
                            </span>
                        </li>
                        <li class="flex items-start gap-2">
                            <div class="rounded-full bg-primary/10 p-1 mt-0.5">
                                <div class="h-1.5 w-1.5 rounded-full bg-primary"></div>
                            </div>
                            <span class="text-sm text-muted-foreground">
                                {{ __('Create, edit and delete transactions, categories, labels and automation rules.') }}
                            </span>
                        </li>
                    </ul>

                    <p class="text-sm text-muted-foreground pt-1">
                        {{ __('Bank-connected accounts and their transactions stay read-only. You can disconnect it at any time from the connected app.') }}
                    </p>
                </div>
            </div>

            <!-- Footer With Buttons -->
            <div class="flex items-center p-6 pt-0 gap-3">
                <!-- Deny Form -->
                <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2 w-full">
                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        {{ __('Cancel') }}
                    </button>
                </form>

                <!-- Approve Form -->
                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1" id="authorizeForm">
                    @csrf
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2 w-full" id="authorizeButton">
                        <span id="authorizeText">{{ __('Connect') }}</span>

                        <svg id="loadingSpinner" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('authorizeForm');
        const button = document.getElementById('authorizeButton');
        const authorizeText = document.getElementById('authorizeText');
        const loadingSpinner = document.getElementById('loadingSpinner');

        form.addEventListener('submit', function(e) {
            // Show loading state...
            button.disabled = true;
            authorizeText.textContent = '{{ __('Connecting…') }}';
            loadingSpinner.classList.remove('hidden');
        });
    });
</script>
</body>
</html>
