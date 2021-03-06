<?php

namespace Codewiser\Rpac\Policies;

use Codewiser\Rpac\Helpers\RpacHelper;
use Codewiser\Rpac\Traits\RPAC;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use \Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Codewiser\Rpac\Permission;
use Codewiser\Rpac\Traits\HasRoles;

abstract class RpacPolicy
{
    use HandlesAuthorization;

    /**
     * Per-hit data storage
     * @var array
     */
    private $cache = [];


    /**
     * The Policy pseudo-name for use in permission table
     * @return string
     */
    public function getNamespace()
    {
        // Will use Policy::class name without Policy word
        // App\Policies\PostPolicy
        // ->
        // App\Post

        $class = get_class($this);
        $class = Str::replaceLast('Policies\\', '', $class);
        $class = Str::replaceLast('Policy', '', $class);
        return $class;
    }

    /**
     * Default (built-in) permissions
     * @param string $action
     * @return array|string|null|void return namespaced(!) roles, allowed to $action
     */
    abstract public function permissions($action);

    /**
     * Get list of model actions
     * @return array|string[]
     * @example [view, update, delete, ...]
     */
    public function getModelActions()
    {
        try {
            return $this->getActions('model');
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    /**
     * Get list of non-model actions
     * @return array|string[]
     * @example [viewAny, create]
     */
    public function getNonModelActions()
    {
        try {
            return $this->getActions('non-model');
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    /**
     * Get list of available Policy actions
     * @param string $option {model => returns actions for model; non-model => returns actions for non-model}
     * @return array
     * @throws \ReflectionException
     */
    private function getActions($option = null)
    {
        $reflection = new \ReflectionClass($this);
        return array_values(
            array_map(function (\ReflectionMethod $n) {
                return $n->name;
            }, array_filter(
                    $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                    function (\ReflectionMethod $n) use ($option) {
                        if ($n->isPublic()) {

                            /* @var \ReflectionParameter $firstArgument */
                            /* @var \ReflectionParameter $secondArguments */
                            $firstArgument = @$n->getParameters()[0];
                            $secondArguments = @$n->getParameters()[1];
                            $argumentCount = $n->getNumberOfParameters();

                            if ($option != 'non-model') {
                                // Require both parameters
                                if ($argumentCount == 2 &&
                                    $firstArgument->name == 'user' &&
                                    $secondArguments->name == 'model') {
                                    return $n;
                                }
                            }

                            if ($option != 'model') {
                                // Require only user parameter
                                if ($argumentCount == 1 && $firstArgument->name == 'user') {
                                    return $n;
                                }
                            }
                        }
                        return null;
                    })
            )
        );
    }

    public function viewAny(?User $user)
    {
        return $this->authorize('viewAny', $user);
    }

    public function view(?User $user, Model $model)
    {
        return $this->authorize('view', $user, $model);
    }

    public function create(?User $user)
    {
        return $this->authorize('create', $user);
    }

    public function update(?User $user, Model $model)
    {
        return $this->authorize('update', $user, $model);
    }

    public function delete(?User $user, Model $model)
    {
        /** @var Model|SoftDeletes $model */
        if ($this->usesSoftDeletes($model) && $model->trashed()) {
            // You can not delete deleted record
            return false;
        }
        return $this->authorize('delete', $user, $model);
    }

    public function restore(?User $user, Model $model)
    {
        /** @var Model|SoftDeletes $model */
        if ($this->usesSoftDeletes($model) && !$model->trashed()) {
            // You can not restore the record
            return false;
        }
        return $this->authorize('restore', $user, $model);
    }

    public function forceDelete(?User $user, Model $model)
    {
        /** @var Model|SoftDeletes $model */
        if (!$this->usesSoftDeletes($model)) {
            // You can not forceDelete record, that not use SoftDeletes trait
            return false;
        }
        return $this->authorize('forceDelete', $user, $model);
    }

    /**
     * If Model uses SoftDeletes trait
     * @param Model $model
     * @return bool
     */
    private function usesSoftDeletes(Model $model)
    {
        return method_exists($model, 'restore');
    }

    /**
     * Get user roles
     * @param User|HasRoles|null $user
     * @return array
     */
    protected function getUserNonModelRoles(?User $user)
    {
        if ($user) {

            if (!isset($this->cache["user-roles"])) {
                $this->cache["user-roles"] = $user->getRoles();
            }

            $roles = $this->cache["user-roles"];
        } else {
            $roles = ['guest'];
        }
        return $roles;
    }

    /**
     * Get relationships between given Model and given User
     * @param User|null $user
     * @param Model|RPAC $model
     * @return array
     */
    protected function getUserModelRoles(User $user, Model $model)
    {
        $roles = [];

        foreach ($model::getRelationshipListing() as $relationship) {
            // Check if given Model relates to User through relation

            if ($model->relatedTo($user, $relationship)) {
                $roles[] = $relationship;
            }
        }

        return $roles;
    }

    /**
     * Signature is a Model+Action string, used as 'action' in abstract sense
     * @param string $action
     * @return string
     */
    protected function getSignature($action)
    {
        return $this->getNamespace() . ':' . $action;
    }

    /**
     * Checks User ability to perform Action against Model
     * @param string $action
     * @param User|null $user
     * @param Model|null $model
     * @return bool
     */
    protected function authorize($action, ?User $user, Model $model = null)
    {
        $permissions = $this->getPermissions($action);

        return
            in_array('*', $permissions)
            ||
            array_intersect($this->getUserRoles($user, $model), $permissions);
    }

    /**
     * Roles and Relationships, that User plays in given Model
     * @param User|null $user
     * @param Model|null $model
     * @return array
     */
    protected function getUserRoles(?User $user, Model $model = null)
    {
        $roles = array_merge(
            ($user && $model) ? $this->getUserModelRoles($user, $model) : [],
            $this->getUserNonModelRoles($user)
        );
        return $roles;
    }

    /**
     * Get roles allowed to perform action
     * @param string $action
     * @return array
     */
    public function getPermissions($action)
    {
        $signature = $this->getSignature($action);

        // Take permissions with signature and user role
        $permissions = Permission::cached()->filter(
            function (Permission $perm) use ($signature) {
                return ($perm->signature == $signature);
            }
        );
        return array_merge(
            (array)$this->permissions($action),
            (array)$permissions->pluck('role')->toArray()
        );
    }


}
