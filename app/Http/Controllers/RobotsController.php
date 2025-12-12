<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function index(): Response
    {
        $baseUrl = config('app.url');

        $content = "User-agent: *\n";
        $content .= "Disallow: /api/\n";
        $content .= "Disallow: /dashboard\n";
        $content .= "Disallow: /transactions\n";
        $content .= "Disallow: /settings\n";
        $content .= "\n";
        $content .= "Sitemap: {$baseUrl}/sitemap.xml\n";

        return response($content, 200)
            ->header('Content-Type', 'text/plain');
    }
}
