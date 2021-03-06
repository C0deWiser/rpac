<?php

namespace Codewiser\Rpac\Middleware;

use Closure;
use Codewiser\Rpac\Traits\HasRoles;
use Illuminate\Contracts\Auth\Guard;

class VerifyRole
{
    /**
     * @var \Illuminate\Contracts\Auth\Guard
     */
    protected $auth;

    /**
     * Create a new filter instance.
     *
     * @param \Illuminate\Contracts\Auth\Guard $auth
     * @return void
     */
    public function __construct(Guard $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @param int|string $roles
     * @return mixed
     */
    public function handle($request, Closure $next, $roles = '')
    {
        /** @var HasRoles $user */
        $user = $this->auth->user();

        if ($this->auth->check() && $user->playRole(!empty($roles) ? explode( "|", $roles) : [])) {
            //$this->auth->user()->load('roles');
            return $next($request);
        }

        abort(403);
    }
}
