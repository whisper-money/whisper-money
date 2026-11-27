<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Admin\AdminUserRow;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * Every user, newest first, a page at a time.
     */
    public function index(): Response
    {
        return Inertia::render('admin/users', [
            'users' => User::query()
                ->latest()
                ->paginate(self::PER_PAGE)
                ->through(AdminUserRow::from(...)),
        ]);
    }
}
