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
    $isApi = $surface === \App\Enums\OAuthScope::Api;
    $appName = config('app.name', 'Whisper Money');
@endphp
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Card Container -->
        <div class="rounded-lg border bg-card text-card-foreground shadow-sm">
            <!-- Header -->
            <div class="flex flex-col gap-1.5 p-6">
                <div class="flex items-center justify-center mb-4">
                    <!-- Shield Icon -->
                    <svg class="h-12 w-12 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>

                <h3 class="text-2xl font-semibold leading-none tracking-tight text-center">
                    {{ __('Connect :client to :app', ['client' => $client->name, 'app' => $appName]) }}
                </h3>

                <p class="text-sm text-muted-foreground text-center">
                    @if($isApi)
                        {{ __(':client wants to use the :app API with your account, with read and write access to your finances.', ['client' => $client->name, 'app' => $appName]) }}
                    @else
                        {{ __(':client wants to connect your AI assistant to :app, with read and write access to your finances.', ['client' => $client->name, 'app' => $appName]) }}
                    @endif
                </p>
            </div>

            <!-- Content -->
            <div class="flex flex-col gap-4 p-6 pt-0">
                <!-- Where the user is sent back: the one thing an app cannot fake -->
                @if($redirectHost)
                    <div class="rounded-lg border p-4 bg-muted/50 text-center" data-test="redirect-host">
                        <p class="text-sm text-muted-foreground mb-1">{{ __('After you approve, you are sent to') }}</p>
                        <p class="text-lg font-semibold break-all">{{ $redirectHost }}</p>
                    </div>
                @endif

                <!-- Unverified app warning -->
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" role="note">
                    <p class="text-sm font-medium">{{ __('Unverified app') }}</p>
                    <p class="text-sm mt-1">
                        {{ __('This app has not been reviewed by Whisper Money, and anyone can give an app any name. Only continue if you trust :domain.', ['domain' => $redirectHost ?: $client->name]) }}
                    </p>
                </div>

                <!-- What the connection can do -->
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium">{{ __('It gets read and write access to your finances:') }}</p>

                    <ul class="flex flex-col gap-2">
                        <li class="flex items-start gap-2">
                            <div class="rounded-full bg-primary/10 p-1 mt-0.5">
                                <div class="h-1.5 w-1.5 rounded-full bg-primary"></div>
                            </div>
                            <span class="text-sm text-muted-foreground">
                                @if($isApi)
                                    {{ __('Read your accounts, balances, transactions, categories, budgets and savings goals.') }}
                                @else
                                    {{ __('Read and analyse your transactions, balances, categories and spending.') }}
                                @endif
                            </span>
                        </li>
                        <li class="flex items-start gap-2">
                            <div class="rounded-full bg-primary/10 p-1 mt-0.5">
                                <div class="h-1.5 w-1.5 rounded-full bg-primary"></div>
                            </div>
                            <span class="text-sm text-muted-foreground">
                                @if($isApi)
                                    {{ __('Create, edit and delete your accounts, transactions, categories, labels, budgets and automation rules.') }}
                                @else
                                    {{ __('Create, edit and delete transactions, categories, labels and automation rules.') }}
                                @endif
                            </span>
                        </li>
                    </ul>

                    <p class="text-sm text-muted-foreground pt-1">
                        @if($isApi)
                            {{ __('Transactions synced from your bank cannot be edited or deleted. You can disconnect the app at any time in Settings → API.') }}
                        @else
                            {{ __('Transactions synced from your bank cannot be edited or deleted. You can disconnect it at any time in Settings → AI Connector.') }}
                        @endif
                    </p>
                </div>

                <!-- Signed-in account -->
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground mb-1">{{ __('Signed in as') }}</p>
                    <p class="font-medium break-all">{{ $user->email }}</p>
                </div>

                <!-- Why this account cannot approve (API only) -->
                @if($refusal === 'shared_account')
                    <div class="rounded-lg border border-destructive/40 bg-destructive/10 p-4" role="alert" data-test="refusal">
                        <p class="text-sm font-medium">{{ __('This account cannot connect apps to the API') }}</p>
                        <p class="text-sm text-muted-foreground mt-1">{{ __('Shared demo and press accounts cannot use the Whisper Money API.') }}</p>
                    </div>
                @elseif($refusal === 'plan_required')
                    <div class="rounded-lg border p-4 bg-muted/50" role="alert" data-test="refusal">
                        <p class="text-sm font-medium">{{ __('The API is available on a paid plan') }}</p>
                        <p class="text-sm text-muted-foreground mt-1">{{ __('Upgrade your plan to connect apps to your account, then come back and try again.') }}</p>
                        <a href="{{ route('subscribe') }}" target="_blank" rel="noopener" class="mt-3 inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2">
                            {{ __('See plans') }}
                        </a>
                    </div>
                @endif
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

                @if($refusal === null)
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
                @endif
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

        if (! form) {
            return;
        }

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
