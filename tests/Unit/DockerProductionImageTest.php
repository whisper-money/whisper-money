<?php

$root = dirname(__DIR__, 2);

it('publishes and uses the production docker image for self hosting', function () use ($root) {
    $workflow = file_get_contents($root.'/.github/workflows/ci.yml');
    $compose = file_get_contents($root.'/docker-compose.production.yml');
    $localCompose = file_get_contents($root.'/docker-compose.production.local.yml');
    $coolifyTemplate = file_get_contents($root.'/templates/coolify/whisper-money.yaml');
    $dockerignore = file_get_contents($root.'/.dockerignore');

    expect($workflow)
        ->toContain('file: Dockerfile.production')
        ->toContain('type=raw,value=production')
        ->toContain('type=raw,value=v${{ steps.package-version.outputs.version }}-production')
        ->toContain("'docker-compose.production.yml'")
        ->toContain("'docker-compose.production.local.yml'")
        ->toContain("'.env.production.example'");

    expect($compose)
        ->toContain('ghcr.io/whisper-money/whisper-money:latest');

    expect($localCompose)
        ->toContain('image: whisper-money:production-local')
        ->toContain('dockerfile: Dockerfile.production');

    expect($coolifyTemplate)
        ->toContain('ghcr.io/whisper-money/whisper-money:latest');

    expect($dockerignore)
        ->toContain('.env')
        ->toContain('node_modules')
        ->toContain('vendor');
});
